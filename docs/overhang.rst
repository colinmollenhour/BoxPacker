Allowed overhang
================

Some pallets can carry products that extend a little past their edges. Allowed overhang describes this without
changing the pallet's real footprint, called the *deck*. Entering a larger footprint instead would let a floor item
sit entirely off the real pallet, and would report utilisation and cost against that larger pallet.

Overhang is off by default. A box that does not opt in, or returns two zero allowances, packs exactly as
before, except that an order whose total volume is too large for an ``int`` no longer makes the block search throw a
``TypeError``. This feature concerns the edges of the box, not items overhanging other items (see `Support`_).

Opting in
---------

Have your box implement ``DVDoug\BoxPacker\OverhangBox``. It extends ``Box`` with one method:
``getAllowedOverhang(): AllowedOverhang``. Return the same allowances throughout a packing, and for as long as you
use the boxes it packed: their JSON, their visualiser URLs and the checks the library makes when PHP assertions are
enabled read the allowance from the box, not from the packing.

``AllowedOverhang`` is a ``final readonly`` value object. Its two integer allowances default to zero and use the
same length unit as the box:

* ``sides`` applies at each of the left and right edges.
* ``frontAndBack`` applies at each of the front and back edges.

Each value is an allowance at each edge, not a total shared between the two. Negative values throw
``InvalidArgumentException``. More than two arguments throw ``ArgumentCountError``, so that a stale four-value call
fails rather than being read as a different allowance. ``isZero()`` returns true when both values are zero;
``AllowedOverhang::ITEM_CAP_PERCENT`` is fixed at 15.

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
much of each allowance as the largest cap among the items allows. If no item can use the allowance in any of its
orientations (every cap rounds down to zero, leaving out items too heavy for the box, orientations that do not fit it
even with its overhang, and items with no volume and no placement callback that fit the deck, which go on it),
``Fast`` packs the box exactly as one without an allowed overhang. Box selection and the thorough strategy's capacity
bounds and sharing of search effort between packings use the full allowances, however. A very large allowance can
therefore change which boxes are tried and how much search each one gets, so give allowances that match what the
pallet can really carry. Allowances so large that the envelope's width, length or volume cannot be represented as an
``int`` make packing throw ``InvalidArgumentException``.

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
best uniform layout stays at 10. ``Thorough`` finds 12 by mixing orientations; see `Centring`_ for their positions.
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
``ConstrainedPlacementItem::canBePacked()`` receives these same coordinates. A callback may refuse positions that
hang over, for example to keep a product on the deck. The block search starts each block at the edge of the area its
items may use, which hangs over. When a block of such an item is refused there, the search also offers the same
block moved as far onto the deck as its free space allows. If nothing goes into a space that way (a block of many
copies of one item can be too wide to move fully onto the deck, and above the floor a corner over the edge may have no
support), blocks of items with callbacks are also tried in the part of the space over the deck, sized for it. So the
item can still be packed on the deck. A callback is asked about these positions whether or not it refused anything,
and, like every call during the search, they count towards the search budget. Items without callbacks are not tried
there: at full support they may not stack at the edges of a load that callbacks kept on the deck.

Code that consumes :doc:`positional information<positional-information>` must handle both sides of the deck.

Centring
--------

After the block search, a load with a non-zero allowance has its holes closed where this reduces overhang, then is
balanced. On each axis, blocks whose extents overlap or touch form a group. The holes between groups are closed,
keeping the groups in order and moving each group rigidly. Supporting items overlap or touch on both axes, so they
move together.
Support and collision relations are preserved, every item stays within its own caps, and angled items stay on the deck.
Closing does not change which blocks the search packs, their volume or the search effort.

Balancing moves the load along each axis on which it hangs over an edge, to make the larger of its two overhangs
on that axis as small as possible within the items' caps. A gap counts as a negative overhang. If two moves give the
same result, the move with the smaller absolute distance wins. This balances the overhang, not the centre of gravity.

