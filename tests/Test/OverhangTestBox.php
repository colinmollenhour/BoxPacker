<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Test;

use DVDoug\BoxPacker\AllowedOverhang;
use DVDoug\BoxPacker\OverhangBox;

class OverhangTestBox extends TestBox implements OverhangBox
{
    public function __construct(
        string $reference,
        int $outerWidth,
        int $outerLength,
        int $outerDepth,
        int $emptyWeight,
        int $innerWidth,
        int $innerLength,
        int $innerDepth,
        int $maxWeight,
        private readonly AllowedOverhang $allowedOverhang
    ) {
        parent::__construct($reference, $outerWidth, $outerLength, $outerDepth, $emptyWeight, $innerWidth, $innerLength, $innerDepth, $maxWeight);
    }

    public function getAllowedOverhang(): AllowedOverhang
    {
        return $this->allowedOverhang;
    }
}
