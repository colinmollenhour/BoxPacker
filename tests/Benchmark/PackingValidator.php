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

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Independent checks that a packed box is physically valid, used by the benchmark and the tests.
 *
 * For a box with an allowed overhang, the overhang rules are worked out here from first principles rather than with
 * the library's own helpers: an item may hang over an edge by no more than the edge's allowance (the sides allowance
 * at the left and right edges, the front and back one at the front and back edges) and no more than 15 % of its
 * extent across that edge, at any height; items at an angle stay within the box's footprint. So is the balance of a
 * packing that hangs over: see {@see centringProblems()}.
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
     * How a packing in a box with an allowed overhang is not balanced, if it is not: on an axis on which the load (the
     * items with a volume) hangs over an edge, moving it all by one unit either way, within every item's limits at
     * both edges, would make the larger of its two overhangs on that axis smaller (a gap counting as a negative
     * overhang). The larger overhang is convex in the move, so if neither neighbouring move is better, none is. Each
     * item with no volume is checked in the same way on its own. Not to be used for a packing that kept its position
     * because a placement callback refused a balanced one.
     *
     * @return list<string> problems found (empty = balanced, or nothing to balance)
     */
    public static function centringProblems(PackedBox $packedBox): array
    {
        $box = $packedBox->box;
        if (!$box instanceof OverhangBox) {
            return [];
        }
        $allowance = $box->getAllowedOverhang();
        $load = [];
        $problems = [];
        foreach ($packedBox->items as $item) {
            if ($item->width * $item->length * $item->depth > 0) {
                $load[] = $item;
            } else {
                foreach (self::imbalance([$item], $box->getInnerWidth(), $box->getInnerLength(), $allowance) as $problem) {
                    $problems[] = "{$item->item->getDescription()} (no volume) {$problem}";
                }
            }
        }
        foreach (self::imbalance($load, $box->getInnerWidth(), $box->getInnerLength(), $allowance) as $problem) {
            $problems[] = "the load {$problem}";
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
     * 15 % of its extent across the edge (see {@see limit()}).
     *
     * @return list<string>
     */
    private static function overhangProblems(PackedItem $item, int $width, int $length, AllowedOverhang $allowance): array
    {
        $edges = [
            'left' => [-$item->x, $allowance->sides, $item->width],
            'right' => [$item->x + $item->width - $width, $allowance->sides, $item->width],
            'front' => [-$item->y, $allowance->frontAndBack, $item->length],
            'back' => [$item->y + $item->length - $length, $allowance->frontAndBack, $item->length],
        ];
        $problems = [];
        foreach ($edges as $edge => [$overhang, $edgeAllowance, $extent]) {
            if ($overhang > $edgeAllowance) {
                $problems[] = "hangs {$overhang} over the {$edge} edge, more than its allowance of {$edgeAllowance}";
            }
            if ($overhang > 0 && $overhang > self::limit($extent)) {
                $problems[] = "hangs {$overhang} over the {$edge} edge, more than 15 % of its extent of {$extent}";
            }
        }

        return $problems;
    }

    /**
     * @param  list<PackedItem> $items
     * @return list<string>
     */
    private static function imbalance(array $items, int $width, int $length, AllowedOverhang $allowance): array
    {
        if ($items === []) {
            return [];
        }
        $axes = [
            'x' => [$width, $allowance->sides, static fn (PackedItem $item): array => [$item->x, $item->boundingWidth, $item->width]],
            'y' => [$length, $allowance->frontAndBack, static fn (PackedItem $item): array => [$item->y, $item->boundingLength, $item->length]],
        ];
        $problems = [];
        foreach ($axes as $axis => [$span, $axisAllowance, $extents]) {
            $low = $high = null;
            $lowest = PHP_INT_MIN; // the most negative move that keeps every item within its limits
            $highest = PHP_INT_MAX;
            foreach ($items as $item) {
                [$position, $extent, $ownExtent] = $extents($item);
                $limit = $item->isAngled() ? 0 : min($axisAllowance, self::limit($ownExtent));
                $low = $low === null ? $position : min($low, $position);
                $high = $high === null ? $position + $extent : max($high, $position + $extent);
                $lowest = max($lowest, -$limit - $position);
                $highest = min($highest, $span + $limit - $position - $extent);
            }
            $before = -$low;
            $after = $high - $span;
            if ($before <= 0 && $after <= 0) {
                continue; // nothing hangs over on this axis
            }
            $larger = max($before, $after);
            if ($highest >= 1 && max($before - 1, $after + 1) < $larger) {
                $problems[] = "hangs over {$before} and {$after} along {$axis}: moving it up by 1 would balance it better";
            }
            if ($lowest <= -1 && max($before + 1, $after - 1) < $larger) {
                $problems[] = "hangs over {$before} and {$after} along {$axis}: moving it down by 1 would balance it better";
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
        $limitX = self::limit($item->width);
        $limitY = self::limit($item->length);
        $x1 = max($item->x, -min($allowance->sides, $limitX));
        $x2 = min($item->x + $item->width, $width + min($allowance->sides, $limitX));
        $y1 = max($item->y, -min($allowance->frontAndBack, $limitY));
        $y2 = min($item->y + $item->length, $length + min($allowance->frontAndBack, $limitY));

        return $x2 > $x1 && $y2 > $y1 ? (float) (($x2 - $x1) * ($y2 - $y1)) : 0.0;
    }

    /**
     * The most an item may hang over an edge for the 15 % rule: 15 % of its extent across the edge, rounded down (an
     * overhang o is within it if and only if 100 o <= 15 e). Worked out as 3 e / 20 from the quotient and remainder of
     * e / 20, so that no product overflows however large the extent.
     */
    private static function limit(int $extent): int
    {
        $twentieths = intdiv($extent, 20);

        return $twentieths * 3 + intdiv(($extent - $twentieths * 20) * 3, 20);
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