An axis uses the closed-and-balanced result only if balancing the load as a whole would still leave it hanging
over, and closing makes the larger overhang strictly smaller. Otherwise, it uses the existing uniform move.
Subject to the placement callbacks and flat-item rule below, if the projections of the items with volume together
fit the deck on an axis, the load ends with no overhang on that axis. Shared stretches count once and holes do not
count. On an axis where centring alone puts the load on the deck, it uses that uniform move and leaves its holes
open. On an axis where holes are closed, every hole between groups is closed, including holes inside the deck. A
load already beyond its caps as found is not moved.

An axis on which nothing hangs over is not moved. Boxes that do not opt in, all-zero boxes and Fast's layer
packing, which never overhangs, are unchanged.

A ``Thorough`` result can also be a ``Fast`` packing. It may then be Fast's deck-only layer packing, which is not
moved. This happens in three cases:

* For one box with up to 40 items, when the search leaves some out and the layer packing is at least as dense (see
  `Strategies and angled placement`_). This also applies to each box that ``Packer`` packs.
* With ``VolumePacker::packBestSubset()``, for up to 40 items, when the search leaves some out. The packing it is
  compared with is ``Fast``'s best subset, which can be a layer packing or a greedy block search, with hole closing
  and centring as above.
* With ``Packer``, for orders of up to 200 items, when ``Fast``'s boxes for the whole order leave fewer items
  unpacked, or as many in fewer or cheaper boxes (see :doc:`Choosing the boxes<thorough>`). Each of those boxes is
  ``Fast``'s packing: its layer packing, not moved, or its greedy block search, with hole closing and centring as above.

Where a load that fits the deck ends up depends on where it was found. The block search starts each block at the
edge of the area its items may use, so a load of items that can hang over is usually found hanging over the
front-left edges. Centring then moves it onto the deck with equal gaps. For example, a 12 in cube on a 48 × 40 in
pallet with 3 in allowances ends at x = 18 in, y = 14 in. A load found entirely on the deck is not moved, and stays
where it was found, often in the front-left corner. Fast's layer packing of the same cube stays in the corner, and
so does a load whose items all have caps that round down to zero. Each axis is treated on its own, so a load found
hanging over on one axis only is moved on that axis only. The same items can therefore end centred or in the corner,
depending on the strategy and on which packing was kept. For the centred cube, ``getUsedWidth()`` is 30 in,
including the 18 in gap before it, and ``getRemainingWidth()`` is only the 18 in far gap.

When the load moves, each ``ConstrainedPlacementItem`` is asked again at the closed-and-balanced positions first,
if holes are closed. If one refuses, the load moved as a whole is tried, exactly as before. If one refuses that too,
the load keeps the valid position the search found, which may leave its overhang unbalanced. At each stage, callbacks
are asked in packing order, with only their already-moved predecessors as context. The first refusal stops that
stage's check; later items are not asked. No move means no extra calls. A callback that counts calls sees these extra
calls; an exception propagates out of ``pack()``. These calls do not use up the search budget.

