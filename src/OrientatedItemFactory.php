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

use function count;
use function usort;
use function array_filter;

/**
 * Figure out orientations for an item and a given set of dimensions.
 * @internal
 */
class OrientatedItemFactory implements LoggerAwareInterface
{
    protected LoggerInterface $logger;

    protected bool $singlePassMode = false;

    protected bool $boxIsRotated = false;

    protected bool $useStates = true;

    /**
     * Cached X/Y-swapped packed context for ConstrainedPlacementItem when the box is tried rotated.
     * Invalidated when the source list identity or item count changes (list only grows via insert).
     */
    private ?PackedItemList $rotatedContextSource = null;

    private int $rotatedContextCount = -1;

    private ?PackedBox $rotatedContextPackedBox = null;

    /**
     * @var array<string, bool>
     */
    protected static array $emptyBoxStableItemOrientationCache = [];

    public function __construct(protected Box $box)
    {
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setSinglePassMode(bool $singlePassMode): void
    {
        $this->singlePassMode = $singlePassMode;
    }

    public function setBoxIsRotated(bool $boxIsRotated): void
    {
        $this->boxIsRotated = $boxIsRotated;
    }

    /**
     * Whether items may be placed in their states (see {@see ReshapableItem}) as well as in their own shape.
     */
    public function setUseStates(bool $useStates): void
    {
        $this->useStates = $useStates;
    }

    /**
     * Get the best orientation for an item.
     */
    public function getBestOrientation(
        Item $item,
        ?OrientatedItem $prevItem,
        ItemList $nextItems,
        int $widthLeft,
        int $lengthLeft,
        int $depthLeft,
        int $rowLength,
        int $x,
        int $y,
        int $z,
        PackedItemList $prevPackedItemList,
        bool $considerStability
    ): ?OrientatedItem {
        $this->logger->debug(
            "evaluating item {$item->getDescription()} for fit",
            [
                'item' => $item,
                'space' => [
                    'widthLeft' => $widthLeft,
                    'lengthLeft' => $lengthLeft,
                    'depthLeft' => $depthLeft,
                ],
                'position' => [
                    'x' => $x,
                    'y' => $y,
                    'z' => $z,
                ],
            ]
        );

        $possibleOrientations = $this->getPossibleOrientations($item, $prevItem, $widthLeft, $lengthLeft, $depthLeft, $x, $y, $z, $prevPackedItemList);
        $usableOrientations = $considerStability ? $this->getUsableOrientations($item, $possibleOrientations) : $possibleOrientations;

        if (empty($usableOrientations)) {
            return null;
        }

        $sorter = new OrientatedItemSorter($this, $this->singlePassMode, $widthLeft, $lengthLeft, $depthLeft, $nextItems, $rowLength, $x, $y, $z, $prevPackedItemList, $this->logger);
        usort($usableOrientations, $sorter);

        $this->logger->debug('Selected best fit orientation', ['orientation' => $usableOrientations[0]]);

        return $usableOrientations[0];
    }

    /**
     * Find all possible orientations for an item.
     *
     * @return OrientatedItem[]
     */
    public function getPossibleOrientations(
        Item $item,
        ?OrientatedItem $prevItem,
        int $widthLeft,
        int $lengthLeft,
        int $depthLeft,
        int $x,
        int $y,
        int $z,
        PackedItemList $prevPackedItemList
    ): array {
        $permutations = $this->generatePermutations($item, $prevItem);

        // remove any that simply don't fit
        $orientations = [];
        foreach ($permutations as [$width, $length, $depth, $state]) {
            if ($width <= $widthLeft && $length <= $lengthLeft && $depth <= $depthLeft) {
                $orientations[] = new OrientatedItem($item, $width, $length, $depth, $state);
            }
        }

        if ($item instanceof ConstrainedPlacementItem && !$this->box instanceof WorkingVolume) {
            /** @var ConstrainedPlacementItem $constrainedItem */
            $constrainedItem = $item;

            if ($this->boxIsRotated) {
                $packedBox = $this->getRotatedContextPackedBox($prevPackedItemList);
                $propX = $y;
                $propY = $x;
            } else {
                $packedBox = new PackedBox($this->box, $prevPackedItemList);
                $propX = $x;
                $propY = $y;
            }

            $filtered = [];
            foreach ($orientations as $orientation) {
                if ($this->boxIsRotated) {
                    $ok = $constrainedItem->canBePacked(
                        $packedBox,
                        $propX,
                        $propY,
                        $z,
                        $orientation->length,
                        $orientation->width,
                        $orientation->depth
                    );
                } else {
                    $ok = $constrainedItem->canBePacked(
                        $packedBox,
                        $propX,
                        $propY,
                        $z,
                        $orientation->width,
                        $orientation->length,
                        $orientation->depth
                    );
                }
                if ($ok) {
                    $filtered[] = $orientation;
                }
            }
            $orientations = $filtered;
        }

        return $orientations;
    }

    /**
     * @param  OrientatedItem[] $possibleOrientations
     * @return OrientatedItem[]
     */
    protected function getUsableOrientations(
        Item $item,
        array $possibleOrientations
    ): array {
        $stableOrientations = $unstableOrientations = [];

        // Divide possible orientations into stable (low centre of gravity) and unstable (high centre of gravity)
        foreach ($possibleOrientations as $orientation) {
            if ($orientation->isStable() || $this->box->getInnerDepth() === $orientation->depth) {
                $stableOrientations[] = $orientation;
            } else {
                $unstableOrientations[] = $orientation;
            }
        }

        /*
         * We prefer to use stable orientations only, but allow unstable ones if
         * the item doesn't fit in the box any other way
         */
        if (count($stableOrientations) > 0) {
            return $stableOrientations;
        }

        if ((count($unstableOrientations) > 0) && !$this->hasStableOrientationsInEmptyBox($item)) {
            return $unstableOrientations;
        }

        return [];
    }

    /**
     * Return the orientations for this item if it were to be placed into the box with nothing else.
     */
    protected function hasStableOrientationsInEmptyBox(Item $item): bool
    {
        $cacheKey = $item->getWidth() .
            '|' .
            $item->getLength() .
            '|' .
            $item->getDepth() .
            '|' .
            $item->getAllowedRotation()->name .
            ($this->useStates ? ItemState::signatureOf($item) : '') .
            '|' .
            $this->box->getInnerWidth() .
            '|' .
            $this->box->getInnerLength() .
            '|' .
            $this->box->getInnerDepth();

        if (isset(static::$emptyBoxStableItemOrientationCache[$cacheKey])) {
            return static::$emptyBoxStableItemOrientationCache[$cacheKey];
        }

        $orientations = $this->getPossibleOrientations(
            $item,
            null,
            $this->box->getInnerWidth(),
            $this->box->getInnerLength(),
            $this->box->getInnerDepth(),
            0,
            0,
            0,
            new PackedItemList()
        );

        $stableOrientations = array_filter(
            $orientations,
            static fn (OrientatedItem $orientation) => $orientation->isStable()
        );
        static::$emptyBoxStableItemOrientationCache[$cacheKey] = count($stableOrientations) > 0;

        return static::$emptyBoxStableItemOrientationCache[$cacheKey];
    }

    /**
     * Build (or reuse) a PackedBox whose items have X/Y swapped to match a rotated box try.
     */
    private function getRotatedContextPackedBox(PackedItemList $prevPackedItemList): PackedBox
    {
        $count = $prevPackedItemList->count();
        if (
            $this->rotatedContextPackedBox !== null
            && $this->rotatedContextSource === $prevPackedItemList
            && $this->rotatedContextCount === $count
        ) {
            return $this->rotatedContextPackedBox;
        }

        $contextItems = new PackedItemList();
        foreach ($prevPackedItemList as $prevPackedItem) {
            $contextItems->insert(new PackedItem(
                $prevPackedItem->item,
                $prevPackedItem->y,
                $prevPackedItem->x,
                $prevPackedItem->z,
                $prevPackedItem->length,
                $prevPackedItem->width,
                $prevPackedItem->depth,
                state: $prevPackedItem->state,
            ));
        }

        $this->rotatedContextSource = $prevPackedItemList;
        $this->rotatedContextCount = $count;
        $this->rotatedContextPackedBox = new PackedBox($this->box, $contextItems);

        return $this->rotatedContextPackedBox;
    }

    /**
     * The ways the item can be placed, as [width, length, depth, state], in each of its shapes (its own dimensions
     * with a null state, then its states, see {@see ReshapableItem}).
     *
     * @return list<array{0: int, 1: int, 2: int, 3: ?ItemState}>
     */
    private function generatePermutations(Item $item, ?OrientatedItem $prevItem): array
    {
        // Special case items that are the same as what we just packed - keep orientation (if this item may be packed that way)
        if ($prevItem !== null && $prevItem->isSameDimensions($item) && ($prevItem->state === null || $prevItem->item === $item)) {
            $state = $prevItem->state;
            $depth = $state?->depth ?? $item->getDepth();
            $sameWayRound = match ($state?->getAllowedRotation($item) ?? $item->getAllowedRotation()) {
                Rotation::BestFit => true,
                Rotation::KeepFlat => $prevItem->depth === $depth,
                Rotation::Never => $prevItem->width === ($state?->width ?? $item->getWidth()) && $prevItem->length === ($state?->length ?? $item->getLength()) && $prevItem->depth === $depth,
            };
            if ($sameWayRound) {
                return [[$prevItem->width, $prevItem->length, $prevItem->depth, $state]];
            }
        }

        $permutations = [];
        foreach (ItemState::shapesOf($item, $this->useStates) as [$w, $l, $d, $rotation, $state]) {
            foreach ($rotation->permutations($w, $l, $d) as [$width, $length, $depth]) {
                $permutations[] = [$width, $length, $depth, $state];
            }
        }

        return $permutations;
    }
}
