<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_keys;
use function array_slice;
use function array_values;
use function atan;
use function count;
use function hrtime;
use function implode;
use function intdiv;
use function ksort;
use function max;
use function min;
use function sort;
use function spl_object_id;
use function usort;
use function assert;

use const PHP_INT_MAX;

/**
 * Single-container packer that builds the load out of blocks of identical items.
 *
 * Free space is tracked as a set of (overlapping) maximal empty cuboids. At each step the space nearest to a
 * corner of the container is filled with the best block of identical items that fits it, the block being placed
 * at that corner. A beam search explores alternative block choices, scoring each partial packing by greedily
 * completing it; the beam is widened step by step (1, 2, 4, 8...) so that a result is always available and more
 * effort gives a better answer. Search effort is bounded by a maximum beam width (deterministic) and optionally
 * by a wall-clock time limit.
 *
 * Every block must be supported from below: by the container floor or the top faces of already packed items
 * covering at least the configured fraction of its base.
 *
 * @internal
 */
class BlockPacker implements LoggerAwareInterface
{
    // Candidate block layout (packed list for speed)
    public const C_SCORE = 0;
    public const C_VOLUME = 1;
    public const C_W = 2;
    public const C_L = 3;
    public const C_H = 4;
    public const C_TYPE = 5;
    public const C_OW = 6;
    public const C_OL = 7;
    public const C_OH = 8;
    public const C_NX = 9;
    public const C_NY = 10;
    public const C_NZ = 11;
    public const C_X = 12;
    public const C_Y = 13;
    public const C_Z = 14;

    /**
     * Minimum angle (radians) between the base and the diagonal for an orientation to count as stable,
     * same rule as {@see OrientatedItem::isStable()}.
     */
    private const STABILITY_ANGLE = 0.261;

    private const FILL_ORDERS = [[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]];

    private LoggerInterface $logger;

    private readonly int $boxWidth;

    private readonly int $boxLength;

    private readonly int $boxDepth;

    private readonly int $weightCapacity;

    /**
     * @var array<int, list<array{0: int, 1: int, 2: int}>>
     */
    private array $orientations = [];

    /**
     * @var array<int, int>
     */
    private array $volumes = [];

    /**
     * @var array<int, int>
     */
    private array $weights = [];

    /**
     * @var array<int, list<Item>>
     */
    private array $itemsByType = [];

    /**
     * @var array<int, bool>
     */
    private array $constrained = [];

    /**
     * @var array<int, int>
     */
    private array $initialCounts = [];

    /**
     * Smallest height any orientation of the type can be packed at.
     *
     * @var array<int, int>
     */
    private array $minHeights = [];

    /**
     * Smallest horizontal edge any orientation of the type can be packed with.
     *
     * @var array<int, int>
     */
    private array $minFootprintEdges = [];

    private int $totalItems = 0;

    private int $packableVolume = 0;

    private float $minSupport = 1.0;

    private int $maxBeamWidth = 8;

    private ?float $timeLimit = null;

    private int $deadline = PHP_INT_MAX;

    private bool $outOfTime = false;

    private int $greedyRuns = 0;

    private int $spaceRule = 0;

    /**
     * @internal tuning hook
     */
    public function setSpaceRule(int $rule): void
    {
        $this->spaceRule = $rule;
    }

