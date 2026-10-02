<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Test;

use DVDoug\BoxPacker\Protection;
use DVDoug\BoxPacker\SoftPack;
use JsonSerializable;

class TestSoftPack implements SoftPack, JsonSerializable
{
    public function __construct(
        private readonly string $reference,
        private readonly int $flatWidth,
        private readonly int $flatLength,
        private readonly int $sideSeamLoss,
        private readonly int $closureLoss,
        private readonly int $maxFillThickness,
        private readonly float $wrapFactor,
        private readonly int $emptyWeight,
        private readonly int $maxWeight,
        private readonly Protection $protection = Protection::None
    ) {
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getFlatWidth(): int
    {
        return $this->flatWidth;
    }

    public function getFlatLength(): int
    {
        return $this->flatLength;
    }

    public function getSideSeamLoss(): int
    {
        return $this->sideSeamLoss;
    }

    public function getClosureLoss(): int
    {
        return $this->closureLoss;
    }

    public function getMaxFillThickness(): int
    {
        return $this->maxFillThickness;
    }

    public function getWrapFactor(): float
    {
        return $this->wrapFactor;
    }

    public function getEmptyWeight(): int
    {
        return $this->emptyWeight;
    }

    public function getMaxWeight(): int
    {
        return $this->maxWeight;
    }

    public function getProtection(): Protection
    {
        return $this->protection;
    }

    public function jsonSerialize(): array
    {
        return ['material' => 'poly'];
    }
}
