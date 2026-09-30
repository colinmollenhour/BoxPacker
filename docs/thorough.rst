Thorough packing
================

BoxPacker has two packing strategies:

``PackingStrategy::Fast`` (the default)
    The original layer-by-layer packer described in :doc:`principles`. It is very quick, and remains the best choice
    for high-volume, low-stakes packing or for very large orders where throughput matters most.

``PackingStrategy::Thorough``
    A search-based packer that typically fills boxes considerably more densely (on the published container loading
    benchmarks, roughly 10-15 percentage points more volume than ``Fast``). It costs more CPU time, but uses a
    deterministic effort budget so run times stay bounded and results are repeatable.

.. code-block:: php

    <?php
        use DVDoug\BoxPacker\PackingStrategy;
        use DVDoug\BoxPacker\VolumePacker;

        $volumePacker = new VolumePacker($box, $items);
        $volumePacker->setStrategy(PackingStrategy::Thorough);
        $packedBox = $volumePacker->pack(); // or ->packBestSubset()

How it works
------------

Identical items are grouped into *blocks* - neat columns, walls and layers of one item type in one orientation.
The empty space in the box is tracked as a set of overlapping *maximal spaces*: the largest empty cuboids that fit
between what has been packed so far. At each step the space closest to a corner of the box is filled with the best
block that fits it, the block being tucked into that corner. "Best" is the block's volume less the part of the
surrounding gap that the remaining items could never fill.

On its own that greedy procedure is already good; a *beam search* then explores alternatives. Each partial packing is
scored by greedily completing it, the most promising few are expanded further, and the search is repeated with an
ever-wider beam (1, 2, 4, 8 ...) until the effort budget or the maximum width is reached. The best complete packing
found is returned. Because the fast packer is also run and the denser result kept (for up to 300 items),
``Thorough`` never packs less volume than ``Fast`` would, support rules permitting.

Everything the fast packer honours is honoured: allowed rotations (``Rotation::Never``, ``KeepFlat``, ``BestFit``),
the preference for stable orientations, box weight limits, and ``ConstrainedPlacementItem`` callbacks.

Support
-------

Every item packed by the thorough strategy rests on the floor of the box or on the tops of other items. By default
at least 75% of each item's base must be supported; you can require more (``1.0`` = no overhang at all) or less:

.. code-block:: php

    <?php
        $volumePacker->setMinimumSupport(1.0);

Requiring full support costs little on loads with few distinct item types, but on very mixed loads it can cost
several percentage points of utilisation, as it is much harder to build flat surfaces from items of many
different heights.

Effort and run time
-------------------

Three settings control how hard the thorough strategy works:

``setSearchBudget(?int $placements)``
    The maximum number of trial block placements the search may make (default 10,000). This is a deterministic proxy
    for time - the same inputs always give the same result, whatever the machine. Small orders usually finish well
    within it; large mixed loads use all of it.

``setMaxBeamWidth(int $width)``
    The widest beam to try (default 16). Each doubling roughly quadruples the effort.

``setSearchTimeLimit(?float $seconds)``
    An optional wall-clock limit. When reached, the best packing found so far is used. Note that results then depend
    on machine speed and load.

As a guide, with the defaults on PHP 8.4 a typical e-commerce order is packed in milliseconds; container loads of
100-150 items of 3-20 types take around 0.2-1.5 seconds, and of 30-100 types 1-4 seconds.

Things the thorough strategy does not do
----------------------------------------

``beStrictAboutItemOrdering()`` and ``packAcrossWidthOnly()`` describe a particular loading sequence, which the block
search does not follow. When either is set, the fast packer is used.
