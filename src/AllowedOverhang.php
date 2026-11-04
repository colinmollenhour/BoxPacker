<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use ArgumentCountError;
use InvalidArgumentException;
use JsonSerializable;

use function func_num_args;

/**
 * How far items may hang over the edges of a box's inner footprint (see {@see OverhangBox}), in the box's length
 * unit. Each value applies at each of its two edges, not shared between them: the sides allowance at the left edge
 * (x = 0) and at the right edge (x = inner width), the front and back allowance at the front edge (y = 0) and at the
 * back edge (y = inner length).
 *
 * Each item is also limited to {@see ITEM_CAP_PERCENT} % of its own extent across that edge, rounded down, so an
 * item may hang over an edge by no more than the smaller of the two.
 */
final readonly class AllowedOverhang implements JsonSerializable
{
    /**
     * Largest overhang of an item over any edge, as a percentage of the item's extent across that edge.
     */
    public const ITEM_CAP_PERCENT = 15;

    public function __construct(
        public int $sides = 0,
        public int $frontAndBack = 0,
    ) {
        // PHP drops extra positional arguments to a constructor without a word, so a call written for the removed
        // four-edge form would otherwise be read as a different allowance
        if (func_num_args() > 2) {
            throw new ArgumentCountError('AllowedOverhang takes two allowances, sides and frontAndBack (each per edge); the four-edge form (left, right, front, back) was removed');
        }
        if ($sides < 0 || $frontAndBack < 0) {
            throw new InvalidArgumentException("Allowed overhang cannot be negative (sides {$sides}, frontAndBack {$frontAndBack})");
        }
    }

    /**
     * Whether no overhang is allowed over any edge (the box packs exactly as one without an allowed overhang).
     */
    public function isZero(): bool
    {
        return $this->sides === 0 && $this->frontAndBack === 0;
    }

    /**
     * @return array{sides: int, frontAndBack: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'sides' => $this->sides,
            'frontAndBack' => $this->frontAndBack,
        ];
    }
}
