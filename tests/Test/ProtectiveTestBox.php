<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Test;

use DVDoug\BoxPacker\Protection;
use DVDoug\BoxPacker\ProtectiveBox;

class ProtectiveTestBox extends TestBox implements ProtectiveBox
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
        private readonly Protection $protection
    ) {
        parent::__construct($reference, $outerWidth, $outerLength, $outerDepth, $emptyWeight, $innerWidth, $innerLength, $innerDepth, $maxWeight);
    }

    public function getProtection(): Protection
    {
        return $this->protection;
    }
}
