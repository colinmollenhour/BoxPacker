Used / remaining space
======================

After packing it is possible to see how much physical space in each ``PackedBox`` is taken up with items,
and how much space was unused (air). This information might be useful to determine whether it would be useful to source
alternative/additional sizes of box.

At a high level, the ``getVolumeUtilisation()`` method exists which calculates how full the box is as a percentage of volume.

Lower-level methods are also available for examining this data in detail either using ``getUsed[Width|Length|Depth()]``
(the span covering the origin and the items) or ``getRemaining[Width|Length|Depth()]`` (the difference between the
inner dimensions of the actual box and that span).

With :doc:`allowed overhang<overhang>`, used width and length include overhang on either side of the origin, so
remaining width and length can be negative. Volume measurements stay based on the deck: utilisation can exceed
100% and unused volume can be negative. For a load centred onto the deck, the gap before the load counts as used,
so remaining width or length is only the far gap.

.. note::

    BoxPacker will try to pack items into the smallest box available

Example - warning on a massively oversized box
----------------------------------------------

.. code-block:: php

    <?php

        // assuming packing already took place
        foreach ($packedBoxes as $packedBox) {
            if ($packedBox->getVolumeUtilisation() < 20) {
                // box is 80% air, log a warning
            }
        }
