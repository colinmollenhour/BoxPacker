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
     * For a box with an allowed overhang ({@see OverhangBox}), an item may be asked again after the search, at its
     * final position once the packing has been moved to balance its overhang, with the items packed before it (also
     * at their final positions) in the box. If it refuses, the packing keeps the position it was found in.
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
