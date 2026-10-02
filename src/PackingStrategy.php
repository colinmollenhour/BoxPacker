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
     * Block-building beam search over maximal free spaces. For small loads (up to 40 items per box, 200 per order)
     * the fast packer is also run and its result kept if it is better and meets the support requirement. For
     * multiple boxes, the box selection is then improved by trying to repack boxes into fewer or cheaper ones; boxes
     * are chosen by number and cost (see PackedBoxCostCalculator), not by a BoxSorter or PackedBoxSorter.
     * Slower, but typically packs considerably denser.
     */
    case Thorough = 'thorough';
}
