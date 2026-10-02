<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * A box made to measure around its contents (by a box-on-demand machine, or cut down by hand). Its dimensions are
 * the largest it can be made; once packed, {@see PackedBox::getOuterDimensions()} reports the contents plus the
 * walls, so a cost calculator working from outer dimensions (e.g. {@see DimensionalWeightCostCalculator}) charges
 * for the box as made, and the Thorough strategy packs to make it small.
 */
interface RightSizeBox extends Box
{
    /**
     * Thickness of the material on each side, in mm: the finished box is the packed contents plus twice this on
     * each axis.
     */
    public function getWallThickness(): int;
}
