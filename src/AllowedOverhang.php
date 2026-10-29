<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use InvalidArgumentException;
use JsonSerializable;

/**
 * How far items may hang over each edge of a box's inner footprint (see {@see OverhangBox}), in the box's length
 * unit. Left is the edge at x = 0, right the edge at x = inner width, front the edge at y = 0 and back the edge at
 * y = inner length.
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
        public int $left = 0,
        public int $right = 0,
        public int $front = 0,
        public int $back = 0,
    ) {
        if ($left < 0 || $right < 0 || $front < 0 || $back < 0) {
            throw new InvalidArgumentException("Allowed overhang cannot be negative (left {$left}, right {$right}, front {$front}, back {$back})");
        }
    }

    /**
     * Whether no overhang is allowed over any edge (the box packs exactly as one without an allowed overhang).
     */
    public function isZero(): bool
    {
        return $this->left === 0 && $this->right === 0 && $this->front === 0 && $this->back === 0;
    }

    /**
     * @return array{left: int, right: int, front: int, back: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'left' => $this->left,
            'right' => $this->right,
            'front' => $this->front,
            'back' => $this->back,
        ];
    }
}
