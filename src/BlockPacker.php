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

use function array_column;
use function array_fill;
use function array_key_exists;
use function array_keys;
use function array_map;
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
use function rad2deg;
use function sort;
use function spl_object_id;
use function usort;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Single-container packer that builds the load out of blocks of identical items (the Thorough strategy).
 *
 * Free space is tracked as a set of (overlapping) maximal empty cuboids. At each step the lowest free space (then the
 * one nearest a corner of the container) is filled with the best block of identical items that fits it, placed in
 * that corner: columns, walls and layers of one item type in one orientation, sized to the space. Blocks are scored
 * by volume less the part of the gap they leave that no combination of item edges could fill.
 *
 * A beam search explores alternative block choices, scoring each partial packing by greedily completing it. The beam
 * is widened step by step (1, 2, 4, 8...) so that a result is always available and more effort gives a better answer.
 * Effort is bounded by a maximum beam width and a budget of trial placements (both deterministic), and optionally by
 * a wall-clock time limit.
 *
 * Optionally, items too long to fit the container any other way can be turned about the vertical axis just enough
 * to fit (angled placement). Identical angled items are laid parallel, each tucked into the triangle beside the last,
 * and the empty triangles left in the corners of the group are offered as free space for other items.
 *
 * Every item must rest on the floor of the container or on the tops of other items over at least the configured
 * fraction of its base. Rotation rules, the preference for stable orientations, the weight limit and placement
 * callbacks (ConstrainedPlacementItem) are honoured.
 *
 * @internal
 */
class BlockPacker implements LoggerAwareInterface
{
    // Candidate block layout (packed list for speed)
    private const C_SCORE = 0;
    private const C_VOLUME = 1;
    private const C_W = 2;
    private const C_L = 3;
    private const C_H = 4;
    private const C_TYPE = 5;
    private const C_OW = 6;
    private const C_OL = 7;
    private const C_OH = 8;
    private const C_NX = 9;
    private const C_NY = 10;
    private const C_NZ = 11;
    private const C_X = 12;
    private const C_Y = 13;
    private const C_Z = 14;
    // angled blocks only: layout id, direction (0: items span x and repeat along y, 1: the reverse), top of the space
    private const C_ANGLED = 15;
    private const C_DIR = 16;
    private const C_ZTOP = 17;

    /**
     * Minimum angle (radians) between the base and the diagonal for an orientation to count as stable,
     * same rule as {@see OrientatedItem::isStable()}.
     */
    private const STABILITY_ANGLE = 0.261;

    /**
     * Largest box dimension for which per-axis fillable length tables are built.
     */
    private const MAX_FILLABLE_TABLE = 50000;

    private const FILLABLE_CACHE_SIZE = 64;

    /**
     * Placement callbacks (ConstrainedPlacementItem) are charged to the search budget at this rate: often cheap
     * (the BR benchmark's edge restrictions), but potentially not, and there can be very many of them.
     */
    private const CALLBACKS_PER_PLACEMENT = 10;

    private LoggerInterface $logger;

    private readonly int $boxWidth;

    private readonly int $boxLength;

    private readonly int $boxDepth;

    private readonly int $weightCapacity;

    /**
     * @var array<int, list<array{0: int, 1: int, 2: int, 3: ?ItemState}>>
     */
    private array $orientations = [];

    /**
     * The state each orientation of a type puts the item in, by "width|length|height".
     *
     * @var array<int, array<string, ?ItemState>>
     */
    private array $orientationStates = [];

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

    /**
     * Smallest volume any orientation (state) of the type takes up.
     *
     * @var array<int, int>
     */
    private array $minVolumes = [];

    private int $totalItems = 0;

    /**
     * Items with a zero dimension. They occupy no volume, so they are placed on the floor in the corner once the
     * rest of the load has been packed.
     *
     * @var list<Item>
     */
    private array $flatItems = [];

    private int $packableVolume = 0;

    private bool $allowAngled = false;

    /**
     * Types that only fit the box turned at an angle: the ways up they can go, as [long side, short side, height].
     *
     * @var array<int, list<array{0: int, 1: int, 2: int}>>
     */
    private array $angledUprights = [];

    /**
     * Angled layouts (see {@see AngledGeometry::layout()}) by id, with the item's long and short sides.
     *
     * @var list<array{angle: float, width: int, itemLength: int, pitch: int, long: int, short: int}>
     */
    private array $angledLayouts = [];

    /**
     * Layout ids by long side, short side and span (null: impossible).
     *
     * @var array<string, int|null>
     */
    private array $angledLayoutIds = [];

    /**
     * Support rectangles and corner spaces of angled blocks, relative to the block, by layout, count and direction.
     *
     * @var array<string, array{0: list<array{0: int, 1: int, 2: int, 3: int}>, 1: list<array{0: int, 1: int, 2: int, 3: int}>}>
     */
    private array $angledShapes = [];

    /**
     * For each axis, the longest length up to each value that item edges placeable along that axis can add up to.
     *
     * @var array{0: list<int>, 1: list<int>, 2: list<int>}
     */
    private array $fillable = [[], [], []];

    /**
     * Fillable length tables already built, by length and item edges (a Packer packs the same box and items many times).
     *
     * @var array<string, list<int>>
     */
    private static array $fillableCache = [];

    private float $minSupport = 0.5;

    private int $maxBeamWidth = 16;

    private ?float $timeLimit = null;

    private int $placementBudget = PHP_INT_MAX;

    /**
     * Hard limit on trial placements, at which even a greedy completion in progress is cut short.
     */
    private int $placementCap = PHP_INT_MAX;

