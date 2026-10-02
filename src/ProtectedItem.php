<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * An item that must not go in just any container: a hardback that must stay flat, a sharp or fragile item, a
 * liquid. It is only packed into containers whose {@see Protection} covers what it requires (a plain {@see Box} is
 * taken to be rigid, so it accepts everything).
 */
interface ProtectedItem extends Item
{
    public function getRequiredProtection(): Protection;
}
