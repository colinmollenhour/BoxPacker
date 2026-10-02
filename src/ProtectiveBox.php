<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * A box that says how much protection it offers. A box that does not is taken to be rigid (Protection::Rigid):
 * implement this for a flimsy container modelled as a box, e.g. a bubble mailer that is treated as a fixed-size box.
 */
interface ProtectiveBox extends Box
{
    public function getProtection(): Protection;
}
