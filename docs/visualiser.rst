:orphan:

.. _visualiser:

Visualiser
----------

.. container:: demotext

    Although one of the principles underlying this library is that "It doesn't require the person actually packing the box
    to be given a 3D diagram explaining just how the items are supposed to fit", it is sometimes useful to do it anyway.

    The ``PackedBox`` objects created when a packing is calculated do contain all of the necessary information needed to
    produce your own visualisation (see :ref:`Positional information<positional_information>`). Alternatively, a basic
    visualiser is included below. You can make use of it by calling the ``generateVisualisationURL()`` method on either
    a ``PackedBox`` or a ``PackedBoxList`` which will generate a custom URL for this page.

    For boxes with :doc:`allowed overhang<overhang>`, the visualiser draws the real deck outline and a dashed amber
    envelope outline, and spaces boxes using the envelope so overhanging items stay in view. Each edge of the envelope
    is drawn only as far as the box's packed items could hang over it. Generated URLs repeat each of the two allowances
    at its two edges in the sixth box element, ``[sides, sides, frontAndBack, frontAndBack]``; the visualiser accepts
    any four non-negative numbers there.

    Generated URLs point at the published copy of this page on ``boxpacker.io``, which draws the envelope only once
    it is rebuilt from this version. Until then it ignores the sixth element: it draws no envelope and spaces boxes by
    their decks, so items that hang over can reach into the space of a neighbouring box.

.. raw:: html

    <canvas id="renderCanvas" style="width: 100%; aspect-ratio: 1;"></canvas>
    <button id="makeFullscreen">Make full screen</button>
