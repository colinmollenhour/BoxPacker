<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Exception\NoBoxesAvailableException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use WeakMap;

use function abs;
use function array_values;
use function ceil;
use function count;
use function implode;
use function intdiv;
use function ksort;
use function iterator_to_array;
use function max;
use function sort;
use function spl_object_id;
use function usort;

use const INF;
use const PHP_INT_MAX;

/**
 * Multi-box packing for {@see PackingStrategy::Thorough}.
 *
 * A greedy construction fills one box at a time: if a single box can hold everything that is left, the cheapest such
 * box is used, otherwise the box that packs the most volume. A local search then tries to reduce the number and cost
 * of the boxes: merging pairs of boxes, emptying a box into the others one item at a time, moving each box's contents
 * into the cheapest box type that holds them, and repacking pairs of boxes into two cheaper ones. Only strict
 * improvements are accepted.
 *
 * The objective is: fewest unpacked items, then fewest boxes, then lowest total cost; or, when minimising cost first,
 * fewest unpacked items, then lowest total cost, then fewest boxes. When minimising cost first, a second solution is
 * also built and improved, filling the box that packs the most volume per unit of cost and considering splitting what
 * would fit into one box between cheaper ones, and the better of the two is kept.
 *
 * Search effort is bounded by the time budget of the VolumePackerFactory if it has one, otherwise by a number of
 * single-box packings proportional to the number of boxes (so results are deterministic).
 *
 * @internal
 */
class ThoroughPacker implements LoggerAwareInterface
{
    /**
     * Without a time budget, the improvement phase may make this many single-box packings per box in the solution...
     */
    private const IMPROVEMENT_PACKINGS_PER_BOX = 20;

    /**
     * ...and at least this many.
     */
    private const MIN_IMPROVEMENT_PACKINGS = 50;

    /**
     * Each attempted improvement searches with this fraction of the single-box search budget: measured on the bookshop
     * corpus, a tenth finds the same improvements in a fraction of the time.
     */
    private const IMPROVEMENT_BUDGET_DIVISOR = 10;

    /**
     * The whole improvement phase may use (in total) this many times the single-box search budget.
     */
    private const IMPROVEMENT_BUDGET_FACTOR = 2;

    private LoggerInterface $logger;

    /**
     * Box types in order of preference: cheapest when empty first, ties in BoxList order.
     *
     * @var list<Box>
     */
    private array $boxTypes = [];

    /**
     * Box type (object id) => quantity not in use.
     *
     * @var array<int, int>
     */
    private array $available = [];

    /**
     * @var array<int, float>
     */
    private array $emptyCosts = [];

    /**
     * @var array<int, int>
     */
    private array $volumes = [];

    /**
     * Box type => weight of items it can carry.
     *
     * @var array<int, int>
     */
    private array $capacities = [];

    private int $maxVolume = 0;

    private int $maxCapacity = 0;

    /**
     * @var WeakMap<PackedBox, float>
     */
    private WeakMap $costs;

    /**
     * Packings already made: "box id|sorted item ids" => packed box.
     *
     * @var array<string, PackedBox>
     */
    private array $packCache = [];

    private bool $improving = false;

    /**
     * Single-box packings made (not counting cache hits) since the improvement phase began.
     */
    private int $improvementPackings = 0;

    private int $maxImprovementPackings = PHP_INT_MAX;

    /**
     * Trial block placements made by the single-box searches since the improvement phase began.
     */
    private int $improvementPlacements = 0;

    private int $maxImprovementPlacements = PHP_INT_MAX;

    /**
     * Construction criterion when no single box will do: fill the box packing the most volume per unit of cost
     * (rather than the most volume).
     */
    private bool $fillByValue = false;

