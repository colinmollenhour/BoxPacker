<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use InvalidArgumentException;

use function intdiv;
use function min;

use const PHP_INT_MAX;

/**
 * The allowed overhang of one box as used while packing it: the deck (the box's inner width, length and depth) and
 * the four edge allowances, with the per-item caps worked out from them. Coordinates are relative to the deck.
 *
 * Only boxes that opt in with a non-zero allowance have one, so that every other box takes the code paths it always
 * has.
 *
 * @internal
 */
final readonly class OverhangBounds
{
    private function __construct(
        public int $width,
        public int $length,
        public int $depth,
        public int $left,
        public int $right,
        public int $front,
        public int $back,
    ) {
        if ($left > PHP_INT_MAX - $width || $right > PHP_INT_MAX - $width - $left
            || $front > PHP_INT_MAX - $length || $back > PHP_INT_MAX - $length - $front) {
            throw new InvalidArgumentException('Allowed overhang is too large: the box with its overhang would be wider or longer than can be represented');
        }
        $envelopeWidth = $width + $left + $right;
        $envelopeLength = $length + $front + $back;
        if (($envelopeWidth > 0 && $envelopeLength > intdiv(PHP_INT_MAX, $envelopeWidth))
            || ($envelopeWidth * $envelopeLength > 0 && $depth > intdiv(PHP_INT_MAX, $envelopeWidth * $envelopeLength))) {
            throw new InvalidArgumentException('Allowed overhang is too large: the volume of the box with its overhang cannot be represented');
        }
    }

    /**
     * The box's allowed overhang, or null if it does not opt in or allows none.
     */
    public static function forBox(Box $box): ?self
    {
        if (!$box instanceof OverhangBox) {
            return null;
        }
        $allowance = $box->getAllowedOverhang();
        if ($allowance->isZero()) {
            return null;
        }

        return new self($box->getInnerWidth(), $box->getInnerLength(), $box->getInnerDepth(), $allowance->left, $allowance->right, $allowance->front, $allowance->back);
    }

    /**
     * Whether every item lies within its caps (always, for a box without an allowed overhang: the usual in-box rules
     * apply instead).
     *
     * @param iterable<PackedItem> $items
     */
    public static function packingIsLegal(Box $box, iterable $items): bool
    {
        $bounds = self::forBox($box);
        if ($bounds === null) {
            return true;
        }
        foreach ($items as $item) {
            if (!$bounds->admitsPackedItem($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Volume the items in the box could take up at most: that of the box with its overhang (the envelope) if it has
     * one, else the inner volume.
     */
    public static function capacityVolume(Box $box): int
    {
        return self::forBox($box)?->envelopeVolume() ?? $box->getInnerWidth() * $box->getInnerLength() * $box->getInnerDepth();
    }

    /**
     * What a box's entry in a visualiser URL has after its items: [[left, right, front, back]] for a box with an
     * allowed overhang, nothing for any other (so that its entry is as it always was).
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function visualiserElements(Box $box): array
    {
        $bounds = self::forBox($box);

        return $bounds === null ? [] : [[$bounds->left, $bounds->right, $bounds->front, $bounds->back]];
    }

    /**
     * The most an item of the given extent across an edge may hang over it: the allowance, or the item's cap
     * ({@see AllowedOverhang::ITEM_CAP_PERCENT} % of the extent, rounded down) if less. Rounding down is exact: an
     * overhang o of an item of extent e is within the cap if and only if 100 o <= 15 e.
     */
    public static function cap(int $allowance, int $extent): int
    {
        if ($extent <= 0) {
            return 0;
        }

        // floor(extent * percent / 100), without the product overflowing
        $itemCap = intdiv($extent, 100) * AllowedOverhang::ITEM_CAP_PERCENT + intdiv(($extent % 100) * AllowedOverhang::ITEM_CAP_PERCENT, 100);

        return min($allowance, $itemCap);
    }

    public function capLeft(int $width): int
    {
        return self::cap($this->left, $width);
    }

    public function capRight(int $width): int
    {
        return self::cap($this->right, $width);
    }

    public function capFront(int $length): int
    {
        return self::cap($this->front, $length);
    }

    public function capBack(int $length): int
    {
        return self::cap($this->back, $length);
    }

    /**
     * Whether an item of this width and length (square to the box) fits the empty box with its overhang.
     */
    public function fits(int $width, int $length): bool
    {
        return $width <= $this->width + $this->capLeft($width) + $this->capRight($width)
            && $length <= $this->length + $this->capFront($length) + $this->capBack($length);
    }

    /**
     * Whether an item of this width and length (square to the box) may be placed with its corner at x, y.
     */
    public function admits(int $x, int $y, int $width, int $length): bool
    {
        return $x >= -$this->capLeft($width)
            && $x + $width <= $this->width + $this->capRight($width)
            && $y >= -$this->capFront($length)
            && $y + $length <= $this->length + $this->capBack($length);
    }

    /**
     * Whether a packed item is within its caps and the box's depth. Items at an angle must lie within the deck.
     */
    public function admitsPackedItem(PackedItem $item): bool
    {
        if ($item->z < 0 || $item->z + $item->depth > $this->depth) {
            return false;
        }
        if ($item->isAngled()) {
            return $item->x >= 0 && $item->x + $item->boundingWidth <= $this->width
                && $item->y >= 0 && $item->y + $item->boundingLength <= $this->length;
        }

        return $this->admits($item->x, $item->y, $item->width, $item->length);
    }

    /**
     * A legal x for an item of this width in the empty box (0 if it fits the deck's width, else as far left as its
     * right cap requires). Only meaningful if the item fits.
     */
    public function startX(int $width): int
    {
        return min(0, $this->width + $this->capRight($width) - $width);
    }

    /**
     * A legal y for an item of this length in the empty box, as {@see startX()}.
     */
    public function startY(int $length): int
    {
        return min(0, $this->length + $this->capBack($length) - $length);
    }

    /**
     * Width of the box with its overhang on both sides.
     */
    public function envelopeWidth(): int
    {
        return $this->width + $this->left + $this->right;
    }

    /**
     * Length of the box with its overhang at the front and back.
     */
    public function envelopeLength(): int
    {
        return $this->length + $this->front + $this->back;
    }

    public function envelopeVolume(): int
    {
        return $this->envelopeWidth() * $this->envelopeLength() * $this->depth;
    }
}
