<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * A container without a fixed shape: a poly mailer, a padded envelope, a bag. It is described by its size lying
 * flat and empty, and the room it has inside depends on how thick it is filled: the thicker the contents, the more
 * of the flat width and length is taken up going round them, see {@see SoftPackAsBox}.
 *
 * Add one to a {@see Packer} with addSoftPack(). Soft packs are assumed to be in unlimited supply.
 */
interface SoftPack
{
    /**
     * Reference for the pack type (e.g. SKU or description).
     */
    public function getReference(): string;

    /**
     * Outer width of the pack lying flat and empty, in mm (the size it is sold as, e.g. 250 for a 250 × 350 mailer).
     */
    public function getFlatWidth(): int;

    /**
     * Outer length of the pack lying flat and empty, in mm, including the flap.
     */
    public function getFlatLength(): int;

    /**
     * Width lost at each side seam, in mm.
     */
    public function getSideSeamLoss(): int;

    /**
     * Length lost to the closure (the flap and seal strip, and the bottom seam), in mm.
     */
    public function getClosureLoss(): int;

    /**
     * The thickest the filled pack may be, in mm: what it can be closed over, or what a postal format allows
     * (e.g. 25 for a Royal Mail large letter).
     */
    public function getMaxFillThickness(): int;

    /**
     * How much of the flat width and of the flat length goes round the contents, as a fraction of the fill
     * thickness. 1.0 is right for rigid contents (a block w × t needs w + t of width); a stack of soft goods that
     * rounds off at the edges needs less, so a lower factor fits more. Measure it: a factor that is too low gives
     * packs that do not close.
     */
    public function getWrapFactor(): float;

    /**
     * Empty weight in g.
     */
    public function getEmptyWeight(): int;

    /**
     * Max weight the pack can hold in g.
     */
    public function getMaxWeight(): int;

    /**
     * What the pack shields its contents from: a poly mailer offers none, a bubble mailer is padded.
     */
    public function getProtection(): Protection;
}
