<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Benchmark;

use DVDoug\BoxPacker\AllowedOverhang;
use DVDoug\BoxPacker\AngledGeometry;
use DVDoug\BoxPacker\OverhangBox;
use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedItem;
use DVDoug\BoxPacker\Rotation;
use DVDoug\BoxPacker\Test\BischoffConstrainedTestItem;

use function count;
use function intdiv;
use function iterator_to_array;
use function max;
use function min;
use function sort;

/**
 * Independent checks that a packed box is physically valid, used by the benchmark and the tests.
 *
 * For a box with an allowed overhang, the overhang rules are worked out here from first principles rather than with
 * the library's own helpers: an item may hang over an edge by no more than the edge's allowance and no more than 15 %
 * of its extent across that edge, at any height; items at an angle stay within the box's footprint.
 */
final class PackingValidator
{
    /**
     * The overhang rule's per-item limit, as a percentage of the item's extent across the edge.
     */
    private const OVERHANG_PERCENT = 15;

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

        $allowance = $box instanceof OverhangBox ? $box->getAllowedOverhang() : null;
        $count = count($items);
        for ($i = 0; $i < $count; ++$i) {
            $a = $items[$i];
            if ($a->z < 0 || $a->z + $a->depth > $box->getInnerDepth()) {
                $problems[] = "item {$i} out of bounds";
            } elseif ($allowance === null || $a->isAngled()) {
                if ($a->x < 0 || $a->y < 0 || !AngledGeometry::withinRectangle(AngledGeometry::footprint($a), $box->getInnerWidth(), $box->getInnerLength())) {
                    $problems[] = "item {$i} out of bounds";
                }
            } else {
                foreach (self::overhangProblems($a, $box->getInnerWidth(), $box->getInnerLength(), $allowance) as $problem) {
                    $problems[] = "item {$i} {$problem}";
                }
            }
            if (!self::orientationAllowed($a)) {
                $problems[] = "item {$i} in a disallowed orientation";
            }
            if ($a->item instanceof BischoffConstrainedTestItem && !$a->item->canBePacked($packedBox, $a->x, $a->y, $a->z, $a->width, $a->length, $a->depth)) {
                $problems[] = "item {$i} stood on a disallowed edge";
            }
            for ($j = $i + 1; $j < $count; ++$j) {
                $b = $items[$j];
                if ($a->z < $b->z + $b->depth && $b->z < $a->z + $a->depth
                    && AngledGeometry::polygonsOverlap(AngledGeometry::footprint($a), AngledGeometry::footprint($b))) {
                    $problems[] = "items {$i} and {$j} overlap";
                }
            }
        }

        return $problems;
    }

    /**
     * Smallest fraction of any item's base resting on the floor or on the top of another item. For a box with an
     * allowed overhang, the floor under an item on it is the box's footprint widened by the item's own overhang limits
     * (the footprint alone for an item at an angle), and any part of its base beyond that is unsupported.
     */
    public static function minimumSupport(PackedBox $packedBox): float
    {
        /** @var list<PackedItem> $items */
        $items = iterator_to_array($packedBox->items, false);
        $box = $packedBox->box;
        $allowance = $box instanceof OverhangBox ? $box->getAllowedOverhang() : null;
        $minimum = 1.0;
        foreach ($items as $item) {
            if ($item->z === 0) {
                if ($allowance !== null && $item->width * $item->length > 0) {
                    $minimum = min($minimum, self::floorArea($item, $box->getInnerWidth(), $box->getInnerLength(), $allowance) / ($item->width * $item->length));
                }
                continue;
            }
            $supported = 0;
            foreach ($items as $other) {
                if ($other->z + $other->depth !== $item->z || $other->z >= $item->z) {
                    continue; // only items reaching up from below can support it
                }
                $supported += AngledGeometry::intersectionArea(AngledGeometry::footprint($item), AngledGeometry::footprint($other));
            }
            $minimum = min($minimum, $supported / (($item->width * $item->length) ?: 1));
        }

        return $minimum;
    }

    /**
     * How a square item breaks the overhang rules, if it does: past an edge by more than the allowance, or by more than
     * 15 % of its extent across the edge (100 × overhang <= 15 × extent, exactly in integers).
     *
     * @return list<string>
     */
    private static function overhangProblems(PackedItem $item, int $width, int $length, AllowedOverhang $allowance): array
    {
        $edges = [
            'left' => [-$item->x, $allowance->left, $item->width],
            'right' => [$item->x + $item->width - $width, $allowance->right, $item->width],
            'front' => [-$item->y, $allowance->front, $item->length],
            'back' => [$item->y + $item->length - $length, $allowance->back, $item->length],
        ];
        $problems = [];
        foreach ($edges as $edge => [$overhang, $edgeAllowance, $extent]) {
            if ($overhang > $edgeAllowance) {
                $problems[] = "hangs {$overhang} over the {$edge} edge, more than its allowance of {$edgeAllowance}";
            }
            if ($overhang > 0 && 100 * $overhang > self::OVERHANG_PERCENT * $extent) {
                $problems[] = "hangs {$overhang} over the {$edge} edge, more than 15 % of its extent of {$extent}";
            }
        }

        return $problems;
    }

    /**
     * Area of a floor item's base over the floor of a box with an allowed overhang (see {@see minimumSupport()}).
     */
    private static function floorArea(PackedItem $item, int $width, int $length, AllowedOverhang $allowance): float
    {
        if ($item->isAngled()) {
            if (AngledGeometry::withinRectangle(AngledGeometry::footprint($item), $width, $length)) {
                return (float) ($item->width * $item->length);
            }

            return AngledGeometry::intersectionArea(AngledGeometry::footprint($item), [[0.0, 0.0], [(float) $width, 0.0], [(float) $width, (float) $length], [0.0, (float) $length]]);
        }
        $limitX = intdiv(self::OVERHANG_PERCENT * $item->width, 100);
        $limitY = intdiv(self::OVERHANG_PERCENT * $item->length, 100);
        $x1 = max($item->x, -min($allowance->left, $limitX));
        $x2 = min($item->x + $item->width, $width + min($allowance->right, $limitX));
        $y1 = max($item->y, -min($allowance->front, $limitY));
        $y2 = min($item->y + $item->length, $length + min($allowance->back, $limitY));

        return $x2 > $x1 && $y2 > $y1 ? (float) (($x2 - $x1) * ($y2 - $y1)) : 0.0;
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
            Rotation::Never => $packed === $defined && !$packedItem->isAngled(),
            Rotation::KeepFlat => $packedItem->depth === $item->getDepth(),
            Rotation::BestFit => true,
        };
    }
}
