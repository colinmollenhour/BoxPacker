<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/*
 * Rotation permutations
 */
enum Rotation: int
{
    /* Must be placed in it's defined orientation only */
    case Never = 1;
    /* Can be turned sideways 90°, but cannot be placed *on* it's side e.g. fragile "↑this way up" items */
    case KeepFlat = 2;
    /* No handling restrictions, item can be placed in any orientation */
    case BestFit = 6;

    /**
     * The distinct ways this rotation allows an item's edges to be assigned to width, length and depth, as
     * [width, length, depth], the item's own way round first.
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public function permutations(int $w, int $l, int $d): array
    {
        if ($this === self::Never) {
            return [[$w, $l, $d]];
        }

        if ($this === self::KeepFlat) {
            return $w === $l
                ? [[$w, $l, $d]]
                : [[$w, $l, $d], [$l, $w, $d]];
        }

        // BestFit: one placement per distinct assignment of edges to axes
        if ($w !== $l && $l !== $d && $w !== $d) {
            return [
                [$w, $l, $d],
                [$l, $w, $d],
                [$w, $d, $l],
                [$l, $d, $w],
                [$d, $w, $l],
                [$d, $l, $w],
            ];
        }

        if ($w === $l && $l === $d) {
            return [[$w, $l, $d]];
        }

        if ($w === $l) {
            return [[$w, $l, $d], [$w, $d, $l], [$d, $w, $l]];
        }

        if ($w === $d) {
            return [[$w, $l, $d], [$l, $w, $d], [$w, $d, $l]];
        }

        // $l === $d
        return [[$w, $l, $d], [$l, $w, $d], [$l, $d, $w]];
    }
}
