Soft packing: mailers, envelopes and bags
=========================================

Not everything ships in a box. Apparel, books, soft goods and small parts often go in a poly mailer, a padded
envelope or a bag, which is usually lighter, cheaper and cheaper to post. BoxPacker can choose between boxes and
soft packs in one packing, and can pack items in alternative shapes (folded a different way, rolled, compressed)
when that is what makes them fit.

Soft packs
----------

A soft pack has no fixed inside size. It is described by its size lying flat and empty, and the room inside depends
on how thick it is filled: the thicker the contents, the more of the flat width and length goes round them. A
250 × 350 mailer takes a 230 × 300 × 18 shirt flat, but not a 230 × 300 × 60 stack of three.

Implement ``BoxPacker\SoftPack`` on your own packaging objects and add them with ``addSoftPack()``:

.. code-block:: php

    <?php
        use DVDoug\BoxPacker\Packer;
        use DVDoug\BoxPacker\PackingStrategy;
        use DVDoug\BoxPacker\Rotation;
        use DVDoug\BoxPacker\SoftPackAsBox;
        use DVDoug\BoxPacker\Test\TestBox;      // use your own `Box` implementation
        use DVDoug\BoxPacker\Test\TestItem;     // use your own `Item` implementation
        use DVDoug\BoxPacker\Test\TestSoftPack; // use your own `SoftPack` implementation

        $packer = new Packer();
        $packer->addBox(new TestBox('Box', 300, 400, 100, 120, 296, 396, 96, 10000));

        // flat width, flat length, side seam loss, closure loss, max fill thickness, wrap factor, empty weight, max weight
        $packer->addSoftPack(new TestSoftPack('Poly mailer 305x394', 305, 394, 6, 45, 60, 1.0, 25, 5000));

        $packer->addItem(new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat), 2);
        $packedBoxes = $packer->pack();

        foreach ($packedBoxes as $packedBox) {
            if ($packedBox->box instanceof SoftPackAsBox) {
                $mailer = $packedBox->box->softPack;             // your own SoftPack object
                [$w, $l, $t] = $packedBox->getOuterDimensions(); // the pack as filled: 269 × 358 × 36
            }
        }

The ``SoftPack`` interface:

``getFlatWidth()``, ``getFlatLength()``
    The pack lying flat and empty, in mm, as sold (the length includes the flap).
``getSideSeamLoss()``
    Width lost at each side seam.
``getClosureLoss()``
    Length lost to the flap, seal strip and bottom seam.
``getMaxFillThickness()``
    The thickest the filled pack may be: what it can close over, or what a postal format allows (25mm for a Royal
    Mail large letter, say).
``getWrapFactor()``
    How much of the flat width and length goes round the contents, as a fraction of the fill thickness. A block
    ``w`` wide and ``t`` thick needs ``w + t`` of sheet, so ``1.0`` is right for rigid contents. A stack of soft
    goods rounds off at the edges and needs less; measure a few real packs before lowering it, as a factor that is
    too low gives packs that will not close.
``getEmptyWeight()``, ``getMaxWeight()``
    As for a box.
``getProtection()``
    What the pack shields its contents from, see below.

How it works
^^^^^^^^^^^^

A soft pack filled to a thickness ``t`` is a box: ``(flat width - 2 × seam - wrap × t)`` wide,
``(flat length - closure - wrap × t)`` long and ``t`` deep. For each packing, the ``Packer`` works out every
thickness the order's items could stack to (up to the pack's limit, at most 24 of them) and tries the pack as a box
of each. The rest of the library sees only boxes, so the Fast and Thorough strategies, weight balancing, linked
items and placement constraints all work as usual.

The chosen candidate is a ``BoxPacker\SoftPackAsBox`` in the result: ``->softPack`` is your object,
``->fillThickness`` the thickness it was packed as, and the ``PackedBox``'s ``getOuterDimensions()`` gives the
filled pack's outside size (``[width, length, thickness]``) from the depth actually used. The ``SoftPackAsBox`` is
also in the JSON output, with ``flatWidth``, ``flatLength`` and ``fillThickness``.

Soft packs are assumed to be in unlimited supply.

Choosing by cost
^^^^^^^^^^^^^^^^

By default the Fast strategy prefers whichever container holds the most items and is best filled, and the Thorough
strategy the smallest inner volume, which usually favours a mailer over a box for flat goods. For real decisions
("stay under the large letter thickness", "is a mailer and a box cheaper than one bigger box?") give the Thorough
strategy a cost calculator. Two are included:

