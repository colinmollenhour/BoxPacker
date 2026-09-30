<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * Treats the inner volume of a box as its cost, so that the Thorough strategy prefers the smallest boxes.
 */
class DefaultPackedBoxCostCalculator implements PackedBoxCostCalculator
{
    public function getCost(PackedBox $packedBox): float
    {
        return (float) $packedBox->getInnerVolume();
    }
}
