<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * A partial packing inside the block search: free space, what is left to pack and what has been placed.
 *
 * Kept as plain arrays so that copying a state (to branch the search) is cheap copy-on-write.
 *
 * @internal
 */
final class BlockSearchState
{
    /**
     * Maximal free spaces as [x1, y1, z1, x2, y2, z2].
     *
     * @var array<int, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}>
     */
    public array $spaces = [];

    /**
     * Remaining quantity per item type.
     *
     * @var array<int, int>
     */
    public array $counts = [];

    /**
     * Top faces of placed items that can support others, keyed by height, as [x1, y1, x2, y2].
     *
     * @var array<int, list<array{0: int, 1: int, 2: int, 3: int}>>
     */
    public array $tops = [];

    /**
     * Placed blocks, in placement order (candidate arrays as produced by the block packer).
     *
     * @var list<array<int, int|float>>
     */
    public array $placements = [];

    public int $volume = 0;

    public int $weightLeft = 0;

    public int $remaining = 0;

    /**
     * Free spaces smaller than this cannot hold any remaining item.
     */
    public int $minHeight = 0;

    public int $minFootprintEdge = 0;

    public int $minVolume = 0;

    /**
     * Materialised items placed so far; only built when placement callbacks need it.
     */
    public ?PackedItemList $context = null;

    public function __clone()
    {
        if ($this->context !== null) {
            $this->context = clone $this->context;
        }
    }
}