``BoxPacker\FormatCostCalculator``
    Prices a parcel by the first ``ParcelFormat`` (size and weight limits, lying any way round) it fits, so letters,
    large letters, flat-rate envelopes and parcel bands can be listed cheapest first:

    .. code-block:: php

        <?php
            $packer->setStrategy(PackingStrategy::Thorough);
            $packer->setCostCalculator(new FormatCostCalculator([
                new ParcelFormat('Large letter', 250, 353, 25, 750, 2.20),
                new ParcelFormat('Small parcel', 350, 450, 160, 2000, 4.99),
            ], 50.0)); // the cost of anything that fits no format

``BoxPacker\DimensionalWeightCostCalculator``
    The greater of the actual and the dimensional (volumetric) weight, from the outer dimensions as a carrier
    measures them, with per-dimension rounding up (``25`` for carriers billing by the whole inch) and an allowance for
    bulge; optionally with a rate callback to turn grams into money.

Both work from ``PackedBox::getOuterDimensions()``, so a soft pack is charged as filled and a right-size box (below)
as made. Any other rate card is a small class implementing ``PackedBoxCostCalculator``.

Items with more than one shape
------------------------------

A shirt can be folded in thirds, in half again, or rolled; a pillow can be compressed; a poster can go flat or in a
tube. Implement ``BoxPacker\ReshapableItem`` to give an item alternative shapes, each a ``BoxPacker\ItemState``
with a name (for the packing instructions), its own dimensions and, optionally, its own ``Rotation``:

.. code-block:: php

    <?php
        use DVDoug\BoxPacker\ItemState;
        use DVDoug\BoxPacker\ReshapableItem;

        class Garment implements ReshapableItem
        {
            // ...getWidth() etc. return the standard retail fold, e.g. 230 x 300 x 18

            public function getStates(): array
            {
                return [
                    new ItemState('fold in half again', 230, 150, 36),
                    new ItemState('roll', 70, 230, 70, Rotation::BestFit), // a roll can stand on end
                ];
            }
        }

The item's own dimensions are the shape it is normally packed in. Items go in that shape wherever everything fits
that way: a state is only used when packing the box in own shapes leaves items out, and then only if it packs
more. So a tee is never rolled just because a corner happened to be free, but a mailer that is a little too short
for it flat will take it folded, and a box that cannot take two flat will take four folded.

The state an item was packed in is on the ``PackedItem`` (``->state``, null for its own shape) and in the JSON output
(``state``). The ``PackedItem``'s ``width``, ``length`` and ``depth`` are those of the state as placed; the item's own
dimensions are unchanged.

Protection
----------

A hardback should not go in a poly mailer, a ceramic needs a padded one, a bottle needs a box. Both sides are
optional: implement ``BoxPacker\ProtectedItem`` on items that need a minimum level, and give each container its
level. The levels are ``Protection::None`` (poly mailer, paper envelope, bag), ``Protection::Padded`` (bubble or
padded mailer) and ``Protection::Rigid`` (box, board-backed mailer):

* a ``SoftPack`` says what it offers with ``getProtection()``;
* a ``Box`` is taken to be rigid unless it implements ``BoxPacker\ProtectiveBox`` and says otherwise (useful for a
  bubble mailer modelled as a fixed-size box);
* an item with no stated requirement goes anywhere.

An item is never packed into a container that offers less than it requires; an order with mixed requirements is
split accordingly, and an item no container can protect is unpackable like an item too large for any box.

Right-size boxes
----------------

A box-on-demand machine (or a knife) makes a box to the size of its contents. Implement ``BoxPacker\RightSizeBox``
(a ``Box`` with ``getWallThickness()``) with the largest dimensions it can be made to; once packed, the
``PackedBox``'s ``getOuterDimensions()`` is the contents plus the walls, so a cost calculator working from outer
dimensions charges for the box as made, and the Thorough strategy packs to keep it small. ``getEmptyWeight()`` is
the weight of the largest box, so weight limits err on the safe side.

Limitations
-----------

* The fill geometry is a model. The wrap factor and the seam and closure losses should be measured on your own
  packs and products; the defaults in the examples are for rigid contents and are conservative.
* Soft packs are in unlimited supply; limited supply applies to boxes only.
* An item's states are alternatives of the item's own shape, so the volume of an item in a state (a rolled tee is
  smaller than a folded one) is what it takes up when packed in that state, while sorting and bounds use the item's
  own dimensions.
* Compression of a whole stack (five tees press down more than one), nesting and squashable items filling leftover
  space are not modelled: give an item a compressed state if its own compression is known.
