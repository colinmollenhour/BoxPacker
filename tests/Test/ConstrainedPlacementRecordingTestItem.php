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
use DVDoug\BoxPacker\PackedItem;

use function iterator_to_array;

/**
 * Accepts every placement (or, while told to, refuses every one), recording what it was asked. Its answer never
 * depends on the position.
 */
class ConstrainedPlacementRecordingTestItem extends TestItem implements ConstrainedPlacementItem
{
    /**
     * Each call: the proposal [x, y, z, width, length, depth], and the items already in the box.
     *
     * @var list<array{proposal: array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}, context: list<PackedItem>}>
     */
    public array $calls = [];

    public bool $refusing = false;

    public function canBePacked(
        PackedBox $packedBox,
        int $proposedX,
        int $proposedY,
        int $proposedZ,
        int $width,
        int $length,
        int $depth
    ): bool {
        $this->calls[] = [
            'proposal' => [$proposedX, $proposedY, $proposedZ, $width, $length, $depth],
            'context' => iterator_to_array($packedBox->items, false),
        ];

        return !$this->refusing;
    }
}
