Allowed overhang
================

Some pallets can carry products that extend a little past their edges. Allowed overhang describes this without
changing the pallet's real footprint, called the *deck*. Entering a larger footprint instead would let a floor item
sit entirely off the real pallet, and would report utilisation and cost against that larger pallet.

Overhang is off by default. A box that does not opt in, or returns two zero allowances, packs exactly as
before. This feature concerns the edges of the box, not items overhanging other items (see `Support`_).

Opting in
---------

Have your box implement ``DVDoug\BoxPacker\OverhangBox``. It extends ``Box`` with one method:
``getAllowedOverhang(): AllowedOverhang``. Return the same allowances throughout a packing.

``AllowedOverhang`` is a ``final readonly`` value object. Its two integer allowances default to zero and use the
same length unit as the box:

* ``sides`` applies at each of the left and right edges.
* ``frontAndBack`` applies at each of the front and back edges.

Each value is an allowance at each edge, not a total shared between the two. Negative values throw
``InvalidArgumentException``. More than two arguments throw ``ArgumentCountError``; the four-edge form has been
removed. ``isZero()`` returns true when both values are zero; ``AllowedOverhang::ITEM_CAP_PERCENT`` is fixed at 15.

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
                return new AllowedOverhang(sides: 150, frontAndBack: 300);
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

The *envelope* is the deck widened by the sides allowance at both sides and the front and back allowance at both
ends. Every item stays within it. Depth (height) is unchanged. Small items gain little: an extent of fewer than
7 length units has a cap of zero after rounding.

Within one box, an allowance larger than any item's cap has no further effect on where items go: the search uses only as
much of each allowance as the largest cap among the items allows. Box selection and the thorough strategy's capacity
bounds and sharing of search effort between packings use the full allowances, however. A very large allowance can
therefore change which boxes are tried and how much search each one gets, so give allowances that match what the pallet
can really carry. Allowances so large that the envelope's width, length or volume cannot be represented as an ``int``
make packing throw ``InvalidArgumentException``.

For example, an ACME Trampolines carton measures 49.53 × 22.52 × 7.56 in. On a 48 × 40 in pallet with a
65 in maximum load height, these are the measured results for 24 cartons offered, free to rotate, with minimum
support 1.0, angled placement off, and ``Thorough`` at the library's default effort:

.. list-table:: Cartons packed by allowance at each edge
    :header-rows: 1

    * - Sides / front and back (in)
      - Uniform grid
      - Thorough
      - Fast
    * - None
      - 10
      - 10
      - 10
    * - 1.5 / 3
      - 16
      - 16
      - 16
    * - 1 / 3
      - 16
      - 16
      - 16
    * - 2.5 / 2.5
      - 10
      - 12
      - 10

Without overhang, the best uniform layout is 10 cartons on end (2 × 5 × 1, across width × length × height).
With 1.5 in at the sides and 3 in at the front and back, 16 fit lying flat (1 × 2 × 8):

* The 49.53 in side runs across the 48 in deck. After centring, it hangs 0.77 in over the left edge and 0.76 in
  over the right, splitting the 1.53 in excess within one hundredth of an inch.
* Two 22.52 in sides run across the 40 in deck, hanging 2.52 in over the front and 2.52 in over the back.
* Eight layers are 60.48 in high, within the unchanged height limit.

With 1 in at the sides and 3 in at the front and back, the final positions are the same.
With 2.5 in at each edge, two deep lying flat would need 2.52 in at each end, 0.02 in more than allowed, so the
best uniform layout stays at 10. ``Thorough`` finds 12 by mixing orientations; see `Centring`_ for its limitation.
``Fast`` keeps its 10-item layer packing on the deck: its greedy block search also finds 10, and the deck-only
packing wins the tie.

The library takes integers: for these dimensions, scale inches to hundredths as in the example above. The cap
for a 2252-unit side is 337 units, rounded down from 337.8.

Edges and coordinates
---------------------

Coordinates stay relative to the deck. Its left-front corner is x = 0, y = 0:

* Left is x = 0; right is x = the box's inner width.
* Front is y = 0; back is y = the box's inner length.

An overhanging item can have negative x or y, or end past the inner width or length.
``ConstrainedPlacementItem::canBePacked()`` receives these same coordinates.

Code that consumes :doc:`positional information<positional-information>` must handle both sides of the deck.

Centring
--------

After packing, the whole load is moved along each axis on which it hangs over an edge, to make the larger of its
two overhangs on that axis as small as possible. A gap counts as a negative overhang. If two moves give the same
result, the smaller move wins. Every item stays within its own caps at both edges, and angled items stay on the deck.
Moving all items together preserves their support and collision relations. This balances the overhang, not the
centre of gravity, and cannot close gaps inside the load.