    /**
     * @param iterable<Item> $items
     */
    public function __construct(private readonly Box $box, iterable $items, private readonly bool $preferStableOrientations = true)
    {
        $this->logger = new NullLogger();
        $this->boxWidth = $box->getInnerWidth();
        $this->boxLength = $box->getInnerLength();
        $this->boxDepth = $box->getInnerDepth();
        $this->weightCapacity = $box->getMaxWeight() - $box->getEmptyWeight();

        $typeIndex = [];
        foreach ($items as $item) {
            $key = $item->getWidth() . '|' . $item->getLength() . '|' . $item->getDepth() . '|' . $item->getWeight() . '|' . $item->getAllowedRotation()->name;
            if ($item instanceof ConstrainedPlacementItem) {
                $key .= '|#' . spl_object_id($item);
            } elseif ($item instanceof LinkedItem) {
                $key .= '|g' . $item->getLinkedItemGroup();
            }

            if (!isset($typeIndex[$key])) {
                $type = count($this->volumes);
                $typeIndex[$key] = $type;
                $this->volumes[$type] = $item->getWidth() * $item->getLength() * $item->getDepth();
                $this->weights[$type] = $item->getWeight();
                $this->orientations[$type] = $this->buildOrientations($item);
                $this->constrained[$type] = $item instanceof ConstrainedPlacementItem;
                $this->itemsByType[$type] = [];
                $this->initialCounts[$type] = 0;
            }
            $type = $typeIndex[$key];
            $this->itemsByType[$type][] = $item;
            ++$this->initialCounts[$type];
            ++$this->totalItems;
        }

        foreach ($this->initialCounts as $type => $count) {
            if ($this->orientations[$type] === [] || $this->weights[$type] > $this->weightCapacity) {
                $this->initialCounts[$type] = 0; // can never go in
                continue;
            }
            $this->packableVolume += $count * $this->volumes[$type];
            $this->minHeights[$type] = $this->minFootprintEdges[$type] = PHP_INT_MAX;
            foreach ($this->orientations[$type] as [$w, $l, $h]) {
                $this->minHeights[$type] = min($this->minHeights[$type], $h);
                $this->minFootprintEdges[$type] = min($this->minFootprintEdges[$type], $w, $l);
            }
        }
    }

