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
 *
 * This is what the Thorough strategy uses when no cost calculator is set, minimising the number of boxes first.
 * Setting it explicitly with Packer::setCostCalculator() makes the total cost (here, volume) the first objective
 * instead, as for any other calculator.
 */
class DefaultPackedBoxCostCalculator implements PackedBoxCostCalculator
{
    public function getCost(PackedBox $packedBox): float
    {
        return (float) $packedBox->getInnerVolume();
    }
}
