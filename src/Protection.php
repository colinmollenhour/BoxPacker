<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

/**
 * How much a container shields its contents, and how much an item needs. An item is only packed into a container
 * offering at least the level it requires.
 */
enum Protection: int
{
    /**
     * No cushioning or rigidity: a poly mailer, a paper envelope, a bag.
     */
    case None = 0;

    /**
     * Cushioned but not rigid: a bubble or padded mailer.
     */
    case Padded = 1;

    /**
     * Rigid: a corrugated box, a board-backed mailer.
     */
    case Rigid = 2;

    public function covers(self $required): bool
    {
        return $this->value >= $required->value;
    }

    /**
     * What a container offers: a box is rigid unless it says otherwise.
     */
    public static function offeredBy(Box $box): self
    {
        return $box instanceof ProtectiveBox ? $box->getProtection() : self::Rigid;
    }

    /**
     * What an item needs: nothing in particular unless it says otherwise.
     */
    public static function requiredBy(Item $item): self
    {
        return $item instanceof ProtectedItem ? $item->getRequiredProtection() : self::None;
    }
}
