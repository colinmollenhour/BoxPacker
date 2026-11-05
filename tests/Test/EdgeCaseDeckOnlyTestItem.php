<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Test;

use DVDoug\BoxPacker\ConstrainedPlacementItem;
use DVDoug\BoxPacker\PackedBox;

/**
 * Accepts only placements within the box's inner footprint (an item that must not hang over the edges of a box with
 * an allowed overhang), counting the calls.
 */
class EdgeCaseDeckOnlyTestItem extends TestItem implements ConstrainedPlacementItem
{
    public int $calls = 0;

    public function canBePacked(
        PackedBox $packedBox,
        int $proposedX,
        int $proposedY,
        int $proposedZ,
        int $width,
        int $length,
        int $depth
    ): bool {
        ++$this->calls;

        return $proposedX >= 0 && $proposedX + $width <= $packedBox->box->getInnerWidth()
            && $proposedY >= 0 && $proposedY + $length <= $packedBox->box->getInnerLength();
    }
}
