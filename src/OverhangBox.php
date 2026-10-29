<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * A "box" (typically a pallet) that items may hang over the edges of.
 *
 * The allowances are per edge of the inner footprint, in the box's length unit: how far an item may extend past the
 * left (x = 0), right (x = inner width), front (y = 0) and back (y = inner length) edges. An item may hang over an
 * edge by no more than the smaller of that edge's allowance and 15 % of the item's own extent across the edge
 * ({@see AllowedOverhang::ITEM_CAP_PERCENT}, rounded down), at any height. Height is unchanged: nothing may rise above
 * the inner depth. Items turned at an angle (angled placement) stay within the inner footprint.
 *
 * Positions stay relative to the inner footprint, so an item hanging over the left or front edge has a negative x
 * or y. The inner dimensions (and so volume utilisation and the default box cost) are still those of the box itself.
 *
 * The value must not change while a packing is in progress. All allowances zero packs exactly as a box that does not
 * implement this interface. Within one box, an allowance beyond the largest cap of any item has no further effect on
 * where items go. The capacity bounds used to choose which boxes to try, and (with the thorough strategy) to share
 * out the search effort between packings, use the full allowances, though: a very large allowance can change which
 * boxes are tried and how much search each gets, so give realistic allowances. Allowances so large that the width,
 * length or volume of the box with its overhang cannot be represented as an int make packing throw an
 * InvalidArgumentException. See the "Allowed overhang" page of the documentation.
 */
interface OverhangBox extends Box
{
    /**
     * How far items may hang over each edge of the inner footprint.
     */
    public function getAllowedOverhang(): AllowedOverhang;
}
