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
 * A packing that hangs over an edge is then moved, on each axis on which it does, so that the larger of its overhangs
 * on that axis is as small as the items' caps allow (a gap counting as a negative overhang; of equally good positions,
 * the nearest). This balances the overhang, not the weight. Where moving the load as a whole would still leave it
 * hanging over on an axis, the gaps along that axis between the stretches its items cover are closed first (keeping the
 * items in order, and items that touch, such as stacks, together) if that leaves the larger overhang smaller: so along
 * an axis on which the items' extents, counting a stretch that several items share once, fit within the footprint, the
 * load uses no overhang. Along an axis on which they do not, the load still uses the overhang, though one item may then
 * hang over even if it would fit within the footprint on its own.
 *
 * If a move changes the position of an item with a placement callback ({@see ConstrainedPlacementItem}), such items are
 * asked again at their new positions, in packing order until one refuses (the items after it are not asked). If one
 * refuses the packing with the gaps closed, the load is moved as a whole instead (and they are asked again), and if one
 * refuses that, the packing keeps the position it was found in. When a placement callback refuses a position at which
 * its item hangs over an edge, the block search also offers it the same block moved as far onto the footprint as its
 * free space allows. If nothing goes into a space, items with placement callbacks are also offered blocks sized for the
 * part of it over the footprint, whether or not they refused anything, so an item that must not hang over still goes on
 * the footprint. In a packing found by the block search, items with no volume (no width, length or depth) are not part
 * of the load: each is placed on its own after the load has been moved, turned (even onto an edge) to fit within the
 * footprint if it can be and its placement callback (if any) accepts that, else hanging over equally. The gaps are not
 * closed if that would leave fewer of them placed (their placement callbacks seeing the load where it ends up).
 *
 * The value must not change while a packing is in progress, nor while its packed boxes are used: their JSON, their
 * visualiser URLs and the real-world assertion read it from the box. All allowances zero packs exactly as a box that
 * does not implement this interface. Within one box, an allowance beyond the largest cap of any item has no further
 * effect on where items go, and an allowance that no item may use (the cap of every item that can go in is 0 in
 * every orientation in which it fits, but for items with no volume and no placement callback that fit the footprint)
 * places them as all allowances zero do. The capacity bounds used to choose which boxes to try, and (with the thorough
 * strategy) to share out the search effort between packings, use the full allowances, though: a very large allowance
 * can change which boxes are tried and how much search each gets, so give realistic allowances. Allowances so large
 * that the width, length or volume of the box with its overhang cannot be represented as an int, or negative ones (in
 * an AllowedOverhang made without its constructor), make packing throw an InvalidArgumentException. See the "Allowed
 * overhang" page of the documentation.
 */
interface OverhangBox extends Box
{
    /**
     * How far items may hang over the edges of the inner footprint.
     */
    public function getAllowedOverhang(): AllowedOverhang;
}
