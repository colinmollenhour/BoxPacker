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

Groups are available too: ``ecommerce`` (Loh/Nee, BR1-4, Ivancic), ``container`` (BR5-7), ``extreme`` (BR8-15),
``bookshop``, ``published`` (all of the literature sets) and ``all``. Ranges like ``br1-7`` work as well.

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
