<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use function max;
use function min;

/**
 * How well the items in a packed box are supported from below.
 *
 * @internal
 */
class SupportCalculator
{
    /**
     * Smallest fraction (0-1) of any item's base that rests on the floor of the box or on the top of another item.
     *
     * @param iterable<PackedItem> $items
     */
    public static function minimumSupport(iterable $items): float
    {
        $byTop = [];
        $raised = [];
        foreach ($items as $item) {
            $byTop[$item->z + $item->depth][] = $item;
            if ($item->z > 0) {
                $raised[] = $item;
            }
        }

        $minimum = 1.0;
        foreach ($raised as $item) {
            $supported = 0;
            foreach ($byTop[$item->z] ?? [] as $other) {
                if ($other->z >= $item->z) {
                    continue; // only items reaching up from below can support it (not itself, nor flat items level with it)
                }
                $ix = min($item->x + $item->width, $other->x + $other->width) - max($item->x, $other->x);
                if ($ix <= 0) {
                    continue;
                }
                $iy = min($item->y + $item->length, $other->y + $other->length) - max($item->y, $other->y);
                if ($iy > 0) {
                    $supported += $ix * $iy;
                }
            }
            $minimum = min($minimum, $supported / (($item->width * $item->length) ?: 1));
            if ($minimum === 0.0) {
                break;
            }
        }

        return $minimum;
    }
}
