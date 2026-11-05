<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * An item to be packed where additional constraints need to be considered. Only implement this interface if you actually
 * need this additional functionality as it will slow down the packing algorithm.
 */
interface ConstrainedPlacementItem extends Item
{
    /**
     * Hook for user implementation of item-specific constraints, e.g. max <x> batteries per box.
     *
     * For a box with an allowed overhang ({@see OverhangBox}), an item that refuses a position at which it would hang
     * over an edge may then be asked about one further onto the inner footprint, so it can be kept from hanging over.
     * When nothing goes into a space, an item may also be asked about positions in the part of that space over the
     * inner footprint, whether or not it refused anything. An item may also be asked again after the search, at its
     * proposed final position once the packing has been moved to keep it on the inner footprint and balance its
     * overhang, with the items packed before it (also at their proposed final positions) in the box: first with the
     * gaps in the load closed (where closing them lessens its overhang), and if it or any later item refuses that, with
     * the load moved as a whole. If it or any later item refuses that too, the whole packing keeps the position it was
     * found in, so accepting is not a commitment. Nor is it if an item with no volume and a placement callback is left
     * out with the gaps closed: the load moved as a whole may then be asked about too, after the closed one was
     * accepted.
     */
    public function canBePacked(
        PackedBox $packedBox,
        int $proposedX,
        int $proposedY,
        int $proposedZ,
        int $width,
        int $length,
        int $depth
    ): bool;
}
