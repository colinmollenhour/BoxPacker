<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Test;

use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\Rotation;

/**
 * Accepts only placements no further right than a given x (a constraint that depends on where in the box the item
 * is), recording what it was asked.
 */
class ConstrainedPlacementMaxXTestItem extends ConstrainedPlacementRecordingTestItem
{
    public function __construct(
        string $description,
        int $width,
        int $length,
        int $depth,
        int $weight,
        Rotation $allowedRotation,
        private readonly int $maxX
    ) {
        parent::__construct($description, $width, $length, $depth, $weight, $allowedRotation);
    }

    public function canBePacked(
        PackedBox $packedBox,
        int $proposedX,
        int $proposedY,
        int $proposedZ,
        int $width,
        int $length,
        int $depth
    ): bool {
        parent::canBePacked($packedBox, $proposedX, $proposedY, $proposedZ, $width, $length, $depth);

        return $proposedX <= $this->maxX;
    }
}
