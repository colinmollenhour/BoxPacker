<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * How hard the packer should work.
 */
enum PackingStrategy: string
{
    /**
     * The original layer-by-layer packer. Very fast; a good choice for very large orders or when throughput
     * matters more than the last few percent of density.
     */
    case Fast = 'fast';

    /**
     * Block-building beam search over maximal free spaces, plus the fast packer, keeping whichever result is
     * better. For multiple boxes, the box selection is then improved by trying to repack boxes into fewer or
     * smaller ones. Slower, but typically packs considerably denser.
     */
    case Thorough = 'thorough';
}