    /**
     * Recalculate the size below which a free space cannot hold any remaining item.
     */
    private function updateSpaceFilter(BlockSearchState $state): void
    {
        $state->minHeight = $state->minFootprintEdge = $state->minVolume = PHP_INT_MAX;
        foreach ($state->counts as $type => $count) {
            if ($count > 0) {
                $state->minHeight = min($state->minHeight, $this->minHeights[$type]);
                $state->minFootprintEdge = min($state->minFootprintEdge, $this->minFootprintEdges[$type]);
                $state->minVolume = min($state->minVolume, $this->volumes[$type]);
            }
        }
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Minimum fraction (0-1) of a block's base that must rest on the floor or on other items.
     */
    public function setMinimumSupport(float $minSupport): void
    {
        $this->minSupport = $minSupport;
    }

    /**
     * Search effort: the beam is widened 1, 2, 4... up to this width. 1 = single greedy pass.
     */
    public function setMaxBeamWidth(int $maxBeamWidth): void
    {
        $this->maxBeamWidth = max(1, $maxBeamWidth);
    }

    /**
     * Optional wall-clock limit (seconds) after which the best packing found so far is returned.
     */
    public function setTimeLimit(?float $timeLimit): void
    {
        $this->timeLimit = $timeLimit;
    }

    public function getGreedyRuns(): int
    {
        return $this->greedyRuns;
    }

    public function pack(): PackedBox
    {
        $this->deadline = $this->timeLimit === null ? PHP_INT_MAX : hrtime(true) + (int) ($this->timeLimit * 1e9);
        $this->outOfTime = false;
        $this->greedyRuns = 0;

        $root = new BlockSearchState();
        $root->spaces = [[0, 0, 0, $this->boxWidth, $this->boxLength, $this->boxDepth]];
        $root->counts = $this->initialCounts;
        $root->weightLeft = $this->weightCapacity;
        $root->remaining = $this->totalItems;
        $this->updateSpaceFilter($root);

        $rootCompleted = $this->greedy(clone $root);
        $best = $rootCompleted;

        for ($width = 2; $width <= $this->maxBeamWidth && !$this->isComplete($best) && !$this->outOfTime; $width *= 2) {
            $best = $this->beamSearch($root, $rootCompleted, $width, $best);
        }

        $this->logger->debug('Block search complete', ['greedyRuns' => $this->greedyRuns, 'volume' => $best->volume, 'outOfTime' => $this->outOfTime]);

        return $this->materialise($best);
    }

    private function isComplete(BlockSearchState $state): bool
    {
        return $state->volume >= $this->packableVolume;
    }

    /**
     * One pass of beam search at a fixed width. Each node carries its own greedy completion: because the greedy
     * is deterministic, the child made from the greedy's own first choice completes to the same result, so it
     * need not be re-evaluated. Children reached by placing the same blocks in a different order are merged.
     */
    private function beamSearch(BlockSearchState $root, BlockSearchState $rootCompleted, int $width, BlockSearchState $best): BlockSearchState
    {
        $beam = [[$root, $rootCompleted]];
        while ($beam !== []) {
            $children = [];
            $seen = [];
            foreach ($beam as [$state, $completed]) {
                $candidates = $this->expand($state, $width);
                $greedyChoice = $completed->placements[count($state->placements)] ?? null;
                foreach ($candidates as $candidate) {
                    $child = clone $state;
                    $this->place($child, $candidate);
                    $signature = $this->signature($child);
                    if (isset($seen[$signature])) {
                        continue;
                    }
                    $seen[$signature] = true;

                    if ($candidate === $greedyChoice) {
                        $childCompleted = $completed;
                    } else {
                        $childCompleted = $this->greedy(clone $child);
                        if ($childCompleted->volume > $best->volume) {
                            $best = $childCompleted;
                            if ($this->isComplete($best)) {
                                return $best;
                            }
                        }
                        if (hrtime(true) > $this->deadline) {
                            $this->outOfTime = true;

                            return $best;
                        }
                    }
                    $children[] = [$childCompleted->volume, $child->volume, $child, $childCompleted];
                }
            }

            usort($children, static fn (array $a, array $b) => ($b[0] <=> $a[0]) ?: ($b[1] <=> $a[1]));
            $beam = [];
            foreach (array_slice($children, 0, $width) as $child) {
                $beam[] = [$child[2], $child[3]];
            }
        }

        return $best;
    }

    /**
     * Order-independent identity of the blocks placed in a state.
     */
    private function signature(BlockSearchState $state): string
    {
        $keys = [];
        foreach ($state->placements as $placement) {
            $keys[] = $placement[self::C_TYPE] . ',' . $placement[self::C_OW] . ',' . $placement[self::C_OL] . ',' . $placement[self::C_OH] . ',' . $placement[self::C_NX] . ',' . $placement[self::C_NY] . ',' . $placement[self::C_NZ] . ',' . $placement[self::C_X] . ',' . $placement[self::C_Y] . ',' . $placement[self::C_Z];
        }
        sort($keys);

        return implode(';', $keys);
    }

    /**
     * Pick the next space to fill in this state and return the best $limit blocks for it (dead spaces are
     * dropped from the state along the way).
     *
     * @return list<array<int, int|float>>
     */
    private function expand(BlockSearchState $state, int $limit): array
    {
        while (true) {
            $spaceIndex = $this->selectSpace($state);
            if ($spaceIndex === null) {
                return [];
            }
            $candidates = $this->candidates($state, $state->spaces[$spaceIndex], $limit);
            if ($candidates !== []) {
                return $candidates;
            }
            unset($state->spaces[$spaceIndex]);
        }
    }

    /**
     * Complete a state by repeatedly placing the best block into the most promising space.
     */
    private function greedy(BlockSearchState $state): BlockSearchState
    {
        ++$this->greedyRuns;
        while ($state->remaining > 0) {
            $spaceIndex = $this->selectSpace($state);
            if ($spaceIndex === null) {
                break;
            }
            $candidates = $this->candidates($state, $state->spaces[$spaceIndex], 1);
            if ($candidates === []) {
                unset($state->spaces[$spaceIndex]);
                continue;
            }
            $this->place($state, $candidates[0]);
        }

        return $state;
    }

    /**
     * The space closest to a corner of the container (by the sorted distance vector of its anchor corner), ties
     * broken in favour of the larger space.
     */
    private function selectSpace(BlockSearchState $state): ?int
    {
        $bestIndex = null;
        $bestA = $bestB = $bestC = PHP_INT_MAX;
        $bestVolume = 0;
        $boxWidth = $this->boxWidth;
        $boxLength = $this->boxLength;
        $rule = $this->spaceRule;
        foreach ($state->spaces as $index => $space) {
            $dx = min($space[0], $boxWidth - $space[3]);
            $dy = min($space[1], $boxLength - $space[4]);
            $dz = $space[2];
            if ($dx > $dy) {
                [$dx, $dy] = [$dy, $dx];
            }
            if ($rule === 1) { // bottom-up: height first, then corner distance
                [$dx, $dy, $dz] = [$dz, $dx, $dy];
            } elseif ($dy > $dz) { // sort the three distances ascending
                [$dy, $dz] = [$dz, $dy];
                if ($dx > $dy) {
                    [$dx, $dy] = [$dy, $dx];
                }
            }
            if ($dx < $bestA || ($dx === $bestA && ($dy < $bestB || ($dy === $bestB && ($dz < $bestC || ($dz === $bestC && ($space[3] - $space[0]) * ($space[4] - $space[1]) * ($space[5] - $space[2]) > $bestVolume)))))) {
                $bestIndex = $index;
                $bestA = $dx;
                $bestB = $dy;
                $bestC = $dz;
                $bestVolume = ($space[3] - $space[0]) * ($space[4] - $space[1]) * ($space[5] - $space[2]);
            }
        }

        return $bestIndex;
    }

    /**
     * Blocks that can go into the anchor corner of this space, best first.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} $space
     * @return list<array<int, int|float>>
     */
    private function candidates(BlockSearchState $state, array $space, int $limit): array
    {
        [$x1, $y1, $z1, $x2, $y2, $z2] = $space;
        $spaceHeight = $z2 - $z1;
        $lowX = $x1 <= $this->boxWidth - $x2;
        $lowY = $y1 <= $this->boxLength - $y2;

        // Footprint limits within which a block placed at the anchor corner is supported
        if ($z1 === 0) {
            $footprints = [[$x2 - $x1, $y2 - $y1, false]];
        } else {
            $footprints = [];
            foreach ($this->supportStaircase($state->tops[$z1] ?? [], $x1, $y1, $x2, $y2, $lowX, $lowY) as [$w, $l]) {
                $footprints[] = [$w, $l, false];
            }
            if ($this->minSupport < 1.0) {
                $footprints[] = [$x2 - $x1, $y2 - $y1, true]; // overhang allowed, check each block's support
            }
            if ($footprints === []) {
                return [];
            }
        }

        // In greedy mode only the best candidate is wanted, so unconstrained candidates are tracked as a running
        // best (the first of equals wins, as with the stable sort below) and anything that cannot beat it is skipped.
        // Candidates with placement callbacks are kept aside to be verified in order, best first.
        $greedy = $limit === 1;
        $candidates = [];
        $best = null;
        $bestScore = -1;
        $weightLeft = $state->weightLeft;
        foreach ($state->counts as $type => $count) {
            if ($count === 0) {
                continue;
            }
            $weight = $this->weights[$type];
            if ($weight > 0) {
                $count = min($count, intdiv($weightLeft, $weight));
                if ($count === 0) {
                    continue;
                }
            }
            $volume = $this->volumes[$type];
            $tracked = $greedy && !$this->constrained[$type];
            if ($tracked && $count * $volume <= $bestScore) {
                continue;
            }
            foreach ($this->orientations[$type] as [$ow, $ol, $oh]) {
                if ($oh > $spaceHeight) {
                    continue;
                }
                $mz = intdiv($spaceHeight, $oh);
                foreach ($footprints as [$maxWidth, $maxLength, $checkSupport]) {
                    if ($ow > $maxWidth || $ol > $maxLength) {
                        continue;
                    }
                    $mx = intdiv($maxWidth, $ow);
                    $my = intdiv($maxLength, $ol);
                    if ($mx * $my * $mz <= $count) {
                        $variants = [[$mx, $my, $mz]];
                    } else {
                        $variants = [];
                        $max = [$mx, $my, $mz];
                        foreach (self::FILL_ORDERS as [$a, $b, $c]) {
                            $n = [0, 0, 0];
                            $n[$a] = min($max[$a], $count);
                            $n[$b] = min($max[$b], intdiv($count, $n[$a]));
                            $n[$c] = min($max[$c], intdiv($count, $n[$a] * $n[$b]));
                            $variants[$n[0] . ',' . $n[1] . ',' . $n[2]] = $n;
                        }
                    }

                    foreach ($variants as [$nx, $ny, $nz]) {
                        $blockVolume = $nx * $ny * $nz * $volume;
                        $score = $blockVolume;
                        if ($tracked && $score <= $bestScore) {
                            continue;
                        }
                        $w = $nx * $ow;
                        $l = $ny * $ol;
                        $x = $lowX ? $x1 : $x2 - $w;
                        $y = $lowY ? $y1 : $y2 - $l;
                        if ($checkSupport && !$this->isSupported($state, $x, $y, $z1, $ow, $ol, $nx, $ny)) {
                            continue;
                        }
                        $candidate = [$score, $blockVolume, $w, $l, $nz * $oh, $type, $ow, $ol, $oh, $nx, $ny, $nz, $x, $y, $z1];
                        if ($tracked) {
                            $best = $candidate;
                            $bestScore = $score;
                        } else {
                            $candidates[] = $candidate;
                        }
                    }
                }
            }
        }

        if ($greedy) {
            if ($candidates === []) {
                return $best === null ? [] : [$best];
            }
            $constrained = $candidates;
            $candidates = [];
            foreach ($constrained as $candidate) {
                if ($candidate[self::C_SCORE] > $bestScore) {
                    $candidates[] = $candidate;
                }
            }
            if ($best !== null) {
                $candidates[] = $best; // constrained candidates are preferred only when strictly better
            }
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, static fn (array $a, array $b) => $b[self::C_SCORE] <=> $a[self::C_SCORE]);

        $chosen = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = $candidate[self::C_TYPE] . ',' . $candidate[self::C_OW] . ',' . $candidate[self::C_OL] . ',' . $candidate[self::C_OH] . ',' . $candidate[self::C_NX] . ',' . $candidate[self::C_NY] . ',' . $candidate[self::C_NZ] . ',' . $candidate[self::C_X] . ',' . $candidate[self::C_Y];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($this->constrained[$candidate[self::C_TYPE]] && !$this->constraintsAllow($state, $candidate)) {
                continue;
            }
            $chosen[] = $candidate;
            if (count($chosen) >= $limit) {
                break;
            }
        }

        return $chosen;
    }

