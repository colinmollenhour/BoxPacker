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
 * There are two allowances, each per edge of the inner footprint, in the box's length unit: the sides allowance, how
 * far an item may extend past the left (x = 0) and right (x = inner width) edges, and the front and back allowance,
 * past the front (y = 0) and back (y = inner length) edges. An item may hang over an edge by no more than the smaller
 * of that edge's allowance and 15 % of the item's own extent across the edge ({@see AllowedOverhang::ITEM_CAP_PERCENT},
 * rounded down), at any height. Height is unchanged: nothing may rise above the inner depth. Items turned at an angle
 * (angled placement) stay within the inner footprint.
 *
 * Positions stay relative to the inner footprint, so an item hanging over the left or front edge has a negative x
 * or y. The inner dimensions (and so volume utilisation and the default box cost) are still those of the box itself.
 *
 * A packing that hangs over an edge is then moved as a whole, on each axis on which it does, so that the larger of its
 * overhangs on that axis is as small as the items' caps allow (a gap counting as a negative overhang; of equally good
 * positions, the nearest). This balances the overhang, not the weight, and cannot close gaps within the load. If the
 * move changes the position of an item with a placement callback ({@see ConstrainedPlacementItem}), every such item is
 * asked again, in packing order, at its new position, and if any refuses, the packing keeps the position it was found
 * in. Items with no volume (no width, length or depth) are not part of the load: each is placed on its own, hanging
 * over equally if it does not fit within the footprint.
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
     * How far items may hang over the edges of the inner footprint.
     */
    public function getAllowedOverhang(): AllowedOverhang;
}
