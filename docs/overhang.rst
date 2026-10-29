Allowed overhang
================

Some pallets can carry products that extend a little past their edges. Allowed overhang describes this without
changing the pallet's real footprint, called the *deck*. Entering a larger footprint instead would let a floor item
sit entirely off the real pallet, and would report utilisation and cost against that larger pallet.

Overhang is off by default. A box that does not opt in, or returns zero allowances on every edge, packs exactly as
before. This feature concerns the edges of the box, not items overhanging other items (see `Support`_).

Opting in
---------

Have your box implement ``DVDoug\BoxPacker\OverhangBox``. It extends ``Box`` with one method:
``getAllowedOverhang(): AllowedOverhang``. Return the same allowances throughout a packing.

``AllowedOverhang`` is a ``final readonly`` value object. Its four integer allowances default to zero and use the
same length unit as the box. Negative values throw ``InvalidArgumentException``. ``isZero()`` returns true when
all four are zero; ``AllowedOverhang::ITEM_CAP_PERCENT`` is fixed at 15.

For example, this pallet uses hundredths of an inch for all dimensions and grams for weights. Its inner depth is
the maximum load height:

.. code-block:: php

    <?php
        use DVDoug\BoxPacker\AllowedOverhang;
        use DVDoug\BoxPacker\OverhangBox;
        use DVDoug\BoxPacker\Packer;
        use DVDoug\BoxPacker\PackingStrategy;

        class Pallet implements OverhangBox
        {
            public function getReference(): string { return '48 x 40 pallet'; }
            public function getOuterWidth(): int { return 4800; }
            public function getOuterLength(): int { return 4000; }
            public function getOuterDepth(): int { return 6500; }
            public function getEmptyWeight(): int { return 25000; }
            public function getInnerWidth(): int { return 4800; }
            public function getInnerLength(): int { return 4000; }
            public function getInnerDepth(): int { return 6500; }
            public function getMaxWeight(): int { return 1000000; }

            public function getAllowedOverhang(): AllowedOverhang
            {
                return new AllowedOverhang(left: 150, right: 150, front: 300, back: 300);
            }
        }

        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setMinimumSupport(1.0);
        $packer->addBox(new Pallet());
        // Add your items using the same length and weight units, then call pack().

The per-item cap
----------------

At each edge, an item may extend by at most the smaller of that edge's allowance and 15% of the item's own extent
perpendicular to that edge, rounded down to a whole unit. Left and right use the item's packed width; front and
back use its packed length. The cap applies separately to each item at every height, including items resting on
other items. A 12 in cube can hang at most 1.8 in past any edge, even if the allowance is larger.

The *envelope* is the deck widened by the four allowances. Every item stays within it. Depth (height) is unchanged.
Small items gain little: an extent of fewer than 7 length units has a cap of zero after rounding.

For example, consider a 49.53 × 22.52 × 7.56 in carton on a 48 × 40 in pallet with a 65 in maximum load height.
Without overhang, the best uniform layout is 10 cartons on end (2 × 5 × 1, across width × length × height).
With 1.5 in allowed on the left and right and 3 in on the front and back, 16 fit lying flat (1 × 2 × 8):

* The 49.53 in side runs across the 48 in deck, overhanging by 1.53 in in total.
* Two 22.52 in sides run across the 40 in deck, overhanging by 5.04 in in total.
* Eight layers are 60.48 in high, within the unchanged height limit.

With the back allowance set to zero, that two-deep layout would require one carton to hang 5.04 in past the front.
That is about 22% of its 22.52 in length, exceeding the 15% cap even if the front allowance were large enough.
The library takes integers: for these dimensions, scale inches to hundredths as in the example above. The cap
for a 2252-unit side is 337 units, rounded down from 337.8.

Edges and coordinates
---------------------

Coordinates stay relative to the deck. Its left-front corner is x = 0, y = 0:

* Left is x = 0; right is x = the box's inner width.
* Front is y = 0; back is y = the box's inner length.

An overhanging item can have negative x or y, or end past the inner width or length.
``ConstrainedPlacementItem::canBePacked()`` receives these same coordinates.

Placement is not centred. The search puts blocks against a corner, so a carton in the example may overhang by
1.50 in on the left and only 0.03 in on the right. Code that consumes :doc:`positional information<positional-information>`
must handle both sides of the deck.

Support
-------

The deck supports items standing on the floor. Overhang within the cap does not count against
``setMinimumSupport()``, so ``setMinimumSupport(1.0)`` works with allowed overhang in pallet mode.
Items above the floor follow the existing item-on-item :doc:`support rules<thorough>`.

The cap is a geometric limit on how far an item hangs off the deck. It does not model carton crush strength or
determine whether a particular product can withstand that overhang.

Metrics and box selection
-------------------------

``getInnerVolume()`` and the default cost and box ordering still use the real deck dimensions.
``getVolumeUtilisation()`` can exceed 100%, and ``getUnusedVolume()`` can be negative.

``getUsedWidth()`` and ``getUsedLength()`` include any overhang: each is ``max(0, maximum end) - min(0, minimum start)``
on its axis. ``getRemainingWidth()`` and ``getRemainingLength()`` are the inner dimension minus that used span, so
they can be negative. Depth measurements are unchanged.

Box selection and the thorough strategy's capacity bounds use the envelope. A box is therefore still considered
when an item or load only fits with overhang, while its default cost remains based on the deck.

Strategies and angled placement
-------------------------------

The :doc:`thorough strategy<thorough>` searches the envelope while enforcing the per-item caps. Its internal
``MAX_FILLABLE_TABLE`` size limit is measured on the envelope dimensions.

For an opted-in box, ``Fast`` also runs a quick, greedy version of the thorough block search. It keeps whichever
is denser: that packing or its usual packing on the deck, so enabling overhang never makes Fast pack fewer items
than it would without overhang. Strict item ordering (``beStrictAboutItemOrdering()``), single-pass mode and
``packAcrossWidthOnly()`` use the deck only.

With :doc:`angled placement<angled-placement>` enabled, angled items stay entirely on the deck. An item that fits
square with overhang is never angled. Square items in the same box may still overhang.

JSON and visualiser
-------------------

For a non-zero allowance, ``PackedBox::jsonSerialize()`` adds ``allowedOverhang`` inside ``box``:

.. code-block:: json

    {"left": 150, "right": 150, "front": 300, "back": 300}

The URLs from ``PackedBox::generateVisualisationURL()`` and ``PackedBoxList::generateVisualisationURL()`` add an
optional sixth element to each box tuple, after the packed items: ``[left, right, front, back]``.
Both additions are omitted for a plain box or an all-zero allowance.

The :ref:`visualiser<visualiser>` draws the deck outline as usual and the envelope as a faint outline. Item
positions stay relative to the deck, including negative coordinates. The envelope sets the spacing and scale
when several boxes are shown together.