In a packing found by the block search (``Thorough``, and Fast's greedy block search), items with a zero width,
length or depth (flat items) are not part of the load: their extents are not counted when closing holes or centring,
and they are placed after the load (see below for when they decide which move is kept). Each is placed on its own,
trying orientations that fit the deck first. An accepted orientation that fits the deck stays at (0, 0);
an orientation that needs overhang is centred within its own caps. A flat item with a placement callback is asked at
that position, possibly first in an empty box to choose its orientation, then with the load of the stage being
tried already packed as context (that load may then not be kept, see below). If it refuses, it is asked about its
other orientations in turn, those that fit the deck first, each at its own position. An item is skipped if no
orientation fits, there is too little weight left, or its callback refuses every orientation tried. Fast's layer
packing places such items as it always has.

Closing never keeps fewer flat items. If the closed load leaves out a flat item that could go in the box and some
flat item has a placement callback, the load moved as a whole is also tried, including its placement callbacks and
flat items. The omitted item need not have a callback: accepting one flat item can use weight that others need. A
flat item with no orientation that fits, or heavier than the weight the load leaves, does not start the comparison.
If a callback refuses the load moved as a whole, the comparison uses the positions found by the search. The closed
result is kept only if it keeps at least as many flat items. A flat item in a linked item group counts only if
every flat item of its group is placed, as a split group is later taken out of the box whole. Accepting a proposed
position therefore does not guarantee that an item ends up there, even if every callback accepts the closed load.

With 2.5 / 2.5 in allowances, ``Thorough`` still packs 12 ACME Trampolines cartons: 8 lying flat at the front and
4 standing on a long edge behind them, in 2 rows stacked 2 high. The 5.99 in hole is closed and the load balanced.
It hangs 0.77 in over the left edge and 0.76 in over the right. Front to back, it lies from y = 1.18 to 38.82 in,
with no overhang and 1.18 in of deck free at each end. ``Fast`` keeps its 10-item layer packing.

On an axis whose items' projections together do not fit the deck, a block that fits the deck on its own can still
hang over: closing and centring move groups, not individual blocks. For example, on a 1000 × 1000 deck with a
50-unit sides allowance and none at the front and back, a 1050 × 400 block ends at x = −25 to 1025. A 600 × 400
block in another row behind it ends at x = −25 to 575. Their extents overlap along x, so they form one group on
that axis and move together. The 600-unit block would fit the deck on its own but still hangs 25 units over the
left edge. ``Thorough`` and ``Fast`` give the same result.
Anchoring every block on the deck first was rejected because it loses items whose overhang is needed: on a 1000 × 500
deck with a 50-unit sides allowance, only one of two 550 × 500 blocks would fit that way.

The test suite's independent ``PackingValidator::avoidableOverhangProblems()`` helper in ``tests/Benchmark/`` reports
overhang on an axis when the union of the items' projected extents fits the deck there. It ignores zero-volume items
and uses angled items' bounding boxes. It must not be used for a packing that kept its position because a placement
callback refused a moved one, or that kept the load moved as a whole because it kept more flat items; the tests
assert those cases separately. This helper is a test-suite tool, not public API.

Support
-------

The deck supports items standing on the floor. Overhang within the cap does not count against
``setMinimumSupport()``, so ``setMinimumSupport(1.0)`` works with allowed overhang in pallet mode.
Items above the floor follow the existing item-on-item :doc:`support rules<thorough>`.

``Thorough`` enforces the minimum support. ``Fast`` enforces it only in its greedy block search: its layer packing
has no support rule, and ``Fast`` keeps that packing when it is at least as dense (see
`Strategies and angled placement`_). Use ``Thorough`` when every item must meet the minimum. Strict item ordering,
single-pass mode and ``packAcrossWidthOnly()`` use the layer packing with either strategy.

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
meets the minimum support, this puts a floor under the thorough result. ``packBestSubset()`` compares with
``Fast``'s best subset instead, which can use the overhang, and ``Packer`` can keep ``Fast``'s boxes for the whole
order (see `Centring`_).

For an opted-in box, ``Fast`` first packs the deck with its usual layer packer. If that holds every item, it is the
result. Otherwise ``Fast`` also runs a quick, greedy version of the thorough block search, which uses the overhang,
and keeps whichever of the two packings uses more volume (then, if equal, packs more items, and then the layer
packing). Fast therefore never packs less volume than the layer packer on the deck alone, but it can pack fewer
items than without overhang. If no item can use the allowance, ``Fast`` packs exactly as for a box without one. With
angled placement enabled, the greedy angled search that Fast runs without overhang is not among the packings
compared. Strict item ordering (``beStrictAboutItemOrdering()``), single-pass mode and ``packAcrossWidthOnly()`` use
the layer packer on the deck only, with either strategy.

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
The visualiser accepts any four non-negative numbers in that order, and ignores the element if it is anything else.
Both additions are omitted for a plain box or an all-zero allowance. Both read the allowance from the box when
they are produced (see `Opting in`_).

The :ref:`visualiser<visualiser>` draws the deck outline as usual and the envelope as a dashed amber outline. Item
positions stay relative to the deck, including negative coordinates. The visualiser draws each edge of the envelope only
as far as that box's packed items could hang over it: the allowance, but no further than the largest cap among the
square-placed items. With realistic allowances this is the full envelope. This drawn envelope also sets the spacing and
scale, including when several boxes are shown together.

The generated URLs point at the published visualiser on ``boxpacker.io``. That page draws the envelope only once it
is rebuilt from this version. Until then it ignores the sixth element: it draws no envelope, spaces the boxes by
their decks, and items that hang over can reach into the space of a neighbouring box.
