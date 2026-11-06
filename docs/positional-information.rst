.. _positional_information:

Positional information
======================

It is possible to see the precise positional and dimensional information of each item as packed. This is exposed as x,y,z
co-ordinates from origin, alongside width/length/depth in the packed orientation. ::

           z    y
           |   /
           |  /
           | /
           |/
           0------x

Example:

.. code-block:: php

    <?php

        // assuming packing already took place
        foreach ($packedBoxes as $packedBox) {
            $packedItems = $packedBox->items;
            foreach ($packedItems as $packedItem) { // $packedItem->item is your own item object
                echo $packedItem->item->getDescription() .  ' was packed at co-ordinate ' ;
                echo '(' . $packedItem->x . ', ' . $packedItem->y . ', ' . $packedItem->z . ') with ';
                echo 'w' . $packedItem->width . ', l' . $packedItem->length . ', d' . $packedItem->depth;
                echo PHP_EOL;
            }
        }

If :doc:`angled placement<angled-placement>` is enabled, an item may be turned about the vertical axis: its ``angle``
is then non-zero, x and y are the corner of its bounding box and ``boundingWidth`` × ``boundingLength`` its extent.

A :ref:`visualiser<visualiser>` is also available.

For a box with :doc:`allowed overhang<overhang>`, the origin stays at the deck's left-front corner: x or y can be
negative, and an item can end past the inner width or length. Positions reflect any moves to close holes and balance
the load's overhang. Placement callbacks must accept the moves, and closing must not keep fewer flat items
(see :doc:`overhang`).
