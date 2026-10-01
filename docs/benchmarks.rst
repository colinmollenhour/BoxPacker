Benchmarks
==========

BoxPacker ships with a repeatable benchmark harness, ``bin/benchmark``, so that changes to the packing algorithms can
be measured rather than guessed at. It runs a *strategy* over one or more *datasets*, in parallel worker processes, and
reports the packing quality and the time taken per instance.

Datasets
--------

``br1`` ... ``br15``
    The Bischoff/Ratcliff (BR1-7) and Davies/Bischoff (BR8-15) single-container loading sets, 100 instances each, with
    3 to 100 item types per instance and each item allowed to stand on only some of its edges. Scored on the volume
    utilisation of the container: more items than fit are supplied.
``loh-nee``
    The 15 Loh & Nee single-container instances.
``ivancic``
    The 47 Ivancic, Mathur & Mohanty instances: pack everything into as few identical containers as possible. Scored on
    the number of containers, reported alongside a simple volume lower bound.
``bookshop-3d``, ``bookshop-2d``
    4,288 real orders from an online bookshop with a choice of three carton sizes, with items allowed to rotate freely
    (3D) or only to lie flat (2D). Scored on the number of cartons and their total volume.
``overlong``
    100 synthetic e-commerce orders (generated from a fixed seed) with six carton sizes, in which some items are a little
    too long for the cartons they would otherwise go in, for :doc:`angled placement<angled-placement>` (run with
    ``--opt=angled=1``). One order in ten has an item too long for any carton unless it is angled.

Groups are available too: ``ecommerce`` (Loh/Nee, BR1-4, Ivancic), ``container`` (BR5-7), ``extreme`` (BR8-15),
``bookshop``, ``published`` (all of the literature sets), ``all`` (published and bookshop) and ``angled``
(``overlong``). Ranges like ``br1-7`` work as well.

Results
-------

``PackingStrategy::Fast`` (the original algorithm) against ``PackingStrategy::Thorough`` with its default settings
(search budget 10,000 placements, beam width up to 16, at least 50% of each item's base supported), PHP 8.4, one core
per instance:

=====================  =================  ===============================  =================
Dataset                Fast               Thorough                         Thorough time
=====================  =================  ===============================  =================
Loh & Nee              64.4% utilisation  71.0%                            0.03s mean
BR1-4 (3-10 types)     79.2 - 79.5%       93.5 - 94.4%                     0.13 - 0.42s mean
BR5-7 (12-20 types)    77.3 - 78.7%       93.0 - 93.9%                     0.46 - 0.61s mean
BR8-15 (30-100 types)  74.2 - 76.4%       88.6 - 92.4%                     0.72 - 1.45s mean
Ivancic                726 containers     697 containers                   0.17s mean
Bookshop (3D)          4,557 cartons      4,560 cartons, 1.9% less volume  0.009s mean
Bookshop (2D)          5,832 cartons      5,814 cartons, 9.4% less volume  0.011s mean
=====================  =================  ===============================  =================

``Thorough`` packs more volume than ``Fast`` in every one of the 1,500 BR instances. On the bookshop corpus, the few
orders where ``Fast`` uses a smaller carton all rely on items resting on less than 10% of their base (``Fast`` does
not check support); with ``setMinimumSupport(0)`` ``Thorough`` needs 4,547 and 5,814 cartons. The Ivancic volume lower
bound (579 containers) is weak, as many of those items cannot share a container at all.

With angled placement (``overlong``, 100 orders):

==============================  ======================  ======================
                                Fast                    Thorough
==============================  ======================  ======================
Items that cannot be packed     22 → 0                  22 → 0
Carton volume (90 orders [#]_)  13.6% less              33.4% less (none more)
Mean time per order             0.06s → 0.06s           0.51s → 0.70s
==============================  ======================  ======================

.. [#] The orders that can be packed completely with and without angled placement.

Running
-------

.. code-block:: shell

    # list the strategies
    bin/benchmark --list

    # the original algorithm over the literature sets, saving the results
    bin/benchmark --strategy=legacy --datasets=published --save=build/legacy.json

    # the thorough strategy on the first 20 instances of each container set, compared per instance with the above
    bin/benchmark --strategy=thorough --datasets=container --limit=20 --compare=build/legacy.json

    # strategy options, e.g. a larger search budget and full support
    bin/benchmark --strategy=thorough --opt=budget=40000 --opt=support=1

Every strategy is deterministic unless a time limit is given, so the same command always reports the same quality
figures. Each packed box is also checked independently for overlaps, items outside the box, disallowed orientations
and overweight boxes, and the smallest fraction of any item's base that is supported is reported.

The PHPUnit suites in ``tests/Published*Test.php`` and ``tests/BookShop*Test.php`` hold the expected per-instance
results for regression testing; ``tests/data/*-lastrun.csv`` receives the values of the latest run. The thorough
strategy's baselines are in the ``efficiency-thorough`` group, which is not run by default:

.. code-block:: shell

    php -d memory_limit=-1 vendor/bin/phpunit --group efficiency-thorough

After an intentional change to the algorithm, regenerate them with ``bin/benchmark --strategy=thorough --datasets=...
--csv=...`` and review the differences.
