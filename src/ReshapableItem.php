<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * An item that can be packed in more than one shape: a garment folded differently or rolled, a pillow compressed,
 * a poster flat or in a tube. The item's own dimensions are the shape it is normally packed in; the states are the
 * alternatives, each tried as if the item had those dimensions. The state an item is packed in is reported on the
 * {@see PackedItem}.
 */
interface ReshapableItem extends Item
{
    /**
     * The other shapes this item can be packed in, in addition to its own dimensions.
     *
     * @return ItemState[]
     */
    public function getStates(): array;
}
