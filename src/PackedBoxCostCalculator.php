<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * Calculates what shipping a packed box costs, e.g. the price of the box plus postage for its weight.
 *
 * Used by the Thorough packing strategy. By default it minimises the number of boxes, then their total cost as given by
 * DefaultPackedBoxCostCalculator (inner volume). Once a calculator is set with Packer::setCostCalculator() (any
 * calculator, including DefaultPackedBoxCostCalculator), it minimises the total cost first, then the number of boxes.
 * Costs are compared between different packings of the same items, so any consistent unit can be used. The cost of a
 * box should not decrease when items are added to it: an empty box is used as a lower bound for the cost of that box
 * type.
 */
interface PackedBoxCostCalculator
{
    public function getCost(PackedBox $packedBox): float;
}
