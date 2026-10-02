<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * Costs a packed box by the first {@see ParcelFormat} it fits, so that the Thorough strategy keeps parcels within
 * a cheaper band when it can: under a large letter's thickness, within a flat-rate envelope's size, under a weight
 * step. List the formats cheapest first.
 */
final class FormatCostCalculator implements PackedBoxCostCalculator
{
    /**
     * @param ParcelFormat[] $formats      tried in order; the first that accepts the parcel gives the cost
     * @param float          $fallbackCost the cost of a parcel that fits no format (finite, so that costs can be compared)
     */
    public function __construct(private readonly array $formats, private readonly float $fallbackCost)
    {
    }

    /**
     * The format the parcel would be sent in, or null if it fits none.
     */
    public function getFormat(PackedBox $packedBox): ?ParcelFormat
    {
        foreach ($this->formats as $format) {
            if ($format->accepts($packedBox)) {
                return $format;
            }
        }

        return null;
    }

    public function getCost(PackedBox $packedBox): float
    {
        return $this->getFormat($packedBox)?->cost ?? $this->fallbackCost;
    }
}
