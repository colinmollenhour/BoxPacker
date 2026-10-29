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
     * Items with no base (zero width or length) are not counted.
     *
     * Given a box with an allowed overhang, items on the floor are checked against its deck as well: the part of an
     * item's base within its caps counts as resting on the floor (so a legal overhang does not count against it), any
     * part beyond them as unsupported.
     *
     * @param iterable<PackedItem> $items
     */
    public static function minimumSupport(iterable $items, ?Box $box = null): float
    {
        $overhang = $box === null ? null : OverhangBounds::forBox($box);
        $byTop = [];
        $raised = [];
        $floor = [];
        foreach ($items as $item) {
            $byTop[$item->z + $item->depth][] = $item;
            if ($item->width * $item->length > 0) {
                if ($item->z > 0) {
                    $raised[] = $item;
                } elseif ($overhang !== null) {
                    $floor[] = $item;
                }
            }
        }

        $minimum = 1.0;
        foreach ($floor as $item) {
            $minimum = min($minimum, self::floorSupport($item, $overhang) / ($item->width * $item->length));
        }
        foreach ($raised as $item) {
            $supported = 0;
            foreach ($byTop[$item->z] ?? [] as $other) {
                if ($other->z >= $item->z) {
                    continue; // only items reaching up from below can support it (not itself, nor flat items level with it)
                }
                $ix = min($item->x + $item->boundingWidth, $other->x + $other->boundingWidth) - max($item->x, $other->x);
                if ($ix <= 0) {
                    continue;
                }
                $iy = min($item->y + $item->boundingLength, $other->y + $other->boundingLength) - max($item->y, $other->y);
                if ($iy <= 0) {
                    continue;
                }
                if ($item->isAngled() || $other->isAngled()) {
                    $supported += AngledGeometry::intersectionArea(AngledGeometry::footprint($item), AngledGeometry::footprint($other));
                } else {
                    $supported += $ix * $iy;
                }
            }
            $minimum = min($minimum, $supported / ($item->width * $item->length));
            if ($minimum === 0.0) {
                break;
            }
        }

        return $minimum;
    }

    /**
     * Area of a floor item's base within its caps (within the deck, for an item at an angle, which may not overhang).
     */
    private static function floorSupport(PackedItem $item, OverhangBounds $overhang): float
    {
        if ($item->isAngled()) {
            if ($item->x >= 0 && $item->y >= 0 && $item->x + $item->boundingWidth <= $overhang->width && $item->y + $item->boundingLength <= $overhang->length) {
                return (float) ($item->width * $item->length); // exactly, as it is wholly on the deck
            }

            return AngledGeometry::intersectionArea($item->getFootprint(), [[0.0, 0.0], [(float) $overhang->width, 0.0], [(float) $overhang->width, (float) $overhang->length], [0.0, (float) $overhang->length]]);
        }

        $ix = min($item->x + $item->width, $overhang->width + $overhang->capRight($item->width)) - max($item->x, -$overhang->capLeft($item->width));
        $iy = min($item->y + $item->length, $overhang->length + $overhang->capBack($item->length)) - max($item->y, -$overhang->capFront($item->length));

        return $ix > 0 && $iy > 0 ? (float) ($ix * $iy) : 0.0;
    }
}