    /**
     * @param WeakMap<Box, int> $boxQuantitiesAvailable updated to reflect the boxes used
     * @param bool              $minimiseCostFirst      minimise the total cost before the number of boxes
     */
    public function __construct(
        private readonly BoxList $boxes,
        private readonly WeakMap $boxQuantitiesAvailable,
        private readonly VolumePackerFactory $volumePackerFactory,
        private readonly PackedBoxCostCalculator $costCalculator,
        private readonly bool $minimiseCostFirst,
        private readonly ?TimeoutChecker $timeoutChecker = null,
    ) {
        $this->logger = new NullLogger();
        $this->costs = new WeakMap();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Pack the items into boxes. Packed items are removed from $items, leaving any that could not be packed.
     *
     * @return list<PackedBox>
     */
    public function pack(ItemList $items, bool $throwOnUnpackableItem): array
    {
        $this->initialiseBoxTypes();

        $solution = $this->minimiseCostFirst
            ? $this->packByVolumeAndByValue($items, $throwOnUnpackableItem)
            : $this->constructAndImprove($items, $throwOnUnpackableItem);

        foreach ($this->boxTypes as $box) {
            $this->boxQuantitiesAvailable[$box] = $this->available[spl_object_id($box)];
        }

        return $solution;
    }

    /**
     * Negative if packing $a is better than packing $b under the objective, positive if worse, 0 if neither.
     * Both must pack the same items.
     *
     * @param iterable<PackedBox> $a
     * @param iterable<PackedBox> $b
     */
    public function compareSolutions(iterable $a, iterable $b): int
    {
        $boxesA = iterator_to_array($a, false);
        $boxesB = iterator_to_array($b, false);
        $costA = $this->totalCost($boxesA);
        $costB = $this->totalCost($boxesB);

        if ($this->isImprovement(count($boxesA) - count($boxesB), $costA - $costB, $costB)) {
            return -1;
        }
        if ($this->isImprovement(count($boxesB) - count($boxesA), $costB - $costA, $costA)) {
            return 1;
        }

        return 0;
    }

    private function initialiseBoxTypes(): void
    {
        $sortable = [];
        foreach ($this->boxes as $order => $box) {
            $id = spl_object_id($box);
            $quantity = $this->boxQuantitiesAvailable[$box] ?? 0;
            if ($quantity <= 0 || $box->getMaxWeight() < $box->getEmptyWeight()) {
                continue;
            }
            $this->available[$id] = $quantity;
            $this->volumes[$id] = $box->getInnerWidth() * $box->getInnerLength() * $box->getInnerDepth();
            $this->capacities[$id] = $box->getMaxWeight() - $box->getEmptyWeight();
            $this->emptyCosts[$id] = $this->costCalculator->getCost(new PackedBox($box, new PackedItemList()));
            $this->maxVolume = max($this->maxVolume, $this->volumes[$id]);
            $this->maxCapacity = max($this->maxCapacity, $this->capacities[$id]);
            $sortable[] = [$this->emptyCosts[$id], $order, $box];
        }

        usort($sortable, static fn (array $a, array $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        foreach ($sortable as [, , $box]) {
            $this->boxTypes[] = $box;
        }
    }

    /**
     * Minimising cost first: fill boxes by the most volume, and separately by the most volume per unit of cost, and
     * keep the better result. Neither is reliably better: filling by value can split an order between several cheap
     * boxes, but it can also leave the search stuck with more boxes than it needs. Packed items are removed from $items.
     *
     * @return list<PackedBox>
     */
    private function packByVolumeAndByValue(ItemList $items, bool $throwOnUnpackableItem): array
    {
        $available = $this->available;
        $byVolumeLeft = clone $items;
        $byVolume = $this->constructAndImprove($byVolumeLeft, $throwOnUnpackableItem);
        $byVolumeAvailable = $this->available;

        $this->available = $available;
        $this->fillByValue = true;
        $byValueLeft = clone $items;
        $byValue = $this->constructAndImprove($byValueLeft, false);
        $this->fillByValue = false;

        $useByValue = $byValueLeft->count() < $byVolumeLeft->count()
            || ($byValueLeft->count() === $byVolumeLeft->count() && $this->compareSolutions($byValue, $byVolume) < 0);
        if (!$useByValue) {
            $this->available = $byVolumeAvailable;
        }

        $solution = $useByValue ? $byValue : $byVolume;
        foreach ($solution as $packedBox) {
            $items->removePackedItems($packedBox->items);
        }

        return $solution;
    }

    /**
     * Construct a solution and improve it. Packed items are removed from $items.
     *
     * @return list<PackedBox>
     */
    private function constructAndImprove(ItemList $items, bool $throwOnUnpackableItem): array
    {
        $solution = $this->construct($items, $throwOnUnpackableItem);
        $availableAfterConstruction = $this->available;
        $this->improve($solution);

        // improvement may have freed up boxes of a type that had run out, so try again with anything left over
        if ($items->count() > 0 && $this->anyBoxTypeFreed($availableAfterConstruction)) {
            $extra = $this->construct($items, false);
            if ($extra !== []) {
                $solution = [...$solution, ...$extra];
                $this->improve($solution);
            }
        }

        return $solution;
    }

    /**
     * Greedy construction: fill one box at a time until everything is packed, or nothing more can be.
     *
     * @param bool $lookAhead when filling by value: check whether splitting what fits in one box is cheaper
     *
     * @return list<PackedBox>
     */
    private function construct(ItemList $items, bool $throwOnUnpackableItem, bool $lookAhead = true): array
    {
        $packedBoxes = [];

        while ($items->count() > 0) {
            $this->budgetConstruction($items);

            /** @var list<Item> $itemArray */
            $itemArray = iterator_to_array($items, false);
            $chosen = $this->findSingleBox($itemArray);
            if ($chosen === null || ($this->fillByValue && $lookAhead)) {
                $partial = $this->findBestPartialBox($itemArray);
                if ($partial !== null && ($chosen === null || ($partial->items->count() < count($itemArray) && $this->splittingIsBetter($chosen, $partial, $items)))) {
                    $chosen = $partial;
                }
            }

            if ($chosen === null) {
                if ($throwOnUnpackableItem) {
                    throw new NoBoxesAvailableException("No boxes could be found for item '{$items->top()->getDescription()}'", $items);
                }
                $this->logger->log(LogLevel::INFO, "{$items->count()} unpackable items found");
                break;
            }

            $items->removePackedItems($chosen->items);
            --$this->available[spl_object_id($chosen->box)];
            $packedBoxes[] = $chosen;
        }

        return $packedBoxes;
    }

    /**
     * The cheapest box that holds all the items, if there is one. Box types are tried cheapest first, so this stops
     * as soon as no untried type can be cheaper than the best found.
     *
     * @param list<Item> $items
     */
    private function findSingleBox(array $items): ?PackedBox
    {
        [$volume, $weight] = self::measure($items);
        $best = null;
        $bestCost = INF;
        foreach ($this->boxTypes as $box) {
            $id = spl_object_id($box);
            if ($this->emptyCosts[$id] >= $bestCost) {
                break;
            }
            if ($this->available[$id] <= 0 || $this->volumes[$id] < $volume || $this->capacities[$id] < $weight) {
                continue;
            }
            $packedBox = $this->packItems($box, $items);
            if ($packedBox->items->count() === count($items) && $this->cost($packedBox) < $bestCost) {
                $best = $packedBox;
                $bestCost = $this->cost($packedBox);
            }
        }

        return $best;
    }

    /**
     * When no single box will do, the box to fill next: the one packing the most volume, or the most volume per unit
     * of cost when minimising cost. The most promising box types are packed first, so that those which cannot do
     * better can be skipped.
     *
     * @param list<Item> $items
     */
    private function findBestPartialBox(array $items): ?PackedBox
    {
        $candidates = [];
        foreach ($this->boxTypes as $order => $box) {
            $id = spl_object_id($box);
            if ($this->available[$id] > 0) {
                $bound = $this->packableVolumeBound($box, $items);
                $promise = $this->fillByValue ? ($this->emptyCosts[$id] > 0 ? $bound / $this->emptyCosts[$id] : INF) : $bound;
                $candidates[] = [-$promise, $order, $bound, $box];
            }
        }
        sort($candidates);

        $best = null;
        foreach ($candidates as [, , $bound, $box]) {
            if ($best !== null && !$this->couldBeBetterPartialBox($box, $bound, $best)) {
                break;
            }
            $packedBox = $this->packItems($box, $items);
            if ($packedBox->items->count() > 0 && ($best === null || $this->comparePartialBoxes($packedBox, $best) < 0)) {
                $best = $packedBox;
            }
        }

        return $best;
    }

    /**
     * Negative if $a is the better box to fill next, positive if $b is.
     */
    private function comparePartialBoxes(PackedBox $a, PackedBox $b): int
    {
        if ($this->fillByValue) {
            // most volume per unit of cost (cross-multiplied, so that free boxes are handled), then most volume
            return (($b->getUsedVolume() * $this->cost($a)) <=> ($a->getUsedVolume() * $this->cost($b)))
                ?: ($b->getUsedVolume() <=> $a->getUsedVolume());
        }

        return ($b->getUsedVolume() <=> $a->getUsedVolume())
            ?: ($this->cost($a) <=> $this->cost($b))
            ?: ($b->items->count() <=> $a->items->count());
    }

    /**
     * Whether a box type able to hold at most $bound volume of the items could be chosen over $best.
     */
    private function couldBeBetterPartialBox(Box $box, int $bound, PackedBox $best): bool
    {
        if ($this->fillByValue) {
            return $bound * $this->cost($best) >= $best->getUsedVolume() * $this->emptyCosts[spl_object_id($box)];
        }

        return $bound >= $best->getUsedVolume();
    }

    /**
     * An upper bound on the volume of the items that could be packed into the box: its volume, or the volume of the
     * items that fit within its weight capacity (lightest for their size first, the last one pro rata), whichever is
     * less. Items too large for the box in any orientation are left out.
     *
     * @param list<Item> $items
     */
    private function packableVolumeBound(Box $box, array $items): int
    {
        $boxEdges = [$box->getInnerWidth(), $box->getInnerLength(), $box->getInnerDepth()];
        sort($boxEdges);

        $candidates = [];
        foreach ($items as $item) {
            $edges = [$item->getWidth(), $item->getLength(), $item->getDepth()];
            sort($edges);
            if ($edges[0] <= $boxEdges[0] && $edges[1] <= $boxEdges[1] && $edges[2] <= $boxEdges[2]) {
                $volume = $edges[0] * $edges[1] * $edges[2];
                $candidates[] = [$item->getWeight() / ($volume ?: 1), $volume, $item->getWeight()];
            }
        }
        sort($candidates);

        $id = spl_object_id($box);
        $weightLeft = $this->capacities[$id];
        $bound = 0;
        foreach ($candidates as [, $volume, $weight]) {
            if ($weight <= $weightLeft) {
                $bound += $volume;
                $weightLeft -= $weight;
            } else {
                $bound += (int) ($volume * $weightLeft / $weight);
                break;
            }
        }

        return $bound < $this->volumes[$id] ? $bound : $this->volumes[$id];
    }

    /**
     * Filling by value only: would filling $partial and greedily packing what is left be better than $single?
     */
    private function splittingIsBetter(PackedBox $single, PackedBox $partial, ItemList $items): bool
    {
        $rest = clone $items;
        $rest->removePackedItems($partial->items);

        $available = $this->available;
        --$this->available[spl_object_id($partial->box)];
        $completion = $this->construct($rest, false, false);
        $this->available = $available;

        if ($rest->count() > 0) {
            return false;
        }

        $split = [$partial, ...$completion];

        return $this->isImprovement(count($split) - 1, $this->totalCost($split) - $this->cost($single), $this->cost($single));
    }

    /**
     * Local search, accepting only strict improvements. When minimising the number of boxes, moves that can only
     * reduce the number of boxes are skipped once it reaches the lower bound.
     *
     * @param list<PackedBox> $solution
     */
    private function improve(array &$solution): void
    {
        if ($solution === []) {
            return;
        }

        $this->improving = true;
        $this->improvementPackings = 0;
        $this->maxImprovementPackings = $this->volumePackerFactory->hasTimeBudget()
            ? PHP_INT_MAX
            : max(self::MIN_IMPROVEMENT_PACKINGS, self::IMPROVEMENT_PACKINGS_PER_BOX * count($solution));
        $remaining = $this->volumePackerFactory->getRemainingTime();
        $this->volumePackerFactory->setCallTimeLimit($remaining === null ? null : $remaining / 10);
        $searchBudget = $this->volumePackerFactory->getSearchBudget();
        $this->improvementPlacements = 0;
        $this->maxImprovementPlacements = $searchBudget === null ? PHP_INT_MAX : self::IMPROVEMENT_BUDGET_FACTOR * $searchBudget;
        $this->volumePackerFactory->setCallBudget($searchBudget === null ? null : intdiv($searchBudget, self::IMPROVEMENT_BUDGET_DIVISOR));

        $lowerBound = $this->lowerBound($solution);
        do {
            $improved = ($this->minimiseCostFirst || count($solution) > $lowerBound)
                && ($this->mergePairs($solution) || $this->emptyABox($solution));
            $improved = $improved || $this->downsizeBoxes($solution) || $this->repackPairs($solution);
        } while ($improved && $this->canContinue());

        $this->improving = false;
        $this->volumePackerFactory->setCallBudget(null);
    }

    /**
     * Try to put the contents of two boxes into a single box, the pairs with the least between them first.
     *
     * @param list<PackedBox> $solution
     */
    private function mergePairs(array &$solution): bool
    {
        $pairs = [];
        $count = count($solution);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $volume = $solution[$i]->getUsedVolume() + $solution[$j]->getUsedVolume();
                $weight = $solution[$i]->getItemWeight() + $solution[$j]->getItemWeight();
                if ($volume <= $this->maxVolume && $weight <= $this->maxCapacity) {
                    $pairs[] = [$volume, $i, $j];
                }
            }
        }
        sort($pairs);

        foreach ($pairs as [, $i, $j]) {
            if (!$this->canContinue()) {
                return false;
            }
            $items = [...$solution[$i]->items->asItemArray(), ...$solution[$j]->items->asItemArray()];
            $oldCost = $this->cost($solution[$i]) + $this->cost($solution[$j]);
            $merged = $this->packIntoBestBox($items, [$solution[$i]->box, $solution[$j]->box], null, -1, $oldCost);
            if ($merged !== null) {
                $this->logger->log(LogLevel::DEBUG, "Merged 2 boxes into a {$merged->box->getReference()}");
                $this->replace($solution, [$i, $j], [$merged]);

                return true;
            }
        }

        return false;
    }

    /**
     * Try to empty a box by moving its items into the other boxes. The least-filled boxes are tried first, but any
     * box may turn out to be the one whose contents fit into the gaps elsewhere.
     *
     * @param list<PackedBox> $solution
     */
    private function emptyABox(array &$solution): bool
    {
        if (count($solution) < 2) {
            return false;
        }

        foreach ($this->leastFilledFirst($solution) as $victim) {
            if (!$this->canContinue()) {
                return false;
            }
            if ($this->tryToEmpty($solution, $victim)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move the victim's items into the other boxes one unit at a time (largest first), each into the box with the
     * least room that can take it. Accepted only if every unit finds a home and the change improves the objective.
     *
     * @param list<PackedBox> $solution
     */
    private function tryToEmpty(array &$solution, int $victim): bool
    {
        $receivers = $solution;
        unset($receivers[$victim]);
        $units = self::movableUnits($solution[$victim]->items->asItemArray());

        if (!$this->unitsMightFit($units, $receivers)) {
            return false;
        }

        foreach ($units as $unit) {
            if (!$this->canContinue() || !$this->moveUnit($unit, $receivers)) {
                return false;
            }
        }

        $oldCost = $this->totalCost($solution);
        $newCost = $this->totalCost($receivers);
        if (!$this->isImprovement(-1, $newCost - $oldCost, $oldCost)) {
            return false;
        }

        $this->logger->log(LogLevel::DEBUG, "Emptied a {$solution[$victim]->box->getReference()} into the other boxes");
        ++$this->available[spl_object_id($solution[$victim]->box)];
        $solution = array_values($receivers);

        return true;
    }

    /**
     * Whether the units could all be moved into the receivers if only volume and weight mattered, assigning each (the
     * largest first) to the box with the least room that can take it, as the real move does.
     *
     * @param list<array{0: int, 1: int, 2: list<Item>}> $units
     * @param array<int, PackedBox>                      $receivers
     */
    private function unitsMightFit(array $units, array $receivers): bool
    {
        $room = [];
        foreach ($receivers as $receiver) {
            $room[] = [$this->volumes[spl_object_id($receiver->box)] - $receiver->getUsedVolume(), $receiver->getRemainingWeight()];
        }

        foreach ($units as [$unitVolume, $unitWeight]) {
            $bestFit = null;
            foreach ($room as $r => [$freeVolume, $freeWeight]) {
                if ($freeVolume >= $unitVolume && $freeWeight >= $unitWeight && ($bestFit === null || $freeVolume < $room[$bestFit][0])) {
                    $bestFit = $r;
                }
            }
            if ($bestFit === null) {
                return false;
            }
            $room[$bestFit][0] -= $unitVolume;
            $room[$bestFit][1] -= $unitWeight;
        }

        return true;
    }

    /**
     * Put the unit into the box with the least room that can take it, if any.
     *
     * @param array{0: int, 1: int, 2: list<Item>} $unit
     * @param array<int, PackedBox>                $receivers
     */
    private function moveUnit(array $unit, array &$receivers): bool
    {
        [$unitVolume, $unitWeight, $unitItems] = $unit;
        $candidates = [];
        foreach ($receivers as $r => $receiver) {
            $freeVolume = $this->volumes[spl_object_id($receiver->box)] - $receiver->getUsedVolume();
            if ($freeVolume >= $unitVolume && $receiver->getRemainingWeight() >= $unitWeight) {
                $candidates[] = [$freeVolume, $r];
            }
        }
        sort($candidates);

        foreach ($candidates as [, $r]) {
            if (!$this->canContinue()) {
                return false;
            }
            $items = [...$receivers[$r]->items->asItemArray(), ...$unitItems];
            $packedBox = $this->packItems($receivers[$r]->box, $items);
            if ($packedBox->items->count() === count($items)) {
                $receivers[$r] = $packedBox;

                return true;
            }
        }

        return false;
    }

    /**
     * Move the contents of each box into the cheapest box type that holds them.
     *
     * @param list<PackedBox> $solution
     */
    private function downsizeBoxes(array &$solution): bool
    {
        $improved = false;
        foreach ($solution as $index => $packedBox) {
            if (!$this->canContinue()) {
                break;
            }
            $smaller = $this->packIntoBestBox($packedBox->items->asItemArray(), [$packedBox->box], $packedBox->box, 0, $this->cost($packedBox));
            if ($smaller !== null) {
                $this->logger->log(LogLevel::DEBUG, "Moved the contents of a {$packedBox->box->getReference()} into a {$smaller->box->getReference()}");
                $this->replace($solution, [$index], [$smaller]);
                $improved = true;
            }
        }

        return $improved;
    }

    /**
     * Try to repack the contents of two boxes into two that cost less in total: fill one box type as fully as
     * possible, then put the rest into the cheapest single box that holds it. The most expensive pairs are tried
     * first.
     *
     * @param list<PackedBox> $solution
     */
    private function repackPairs(array &$solution): bool
    {
        if (count($this->boxTypes) < 2 || count($solution) < 2) {
            return false;
        }

        $cheapest = $this->emptyCosts[spl_object_id($this->boxTypes[0])];
        $pairs = [];
        $count = count($solution);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $pairs[] = [-($this->cost($solution[$i]) + $this->cost($solution[$j])), $i, $j];
            }
        }
        sort($pairs);

        foreach ($pairs as [$negativeCost, $i, $j]) {
            $oldCost = -$negativeCost;
            $pair = [$solution[$i], $solution[$j]];
            $items = [...$pair[0]->items->asItemArray(), ...$pair[1]->items->asItemArray()];
            foreach ($this->boxTypes as $box) {
                if (!$this->canContinue()) {
                    return false;
                }
                $id = spl_object_id($box);
                if (!$this->isImprovement(0, $this->emptyCosts[$id] + $cheapest - $oldCost, $oldCost)) {
                    break; // types are in cost order, so no later type can do better
                }

                $available = $this->available;
                ++$this->available[spl_object_id($pair[0]->box)];
                ++$this->available[spl_object_id($pair[1]->box)];
                $replacement = null;
                if ($this->available[$id] > 0) {
                    $first = $this->packItems($box, $items);
                    if ($first->items->count() > 0 && $first->items->count() < count($items)) {
                        --$this->available[$id];
                        $second = $this->findSingleBox(self::without($items, $first));
                        if ($second !== null && $this->isImprovement(0, $this->cost($first) + $this->cost($second) - $oldCost, $oldCost)) {
                            $replacement = [$first, $second];
                        }
                    }
                }
                $this->available = $available;

                if ($replacement !== null) {
                    $this->logger->log(LogLevel::DEBUG, "Repacked a {$pair[0]->box->getReference()} and a {$pair[1]->box->getReference()} into a {$replacement[0]->box->getReference()} and a {$replacement[1]->box->getReference()}");
                    $this->replace($solution, [$i, $j], $replacement);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Pack all the items into the cheapest box type for which the change would be an improvement, if any.
     *
     * @param list<Item> $items
     * @param list<Box>  $freed    boxes that become available if the change is made
     * @param ?Box       $skip     box type not to try
     * @param int        $boxDelta change in the number of boxes if the change is made
     * @param float      $oldCost  cost of the boxes being replaced
     */
    private function packIntoBestBox(array $items, array $freed, ?Box $skip, int $boxDelta, float $oldCost): ?PackedBox
    {
        [$volume, $weight] = self::measure($items);
        foreach ($this->boxTypes as $box) {
            $id = spl_object_id($box);
            if (!$this->isImprovement($boxDelta, $this->emptyCosts[$id] - $oldCost, $oldCost)) {
                break; // types are in cost order, so no later type can do better
            }
            if ($box === $skip || $this->volumes[$id] < $volume || $this->capacities[$id] < $weight) {
                continue;
            }
            $available = $this->available[$id];
            foreach ($freed as $freedBox) {
                $available += $freedBox === $box ? 1 : 0;
            }
            if ($available <= 0) {
                continue;
            }

            $packedBox = $this->packItems($box, $items);
            if ($packedBox->items->count() === count($items) && $this->isImprovement($boxDelta, $this->cost($packedBox) - $oldCost, $oldCost)) {
                return $packedBox;
            }
        }

        return null;
    }

    /**
     * Replace some boxes of the solution with others, keeping track of the box types in use. Replacements take the
     * places of the boxes they replace, so that when there are as many of them, no other box moves.
     *
     * @param list<PackedBox> $solution
     * @param list<int>       $indexes
     * @param list<PackedBox> $replacements
     */
    private function replace(array &$solution, array $indexes, array $replacements): void
    {
        $next = count($solution);
        foreach ($indexes as $index) {
            ++$this->available[spl_object_id($solution[$index]->box)];
            unset($solution[$index]);
        }
        foreach ($replacements as $i => $replacement) {
            --$this->available[spl_object_id($replacement->box)];
            $solution[$indexes[$i] ?? $next++] = $replacement;
        }
        ksort($solution);
        $solution = array_values($solution);
    }

    /**
     * Pack the items into the box, keeping linked item groups together. Results are cached.
     *
     * @param list<Item> $items
     */
    private function packItems(Box $box, array $items): PackedBox
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = spl_object_id($item);
        }
        sort($ids);
        $key = spl_object_id($box) . '|' . implode(',', $ids);
        if (isset($this->packCache[$key])) {
            return $this->packCache[$key];
        }

        $this->timeoutChecker?->throwOnTimeout();
        if ($this->improving) {
            ++$this->improvementPackings;
        }

        $itemList = ItemList::fromArray($items);
        $volumePacker = $this->volumePackerFactory->create($box, $itemList);
        $packedBox = $volumePacker->pack();
        if ($this->improving) {
            $this->improvementPlacements += $volumePacker->getSearchPlacements();
        }
        if ($itemList->hasLinkedItems()) {
            $linkedItemGroupEnforcer = new LinkedItemGroupEnforcer();
            $linkedItemGroupEnforcer->setLogger($this->logger);
            $linkedItemGroupEnforcer->setVolumePackerFactory($this->volumePackerFactory);
            $packedBox = $linkedItemGroupEnforcer->enforceConstraint($packedBox, $itemList);
        }

        return $this->packCache[$key] = $packedBox;
    }

    /**
     * A lower bound on the number of boxes needed for the packed items: by volume, by weight, and by the number of
     * items too big to share a box with each other.
     *
     * @param list<PackedBox> $solution
     */
    private function lowerBound(array $solution): int
    {
        $volume = $weight = $bigItems = 0;
        foreach ($solution as $packedBox) {
            foreach ($packedBox->items as $packedItem) {
                $volume += $packedItem->volume;
                $weight += $packedItem->weight;
                if (2 * $packedItem->volume > $this->maxVolume) {
                    ++$bigItems;
                }
            }
        }

        $byVolume = (int) ceil($volume / $this->maxVolume);
        $byWeight = $this->maxCapacity > 0 ? (int) ceil($weight / $this->maxCapacity) : 0;

        return max($byVolume, $byWeight, $bigItems, 1);
    }

    /**
     * Box indexes, the box least worth keeping first: least volume packed, or least volume per unit of cost when
     * minimising cost.
     *
     * @param  list<PackedBox> $solution
     * @return list<int>
     */
    private function leastFilledFirst(array $solution): array
    {
        $order = [];
        foreach ($solution as $index => $packedBox) {
            $value = $packedBox->getUsedVolume();
            if ($this->minimiseCostFirst) {
                $value /= max($this->cost($packedBox), 1e-9);
            }
            $order[] = [$value, $index];
        }
        sort($order);

        $indexes = [];
        foreach ($order as [, $index]) {
            $indexes[] = $index;
        }

        return $indexes;
    }

    /**
     * Items as units that can be moved independently (a linked item group moves as one), largest first.
     *
     * @param  list<Item>                                 $items
     * @return list<array{0: int, 1: int, 2: list<Item>}> [volume, weight, items]
     */
    private static function movableUnits(array $items): array
    {
        $units = [];
        $groups = [];
        foreach ($items as $item) {
            if ($item instanceof LinkedItem) {
                $groups[$item->getLinkedItemGroup()][] = $item;
            } else {
                $units[] = [...self::measure([$item]), [$item]];
            }
        }
        foreach ($groups as $groupItems) {
            $units[] = [...self::measure($groupItems), $groupItems];
        }
        usort($units, static fn (array $a, array $b) => ($b[0] <=> $a[0]) ?: ($b[1] <=> $a[1]));

        return $units;
    }

    /**
     * The items not packed into a box.
     *
     * @param  list<Item> $items
     * @return list<Item>
     */
    private static function without(array $items, PackedBox $packedBox): array
    {
        $packedCounts = [];
        foreach ($packedBox->items as $packedItem) {
            $id = spl_object_id($packedItem->item);
            $packedCounts[$id] = ($packedCounts[$id] ?? 0) + 1;
        }

        $rest = [];
        foreach ($items as $item) {
            $id = spl_object_id($item);
            if (($packedCounts[$id] ?? 0) > 0) {
                --$packedCounts[$id];
            } else {
                $rest[] = $item;
            }
        }

        return $rest;
    }

    /**
     * @param  list<Item>            $items
     * @return array{0: int, 1: int} [volume, weight]
     */
    private static function measure(array $items): array
    {
        $volume = $weight = 0;
        foreach ($items as $item) {
            $volume += $item->getWidth() * $item->getLength() * $item->getDepth();
            $weight += $item->getWeight();
        }

        return [$volume, $weight];
    }

    private function cost(PackedBox $packedBox): float
    {
        return $this->costs[$packedBox] ??= $this->costCalculator->getCost($packedBox);
    }

    /**
     * @param list<PackedBox> $packedBoxes
     */
    private function totalCost(array $packedBoxes): float
    {
        $cost = 0.0;
        foreach ($packedBoxes as $packedBox) {
            $cost += $this->cost($packedBox);
        }

        return $cost;
    }

    /**
     * Whether a change in the number of boxes and total cost improves the objective. Cost differences within floating
     * point noise count as no change.
     */
    private function isImprovement(int $boxDelta, float $costDelta, float $referenceCost): bool
    {
        $epsilon = 1e-9 * max(1.0, abs($referenceCost));
        $costDecider = $costDelta < -$epsilon ? -1 : ($costDelta > $epsilon ? 1 : 0);
        $boxDecider = $boxDelta <=> 0;

        if ($this->minimiseCostFirst) {
            return $costDecider < 0 || ($costDecider === 0 && $boxDecider < 0);
        }

        return $boxDecider < 0 || ($boxDecider === 0 && $costDecider < 0);
    }

    private function canContinue(): bool
    {
        return $this->improvementPackings < $this->maxImprovementPackings
            && $this->improvementPlacements < $this->maxImprovementPlacements
            && !$this->volumePackerFactory->isOutOfTime();
    }

    /**
     * Share out the search effort for the next box of the construction: while several more boxes are needed, each gets
     * a proportional share of the search budget, and with a time budget, a share of the time left (keeping half back
     * for the improvement phase).
     */
    private function budgetConstruction(ItemList $items): void
    {
        $boxesStillNeeded = $this->maxVolume > 0 ? max(1, (int) ceil($items->getVolume() / $this->maxVolume)) : 1;

        $searchBudget = $this->volumePackerFactory->getSearchBudget();
        if ($searchBudget !== null) {
            // while several more boxes are needed, each one's packing matters less: share the budget out
            $this->volumePackerFactory->setCallBudget(max(intdiv($searchBudget, self::IMPROVEMENT_BUDGET_DIVISOR), intdiv($searchBudget, $boxesStillNeeded)));
        }

        $remaining = $this->volumePackerFactory->getRemainingTime();
        if ($remaining === null) {
            return;
        }

        $searchesStillNeeded = max(1, count($this->boxTypes) * $boxesStillNeeded);
        $this->volumePackerFactory->setCallTimeLimit($remaining / 2 / $searchesStillNeeded);
    }

    /**
     * @param array<int, int> $availableBefore
     */
    private function anyBoxTypeFreed(array $availableBefore): bool
    {
        foreach ($availableBefore as $id => $quantity) {
            if ($quantity <= 0 && $this->available[$id] > 0) {
                return true;
            }
        }

        return false;
    }
}
