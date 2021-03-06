<?php
/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use function array_filter;
use function count;
use function min;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use function usort;

/**
 * Figure out orientations for an item and a given set of dimensions.
 *
 * @author Doug Wright
 * @internal
 */
class OrientatedItemFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var Box */
    protected $box;

    /**
     * Whether the packer is in single-pass mode.
     *
     * @var bool
     */
    protected $singlePassMode = false;

    /**
     * @var OrientatedItem[]
     */
    protected static $emptyBoxCache = [];

    /**
     * @var int[]
     */
    protected static $lookaheadCache = [];

    public function __construct(Box $box)
    {
        $this->box = $box;
        $this->logger = new NullLogger();
    }

    public function setSinglePassMode(bool $singlePassMode): void
    {
        $this->singlePassMode = $singlePassMode;
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
        PackedItemList $prevPackedItemList
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
            ]
        );

        $possibleOrientations = $this->getPossibleOrientations($item, $prevItem, $widthLeft, $lengthLeft, $depthLeft, $x, $y, $z, $prevPackedItemList);
        $usableOrientations = $this->getUsableOrientations($item, $possibleOrientations);

        if (empty($usableOrientations)) {
            return null;
        }

        usort($usableOrientations, function (OrientatedItem $a, OrientatedItem $b) use ($widthLeft, $lengthLeft, $depthLeft, $nextItems, $rowLength, $x, $y, $z, $prevPackedItemList) {
            //Prefer exact fits in width/length/depth order
            $orientationAWidthLeft = $widthLeft - $a->getWidth();
            $orientationBWidthLeft = $widthLeft - $b->getWidth();
            if ($orientationAWidthLeft === 0 && $orientationBWidthLeft > 0) {
                return -1;
            }
            if ($orientationBWidthLeft === 0 && $orientationAWidthLeft > 0) {
                return 1;
            }

            $orientationALengthLeft = $lengthLeft - $a->getLength();
            $orientationBLengthLeft = $lengthLeft - $b->getLength();
            if ($orientationALengthLeft === 0 && $orientationBLengthLeft > 0) {
                return -1;
            }
            if ($orientationBLengthLeft === 0 && $orientationALengthLeft > 0) {
                return 1;
            }

            $orientationADepthLeft = $depthLeft - $a->getDepth();
            $orientationBDepthLeft = $depthLeft - $b->getDepth();
            if ($orientationADepthLeft === 0 && $orientationBDepthLeft > 0) {
                return -1;
            }
            if ($orientationBDepthLeft === 0 && $orientationADepthLeft > 0) {
                return 1;
            }

            // prefer leaving room for next item(s)
            $followingItemDecider = $this->lookAheadDecider($nextItems, $a, $b, $orientationAWidthLeft, $orientationBWidthLeft, $widthLeft, $lengthLeft, $depthLeft, $rowLength, $x, $y, $z, $prevPackedItemList);
            if ($followingItemDecider !== 0) {
                return $followingItemDecider;
            }

            // otherwise prefer leaving minimum possible gap, or the greatest footprint
            $orientationAMinGap = min($orientationAWidthLeft, $orientationALengthLeft);
            $orientationBMinGap = min($orientationBWidthLeft, $orientationBLengthLeft);

            return $orientationAMinGap <=> $orientationBMinGap ?: $a->getSurfaceFootprint() <=> $b->getSurfaceFootprint();
        });

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
        $orientations = $orientationsDimensions = [];

        //Special case items that are the same as what we just packed - keep orientation
        if ($prevItem && $prevItem->isSameDimensions($item)) {
            $orientationsDimensions[] = [$prevItem->getWidth(), $prevItem->getLength(), $prevItem->getDepth()];
        } else {
            //simple 2D rotation
            $orientationsDimensions[] = [$item->getWidth(), $item->getLength(), $item->getDepth()];
            $orientationsDimensions[] = [$item->getLength(), $item->getWidth(), $item->getDepth()];

            //add 3D rotation if we're allowed
            if (!$item->getKeepFlat()) {
                $orientationsDimensions[] = [$item->getWidth(), $item->getDepth(), $item->getLength()];
                $orientationsDimensions[] = [$item->getLength(), $item->getDepth(), $item->getWidth()];
                $orientationsDimensions[] = [$item->getDepth(), $item->getWidth(), $item->getLength()];
                $orientationsDimensions[] = [$item->getDepth(), $item->getLength(), $item->getWidth()];
            }
        }

        //remove any that simply don't fit
        foreach ($orientationsDimensions as $dimensions) {
            if ($dimensions[0] <= $widthLeft && $dimensions[1] <= $lengthLeft && $dimensions[2] <= $depthLeft) {
                $orientations[] = new OrientatedItem($item, $dimensions[0], $dimensions[1], $dimensions[2]);
            }
        }

        if ($item instanceof ConstrainedPlacementItem) {
            $orientations = array_filter($orientations, function (OrientatedItem $i) use ($x, $y, $z, $prevPackedItemList) {
                return $i->getItem()->canBePacked($this->box, $prevPackedItemList, $x, $y, $z, $i->getWidth(), $i->getLength(), $i->getDepth());
            });
        }

        return $orientations;
    }

    /**
     * @return OrientatedItem[]
     */
    public function getPossibleOrientationsInEmptyBox(Item $item): array
    {
        $cacheKey = $item->getWidth() .
            '|' .
            $item->getLength() .
            '|' .
            $item->getDepth() .
            '|' .
            ($item->getKeepFlat() ? '2D' : '3D') .
            '|' .
            $this->box->getInnerWidth() .
            '|' .
            $this->box->getInnerLength() .
            '|' .
            $this->box->getInnerDepth();

        if (isset(static::$emptyBoxCache[$cacheKey])) {
            $orientations = static::$emptyBoxCache[$cacheKey];
        } else {
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
            static::$emptyBoxCache[$cacheKey] = $orientations;
        }

        return $orientations;
    }

    /**
     * @param OrientatedItem[] $possibleOrientations
     *
     * @return OrientatedItem[]
     */
    protected function getUsableOrientations(
        Item $item,
        array $possibleOrientations
    ): array {
        $orientationsToUse = $stableOrientations = $unstableOrientations = [];

        // Divide possible orientations into stable (low centre of gravity) and unstable (high centre of gravity)
        foreach ($possibleOrientations as $orientation) {
            if ($orientation->isStable() || $this->box->getInnerDepth() === $orientation->getDepth()) {
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
            $orientationsToUse = $stableOrientations;
        } elseif (count($unstableOrientations) > 0) {
            $stableOrientationsInEmptyBox = $this->getStableOrientationsInEmptyBox($item);

            if (count($stableOrientationsInEmptyBox) === 0) {
                $orientationsToUse = $unstableOrientations;
            }
        }

        return $orientationsToUse;
    }

    /**
     * Return the orientations for this item if it were to be placed into the box with nothing else.
     */
    protected function getStableOrientationsInEmptyBox(Item $item): array
    {
        $orientationsInEmptyBox = $this->getPossibleOrientationsInEmptyBox($item);

        return array_filter(
            $orientationsInEmptyBox,
            function (OrientatedItem $orientation) {
                return $orientation->isStable();
            }
        );
    }

    /**
     * Approximation of a forward-looking packing.
     *
     * Not an actual packing, that has additional logic regarding constraints and stackability, this focuses
     * purely on fit.
     */
    protected function calculateAdditionalItemsPackedWithThisOrientation(
        OrientatedItem $prevItem,
        ItemList $nextItems,
        int $originalWidthLeft,
        int $originalLengthLeft,
        int $depthLeft,
        int $currentRowLengthBeforePacking
    ): int {
        if ($this->singlePassMode) {
            return 0;
        }

        $currentRowLength = max($prevItem->getLength(), $currentRowLengthBeforePacking);

        $itemsToPack = $nextItems->topN(8); // cap lookahead as this gets recursive and slow

        $cacheKey = $originalWidthLeft .
            '|' .
            $originalLengthLeft .
            '|' .
            $prevItem->getWidth() .
            '|' .
            $prevItem->getLength() .
            '|' .
            $currentRowLength .
            '|'
            . $depthLeft;

        /** @var Item $itemToPack */
        foreach ($itemsToPack as $itemToPack) {
            $cacheKey .= '|' .
                $itemToPack->getWidth() .
                '|' .
                $itemToPack->getLength() .
                '|' .
                $itemToPack->getDepth() .
                '|' .
                $itemToPack->getWeight() .
                '|' .
                ($itemToPack->getKeepFlat() ? '1' : '0');
        }

        if (!isset(static::$lookaheadCache[$cacheKey])) {
            $tempBox = new WorkingVolume($originalWidthLeft - $prevItem->getWidth(), $currentRowLength, $depthLeft, PHP_INT_MAX);
            $tempPacker = new VolumePacker($tempBox, $itemsToPack);
            $tempPacker->setSinglePassMode(true);
            $remainingRowPacked = $tempPacker->pack();
            /** @var PackedItem $packedItem */
            foreach ($remainingRowPacked->getItems() as $packedItem) {
                $itemsToPack->remove($packedItem->getItem());
            }

            $tempBox = new WorkingVolume($originalWidthLeft, $originalLengthLeft - $currentRowLength, $depthLeft, PHP_INT_MAX);
            $tempPacker = new VolumePacker($tempBox, $itemsToPack);
            $tempPacker->setSinglePassMode(true);
            $nextRowsPacked = $tempPacker->pack();
            /** @var PackedItem $packedItem */
            foreach ($nextRowsPacked->getItems() as $packedItem) {
                $itemsToPack->remove($packedItem->getItem());
            }

            $packedCount = $nextItems->count() - $itemsToPack->count();
            $this->logger->debug('Lookahead with orientation', ['packedCount' => $packedCount, 'orientatedItem' => $prevItem]);

            static::$lookaheadCache[$cacheKey] = $packedCount;
        }

        return static::$lookaheadCache[$cacheKey];
    }

    private function lookAheadDecider(ItemList $nextItems, OrientatedItem $a, OrientatedItem $b, int $orientationAWidthLeft, int $orientationBWidthLeft, int $widthLeft, int $lengthLeft, int $depthLeft, int $rowLength, int $x, int $y, int $z, PackedItemList $prevPackedItemList): int
    {
        if ($nextItems->count() === 0) {
            return 0;
        }

        $nextItemFitA = $this->getPossibleOrientations($nextItems->top(), $a, $orientationAWidthLeft, $lengthLeft, $depthLeft, $x, $y, $z, $prevPackedItemList);
        $nextItemFitB = $this->getPossibleOrientations($nextItems->top(), $b, $orientationBWidthLeft, $lengthLeft, $depthLeft, $x, $y, $z, $prevPackedItemList);
        if ($nextItemFitA && !$nextItemFitB) {
            return -1;
        }
        if ($nextItemFitB && !$nextItemFitA) {
            return 1;
        }

        // if not an easy either/or, do a partial lookahead
        $additionalPackedA = $this->calculateAdditionalItemsPackedWithThisOrientation($a, $nextItems, $widthLeft, $lengthLeft, $depthLeft, $rowLength);
        $additionalPackedB = $this->calculateAdditionalItemsPackedWithThisOrientation($b, $nextItems, $widthLeft, $lengthLeft, $depthLeft, $rowLength);

        return $additionalPackedB <=> $additionalPackedA ?: 0;
    }
}
