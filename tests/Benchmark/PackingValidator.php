<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Benchmark;

use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedItem;
use DVDoug\BoxPacker\Rotation;
use DVDoug\BoxPacker\Test\BischoffConstrainedTestItem;

use function count;
use function iterator_to_array;
use function max;
use function min;
use function sort;

/**
 * Independent checks that a packed box is physically valid, used by the benchmark and the tests.
 */
final class PackingValidator
{
    /**
     * @return list<string> problems found (empty = valid)
     */
    public static function problems(PackedBox $packedBox): array
    {
        $problems = [];
        $box = $packedBox->box;
        /** @var list<PackedItem> $items */
        $items = iterator_to_array($packedBox->items, false);

        if ($packedBox->getWeight() > $box->getMaxWeight()) {
            $problems[] = "overweight {$packedBox->getWeight()} > {$box->getMaxWeight()}";
        }

        $count = count($items);
        for ($i = 0; $i < $count; ++$i) {
            $a = $items[$i];
            if ($a->x < 0 || $a->y < 0 || $a->z < 0 || $a->x + $a->width > $box->getInnerWidth() || $a->y + $a->length > $box->getInnerLength() || $a->z + $a->depth > $box->getInnerDepth()) {
                $problems[] = "item {$i} out of bounds";
            }
            if (!self::orientationAllowed($a)) {
                $problems[] = "item {$i} in a disallowed orientation";
            }
            if ($a->item instanceof BischoffConstrainedTestItem && !$a->item->canBePacked($packedBox, $a->x, $a->y, $a->z, $a->width, $a->length, $a->depth)) {
                $problems[] = "item {$i} stood on a disallowed edge";
            }
            for ($j = $i + 1; $j < $count; ++$j) {
                $b = $items[$j];
                if ($a->x < $b->x + $b->width && $b->x < $a->x + $a->width
                    && $a->y < $b->y + $b->length && $b->y < $a->y + $a->length
                    && $a->z < $b->z + $b->depth && $b->z < $a->z + $a->depth) {
                    $problems[] = "items {$i} and {$j} overlap";
                }
            }
        }

        return $problems;
    }

    /**
     * Smallest fraction of any item's base resting on the floor or on the top of another item.
     */
    public static function minimumSupport(PackedBox $packedBox): float
    {
        /** @var list<PackedItem> $items */
        $items = iterator_to_array($packedBox->items, false);
        $minimum = 1.0;
        foreach ($items as $item) {
            if ($item->z === 0) {
                continue;
            }
            $supported = 0;
            foreach ($items as $other) {
                if ($other->z + $other->depth !== $item->z) {
                    continue;
                }
                $ix = min($item->x + $item->width, $other->x + $other->width) - max($item->x, $other->x);
                $iy = min($item->y + $item->length, $other->y + $other->length) - max($item->y, $other->y);
                if ($ix > 0 && $iy > 0) {
                    $supported += $ix * $iy;
                }
            }
            $minimum = min($minimum, $supported / ($item->width * $item->length));
        }

        return $minimum;
    }

    private static function orientationAllowed(PackedItem $packedItem): bool
    {
        $item = $packedItem->item;
        $packed = [$packedItem->width, $packedItem->length, $packedItem->depth];
        $defined = [$item->getWidth(), $item->getLength(), $item->getDepth()];
        $sortedPacked = $packed;
        $sortedDefined = $defined;
        sort($sortedPacked);
        sort($sortedDefined);
        if ($sortedPacked !== $sortedDefined) {
            return false;
        }

        return match ($item->getAllowedRotation()) {
            Rotation::Never => $packed === $defined,
            Rotation::KeepFlat => $packedItem->depth === $item->getDepth(),
            Rotation::BestFit => true,
        };
    }
}
