<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use function sort;

/**
 * A size and weight band a carrier prices as one thing: a letter, a large letter, a small parcel, a flat-rate
 * envelope, a cubic tier. A parcel is in the format if it is within every limit, lying any way round.
 */
final readonly class ParcelFormat
{
    /**
     * @param int   $maxWidth  mm
     * @param int   $maxLength mm
     * @param int   $maxDepth  mm
     * @param int   $maxWeight g
     * @param float $cost      what a parcel in this format costs to send
     */
    public function __construct(
        public string $name,
        public int $maxWidth,
        public int $maxLength,
        public int $maxDepth,
        public int $maxWeight,
        public float $cost,
    ) {
    }

    public function accepts(PackedBox $packedBox): bool
    {
        if ($packedBox->getWeight() > $this->maxWeight) {
            return false;
        }

        $limits = [$this->maxWidth, $this->maxLength, $this->maxDepth];
        sort($limits);
        $dimensions = $packedBox->getOuterDimensions();
        sort($dimensions);

        return $dimensions[0] <= $limits[0] && $dimensions[1] <= $limits[1] && $dimensions[2] <= $limits[2];
    }
}
