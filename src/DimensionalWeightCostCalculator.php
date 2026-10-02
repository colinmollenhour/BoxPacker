<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use Closure;

use function ceil;
use function max;

/**
 * Costs a packed box by its billable weight: the greater of its actual weight and its dimensional (volumetric)
 * weight, worked out from its outer dimensions as a carrier would measure them (so soft packs are charged as filled
 * and right-size boxes as made). Optionally turns that into a price with a rate callback; without one, the billable
 * weight in grams is the cost.
 */
final class DimensionalWeightCostCalculator implements PackedBoxCostCalculator
{
    /**
     * @param int                                                            $divisor             mm³ of volume per gram of dimensional weight: 5000 for the common 5000 cm³/kg, 139 in³/lb is 5022
     * @param int                                                            $roundDimensionsUpTo round each dimension up to a multiple of this many mm before multiplying (25 for carriers that bill by the whole inch), 1 for none
     * @param int                                                            $allowance           mm added to each dimension before rounding, e.g. for the bulge of a soft pack
     * @param Closure(int $billableWeight, PackedBox $packedBox): float|null $rate                the cost for a billable weight in grams; by default the billable weight itself
     */
    public function __construct(
        private readonly int $divisor,
        private readonly int $roundDimensionsUpTo = 1,
        private readonly int $allowance = 0,
        private readonly ?Closure $rate = null,
    ) {
    }

    /**
     * The greater of the actual weight and the dimensional weight, in grams.
     */
    public function getBillableWeight(PackedBox $packedBox): int
    {
        $volume = 1;
        foreach ($packedBox->getOuterDimensions() as $dimension) {
            $dimension += $this->allowance;
            if ($this->roundDimensionsUpTo > 1) {
                $dimension = (int) ceil($dimension / $this->roundDimensionsUpTo) * $this->roundDimensionsUpTo;
            }
            $volume *= $dimension;
        }

        return max($packedBox->getWeight(), (int) ceil($volume / $this->divisor));
    }

    public function getCost(PackedBox $packedBox): float
    {
        $billableWeight = $this->getBillableWeight($packedBox);

        return $this->rate === null ? (float) $billableWeight : ($this->rate)($billableWeight, $packedBox);
    }
}
