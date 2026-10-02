<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Test;

use DVDoug\BoxPacker\RightSizeBox;

class RightSizeTestBox extends TestBox implements RightSizeBox
{
    public function __construct(
        string $reference,
        int $maxInnerWidth,
        int $maxInnerLength,
        int $maxInnerDepth,
        int $emptyWeight,
        int $maxWeight,
        private readonly int $wallThickness
    ) {
        parent::__construct($reference, $maxInnerWidth + 2 * $wallThickness, $maxInnerLength + 2 * $wallThickness, $maxInnerDepth + 2 * $wallThickness, $emptyWeight, $maxInnerWidth, $maxInnerLength, $maxInnerDepth, $maxWeight);
    }

    public function getWallThickness(): int
    {
        return $this->wallThickness;
    }
}