    private int $spaceRule = 1;

    private int $scoring = 1;

    private int $deadline = PHP_INT_MAX;

    private bool $outOfTime = false;

    private bool $budgetExhausted = false;

    private int $greedyRuns = 0;

    private int $placements = 0;

    private int $callbacks = 0;

    /**
     * Greedy completions already computed during this search, by signature of the state they started from.
     *
     * @var array<string, BlockSearchState>
     */
    private array $completions = [];

    /**
     * @param iterable<Item> $items
     * @param bool           $useStates whether items may be placed in their states (see {@see ReshapableItem}) as well as in their own shape
     */
    public function __construct(private readonly Box $box, iterable $items, private readonly bool $preferStableOrientations = true, private readonly bool $useStates = true)
    {
        $this->logger = new NullLogger();
        $this->boxWidth = $box->getInnerWidth();
        $this->boxLength = $box->getInnerLength();
        $this->boxDepth = $box->getInnerDepth();
        $this->weightCapacity = $box->getMaxWeight() - $box->getEmptyWeight();

        $typeIndex = [];
        foreach ($items as $item) {
            if ($item->getWidth() === 0 || $item->getLength() === 0 || $item->getDepth() === 0) {
                $this->flatItems[] = $item; // take up no space: added at the end
                continue;
            }
            $key = $item->getWidth() . '|' . $item->getLength() . '|' . $item->getDepth() . '|' . $item->getWeight() . '|' . $item->getAllowedRotation()->name . ($this->useStates ? ItemState::signatureOf($item) : '');
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
                foreach ($this->orientations[$type] as [$ow, $ol, $oh, $state]) {
                    $this->orientationStates[$type][$ow . '|' . $ol . '|' . $oh] = $state;
                }
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
            $this->minHeights[$type] = $this->minFootprintEdges[$type] = $this->minVolumes[$type] = PHP_INT_MAX;
            foreach ($this->orientations[$type] as [$w, $l, $h]) {
                $this->minHeights[$type] = min($this->minHeights[$type], $h);
                $this->minFootprintEdges[$type] = min($this->minFootprintEdges[$type], $w, $l);
                $this->minVolumes[$type] = min($this->minVolumes[$type], $w * $l * $h);
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
        $this->minSupport = max(0.0, min(1.0, $minSupport));
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

    /**
     * Deterministic cap on search effort: the number of trial block placements (across all greedy completions)
     * after which no new work is started and the best packing found so far is returned. Work in progress is
     * allowed to finish, but is cut short at twice the budget. Roughly proportional to run time.
     */
    public function setPlacementBudget(?int $placements): void
    {
        $this->placementBudget = $placements ?? PHP_INT_MAX;
        $this->placementCap = $placements === null ? PHP_INT_MAX : 2 * $placements;
    }

    /**
     * Allow items that fit no other way to be turned about the vertical axis just enough to fit (not items that
     * cannot be rotated, nor items with placement callbacks, which cannot be told about the angle).
     */
    public function setAllowAngledPlacement(bool $allow): void
    {
        if (!$allow || $this->allowAngled) {
            return;
        }
        $this->allowAngled = true;

        foreach ($this->orientations as $type => $orientations) {
            if ($orientations !== [] || $this->constrained[$type] || $this->weights[$type] > $this->weightCapacity) {
                continue;
            }
            $uprights = $this->buildAngledUprights($this->itemsByType[$type][0]);
            if ($uprights === []) {
                continue;
            }
            $this->angledUprights[$type] = $uprights;
            $this->initialCounts[$type] = count($this->itemsByType[$type]);
            $this->packableVolume += $this->initialCounts[$type] * $this->volumes[$type];
            $this->minHeights[$type] = min(array_column($uprights, 2));
            $this->minFootprintEdges[$type] = min(array_column($uprights, 1));
            $this->minVolumes[$type] = $this->volumes[$type];
        }
    }

    /**
     * Tuning hook for experiments (bin/benchmark): 1 (default) fills the lowest free space first, then the one
     * nearest a corner; 0 fills the space nearest a corner in any direction, lowest distance first.
     */
    public function setSpaceRule(int $rule): void
    {
        $this->spaceRule = $rule;
    }

    /**
     * Tuning hook for experiments (bin/benchmark): 1 (default) scores blocks by volume less the gap next to them
     * that item edges cannot fill; 0 by volume alone.
     */
    public function setScoring(int $scoring): void
    {
        $this->scoring = $scoring;
    }

    public function getPlacements(): int
    {
        return $this->placements;
    }

    public function getGreedyRuns(): int
    {
        return $this->greedyRuns;
    }

    public function pack(): PackedBox
    {
        $this->buildFillableTables();
        $this->deadline = $this->timeLimit === null ? PHP_INT_MAX : hrtime(true) + (int) ($this->timeLimit * 1e9);
        $this->outOfTime = false;
        $this->greedyRuns = 0;
        $this->placements = 0;
        $this->callbacks = 0;
        $this->completions = [];

        $root = new BlockSearchState();
        $root->spaces = [[0, 0, 0, $this->boxWidth, $this->boxLength, $this->boxDepth]];
        $root->counts = $this->initialCounts;
        $root->weightLeft = $this->weightCapacity;
        $root->remaining = $this->totalItems;
        $this->updateSpaceFilter($root);

        $rootCompleted = $this->greedy(clone $root, false);
        $best = $rootCompleted;

        $this->budgetExhausted = false;
        for ($width = 2; $width <= $this->maxBeamWidth && !$this->isComplete($best) && !$this->budgetExhausted && !$this->outOfTime; $width = $width < $this->maxBeamWidth && $width * 2 > $this->maxBeamWidth ? $this->maxBeamWidth : $width * 2) {
            $best = $this->beamSearch($root, $rootCompleted, $width, $best);
        }

        $this->logger->debug('Block search complete', ['greedyRuns' => $this->greedyRuns, 'placements' => $this->placements, 'volume' => $best->volume, 'budgetExhausted' => $this->budgetExhausted, 'outOfTime' => $this->outOfTime]);
        $this->completions = [];

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
                    } elseif (isset($this->completions[$signature])) {
                        $childCompleted = $this->completions[$signature]; // already evaluated in a narrower pass
                    } else {
                        $childCompleted = $this->greedy(clone $child);
                        $this->completions[$signature] = $childCompleted;
                        if ($childCompleted->volume > $best->volume) {
                            $best = $childCompleted;
                            if ($this->isComplete($best)) {
                                return $best;
                            }
                        }
                        if ($this->placements > $this->placementBudget) {
                            $this->budgetExhausted = true;

                            return $best;
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
            $keys[] = $placement[self::C_TYPE] . ',' . $placement[self::C_OW] . ',' . $placement[self::C_OL] . ',' . $placement[self::C_OH] . ',' . $placement[self::C_NX] . ',' . $placement[self::C_NY] . ',' . $placement[self::C_NZ] . ',' . $placement[self::C_X] . ',' . $placement[self::C_Y] . ',' . $placement[self::C_Z] . (isset($placement[self::C_ANGLED]) ? ',a' . $placement[self::C_ANGLED] . ',' . $placement[self::C_DIR] : '');
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
            $this->retire($state, $spaceIndex);
        }
    }

    /**
     * Complete a state by repeatedly placing the best block into the most promising space.
     */
    private function greedy(BlockSearchState $state, bool $withinLimits = true): BlockSearchState
    {
        ++$this->greedyRuns;
        while ($state->remaining > 0) {
            // stop part way through at the hard effort cap or when time runs out, keeping what has been placed; the
            // very first completion is exempt, so that there is always a complete answer (it is only one pass)
            if ($withinLimits && ($this->placements > $this->placementCap || hrtime(true) > $this->deadline)) {
                break;
            }
            $spaceIndex = $this->selectSpace($state);
            if ($spaceIndex === null) {
                break;
            }
            $candidates = $this->candidates($state, $state->spaces[$spaceIndex], 1);
            if ($candidates === []) {
                $this->retire($state, $spaceIndex);
                continue;
            }
            $this->place($state, $candidates[0]);
        }

        // a completed packing is only ever read for its placements and volume, so free the rest
        $state->spaces = $state->tops = [];
        $state->context = null;

        return $state;
    }

    /**
     * Nothing can go into this space right now. A space on the floor never becomes usable again (it can only
     * shrink and fewer items remain), but a raised one may once more supporting items are placed at its level,
     * so it is kept as dormant: still part of the free space bookkeeping, but not selected.
     */
    private function retire(BlockSearchState $state, int $spaceIndex): void
    {
        if ($state->spaces[$spaceIndex][2] === 0) {
            unset($state->spaces[$spaceIndex]);
        } else {
            $state->spaces[$spaceIndex][6] = true;
        }
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
        if ($this->spaceRule === 1) {
            // bottom-up: lowest first, then nearest a corner
            foreach ($state->spaces as $index => $space) {
                $z = $space[2];
                if ($z > $bestA || isset($space[6])) {
                    continue; // higher than the best so far, or dormant until new support appears
                }
                $dx = min($space[0], $boxWidth - $space[3]);
                $dy = min($space[1], $boxLength - $space[4]);
                if ($dx > $dy) {
                    $swap = $dx;
                    $dx = $dy;
                    $dy = $swap;
                }
                if ($z < $bestA || $dx < $bestB || ($dx === $bestB && ($dy < $bestC || ($dy === $bestC && ($space[3] - $space[0]) * ($space[4] - $space[1]) * ($space[5] - $space[2]) > $bestVolume)))) {
                    $bestIndex = $index;
                    $bestA = $z;
                    $bestB = $dx;
                    $bestC = $dy;
                    $bestVolume = ($space[3] - $space[0]) * ($space[4] - $space[1]) * ($space[5] - $space[2]);
                }
            }

            return $bestIndex;
        }

        // nearest a corner of the container, by the distances to it sorted ascending
        foreach ($state->spaces as $index => $space) {
            if (isset($space[6])) {
                continue; // dormant until new support appears
            }
            $dx = min($space[0], $boxWidth - $space[3]);
            $dy = min($space[1], $boxLength - $space[4]);
            $dz = $space[2];
            if ($dx > $dy) {
                $swap = $dx;
                $dx = $dy;
                $dy = $swap;
            }
            if ($dy > $dz) {
                $swap = $dy;
                $dy = $dz;
                $dz = $swap;
                if ($dx > $dy) {
                    $swap = $dx;
                    $dx = $dy;
                    $dy = $swap;
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
        $lowX = $space[0] <= $this->boxWidth - $space[3];
        $lowY = $space[1] <= $this->boxLength - $space[4];
        $candidates = $this->candidatesAt($state, $space, $limit, $lowX, $lowY);
        if ($candidates !== [] || $space[2] === 0) {
            return $candidates;
        }

        // A raised space may be supported at one of its other bottom corners even if not at the preferred one
        foreach ([[$lowX, !$lowY], [!$lowX, $lowY], [!$lowX, !$lowY]] as [$cornerX, $cornerY]) {
            $candidates = $this->candidatesAt($state, $space, $limit, $cornerX, $cornerY);
            if ($candidates !== []) {
                return $candidates;
            }
        }

        return [];
    }

    /**
     * Blocks that can go into the given bottom corner of this space, best first.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} $space
     * @return list<array<int, int|float>>
     */
    private function candidatesAt(BlockSearchState $state, array $space, int $limit, bool $lowX, bool $lowY): array
    {
        [$x1, $y1, $z1, $x2, $y2, $z2] = $space;
        $spaceHeight = $z2 - $z1;
        $spaceWidth = $x2 - $x1;
        $spaceLength = $y2 - $y1;
        $spaceVolume = $spaceWidth * $spaceLength * $spaceHeight;
        $scoring = $this->scoring;
        [$fillX, $fillY, $fillZ] = $this->fillable;

        // Footprint limits [width, length, check support, max area] within which a block at the corner can go
        if ($z1 === 0 || $this->minSupport <= 0.0) {
            $footprints = [[$x2 - $x1, $y2 - $y1, false, PHP_INT_MAX]];
        } else {
            $tops = $state->tops[$z1] ?? [];
            if ($tops === []) {
                return [];
            }
            $footprints = [];
            // fully supported blocks must lie within a covered rectangle starting at the corner
            if (self::covers($tops, $lowX ? $x1 : $x2 - 1, $lowY ? $y1 : $y2 - 1)) {
                foreach ($this->supportStaircase($tops, $x1, $y1, $x2, $y2, $lowX, $lowY) as [$w, $l]) {
                    $footprints[] = [$w, $l, false, PHP_INT_MAX];
                }
            }
            // when overhangs are allowed, larger blocks are possible but need checking; they can be no bigger than
            // the supporting area within the space allows
            if ($this->minSupport < 1.0) {
                $supportable = self::supportedArea($tops, $x1, $y1, $x2, $y2);
                if ($supportable > 0) {
                    $footprints[] = [$x2 - $x1, $y2 - $y1, true, $supportable / $this->minSupport];
                }
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
        $bestScore = PHP_INT_MIN;
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
            $fitting = min($count, intdiv($spaceVolume, $volume));
            if ($fitting === 0) {
                continue;
            }
            $tracked = $greedy && !$this->constrained[$type];
            if ($tracked && $fitting * $volume <= $bestScore) {
                continue;
            }
            foreach ($this->orientations[$type] as [$ow, $ol, $oh]) {
                if ($oh > $spaceHeight) {
                    continue;
                }
                $mz = intdiv($spaceHeight, $oh);
                foreach ($footprints as [$maxWidth, $maxLength, $checkSupport, $maxArea]) {
                    if ($ow > $maxWidth || $ol > $maxLength) {
                        continue;
                    }
                    $mx = intdiv($maxWidth, $ow);
                    $my = intdiv($maxLength, $ol);
                    $capacity = $mx * $my * $mz;
                    if ($tracked && ($capacity < $count ? $capacity : $count) * $volume <= $bestScore) {
                        continue; // no block of this orientation could beat the best so far
                    }
                    if ($capacity <= $count) {
                        $variants = [[$mx, $my, $mz]];
                    } elseif ($count === 1) {
                        $variants = [[1, 1, 1]];
                    } else {
                        // not enough for a full block: fill as many as possible along a first axis, then a second,
                        // then the third, for each order of axes
                        $key = $count + 1;
                        $nx = min($mx, $count);
                        $ny = min($my, intdiv($count, $nx));
                        $nz = min($mz, intdiv($count, $nx * $ny));
                        $variants = [$nx + $key * ($ny + $key * $nz) => [$nx, $ny, $nz]];
                        $nz = min($mz, intdiv($count, $nx));
                        $ny = min($my, intdiv($count, $nx * $nz));
                        $variants[$nx + $key * ($ny + $key * $nz)] = [$nx, $ny, $nz];
                        $ny = min($my, $count);
                        $nx = min($mx, intdiv($count, $ny));
                        $nz = min($mz, intdiv($count, $nx * $ny));
                        $variants[$nx + $key * ($ny + $key * $nz)] = [$nx, $ny, $nz];
                        $nz = min($mz, intdiv($count, $ny));
                        $nx = min($mx, intdiv($count, $ny * $nz));
                        $variants[$nx + $key * ($ny + $key * $nz)] = [$nx, $ny, $nz];
                        $nz = min($mz, $count);
                        $nx = min($mx, intdiv($count, $nz));
                        $ny = min($my, intdiv($count, $nx * $nz));
                        $variants[$nx + $key * ($ny + $key * $nz)] = [$nx, $ny, $nz];
                        $ny = min($my, intdiv($count, $nz));
                        $nx = min($mx, intdiv($count, $ny * $nz));
                        $variants[$nx + $key * ($ny + $key * $nz)] = [$nx, $ny, $nz];
                    }

                    foreach ($variants as [$nx, $ny, $nz]) {
                        $blockVolume = $nx * $ny * $nz * $volume;
                        if ($scoring === 1) {
                            $w = $nx * $ow;
                            $l = $ny * $ol;
                            $h = $nz * $oh;
                            $rx = $spaceWidth - $w;
                            $ry = $spaceLength - $l;
                            $rz = $spaceHeight - $h;
                            $score = $blockVolume - ($rx - $fillX[$rx]) * $l * $h - ($ry - $fillY[$ry]) * $w * $h - ($rz - $fillZ[$rz]) * $w * $l;
                        } else {
                            $score = $blockVolume;
                        }
                        if ($tracked && $score <= $bestScore) {
                            continue;
                        }
                        $w = $nx * $ow;
                        $l = $ny * $ol;
                        $x = $lowX ? $x1 : $x2 - $w;
                        $y = $lowY ? $y1 : $y2 - $l;
                        if ($checkSupport && ($w * $l > $maxArea || !$this->isSupported($state, $x, $y, $z1, $ow, $ol, $nx, $ny))) {
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

            // Items that only fit turned at an angle: rows of parallel items (stacked in layers), turned just enough
            // to span the footprint in one direction. Only placed where their whole bounding box is supported.
            foreach ($this->angledUprights[$type] ?? [] as [$long, $short, $oh]) {
                if ($oh > $spaceHeight) {
                    continue;
                }
                $mz = intdiv($spaceHeight, $oh);
                foreach ($footprints as [$maxWidth, $maxLength, $checkSupport]) {
                    if ($checkSupport) {
                        continue;
                    }
                    for ($direction = 0; $direction <= 1; ++$direction) {
                        $layoutId = $this->angledLayout($long, $short, $direction === 0 ? $maxWidth : $maxLength);
                        if ($layoutId === null) {
                            continue;
                        }
                        ['width' => $spanned, 'itemLength' => $itemLength, 'pitch' => $pitch] = $this->angledLayouts[$layoutId];
                        $across = $direction === 0 ? $maxLength : $maxWidth;
                        if ($itemLength > $across) {
                            continue;
                        }
                        $mn = 1 + intdiv($across - $itemLength, $pitch);
                        $capacity = $mn * $mz;
                        if ($tracked && ($capacity < $count ? $capacity : $count) * $volume <= $bestScore) {
                            continue;
                        }
                        if ($capacity <= $count) {
                            $variants = [[$mn, $mz]];
                        } elseif ($count === 1) {
                            $variants = [[1, 1]];
                        } else {
                            // not enough for a full block: a full row stacked as high as possible, or a full stack
                            // made as long as possible
                            $n = min($mn, $count);
                            $k = min($mz, intdiv($count, $n));
                            $variants = [$n . ',' . $k => [$n, $k]];
                            $k = min($mz, $count);
                            $n = min($mn, intdiv($count, $k));
                            $variants[$n . ',' . $k] = [$n, $k];
                        }
                        foreach ($variants as [$n, $k]) {
                            $blockVolume = $n * $k * $volume;
                            $rowLength = $itemLength + ($n - 1) * $pitch;
                            $w = $direction === 0 ? $spanned : $rowLength;
                            $l = $direction === 0 ? $rowLength : $spanned;
                            $h = $k * $oh;
                            if ($scoring === 1) {
                                $rx = $spaceWidth - $w;
                                $ry = $spaceLength - $l;
                                $rz = $spaceHeight - $h;
                                $score = $blockVolume - ($rx - $fillX[$rx]) * $l * $h - ($ry - $fillY[$ry]) * $w * $h - ($rz - $fillZ[$rz]) * $w * $l;
                            } else {
                                $score = $blockVolume;
                            }
                            if ($tracked && $score <= $bestScore) {
                                continue;
                            }
                            $x = $lowX ? $x1 : $x2 - $w;
                            $y = $lowY ? $y1 : $y2 - $l;
                            $candidate = [$score, $blockVolume, $w, $l, $h, $type, $long, $short, $oh, $direction === 0 ? 1 : $n, $direction === 0 ? $n : 1, $k, $x, $y, $z1, $layoutId, $direction, $z2];
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
            $key = $candidate[self::C_TYPE] . ',' . $candidate[self::C_OW] . ',' . $candidate[self::C_OL] . ',' . $candidate[self::C_OH] . ',' . $candidate[self::C_NX] . ',' . $candidate[self::C_NY] . ',' . $candidate[self::C_NZ] . ',' . $candidate[self::C_X] . ',' . $candidate[self::C_Y] . (isset($candidate[self::C_ANGLED]) ? ',a' . $candidate[self::C_ANGLED] . ',' . $candidate[self::C_DIR] : '');
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
     * Whether the unit square at x, y is covered by one of the rectangles.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $tops
     */
    private static function covers(array $tops, int $x, int $y): bool
    {
        foreach ($tops as [$rx1, $ry1, $rx2, $ry2]) {
            if ($x >= $rx1 && $x < $rx2 && $y >= $ry1 && $y < $ry2) {
                return true;
            }
        }

        return false;
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
        $context = clone $this->context($state);
        $next = [];
        foreach ($this->itemsOf($candidate) as [$type, $x, $y, $z, $w, $l, $h, $angle]) {
            $next[$type] ??= $this->initialCounts[$type] - $state->counts[$type];
            $item = $this->itemsByType[$type][$next[$type]++];
            if ($item instanceof ConstrainedPlacementItem) {
                if (++$this->callbacks % self::CALLBACKS_PER_PLACEMENT === 0) {
                    ++$this->placements; // callbacks can be costly, so they count against the search budget too
                }
                if (!$item->canBePacked(new PackedBox($this->box, $context), $x, $y, $z, $w, $l, $h)) {
                    return false;
                }
            }
            $context->insert(new PackedItem($item, $x, $y, $z, $w, $l, $h, $angle, $angle == 0.0 ? $this->stateOf($type, $w, $l, $h) : null));
        }

        return true;
    }

    /**
     * The individual items of a placed block, bottom-up, as [type, x, y, z, width, length, height, angle]. The
     * items of an angled block are offset by the layout's pitch and positioned by their bounding boxes.
     *
     * @param  array<int, int|float>                                                         $candidate
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: float}>
     */
    private function itemsOf(array $candidate): array
    {
        $type = $candidate[self::C_TYPE];
        $x = $candidate[self::C_X];
        $y = $candidate[self::C_Y];
        $z = $candidate[self::C_Z];
        $items = [];
        [$ow, $ol, $oh, $nx, $ny, $nz] = [$candidate[self::C_OW], $candidate[self::C_OL], $candidate[self::C_OH], $candidate[self::C_NX], $candidate[self::C_NY], $candidate[self::C_NZ]];
        $angle = 0.0;
        if (isset($candidate[self::C_ANGLED])) {
            $layout = $this->angledLayouts[$candidate[self::C_ANGLED]];
            // mirroring the layout into the other direction turns the items the other way from the y axis
            $angle = $candidate[self::C_DIR] === 0 ? rad2deg($layout['angle']) : 90.0 - rad2deg($layout['angle']);
            $ow = $nx > 1 ? $layout['pitch'] : 0; // step between items; the items' own sides are long × short
            $ol = $ny > 1 ? $layout['pitch'] : 0;
        }
        for ($iz = 0; $iz < $nz; ++$iz) {
            for ($iy = 0; $iy < $ny; ++$iy) {
                for ($ix = 0; $ix < $nx; ++$ix) {
                    $items[] = [$type, $x + $ix * $ow, $y + $iy * $ol, $z + $iz * $oh, $candidate[self::C_OW], $candidate[self::C_OL], $oh, $angle];
                }
            }
        }

        return $items;
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
        ++$this->placements;
        $x = $candidate[self::C_X];
        $y = $candidate[self::C_Y];
        $z = $candidate[self::C_Z];
        $x2 = $x + $candidate[self::C_W];
        $y2 = $y + $candidate[self::C_L];
        $z2 = $z + $candidate[self::C_H];
        $type = $candidate[self::C_TYPE];

        if ($state->context !== null) {
            $next = [$type => $this->initialCounts[$type] - $state->counts[$type]];
            $this->appendItems($state->context, $candidate, $next);
        }

        $n = $candidate[self::C_NX] * $candidate[self::C_NY] * $candidate[self::C_NZ];
        $state->counts[$type] -= $n;
        $state->remaining -= $n;
        $state->weightLeft -= $n * $this->weights[$type];
        $angled = isset($candidate[self::C_ANGLED]);
        if ($angled) {
            // an angled block's top is only partly solid; offer what lies under it, and the empty corners as space
            [$supports, $corners] = $this->angledShape($candidate);
            foreach ($supports as [$rx, $ry, $rw, $rl]) {
                $state->tops[$z2][] = [$x + $rx, $y + $ry, $x + $rx + $rw, $y + $ry + $rl];
            }
        } else {
            $state->tops[$z2][] = [$x, $y, $x2, $y2];
        }
        // the filter only changes if the type just used up was the one setting one of its minimums
        if ($state->counts[$type] === 0 && ($this->minHeights[$type] <= $state->minHeight || $this->minFootprintEdges[$type] <= $state->minFootprintEdge || $this->minVolumes[$type] <= $state->minVolume)) {
            $this->updateSpaceFilter($state);
        }
        $state->spaces = $this->occupy($state, $x, $y, $z, $x2, $y2, $z2);
        if ($angled) {
            $zTop = $candidate[self::C_ZTOP];
            foreach ($corners as [$rx, $ry, $rw, $rl]) {
                if ($rw < $state->minFootprintEdge || $rl < $state->minFootprintEdge || $zTop - $z < $state->minHeight || $rw * $rl * ($zTop - $z) < $state->minVolume) {
                    continue;
                }
                $state->spaces[] = [$x + $rx, $y + $ry, $z, $x + $rx + $rw, $y + $ry + $rl, $zTop];
            }
        }
        $state->volume += $candidate[self::C_VOLUME];
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
        // A piece left over on one side of the block spans its parent space in the other two axes, which overlap
        // the block, so it can only lie inside another piece from the same side or inside an untouched space whose
        // face is flush with that side of the block. Only those need comparing.
        $kept = [];
        $flush = [[], [], [], [], [], []];
        $pieces = [[], [], [], [], [], []];
        foreach ($state->spaces as $space) {
            if ($bx1 >= $space[3] || $bx2 <= $space[0] || $by1 >= $space[4] || $by2 <= $space[1] || $bz1 >= $space[5] || $bz2 <= $space[2]) {
                // a dormant space resting on the new block's top may now be usable
                if (isset($space[6]) && $space[2] === $bz2 && $bx1 < $space[3] && $bx2 > $space[0] && $by1 < $space[4] && $by2 > $space[1]) {
                    unset($space[6]);
                }
                $kept[] = $space;
                if ($space[3] === $bx1) {
                    $flush[0][] = $space;
                }
                if ($space[0] === $bx2) {
                    $flush[1][] = $space;
                }
                if ($space[4] === $by1) {
                    $flush[2][] = $space;
                }
                if ($space[1] === $by2) {
                    $flush[3][] = $space;
                }
                if ($space[5] === $bz1) {
                    $flush[4][] = $space;
                }
                if ($space[2] === $bz2) {
                    $flush[5][] = $space;
                }
                continue;
            }
            [$x1, $y1, $z1, $x2, $y2, $z2] = $space;
            $dormant = isset($space[6]); // pieces of a dormant space stay dormant, except the new one above the block
            if ($bx1 > $x1) {
                $pieces[0][] = $dormant ? [$x1, $y1, $z1, $bx1, $y2, $z2, true] : [$x1, $y1, $z1, $bx1, $y2, $z2];
            }
            if ($bx2 < $x2) {
                $pieces[1][] = $dormant ? [$bx2, $y1, $z1, $x2, $y2, $z2, true] : [$bx2, $y1, $z1, $x2, $y2, $z2];
            }
            if ($by1 > $y1) {
                $pieces[2][] = $dormant ? [$x1, $y1, $z1, $x2, $by1, $z2, true] : [$x1, $y1, $z1, $x2, $by1, $z2];
            }
            if ($by2 < $y2) {
                $pieces[3][] = $dormant ? [$x1, $by2, $z1, $x2, $y2, $z2, true] : [$x1, $by2, $z1, $x2, $y2, $z2];
            }
            if ($bz1 > $z1) {
                $pieces[4][] = $dormant ? [$x1, $y1, $z1, $x2, $y2, $bz1, true] : [$x1, $y1, $z1, $x2, $y2, $bz1];
            }
            if ($bz2 < $z2) {
                $pieces[5][] = [$x1, $y1, $bz2, $x2, $y2, $z2];
            }
        }

        $minFootprintEdge = $state->minFootprintEdge;
        $minHeight = $state->minHeight;
        $minVolume = $state->minVolume;
        foreach ($pieces as $side => $sidePieces) {
            $pieceCount = count($sidePieces);
            for ($i = 0; $i < $pieceCount; ++$i) {
                [$x1, $y1, $z1, $x2, $y2, $z2] = $sidePieces[$i];
                if ($x2 - $x1 < $minFootprintEdge || $y2 - $y1 < $minFootprintEdge || $z2 - $z1 < $minHeight || ($x2 - $x1) * ($y2 - $y1) * ($z2 - $z1) < $minVolume) {
                    continue;
                }
                foreach ($flush[$side] as $other) {
                    if ($other[0] <= $x1 && $other[1] <= $y1 && $other[2] <= $z1 && $other[3] >= $x2 && $other[4] >= $y2 && $other[5] >= $z2) {
                        continue 2;
                    }
                }
                for ($j = 0; $j < $pieceCount; ++$j) {
                    $other = $sidePieces[$j];
                    if ($j !== $i && $other[0] <= $x1 && $other[1] <= $y1 && $other[2] <= $z1 && $other[3] >= $x2 && $other[4] >= $y2 && $other[5] >= $z2
                        && ($j > $i || $other[0] !== $x1 || $other[1] !== $y1 || $other[2] !== $z1 || $other[3] !== $x2 || $other[4] !== $y2 || $other[5] !== $z2)) {
                        continue 2; // inside another piece (of identical pieces, only the last is kept)
                    }
                }
                $kept[] = $sidePieces[$i];
            }
        }

        return $kept;
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
                $state->minVolume = min($state->minVolume, $this->minVolumes[$type]);
            }
        }
    }

    /**
     * @param array<int, int|float> $placement
     * @param array<int, int>       $next      per-type index of the next item object to hand out
     */
    private function appendItems(PackedItemList $list, array $placement, array &$next): void
    {
        foreach ($this->itemsOf($placement) as [$type, $x, $y, $z, $w, $l, $h, $angle]) {
            $index = $next[$type] ?? 0;
            $list->insert(new PackedItem($this->itemsByType[$type][$index], $x, $y, $z, $w, $l, $h, $angle, $angle == 0.0 ? $this->stateOf($type, $w, $l, $h) : null));
            $next[$type] = $index + 1;
        }
    }

    private function materialise(BlockSearchState $state): PackedBox
    {
        $list = new PackedItemList();
        $next = [];
        foreach ($state->placements as $placement) {
            $this->appendItems($list, $placement, $next);
        }

        $weightLeft = $state->weightLeft;
        foreach ($this->flatItems as $item) {
            $orientation = $this->buildOrientations($item)[0] ?? null;
            if ($orientation === null || $item->getWeight() > $weightLeft) {
                continue;
            }
            [$w, $l, $h, $state] = $orientation;
            if ($item instanceof ConstrainedPlacementItem && !$item->canBePacked(new PackedBox($this->box, $list), 0, 0, 0, $w, $l, $h)) {
                continue;
            }
            $list->insert(new PackedItem($item, 0, 0, 0, $w, $l, $h, state: $state));
            $weightLeft -= $item->getWeight();
        }

        return new PackedBox($this->box, $list);
    }

    /**
     * Distinct orientations of the item (in any of its states) that fit the empty box, as [width, length, height,
     * state], keeping only stable ones where there are any (same rule as the layer packer). Where a state has the
     * same dimensions as the item itself, the item's own shape is kept.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: ?ItemState}>
     */
    private function buildOrientations(Item $item): array
    {
        $fitting = [];
        foreach (ItemState::shapesOf($item, $this->useStates) as [$w, $l, $d, $rotation, $state]) {
            foreach ($rotation->permutations($w, $l, $d) as [$ow, $ol, $oh]) {
                if ($ow <= $this->boxWidth && $ol <= $this->boxLength && $oh <= $this->boxDepth) {
                    $fitting[$ow . '|' . $ol . '|' . $oh] ??= [$ow, $ol, $oh, $state];
                }
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

    /**
     * The state an item of this type is in when placed square with these dimensions (null for its own shape).
     * Angled placements only ever use the item's own shape.
     */
    private function stateOf(int $type, int $width, int $length, int $height): ?ItemState
    {
        return $this->orientationStates[$type][$width . '|' . $length . '|' . $height] ?? null;
    }

    /**
     * The ways up an item can go when turned at an angle, as [long side, short side, height], keeping only those
     * that fit the empty box in at least one direction (and only stable ones where there are any).
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function buildAngledUprights(Item $item): array
    {
        $fitting = [];
        foreach (AngledGeometry::uprights($item) as [$long, $short, $h]) {
            if ($h > $this->boxDepth) {
                continue;
            }
            $acrossWidth = $this->angledLayout($long, $short, $this->boxWidth);
            $acrossLength = $this->angledLayout($long, $short, $this->boxLength);
            if (($acrossWidth !== null && $this->angledLayouts[$acrossWidth]['itemLength'] <= $this->boxLength)
                || ($acrossLength !== null && $this->angledLayouts[$acrossLength]['itemLength'] <= $this->boxWidth)) {
                $fitting[$long . '|' . $short . '|' . $h] = [$long, $short, $h];
            }
        }

        if (!$this->preferStableOrientations) {
            return array_values($fitting);
        }

        $stable = [];
        foreach ($fitting as [$long, $short, $h]) {
            if ($h === $this->boxDepth || atan($short / $h) > self::STABILITY_ANGLE) {
                $stable[] = [$long, $short, $h];
            }
        }

        return $stable !== [] ? $stable : array_values($fitting);
    }

    /**
     * Id of the layout of parallel $long × $short items turned just enough to span no more than $span, or null.
     */
    private function angledLayout(int $long, int $short, int $span): ?int
    {
        $key = $long . '|' . $short . '|' . $span;
        if (!isset($this->angledLayoutIds[$key]) && !array_key_exists($key, $this->angledLayoutIds)) {
            $layout = AngledGeometry::layout($long, $short, $span);
            if ($layout === null) {
                $this->angledLayoutIds[$key] = null;
            } else {
                $this->angledLayoutIds[$key] = count($this->angledLayouts);
                $this->angledLayouts[] = [...$layout, 'long' => $long, 'short' => $short];
            }
        }

        return $this->angledLayoutIds[$key];
    }

    /**
     * Rectangles (relative to the block, as [x, y, width, length]) under the top of an angled block, and inscribed
     * in the empty triangles in its corners.
     *
     * @param  array<int, int|float>                                                                                 $candidate
     * @return array{0: list<array{0: int, 1: int, 2: int, 3: int}>, 1: list<array{0: int, 1: int, 2: int, 3: int}>}
     */
    private function angledShape(array $candidate): array
    {
        $direction = $candidate[self::C_DIR];
        $count = $direction === 0 ? $candidate[self::C_NY] : $candidate[self::C_NX];
        $key = $candidate[self::C_ANGLED] . '|' . $count . '|' . $direction;
        if (!isset($this->angledShapes[$key])) {
            $layout = $this->angledLayouts[$candidate[self::C_ANGLED]];
            $rowLength = $layout['itemLength'] + ($count - 1) * $layout['pitch'];
            $angle = rad2deg($layout['angle']);
            $items = [];
            for ($i = 0; $i < $count; ++$i) {
                $items[] = AngledGeometry::corners(0, $i * $layout['pitch'], $layout['long'], $layout['short'], $angle);
            }
            $supports = AngledGeometry::supportRectangles($items, 0, $rowLength);
            $corners = AngledGeometry::cornerRectangles(AngledGeometry::groupOutline($layout, $layout['long'], $layout['short'], $count), $layout['width'], $rowLength);
            if ($direction === 1) {
                // the layout is worked out spanning x; mirror it to span y
                $mirror = static fn (array $rectangle): array => [$rectangle[1], $rectangle[0], $rectangle[3], $rectangle[2]];
                $supports = array_map($mirror, $supports);
                $corners = array_map($mirror, $corners);
            }
            $this->angledShapes[$key] = [$supports, $corners];
        }

        return $this->angledShapes[$key];
    }

    /**
     * Precompute, per axis, which lengths can be made exactly from item edges (ignoring quantities), so the
     * unusable part of a gap left next to a block can be looked up.
     */
    private function buildFillableTables(): void
    {
        $limits = [$this->boxWidth, $this->boxLength, $this->boxDepth];
        if ($this->scoring !== 1 || max($limits) > self::MAX_FILLABLE_TABLE) {
            $this->scoring = 0; // tables would be too large; score on volume alone

            return;
        }
        foreach ([0, 1, 2] as $axis) {
            $edges = [];
            foreach ($this->orientations as $type => $orientations) {
                if ($this->initialCounts[$type] === 0) {
                    continue;
                }
                foreach ($orientations as $orientation) {
                    $edges[$orientation[$axis]] = true;
                }
            }
            $limit = $limits[$axis];
            ksort($edges);
            $cacheKey = $limit . ':' . implode(',', array_keys($edges));
            if (isset(self::$fillableCache[$cacheKey])) {
                $this->fillable[$axis] = self::$fillableCache[$cacheKey];
                continue;
            }
            $reachable = array_fill(0, $limit + 1, false);
            $reachable[0] = true;
            foreach (array_keys($edges) as $edge) {
                for ($length = $edge; $length <= $limit; ++$length) {
                    if (!$reachable[$length] && $reachable[$length - $edge]) {
                        $reachable[$length] = true;
                    }
                }
            }
            $best = 0;
            $table = [];
            for ($length = 0; $length <= $limit; ++$length) {
                if ($reachable[$length]) {
                    $best = $length;
                }
                $table[$length] = $best;
            }
            $this->fillable[$axis] = $table;
            if (count(self::$fillableCache) >= self::FILLABLE_CACHE_SIZE) {
                self::$fillableCache = [];
            }
            self::$fillableCache[$cacheKey] = $table;
        }
    }
}