An axis on which nothing hangs over is not moved. Boxes that do not opt in, all-zero boxes and Fast's layer
packing, which never overhangs, are unchanged. The same deck-only layer packing can also be ``Thorough``'s result:
for up to 40 items, when the search leaves some out and the layer packing is at least as dense (see
`Strategies and angled placement`_). It is then not centred either.

The thorough search starts in a corner of the widened area, so even a load that fits the deck is usually found
hanging over the front-left edges. Centring then moves it onto the deck with equal gaps. For example, a 12 in cube
on a 48 × 40 in pallet with 3 in allowances ends at x = 18 in, y = 14 in. Fast's layer packing of the same cube stays
in the corner. For the centred cube, ``getUsedWidth()`` is 30 in, including the 18 in gap before it, and
``getRemainingWidth()`` is only the 18 in far gap.

When the load moves, each ``ConstrainedPlacementItem`` is asked again once, in packing order, at its proposed final
position, with only its already-moved predecessors as context. On the first refusal, the check stops (later items are
not asked) and the whole load keeps the valid position the search found, which may leave its overhang unbalanced. This check makes no extra
calls when the load does not move. A callback that counts calls sees these extra calls; an exception thrown during this
check propagates out of ``pack()``. These calls do not use up the search budget.

In a packing found by the block search (``Thorough``, and Fast's greedy block search), items with a zero width,
length or depth are placed after the load and do not affect its centring. Each is placed on its own: one that fits
the deck stays at (0, 0); one that needs overhang is centred within its own caps. A constrained flat item is asked
at that position, possibly first in an empty box to choose its orientation, then with the final items already packed
as context, and is skipped if it refuses. Fast's layer packing places such items as it always has.

The 2.5 / 2.5 in ACME Trampolines result shows the limit of moving the whole load. ``Thorough`` packs 8 lying flat
at the front and 4 standing on a long edge behind them, in 2 rows stacked 2 high. Centring balances the sides at
0.77 / 0.76 in, but the load cannot move front to back. The flat stack is at its own 2.50 in front limit, and the
back row is at its own 1.13 in back limit (15% of its 7.56 in packed length, rounded down, as it stands on a long
edge). The load stays 2.50 in over the
front and 1.13 in over the back, with a 5.99 in gap inside it. Moving the load as a whole cannot close that gap.

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
they can be negative. For a load centred onto the deck, the gap before the load counts as used, so remaining width
or length is only the far gap. Depth measurements are unchanged.

Box selection and the thorough strategy's capacity bounds use the envelope. A box is therefore still considered
when an item or load only fits with overhang, while its default cost remains based on the deck.

Strategies and angled placement
-------------------------------

The :doc:`thorough strategy<thorough>` searches the envelope while enforcing the per-item caps. Its internal
``MAX_FILLABLE_TABLE`` size limit is measured on the envelope dimensions, using only as much of each allowance as
the items can use. The search is a heuristic: with the larger space it can occasionally find a slightly less dense
packing than it would without overhang. For up to 40 items, if the search leaves some out, it also compares its
packing with the ``Fast`` packing on the deck alone and keeps the denser. Time permitting, and provided that packing
meets the minimum support, this puts a floor under the thorough result.

For an opted-in box, ``Fast`` also runs a quick, greedy version of the thorough block search. It keeps whichever of
that packing and its usual layer packing on the deck uses more volume (then, if equal, packs more items). Fast
therefore never packs less volume than the layer packer on the deck alone, but it can pack fewer items than without
overhang. With angled placement enabled, the greedy angled search that Fast runs without overhang is not among the
packings compared. Strict item ordering (``beStrictAboutItemOrdering()``), single-pass mode and
``packAcrossWidthOnly()`` use the deck only.

With :doc:`angled placement<angled-placement>` enabled, angled items stay entirely on the deck. An item that fits
square with overhang is never angled. Square items in the same box may still overhang.

JSON and visualiser
-------------------

For a non-zero allowance, ``PackedBox::jsonSerialize()`` adds ``allowedOverhang`` inside ``box``:

.. code-block:: json

    {"sides": 150, "frontAndBack": 300}

The URLs from ``PackedBox::generateVisualisationURL()`` and ``PackedBoxList::generateVisualisationURL()`` add an
optional sixth element to each box tuple, after the packed items. It stays a four-value array in left, right,
front, back order: ``[sides, sides, frontAndBack, frontAndBack]`` (``[150, 150, 300, 300]`` for the example).
The visualiser still reads any four-value URL, including earlier ones with different allowances at each edge.
Both additions are omitted for a plain box or an all-zero allowance.

The :ref:`visualiser<visualiser>` draws the deck outline as usual and the envelope as a dashed amber outline. Item
positions stay relative to the deck, including negative coordinates. The visualiser draws each edge of the envelope only
as far as that box's packed items could hang over it: the allowance, but no further than the largest cap among the
square-placed items. With realistic allowances this is the full envelope. This drawn envelope also sets the spacing and
scale, including when several boxes are shown together.