    /**
     * Maximal rectangles, anchored at the space's anchor corner, that are fully covered by supporting top faces.
     * Returned as [width, length] pairs with width increasing and length decreasing.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}> $tops
     * @return list<array{0: int, 1: int}>
     */
    private function supportStaircase(array $tops, int $x1, int $y1, int $x2, int $y2, bool $lowX, bool $lowY): array
    {
        $rects = [];
        $edges = [0 => true];
        foreach ($tops as [$rx1, $ry1, $rx2, $ry2]) {
            $cx1 = max($rx1, $x1);
            $cx2 = min($rx2, $x2);
            if ($cx1 >= $cx2) {
                continue;
            }
            $cy1 = max($ry1, $y1);
            $cy2 = min($ry2, $y2);
            if ($cy1 >= $cy2) {
                continue;
            }
            if ($lowX) {
                $u1 = $cx1 - $x1;
                $u2 = $cx2 - $x1;
            } else {
                $u1 = $x2 - $cx2;
                $u2 = $x2 - $cx1;
            }
            if ($lowY) {
                $v1 = $cy1 - $y1;
                $v2 = $cy2 - $y1;
            } else {
                $v1 = $y2 - $cy2;
                $v2 = $y2 - $cy1;
            }
            $rects[] = [$u1, $v1, $u2, $v2];
            $edges[$u1] = true;
            $edges[$u2] = true;
        }
        if ($rects === []) {
            return [];
        }

        ksort($edges);
        $edges = array_keys($edges);
        $edgeCount = count($edges);
        $steps = [];
        $runMin = PHP_INT_MAX;
        $endU = 0;
        for ($i = 0; $i < $edgeCount - 1; ++$i) {
            $u = $edges[$i];
            $uNext = $edges[$i + 1];
            $intervals = [];
            foreach ($rects as [$ru1, $rv1, $ru2, $rv2]) {
                if ($ru1 <= $u && $ru2 >= $uNext) {
                    $intervals[$rv1] = max($intervals[$rv1] ?? 0, $rv2);
                }
            }
            $reach = 0;
            if ($intervals !== []) {
                ksort($intervals);
                foreach ($intervals as $start => $end) {
                    if ($start > $reach) {
                        break;
                    }
                    if ($end > $reach) {
                        $reach = $end;
                    }
                }
            }
            if ($reach === 0) {
                break;
            }
            if ($reach < $runMin) {
                if ($runMin !== PHP_INT_MAX) {
                    $steps[] = [$u, $runMin];
                }
                $runMin = $reach;
            }
            $endU = $uNext;
        }
        if ($runMin !== PHP_INT_MAX) {
            $steps[] = [$endU, $runMin];
        }

        return $steps;
    }

