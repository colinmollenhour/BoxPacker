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

use function array_pop;
use function count;
use function max;
use function usort;

use const PHP_INT_MAX;

/**
 * Actual packer.
 */
class Packer implements LoggerAwareInterface
{
    private LoggerInterface $logger;

    protected int $maxBoxesToBalanceWeight = 12;

    protected ItemList $items;

    protected BoxList $boxes;

    /**
     * @var WeakMap<Box, int>
     */
    protected WeakMap $boxQuantitiesAvailable;

    protected PackedBoxSorter $packedBoxSorter;

    protected bool $throwOnUnpackableItem = true;

    private bool $beStrictAboutItemOrdering = false;

    protected ?TimeoutChecker $timeoutChecker = null;

    protected PackingStrategy $strategy = PackingStrategy::Fast;

    protected int $maxBeamWidth = 8;

    protected ?float $searchTimeLimit = null;

    protected float $minimumSupport = 1.0;

    protected ?PackedBoxCostCalculator $costCalculator = null;

    public function __construct(
        ItemList $items = new ItemList(),
        BoxList $boxes = new BoxList(),
        PackedBoxSorter $packedBoxSorter = new DefaultPackedBoxSorter(),
        LoggerInterface $logger = new NullLogger(),
    ) {
        $this->items = $items;
        $this->boxes = $boxes;
        $this->packedBoxSorter = $packedBoxSorter;
        $this->boxQuantitiesAvailable = new WeakMap();

        $this->logger = $logger;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Add item to be packed.
     */
    public function addItem(Item $item, int $qty = 1): void
    {
        $this->items->insert($item, $qty);
        $this->logger->log(LogLevel::INFO, "added {$qty} x {$item->getDescription()}", ['item' => $item]);
    }

    /**
     * Set a list of items all at once.
     * @param iterable<Item>|ItemList $items
     */
    public function setItems(iterable|ItemList $items): void
    {
        if ($items instanceof ItemList) {
            $this->items = clone $items;
        } else {
            $this->items = new ItemList();
            foreach ($items as $item) {
                $this->items->insert($item);
            }
        }
    }

    /**
     * Add box size.
     */
    public function addBox(Box $box): void
    {
        $this->boxes->insert($box);
        $this->setBoxQuantity($box, $box instanceof LimitedSupplyBox ? $box->getQuantityAvailable() : PHP_INT_MAX);
        $this->logger->log(LogLevel::INFO, "added box {$box->getReference()}", ['box' => $box]);
    }

    /**
     * Add a pre-prepared set of boxes all at once.
     */
    public function setBoxes(BoxList $boxList): void
    {
        $this->boxes = $boxList;
        foreach ($this->boxes as $box) {
            $this->setBoxQuantity($box, $box instanceof LimitedSupplyBox ? $box->getQuantityAvailable() : PHP_INT_MAX);
        }
    }

    /**
     * Set the quantity of this box type available.
     */
    public function setBoxQuantity(Box $box, int $qty): void
    {
        $this->boxQuantitiesAvailable[$box] = $qty;
    }

    /**
     * Number of boxes at which balancing weight is deemed not worth the extra computation time.
     */
    public function getMaxBoxesToBalanceWeight(): int
    {
        return $this->maxBoxesToBalanceWeight;
    }

    /**
     * Number of boxes at which balancing weight is deemed not worth the extra computation time.
     */
    public function setMaxBoxesToBalanceWeight(int $maxBoxesToBalanceWeight): void
    {
        $this->maxBoxesToBalanceWeight = $maxBoxesToBalanceWeight;
    }

    public function setPackedBoxSorter(PackedBoxSorter $packedBoxSorter): void
    {
        $this->packedBoxSorter = $packedBoxSorter;
    }

    public function setTimeoutChecker(TimeoutChecker $timeoutChecker): void
    {
        $this->timeoutChecker = $timeoutChecker;
    }

    public function throwOnUnpackableItem(bool $throwOnUnpackableItem): void
    {
        $this->throwOnUnpackableItem = $throwOnUnpackableItem;
    }

    public function beStrictAboutItemOrdering(bool $beStrict): void
    {
        $this->beStrictAboutItemOrdering = $beStrict;
    }

    /**
     * Fast (original algorithms, the default) or Thorough (denser packing of each box, then a search for fewer and
     * cheaper boxes). Thorough is not used when being strict about item ordering.
     */
    public function setStrategy(PackingStrategy $strategy): void
    {
        $this->strategy = $strategy;
    }

    /**
     * Thorough strategy only: how widely to search when packing each box, see {@see VolumePacker::setMaxBeamWidth()}.
     */
    public function setMaxBeamWidth(int $maxBeamWidth): void
    {
        $this->maxBeamWidth = max(1, $maxBeamWidth);
    }

    /**
     * Thorough strategy only: optional wall-clock limit in seconds for the whole of pack(), shared between packing
     * the boxes and searching for a better set of boxes. The limit is approximate: the boxes are always completed, with
     * little search once the time is up. Note that results then depend on machine speed.
     */
    public function setSearchTimeLimit(?float $seconds): void
    {
        $this->searchTimeLimit = $seconds;
    }

    /**
     * Thorough strategy only: the minimum fraction (0-1) of each item's base that must rest on the box floor or on
     * other items, see {@see VolumePacker::setMinimumSupport()}.
     */
    public function setMinimumSupport(float $fraction): void
    {
        $this->minimumSupport = $fraction;
    }

    /**
     * Thorough strategy only: how much a packed box costs. By default, the Thorough strategy minimises the number of
     * boxes and then their total inner volume. When a cost calculator is set, it instead minimises the total cost of
     * the boxes, and then their number.
     */
    public function setCostCalculator(PackedBoxCostCalculator $costCalculator): void
    {
        $this->costCalculator = $costCalculator;
    }

    /**
     * Return the items that haven't been packed.
     */
    public function getUnpackedItems(): ItemList
    {
        return $this->items;
    }

    /**
     * Pack items into boxes using built-in heuristics for the best solution.
     */
    public function pack(): PackedBoxList
    {
        $this->logger->log(LogLevel::INFO, '[PACKING STARTED]');
        $this->timeoutChecker?->start();
        $volumePackerFactory = $this->createVolumePackerFactory();

        if ($this->strategy === PackingStrategy::Thorough && !$this->beStrictAboutItemOrdering) {
            return $this->doThoroughPacking($volumePackerFactory);
        }

        $packedBoxes = $this->doBasicPacking(false, $volumePackerFactory);

        // If we have multiple boxes, try and optimise/even-out weight distribution
        if (!$this->beStrictAboutItemOrdering && $packedBoxes->count() > 1 && $packedBoxes->count() <= $this->maxBoxesToBalanceWeight) {
            $redistributor = new WeightRedistributor($this->boxes, $this->packedBoxSorter, $this->boxQuantitiesAvailable, $this->timeoutChecker, $volumePackerFactory);
            $redistributor->setLogger($this->logger);
            $packedBoxes = $redistributor->redistributeWeight($packedBoxes);
        }

        $this->logger->log(LogLevel::INFO, "[PACKING COMPLETED], {$packedBoxes->count()} boxes");

        return $packedBoxes;
    }

    /**
     * Thorough strategy: pack, search for fewer/cheaper boxes, then balance weight as far as that costs nothing.
     *
     * Weight redistribution moves items between boxes and may change their types, so its result is kept only if it is
     * no worse under the Thorough objective (it cannot pack more items, but it can use more or costlier boxes).
     */
    private function doThoroughPacking(VolumePackerFactory $volumePackerFactory): PackedBoxList
    {
        $thoroughPacker = new ThoroughPacker(
            $this->boxes,
            $this->boxQuantitiesAvailable,
            $volumePackerFactory,
            $this->costCalculator ?? new DefaultPackedBoxCostCalculator(),
            $this->costCalculator !== null,
            $this->timeoutChecker
        );
        $thoroughPacker->setLogger($this->logger);

        $packedBoxes = new PackedBoxList($this->packedBoxSorter);
        $packedBoxes->insertFromArray($thoroughPacker->pack($this->items, $this->throwOnUnpackableItem));

        if ($packedBoxes->count() > 1 && $packedBoxes->count() <= $this->maxBoxesToBalanceWeight) {
            $boxQuantitiesAvailable = clone $this->boxQuantitiesAvailable;
            $redistributor = new WeightRedistributor($this->boxes, $this->packedBoxSorter, $boxQuantitiesAvailable, $this->timeoutChecker, $volumePackerFactory);
            $redistributor->setLogger($this->logger);
            $redistributed = $redistributor->redistributeWeight($packedBoxes);
            if ($thoroughPacker->compareSolutions($redistributed, $packedBoxes) <= 0) {
                $packedBoxes = $redistributed;
                $this->boxQuantitiesAvailable = $boxQuantitiesAvailable;
            }
        }

        $this->logger->log(LogLevel::INFO, "[PACKING COMPLETED], {$packedBoxes->count()} boxes");

        return $packedBoxes;
    }

    /**
     * @internal
     */
    public function doBasicPacking(bool $enforceSingleBox = false, ?VolumePackerFactory $volumePackerFactory = null): PackedBoxList
    {
        $volumePackerFactory ??= $this->createVolumePackerFactory();
        $packedBoxes = new PackedBoxList($this->packedBoxSorter);

        // Keep going until everything packed
        while ($this->items->count()) {
            $packedBoxesIteration = [];

            // Loop through boxes starting with smallest, see what happens
            foreach ($this->getBoxList($enforceSingleBox) as $box) {
                $this->timeoutChecker?->throwOnTimeout();
                $packedBox = $volumePackerFactory->create($box, $this->items)->pack();
                $linkedItemGroupEnforcer = new LinkedItemGroupEnforcer();
                $linkedItemGroupEnforcer->setLogger($this->logger);
                $linkedItemGroupEnforcer->beStrictAboutItemOrdering($this->beStrictAboutItemOrdering);
                $linkedItemGroupEnforcer->setVolumePackerFactory($volumePackerFactory);
                $packedBox = $linkedItemGroupEnforcer->enforceConstraint($packedBox, $this->items);
                if ($packedBox->items->count()) {
                    $packedBoxesIteration[] = $packedBox;

                    // Have we found a single box that contains everything?
                    if ($packedBox->items->count() === $this->items->count()) {
                        $this->logger->log(LogLevel::DEBUG, "Single box found for remaining {$this->items->count()} items");
                        break;
                    }
                }
            }

            if (count($packedBoxesIteration) > 0) {
                // Find best box of iteration, and remove packed items from unpacked list
                usort($packedBoxesIteration, $this->packedBoxSorter->compare(...));
                $bestBox = $packedBoxesIteration[0];

                $this->items->removePackedItems($bestBox->items);

                $packedBoxes->insert($bestBox);
                --$this->boxQuantitiesAvailable[$bestBox->box];
            } elseif ($this->throwOnUnpackableItem) {
                throw new NoBoxesAvailableException("No boxes could be found for item '{$this->items->top()->getDescription()}'", $this->items);
            } else {
                $this->logger->log(LogLevel::INFO, "{$this->items->count()} unpackable items found");
                break;
            }
        }

        return $packedBoxes;
    }

    /**
     * Pack items into boxes returning "all" possible box combination permutations.
     * Use with caution (will be slow) with a large number of box types!
     *
     * @return PackedBoxList[]
     */
    public function packAllPermutations(): array
    {
        $this->logger->log(LogLevel::INFO, '[PACKING STARTED (all permutations)]');
        $this->timeoutChecker?->start();
        $volumePackerFactory = $this->createVolumePackerFactory();

        $boxQuantitiesAvailable = clone $this->boxQuantitiesAvailable;

        $wipPermutations = [['permutation' => new PackedBoxList($this->packedBoxSorter), 'itemsLeft' => $this->items]];
        $completedPermutations = [];

        // Keep going until everything packed
        while ($wipPermutations) {
            $wipPermutation = array_pop($wipPermutations);
            $remainingBoxQuantities = clone $boxQuantitiesAvailable;
            foreach ($wipPermutation['permutation'] as $packedBox) {
                --$remainingBoxQuantities[$packedBox->box];
            }
            if ($wipPermutation['itemsLeft']->count() === 0) {
                $completedPermutations[] = $wipPermutation['permutation'];
                continue;
            }

            $additionalPermutationsForThisPermutation = [];
            foreach ($this->boxes as $box) {
                $this->timeoutChecker?->throwOnTimeout();
                if ($remainingBoxQuantities[$box] > 0) {
                    $packedBox = $volumePackerFactory->create($box, $wipPermutation['itemsLeft'])->pack();
                    $linkedGroupConstraint = new LinkedItemGroupEnforcer();
                    $linkedGroupConstraint->setLogger($this->logger);
                    $linkedGroupConstraint->beStrictAboutItemOrdering($this->beStrictAboutItemOrdering);
                    $linkedGroupConstraint->setVolumePackerFactory($volumePackerFactory);
                    $packedBox = $linkedGroupConstraint->enforceConstraint($packedBox, $wipPermutation['itemsLeft']);
                    if ($packedBox->items->count()) {
                        $additionalPermutationsForThisPermutation[] = $packedBox;
                    }
                }
            }

            if (count($additionalPermutationsForThisPermutation) > 0) {
                foreach ($additionalPermutationsForThisPermutation as $additionalPermutationForThisPermutation) {
                    $newPermutation = clone $wipPermutation['permutation'];
                    $newPermutation->insert($additionalPermutationForThisPermutation);
                    $itemsRemainingOnPermutation = clone $wipPermutation['itemsLeft'];
                    $itemsRemainingOnPermutation->removePackedItems($additionalPermutationForThisPermutation->items);
                    $wipPermutations[] = ['permutation' => $newPermutation, 'itemsLeft' => $itemsRemainingOnPermutation];
                }
            } elseif ($this->throwOnUnpackableItem) {
                throw new NoBoxesAvailableException("No boxes could be found for item '{$wipPermutation['itemsLeft']->top()->getDescription()}'", $wipPermutation['itemsLeft']);
            } else {
                $this->logger->log(LogLevel::INFO, "{$this->items->count()} unpackable items found");
                if ($wipPermutation['permutation']->count() > 0) { // don't treat initial empty permutation as completed
                    $completedPermutations[] = $wipPermutation['permutation'];
                }
            }
        }

        $this->logger->log(LogLevel::INFO, '[PACKING COMPLETED], ' . count($completedPermutations) . ' permutations');

        foreach ($completedPermutations as $completedPermutation) {
            foreach ($completedPermutation as $packedBox) {
                $this->items->removePackedItems($packedBox->items);
            }
        }

        return $completedPermutations;
    }

    /**
     * VolumePackers for one packing run, using this packer's settings; any time limit starts now.
     */
    private function createVolumePackerFactory(): VolumePackerFactory
    {
        return new VolumePackerFactory(
            $this->strategy,
            $this->maxBeamWidth,
            $this->minimumSupport,
            $this->strategy === PackingStrategy::Thorough ? $this->searchTimeLimit : null,
            $this->logger,
            $this->beStrictAboutItemOrdering
        );
    }

    /**
     * Get a "smart" ordering of the boxes to try packing items into. The initial BoxList is already sorted in order
     * so that the smallest boxes are evaluated first, but this means that time is spent on boxes that cannot possibly
     * hold the entire set of items due to volume limitations. These should be evaluated first.
     *
     * @return iterable<Box>
     */
    protected function getBoxList(bool $enforceSingleBox = false): iterable
    {
        $this->logger->log(LogLevel::INFO, 'Determining box search pattern', ['enforceSingleBox' => $enforceSingleBox]);
        $itemVolume = 0;
        foreach ($this->items as $item) {
            $itemVolume += $item->getWidth() * $item->getLength() * $item->getDepth();
        }
        $this->logger->log(LogLevel::DEBUG, 'Item volume', ['itemVolume' => $itemVolume]);

        $preferredBoxes = [];
        $otherBoxes = [];
        foreach ($this->boxes as $box) {
            if ($this->boxQuantitiesAvailable[$box] > 0) {
                if ($box->getInnerWidth() * $box->getInnerLength() * $box->getInnerDepth() >= $itemVolume) {
                    $preferredBoxes[] = $box;
                } elseif (!$enforceSingleBox) {
                    $otherBoxes[] = $box;
                }
            }
        }

        $this->logger->log(LogLevel::INFO, 'Box search pattern complete', ['preferredBoxCount' => count($preferredBoxes), 'otherBoxCount' => count($otherBoxes)]);

        return [...$preferredBoxes, ...$otherBoxes];
    }
}
