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
use DVDoug\BoxPacker\Box;
use DVDoug\BoxPacker\OverhangBox;
use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedItem;
use DVDoug\BoxPacker\Rotation;
use DVDoug\BoxPacker\Test\BischoffConstrainedTestItem;

use function array_keys;
use function count;
use function implode;
use function intdiv;
use function iterator_to_array;
use function max;
use function min;
use function reset;
use function sort;
use function spl_object_id;
use function usort;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Independent checks that a packed box is physically valid, used by the benchmark and the tests.
 *
 * For a box with an allowed overhang, the overhang rules are worked out here from first principles rather than with
 * the library's own helpers: an item may hang over an edge by no more than the edge's allowance (the sides allowance
 * at the left and right edges, the front and back one at the front and back edges) and no more than 15 % of its
 * extent across that edge, at any height; items at an angle stay within the box's footprint. So are the balance of a
 * packing that hangs over (see {@see centringProblems()}) and the move that balanced it (see {@see shiftProblems()}).
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

        $allowance = self::allowance($box);
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
            // with an allowed overhang, an item with no volume (a zero width, length or depth) is placed after the load
            // has been moved, so may stand inside an item's footprint: it takes no space, so cannot collide with another.
            // Every other box is checked as it always was
            if ($allowance !== null && $a->width * $a->length * $a->depth === 0) {
                continue;
            }
            for ($j = $i + 1; $j < $count; ++$j) {
                $b = $items[$j];
                if (($allowance === null || $b->width * $b->length * $b->depth > 0)
                    && $a->z < $b->z + $b->depth && $b->z < $a->z + $a->depth
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
     * This looks at the packing as it is, so cannot tell where the load was before it was moved: on an axis on which
     * the load hangs over neither edge, any position passes, so a load moved by the wrong amount (or moved when it
     * should not have been) but onto the deck is not caught here. {@see shiftProblems()} checks the move itself, given
     * where the items were before it.
     *
     * @return list<string> problems found (empty = balanced, or nothing to balance)
     */
    public static function centringProblems(PackedBox $packedBox): array
    {
        $box = $packedBox->box;
        $allowance = self::allowance($box);
        if ($allowance === null) {
            return [];
        }
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
     * How a packing in a box with an allowed overhang was moved to balance it other than as the rule says, given where
     * its items were before the move (matched to the packed items by their Item objects). Every item with a volume must
     * have moved by the same amount; items with no volume are placed after the move, so are left out. The move along
     * each axis is worked out here from the items before it alone: none on an axis on which nothing hangs over an edge
     * (a gap counting as a negative overhang); else half the difference of the two overhangs, rounded towards zero (so
     * of two equally good moves, the smaller), but no further than keeps every item within its limits at both edges
     * (an item at an angle within the box). A load already beyond those limits before the move is reported as such,
     * and should not have been moved on that axis. Without an allowed overhang (or with a zero one), nothing can hang
     * over, so no move is right. Not to be used for a packing that kept its position because a placement callback
     * refused a balanced one.
     *
     * @param  iterable<PackedItem> $before
     * @return list<string>         problems found (empty = moved as it should have been, or not moved if it should not)
     */
    public static function shiftProblems(iterable $before, PackedBox $after): array
    {
        $box = $after->box;
        $allowance = self::allowance($box) ?? new AllowedOverhang();
        $load = $was = [];
        foreach ($before as $item) {
            if ($item->width * $item->length * $item->depth > 0) {
                $load[] = $item;
                $was[spl_object_id($item->item)][] = $item;
            }
        }
        $is = [];
        foreach ($after->items as $item) {
            if ($item->width * $item->length * $item->depth > 0) {
                $is[spl_object_id($item->item)][] = $item;
            }
        }

        // an Item object may be packed more than once: a move keeps the order of its placements by position, so pair
        // them up in that order
        $problems = $moves = [];
        foreach ($is + $was as $id => $placements) {
            $wasPlaced = self::byPosition($was[$id] ?? []);
            $isPlaced = self::byPosition($is[$id] ?? []);
            $description = $placements[0]->item->getDescription();
            for ($i = count($wasPlaced); $i < count($isPlaced); ++$i) {
                $problems[] = "{$description} was not in the packing before the move";
            }
            for ($i = count($isPlaced); $i < count($wasPlaced); ++$i) {
                $problems[] = "{$description} was not in the packing after the move";
            }
            for ($i = 0; $i < min(count($wasPlaced), count($isPlaced)); ++$i) {
                [$a, $b] = [$wasPlaced[$i], $isPlaced[$i]];
                if ([$b->z, $b->width, $b->length, $b->depth, $b->angle] !== [$a->z, $a->width, $a->length, $a->depth, $a->angle]) {
                    $problems[] = "{$description} was changed other than by moving it across the floor";
                }
                $move = [$b->x - $a->x, $b->y - $a->y];
                $moves["{$move[0]},{$move[1]}"] = $move;
            }
        }
        if ($moves === []) {
            return $problems;
        }
        if (count($moves) > 1) {
            $problems[] = 'items were moved by different amounts: ' . implode(' and ', array_keys($moves));

            return $problems;
        }

        $expected = [];
        foreach (self::axes($box->getInnerWidth(), $box->getInnerLength(), $allowance) as $axis => [$span, $axisAllowance, $extents]) {
            [$overhangBefore, $overhangAfter, $lowest, $highest] = self::reach($load, $span, $axisAllowance, $extents);
            if ($overhangBefore <= 0 && $overhangAfter <= 0) {
                $expected[] = 0; // nothing hangs over on this axis
                continue;
            }
            if ($lowest > 0 || $highest < 0) {
                $problems[] = "the load was beyond its limits along {$axis} before the move";
                $expected[] = 0; // a packing that breaks its limits is never moved
                continue;
            }
            $difference = $overhangBefore - $overhangAfter;
            $half = (int) (($difference - $difference % 2) / 2); // % takes the sign of the difference: rounded towards 0
            $expected[] = max($lowest, min($highest, $half));
        }
        $move = reset($moves);
        if ($move !== $expected) {
            $problems[] = "the load was moved by {$move[0]},{$move[1]}, but should have been moved by {$expected[0]},{$expected[1]}";
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
        $allowance = self::allowance($box);
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
     * The box's allowed overhang, or null if it has none: if it does not opt in, or allows none at any edge (and so is
     * checked exactly as the same box without it, as it packs exactly the same).
     */
    private static function allowance(Box $box): ?AllowedOverhang
    {
        if (!$box instanceof OverhangBox) {
            return null;
        }
        $allowance = $box->getAllowedOverhang();

        return $allowance->sides === 0 && $allowance->frontAndBack === 0 ? null : $allowance;
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
        $problems = [];
        foreach (self::axes($width, $length, $allowance) as $axis => [$span, $axisAllowance, $extents]) {
            [$before, $after, $lowest, $highest] = self::reach($items, $span, $axisAllowance, $extents);
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
     * @param  list<PackedItem> $items
     * @return list<PackedItem>
     */
    private static function byPosition(array $items): array
    {
        usort($items, static fn (PackedItem $a, PackedItem $b): int => [$a->x, $a->y, $a->z, $a->width, $a->length, $a->depth, $a->angle] <=> [$b->x, $b->y, $b->z, $b->width, $b->length, $b->depth, $b->angle]);

        return $items;
    }

    /**
     * Each axis of the floor: its span, its allowance at each of its two edges, and an item's position along it, the
     * extent of its bounding box along it and its own extent along it.
     *
     * @return array<string, array{0: int, 1: int, 2: callable(PackedItem): array{0: int, 1: int, 2: int}}>
     */
    private static function axes(int $width, int $length, AllowedOverhang $allowance): array
    {
        return [
            'x' => [$width, $allowance->sides, static fn (PackedItem $item): array => [$item->x, $item->boundingWidth, $item->width]],
            'y' => [$length, $allowance->frontAndBack, static fn (PackedItem $item): array => [$item->y, $item->boundingLength, $item->length]],
        ];
    }

    /**
     * Where some items reach along an axis (see {@see axes()}): how far they hang over its low edge and its high edge
     * (negative: a gap), and the most negative and most positive moves that keep every item within its limits.
     *
     * @param  non-empty-list<PackedItem>                          $items
     * @param  callable(PackedItem): array{0: int, 1: int, 2: int} $extents
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function reach(array $items, int $span, int $axisAllowance, callable $extents): array
    {
        $low = $high = null;
        $lowest = PHP_INT_MIN;
        $highest = PHP_INT_MAX;
        foreach ($items as $item) {
            [$position, $extent, $ownExtent] = $extents($item);
            $limit = $item->isAngled() ? 0 : min($axisAllowance, self::limit($ownExtent));
            $low = $low === null ? $position : min($low, $position);
            $high = $high === null ? $position + $extent : max($high, $position + $extent);
            $lowest = max($lowest, -$limit - $position);
            $highest = min($highest, $span + $limit - $position - $extent);
        }

        return [-$low, $high - $span, $lowest, $highest];
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