    /**
     * Whether every item in the bottom layer of a block (nx × ny items of $itemWidth × $itemLength) placed with
     * its corner at x, y, z rests on enough supporting area.
     */
    private function isSupported(BlockSearchState $state, int $x, int $y, int $z, int $itemWidth, int $itemLength, int $nx, int $ny): bool
    {
        if ($z === 0 || $this->minSupport <= 0.0) {
            return true;
        }
        $tops = $state->tops[$z] ?? [];
        $blockArea = $nx * $itemWidth * $ny * $itemLength;
        $supported = self::supportedArea($tops, $x, $y, $x + $nx * $itemWidth, $y + $ny * $itemLength);
        if ($supported === $blockArea) {
            return true;
        }
        if ($supported < $this->minSupport * $blockArea) {
            return false;
        }
        $needed = $this->minSupport * $itemWidth * $itemLength;
        for ($ix = 0; $ix < $nx; ++$ix) {
            $itemX = $x + $ix * $itemWidth;
            for ($iy = 0; $iy < $ny; ++$iy) {
                $itemY = $y + $iy * $itemLength;
                if (self::supportedArea($tops, $itemX, $itemY, $itemX + $itemWidth, $itemY + $itemLength) < $needed) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $tops
     */
    private static function supportedArea(array $tops, int $x1, int $y1, int $x2, int $y2): int
    {
        $area = 0;
        foreach ($tops as [$rx1, $ry1, $rx2, $ry2]) {
            $ix = min($x2, $rx2) - max($x1, $rx1);
            if ($ix <= 0) {
                continue;
            }
            $iy = min($y2, $ry2) - max($y1, $ry1);
            if ($iy <= 0) {
                continue;
            }
            $area += $ix * $iy;
        }

        return $area;
    }

    /**
     * Ask each item's own placement callback, in the order the items would be placed.
     *
     * @param array<int, int|float> $candidate
     */
    private function constraintsAllow(BlockSearchState $state, array $candidate): bool
    {
        $type = $candidate[self::C_TYPE];
        $context = clone $this->context($state);
        $next = $this->initialCounts[$type] - $state->counts[$type];
        [$ow, $ol, $oh, $nx, $ny, $nz, $x, $y, $z] = [$candidate[self::C_OW], $candidate[self::C_OL], $candidate[self::C_OH], $candidate[self::C_NX], $candidate[self::C_NY], $candidate[self::C_NZ], $candidate[self::C_X], $candidate[self::C_Y], $candidate[self::C_Z]];
        for ($iz = 0; $iz < $nz; ++$iz) {
            for ($iy = 0; $iy < $ny; ++$iy) {
                for ($ix = 0; $ix < $nx; ++$ix) {
                    $item = $this->itemsByType[$type][$next++];
                    assert($item instanceof ConstrainedPlacementItem);
                    $px = $x + $ix * $ow;
                    $py = $y + $iy * $ol;
                    $pz = $z + $iz * $oh;
                    if (!$item->canBePacked(new PackedBox($this->box, $context), $px, $py, $pz, $ow, $ol, $oh)) {
                        return false;
                    }
                    $context->insert(new PackedItem($item, $px, $py, $pz, $ow, $ol, $oh));
                }
            }
        }

        return true;
    }

    private function context(BlockSearchState $state): PackedItemList
    {
        if ($state->context === null) {
            $state->context = new PackedItemList();
            $next = [];
            foreach ($state->placements as $placement) {
                $this->appendItems($state->context, $placement, $next);
            }
        }

        return $state->context;
    }

    /**
     * @param array<int, int|float> $candidate
     */
    private function place(BlockSearchState $state, array $candidate): void
    {
        $x = $candidate[self::C_X];
        $y = $candidate[self::C_Y];
        $z = $candidate[self::C_Z];
        $x2 = $x + $candidate[self::C_W];
        $y2 = $y + $candidate[self::C_L];
        $z2 = $z + $candidate[self::C_H];
        $type = $candidate[self::C_TYPE];
        $n = $candidate[self::C_NX] * $candidate[self::C_NY] * $candidate[self::C_NZ];

        if ($state->context !== null) {
            $next = [$type => $this->initialCounts[$type] - $state->counts[$type]];
            $this->appendItems($state->context, $candidate, $next);
        }

        $state->counts[$type] -= $n;
        if ($state->counts[$type] === 0) {
            $this->updateSpaceFilter($state);
        }
        $state->spaces = $this->occupy($state, $x, $y, $z, $x2, $y2, $z2);
        $state->remaining -= $n;
        $state->weightLeft -= $n * $this->weights[$type];
        $state->volume += $candidate[self::C_VOLUME];
        $state->tops[$z2][] = [$x, $y, $x2, $y2];
        $state->placements[] = $candidate;
    }

    /**
     * Remove a newly occupied cuboid from the free spaces, replacing each space it cuts with the (maximal) pieces
     * left over on each side, then dropping pieces that are too small or lie inside another space.
     *
     * @return array<int, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}>
     */
    private function occupy(BlockSearchState $state, int $bx1, int $by1, int $bz1, int $bx2, int $by2, int $bz2): array
    {
        $kept = [];
        $pieces = [];
        foreach ($state->spaces as $space) {
            if ($bx1 >= $space[3] || $bx2 <= $space[0] || $by1 >= $space[4] || $by2 <= $space[1] || $bz1 >= $space[5] || $bz2 <= $space[2]) {
                $kept[] = $space;
                continue;
            }
            [$x1, $y1, $z1, $x2, $y2, $z2] = $space;
            if ($bx1 > $x1) {
                $pieces[] = [$x1, $y1, $z1, $bx1, $y2, $z2];
            }
            if ($bx2 < $x2) {
                $pieces[] = [$bx2, $y1, $z1, $x2, $y2, $z2];
            }
            if ($by1 > $y1) {
                $pieces[] = [$x1, $y1, $z1, $x2, $by1, $z2];
            }
            if ($by2 < $y2) {
                $pieces[] = [$x1, $by2, $z1, $x2, $y2, $z2];
            }
            if ($bz1 > $z1) {
                $pieces[] = [$x1, $y1, $z1, $x2, $y2, $bz1];
            }
            if ($bz2 < $z2) {
                $pieces[] = [$x1, $y1, $bz2, $x2, $y2, $z2];
            }
        }

        $minFootprintEdge = $state->minFootprintEdge;
        $minHeight = $state->minHeight;
        $minVolume = $state->minVolume;
        $pieceCount = count($pieces);
        for ($i = 0; $i < $pieceCount; ++$i) {
            [$x1, $y1, $z1, $x2, $y2, $z2] = $pieces[$i];
            if ($x2 - $x1 < $minFootprintEdge || $y2 - $y1 < $minFootprintEdge || $z2 - $z1 < $minHeight || ($x2 - $x1) * ($y2 - $y1) * ($z2 - $z1) < $minVolume) {
                continue;
            }
            foreach ($kept as $other) {
                if ($other[0] <= $x1 && $other[1] <= $y1 && $other[2] <= $z1 && $other[3] >= $x2 && $other[4] >= $y2 && $other[5] >= $z2) {
                    continue 2;
                }
            }
            for ($j = $i + 1; $j < $pieceCount; ++$j) {
                $other = $pieces[$j];
                if ($other[0] <= $x1 && $other[1] <= $y1 && $other[2] <= $z1 && $other[3] >= $x2 && $other[4] >= $y2 && $other[5] >= $z2) {
                    continue 2; // contained in (or equal to) a later piece, which will be kept or dropped on its own merits
                }
            }
            $kept[] = $pieces[$i];
        }

        return $kept;
    }

    /**
     * @param array<int, int|float> $placement
     * @param array<int, int>       $next      per-type index of the next item object to hand out
     */
    private function appendItems(PackedItemList $list, array $placement, array &$next): void
    {
        $type = $placement[self::C_TYPE];
        $index = $next[$type] ?? 0;
        [$ow, $ol, $oh, $nx, $ny, $nz, $x, $y, $z] = [$placement[self::C_OW], $placement[self::C_OL], $placement[self::C_OH], $placement[self::C_NX], $placement[self::C_NY], $placement[self::C_NZ], $placement[self::C_X], $placement[self::C_Y], $placement[self::C_Z]];
        for ($iz = 0; $iz < $nz; ++$iz) {
            for ($iy = 0; $iy < $ny; ++$iy) {
                for ($ix = 0; $ix < $nx; ++$ix) {
                    $list->insert(new PackedItem($this->itemsByType[$type][$index++], $x + $ix * $ow, $y + $iy * $ol, $z + $iz * $oh, $ow, $ol, $oh));
                }
            }
        }
        $next[$type] = $index;
    }

    private function materialise(BlockSearchState $state): PackedBox
    {
        $list = new PackedItemList();
        $next = [];
        foreach ($state->placements as $placement) {
            $this->appendItems($list, $placement, $next);
        }

        return new PackedBox($this->box, $list);
    }

    /**
     * Distinct orientations of the item that fit the empty box, keeping only stable ones where there are any
     * (same rule as the layer packer).
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function buildOrientations(Item $item): array
    {
        $w = $item->getWidth();
        $l = $item->getLength();
        $d = $item->getDepth();

        $permutations = match ($item->getAllowedRotation()) {
            Rotation::Never => [[$w, $l, $d]],
            Rotation::KeepFlat => [[$w, $l, $d], [$l, $w, $d]],
            Rotation::BestFit => [[$w, $l, $d], [$l, $w, $d], [$w, $d, $l], [$l, $d, $w], [$d, $w, $l], [$d, $l, $w]],
        };

        $fitting = [];
        foreach ($permutations as $permutation) {
            if ($permutation[0] <= $this->boxWidth && $permutation[1] <= $this->boxLength && $permutation[2] <= $this->boxDepth) {
                $fitting[$permutation[0] . '|' . $permutation[1] . '|' . $permutation[2]] = $permutation;
            }
        }

        if (!$this->preferStableOrientations) {
            return array_values($fitting);
        }

        $stable = [];
        foreach ($fitting as $key => [$ow, $ol, $oh]) {
            if ($oh === $this->boxDepth || atan(min($ow, $ol) / ($oh ?: 1)) > self::STABILITY_ANGLE) {
                $stable[] = $fitting[$key];
            }
        }

        return $stable !== [] ? $stable : array_values($fitting);
    }
}
