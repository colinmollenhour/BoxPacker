<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use JsonSerializable;

use function iterator_to_array;
use function json_encode;
use function max;
use function min;
use function round;
use function is_iterable;
use function count;
use function array_pop;
use function assert;
use function array_map;
use function spl_object_id;
use function rawurlencode;
use function sort;

use const JSON_THROW_ON_ERROR;
use const JSON_NUMERIC_CHECK;
use const JSON_UNESCAPED_UNICODE;
use const JSON_UNESCAPED_SLASHES;

/**
 * A "box" with items.
 */
readonly class PackedBox implements JsonSerializable
{
    protected int $itemWeight;

    protected float $volumeUtilisation;

    /**
     * Get packed weight.
     *
     * @return int weight in grams
     */
    public function getWeight(): int
    {
        return $this->box->getEmptyWeight() + $this->getItemWeight();
    }

    /**
     * Get packed weight of the items only.
     *
     * @return int weight in grams
     */
    public function getItemWeight(): int
    {
        if (!isset($this->itemWeight)) {
            $itemWeight = 0;
            foreach ($this->items as $item) {
                $itemWeight += $item->item->getWeight();
            }
            $this->itemWeight = $itemWeight;
        }

        return $this->itemWeight;
    }

    /**
     * Get remaining width inside box for another item: inner width less used width (negative if items hang over the
     * sides of a box with an allowed overhang by more than is left).
     */
    public function getRemainingWidth(): int
    {
        return $this->box->getInnerWidth() - $this->getUsedWidth();
    }

    /**
     * Get remaining length inside box for another item: inner length less used length (negative if items hang over
     * the front or back of a box with an allowed overhang by more than is left).
     */
    public function getRemainingLength(): int
    {
        return $this->box->getInnerLength() - $this->getUsedLength();
    }

    /**
     * Get remaining depth inside box for another item.
     */
    public function getRemainingDepth(): int
    {
        return $this->box->getInnerDepth() - $this->getUsedDepth();
    }

    /**
     * Used width inside box for packing items: from x = 0 (or the leftmost item, if it hangs over the left side of a
     * box with an allowed overhang) to the rightmost item. A load that a box with an allowed overhang has moved off
     * x = 0 to balance its overhang counts the gap at the left as used.
     */
    public function getUsedWidth(): int
    {
        $minX = 0;
        $maxWidth = 0;

        foreach ($this->items as $item) {
            $minX = min($minX, $item->x);
            $maxWidth = max($maxWidth, $item->x + $item->boundingWidth);
        }

        return $maxWidth - $minX;
    }

    /**
     * Used length inside box for packing items: from y = 0 (or the frontmost item, if it hangs over the front of a box
     * with an allowed overhang) to the backmost item. As with the width, a gap at the front counts as used.
     */
    public function getUsedLength(): int
    {
        $minY = 0;
        $maxLength = 0;

        foreach ($this->items as $item) {
            $minY = min($minY, $item->y);
            $maxLength = max($maxLength, $item->y + $item->boundingLength);
        }

        return $maxLength - $minY;
    }

    /**
     * Used depth inside box for packing items.
     */
    public function getUsedDepth(): int
    {
        $maxDepth = 0;

        foreach ($this->items as $item) {
            $maxDepth = max($maxDepth, $item->z + $item->depth);
        }

        return $maxDepth;
    }

    /**
     * Get remaining weight inside box for another item.
     */
    public function getRemainingWeight(): int
    {
        return $this->box->getMaxWeight() - $this->getWeight();
    }

    public function getInnerVolume(): int
    {
        return $this->box->getInnerWidth() * $this->box->getInnerLength() * $this->box->getInnerDepth();
    }

    /**
     * Get used volume of the packed box.
     */
    public function getUsedVolume(): int
    {
        return $this->items->getVolume();
    }

    /**
     * Get unused volume of the packed box (negative if items hanging over the edges of a box with an allowed overhang
     * take up more than its inner volume).
     */
    public function getUnusedVolume(): int
    {
        return $this->getInnerVolume() - $this->getUsedVolume();
    }

    /**
     * Get volume utilisation of the packed box, as a percentage of its inner volume (so over 100 if items hanging over
     * the edges of a box with an allowed overhang take up more than that).
     */
    public function getVolumeUtilisation(): float
    {
        if (!isset($this->volumeUtilisation)) {
            $this->volumeUtilisation = round($this->getUsedVolume() / ($this->getInnerVolume() ?: 1) * 100, 1);
        }

        return $this->volumeUtilisation;
    }

    /**
     * Create a custom website visualiser URL for this packing.
     */
    public function generateVisualisationURL(): string
    {
        $dedupedItems = $splIdToIntMap = [];
        $splIdIndex = 0;
        foreach ($this->items->asItemArray() as $item) {
            if (!isset($splIdToIntMap[spl_object_id($item)])) {
                $splIdToIntMap[spl_object_id($item)] = $splIdIndex++;
            }
            $dedupedItems[$splIdToIntMap[spl_object_id($item)]] = $item;
        }

        foreach ($dedupedItems as $item) {
            $data['items'][$splIdToIntMap[spl_object_id($item)]] = [$item->getDescription(), $item->getWidth(), $item->getLength(), $item->getDepth()];
        }

        $data['boxes'][] = [
            $this->box->getReference(),
            $this->box->getInnerWidth(),
            $this->box->getInnerLength(),
            $this->box->getInnerDepth(),
            array_map(
                static fn (PackedItem $item) => [$splIdToIntMap[spl_object_id($item->item)], $item->x, $item->y, $item->z, $item->width, $item->length, $item->depth, ...($item->isAngled() ? [round($item->angle, 3)] : [])],
                iterator_to_array($this->items)
            ),
            ...OverhangBounds::visualiserElements($this->box),
        ];

        return 'https://boxpacker.io/en/master/visualiser.html#packing=' . rawurlencode(json_encode($data, flags: JSON_THROW_ON_ERROR | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function __construct(public Box $box, public PackedItemList $items)
    {
        assert($this->assertPackingCompliesWithRealWorld());
    }

    public function jsonSerialize(): array
    {
        $userValues = [];

        if ($this->box instanceof JsonSerializable) {
            $userSerialisation = $this->box->jsonSerialize();
            if (is_iterable($userSerialisation)) {
                $userValues = $userSerialisation;
            } else {
                $userValues = ['extra' => $userSerialisation];
            }
        }

        $overhang = [];
        if ($this->box instanceof OverhangBox && OverhangBounds::forBox($this->box) !== null) {
            $overhang = ['allowedOverhang' => $this->box->getAllowedOverhang()];
        }

        return [
            'box' => [
                ...$userValues,
                'reference' => $this->box->getReference(),
                'innerWidth' => $this->box->getInnerWidth(),
                'innerLength' => $this->box->getInnerLength(),
                'innerDepth' => $this->box->getInnerDepth(),
                ...$overhang,
            ],
            'items' => iterator_to_array($this->items),
        ];
    }

    /**
     * Geometric and weight invariants of a finished box: in-bounds (within the caps
     * of any allowed overhang), no overlaps (among items with a volume), legal item
     * orientation, under max weight.
     *
     * Placement callbacks and linked-group membership need the live unpacked list
     * and are enforced at pack time, not here.
     */
    private function assertPackingCompliesWithRealWorld(): true
    {
        assert($this->getWeight() <= $this->box->getMaxWeight());

        $overhang = OverhangBounds::forBox($this->box);

        /** @var PackedItem[] $itemsToCheck */
        $itemsToCheck = iterator_to_array($this->items);
        while (count($itemsToCheck) > 0) {
            $itemToCheck = array_pop($itemsToCheck);

            if ($overhang !== null) {
                assert($overhang->admitsPackedItem($itemToCheck));
            } else {
                assert($itemToCheck->x >= 0);
                assert($itemToCheck->x + $itemToCheck->boundingWidth <= $this->box->getInnerWidth());
                assert($itemToCheck->y >= 0);
                assert($itemToCheck->y + $itemToCheck->boundingLength <= $this->box->getInnerLength());
                assert($itemToCheck->z >= 0);
                assert($itemToCheck->z + $itemToCheck->depth <= $this->box->getInnerDepth());
            }
            assert($this->packedDimensionsMatchItem($itemToCheck));

            // an item with no volume (a zero width, length or depth) takes no space, so cannot collide with another
            if ($itemToCheck->width * $itemToCheck->length * $itemToCheck->depth === 0) {
                continue;
            }
            foreach ($itemsToCheck as $otherItem) {
                if ($otherItem->width * $otherItem->length * $otherItem->depth === 0) {
                    continue;
                }
                $hasXOverlap = $itemToCheck->x < ($otherItem->x + $otherItem->boundingWidth) && $otherItem->x < ($itemToCheck->x + $itemToCheck->boundingWidth);
                $hasYOverlap = $itemToCheck->y < ($otherItem->y + $otherItem->boundingLength) && $otherItem->y < ($itemToCheck->y + $itemToCheck->boundingLength);
                $hasZOverlap = $itemToCheck->z < ($otherItem->z + $otherItem->depth) && $otherItem->z < ($itemToCheck->z + $itemToCheck->depth);

                $hasOverlap = $hasXOverlap && $hasYOverlap && $hasZOverlap;
                if ($hasOverlap && ($itemToCheck->isAngled() || $otherItem->isAngled())) {
                    // the bounding boxes overlap, but angled items only occupy part of theirs
                    $hasOverlap = AngledGeometry::polygonsOverlap(AngledGeometry::footprint($itemToCheck), AngledGeometry::footprint($otherItem));
                }
                assert(!$hasOverlap);
            }
        }

        return true;
    }

    /**
     * Packed edges must be the item's own dimensions, permuted only as its Rotation allows.
     */
    private function packedDimensionsMatchItem(PackedItem $packedItem): bool
    {
        $item = $packedItem->item;
        $packed = [$packedItem->width, $packedItem->length, $packedItem->depth];
        $defined = [$item->getWidth(), $item->getLength(), $item->getDepth()];
        $packedSorted = $packed;
        $definedSorted = $defined;
        sort($packedSorted);
        sort($definedSorted);
        if ($packedSorted !== $definedSorted) {
            return false;
        }

        return match ($item->getAllowedRotation()) {
            Rotation::Never => $packed === $defined && !$packedItem->isAngled(),
            Rotation::KeepFlat => $packedItem->depth === $item->getDepth(),
            Rotation::BestFit => true,
        };
    }
}
