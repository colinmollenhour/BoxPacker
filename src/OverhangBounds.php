<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use InvalidArgumentException;

use function array_column;
use function array_fill;
use function array_keys;
use function array_map;
use function assert;
use function count;
use function intdiv;
use function max;
use function min;
use function usort;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * The allowed overhang of one box as used while packing it: the deck (the box's inner width, length and depth) and
 * the two allowances (sides, at x = 0 and x = width; front and back, at y = 0 and y = length), with the per-item caps
 * worked out from them. Coordinates are relative to the deck.
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
        public int $sides,
        public int $frontAndBack,
    ) {
        // AllowedOverhang's constructor rejects these, but an instance can be made without it (by unserialize())
        if ($sides < 0 || $frontAndBack < 0) {
            throw new InvalidArgumentException("Allowed overhang cannot be negative (sides {$sides}, frontAndBack {$frontAndBack})");
        }
        if ($sides > intdiv(PHP_INT_MAX - $width, 2) || $frontAndBack > intdiv(PHP_INT_MAX - $length, 2)) {
            throw new InvalidArgumentException('Allowed overhang is too large: the box with its overhang would be wider or longer than can be represented');
        }
        $envelopeWidth = $this->envelopeWidth();
        $envelopeLength = $this->envelopeLength();
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

        return new self($box->getInnerWidth(), $box->getInnerLength(), $box->getInnerDepth(), $allowance->sides, $allowance->frontAndBack);
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
     * one, else the inner volume. The envelope's volume is always an int (an allowance too large for that makes packing
     * throw), but the inner volume of any other box is, as it always was, the product of its inner dimensions: a float
     * if that is too large for an int.
     */
    public static function capacityVolume(Box $box): int|float
    {
        return self::forBox($box)?->envelopeVolume() ?? $box->getInnerWidth() * $box->getInnerLength() * $box->getInnerDepth();
    }

    /**
     * What a box's entry in a visualiser URL has after its items: [[left, right, front, back]] for a box with an
     * allowed overhang (the sides allowance at both sides, the front and back one at both ends: the visualiser draws
     * each edge), nothing for any other (so that its entry is as it always was).
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function visualiserElements(Box $box): array
    {
        $bounds = self::forBox($box);

        return $bounds === null ? [] : [[$bounds->sides, $bounds->sides, $bounds->frontAndBack, $bounds->frontAndBack]];
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

    /**
     * The most an item of this width may hang over each side (the left and the right edge).
     */
    public function sideCap(int $width): int
    {
        return self::cap($this->sides, $width);
    }

    /**
     * The most an item of this length may hang over each end (the front and the back edge).
     */
    public function endCap(int $length): int
    {
        return self::cap($this->frontAndBack, $length);
    }

    /**
     * Whether the item may hang over an edge: whether, in some orientation its rotation allows in which it fits the box
     * with its overhang, its cap across an edge is above 0. An item with no volume (a zero width, length or depth) is
     * placed on its own, on the deck if it fits it in such an orientation, so may then hang over only if its placement
     * callback ({@see ConstrainedPlacementItem}) refuses that. If no item that can go in may, the overhang cannot be
     * used.
     */
    public function canHangOver(Item $item): bool
    {
        $width = $item->getWidth();
        $length = $item->getLength();
        $depth = $item->getDepth();
        $orientations = match ($item->getAllowedRotation()) {
            Rotation::Never => [[$width, $length, $depth]],
            Rotation::KeepFlat => [[$width, $length, $depth], [$length, $width, $depth]],
            Rotation::BestFit => [[$width, $length, $depth], [$length, $width, $depth], [$width, $depth, $length], [$length, $depth, $width], [$depth, $width, $length], [$depth, $length, $width]],
        };
        $placedOnTheDeck = ($width === 0 || $length === 0 || $depth === 0) && !$item instanceof ConstrainedPlacementItem;
        $canHangOver = false;
        foreach ($orientations as [$acrossSides, $acrossEnds, $height]) {
            if ($height > $this->depth || !$this->fits($acrossSides, $acrossEnds)) {
                continue;
            }
            if ($placedOnTheDeck && $acrossSides <= $this->width && $acrossEnds <= $this->length) {
                return false;
            }
            $canHangOver = $canHangOver || $this->sideCap($acrossSides) > 0 || $this->endCap($acrossEnds) > 0;
        }

        return $canHangOver;
    }

    /**
     * Whether an item of this width and length (square to the box) fits the empty box with its overhang.
     */
    public function fits(int $width, int $length): bool
    {
        return $width <= $this->width + 2 * $this->sideCap($width)
            && $length <= $this->length + 2 * $this->endCap($length);
    }

    /**
     * Whether an item of this width and length (square to the box) may be placed with its corner at x, y.
     */
    public function admits(int $x, int $y, int $width, int $length): bool
    {
        $sideCap = $this->sideCap($width);
        $endCap = $this->endCap($length);

        return $x >= -$sideCap
            && $x + $width <= $this->width + $sideCap
            && $y >= -$endCap
            && $y + $length <= $this->length + $endCap;
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
     * Where an item of this width goes on its own in the empty box: x = 0 if it fits the deck's width, else hanging
     * over both sides equally (as {@see centringShift()} would place it). Only meaningful if the item fits.
     */
    public function startX(int $width): int
    {
        return self::start($this->width, $width, $this->sideCap($width));
    }

    /**
     * Where an item of this length goes on its own in the empty box, as {@see startX()}.
     */
    public function startY(int $length): int
    {
        return self::start($this->length, $length, $this->endCap($length));
    }

    /**
     * How far to move a packing (in the deck's frame) along x and along y to balance its overhang: on each axis on which
     * it hangs over an edge, so that the larger of its two overhangs there (a gap counting as a negative overhang) is
     * as small as can be with every item still within its caps at both edges (items at an angle within the deck). Of
     * two equally good moves, the smaller. Nothing on an axis on which nothing hangs over.
     *
     * Moving every item by the same amount keeps every item's support and keeps them apart, so only the caps limit it.
     *
     * @param  iterable<PackedItem>  $items
     * @return array{0: int, 1: int}
     */
    public function centringShift(iterable $items): array
    {
        $reach = $this->reach($items);
        if ($reach === null) {
            return [0, 0];
        }

        return [self::shift($this->width, ...$reach[0]), self::shift($this->length, ...$reach[1])];
    }

    /**
     * How far to move each of some groups of items (in the deck's frame, each group moving as one) along x and along y
     * to keep the load on the deck as far as it fits there, closing the gaps within it where the load hangs over.
     *
     * Along each axis, groups whose extents (by their items' bounding boxes) overlap or touch form one part of the
     * load. The gaps between the parts are closed, keeping the parts in order, and the closed load is then balanced as
     * {@see centringShift()} balances a packing. That is done only on an axis on which the load would still hang over
     * an edge if it were balanced as it is, and only if closing the gaps makes the larger of its two overhangs there
     * smaller: on any other axis (as on one with no gaps), every group gets the move centringShift() gives the load.
     *
     * Items that touch one another (as an item and those supporting it do) overlap or touch along both axes, so are in
     * the same part along each, and move together; parts stay apart. A part moved to close a gap starts no lower than
     * the first part ends, which is past the low edge (no item can lie wholly beyond an edge), and ends no higher than
     * it did, so every item is still within its caps (items at an angle within the deck) before the closed load is
     * balanced.
     *
     * Returned along x and along y: each group's move (by its index), the total length of the gaps between the parts as
     * found, whether they are closed, and the parts as found, as [start, end, move] (if not closed, the whole load as
     * one part).
     *
     * @param  list<iterable<PackedItem>>                                                                        $groups each non-empty
     * @return list<array{moves: list<int>, gap: int, closed: bool, parts: list<array{0: int, 1: int, 2: int}>}> along x and along y
     */
    public function closingMoves(array $groups): array
    {
        $reaches = [];
        foreach ($groups as $group) {
            $reach = $this->reach($group);
            assert($reach !== null);
            $reaches[] = $reach;
        }

        return [self::closingMovesAlong($this->width, array_column($reaches, 0)), self::closingMovesAlong($this->length, array_column($reaches, 1))];
    }

    /**
     * Width of the box with its overhang on both sides.
     */
    public function envelopeWidth(): int
    {
        return $this->width + 2 * $this->sides;
    }

    /**
     * Length of the box with its overhang at the front and back.
     */
    public function envelopeLength(): int
    {
        return $this->length + 2 * $this->frontAndBack;
    }

    public function envelopeVolume(): int
    {
        return $this->envelopeWidth() * $this->envelopeLength() * $this->depth;
    }

    /**
     * Where some items reach along x and along y: [lowest start, highest end, most negative move, most positive move]
     * on each, the moves being the furthest the items can go with every one within its caps (null for no items).
     *
     * @param  iterable<PackedItem>                                                                           $items
     * @return array{0: array{0: int, 1: int, 2: int, 3: int}, 1: array{0: int, 1: int, 2: int, 3: int}}|null
     */
    private function reach(iterable $items): ?array
    {
        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = $loX = $loY = PHP_INT_MIN;
        $hiX = $hiY = PHP_INT_MAX;
        foreach ($items as $item) {
            [$capX, $capY] = $item->isAngled() ? [0, 0] : [$this->sideCap($item->width), $this->endCap($item->length)];
            $x2 = $item->x + $item->boundingWidth;
            $y2 = $item->y + $item->boundingLength;
            $minX = min($minX, $item->x);
            $minY = min($minY, $item->y);
            $maxX = max($maxX, $x2);
            $maxY = max($maxY, $y2);
            $loX = max($loX, -$capX - $item->x);
            $loY = max($loY, -$capY - $item->y);
            $hiX = min($hiX, $this->width + $capX - $x2);
            $hiY = min($hiY, $this->length + $capY - $y2);
        }
        if ($minX === PHP_INT_MAX) {
            return null;
        }

        return [[$minX, $maxX, $loX, $hiX], [$minY, $maxY, $loY, $hiY]];
    }

    /**
     * {@see closingMoves()} along one axis of the given span, given where each group reaches along it (see
     * {@see reach()}).
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>                                                 $reaches
     * @return array{moves: list<int>, gap: int, closed: bool, parts: list<array{0: int, 1: int, 2: int}>}
     */
    private static function closingMovesAlong(int $span, array $reaches): array
    {
        if ($reaches === []) {
            return ['moves' => [], 'gap' => 0, 'closed' => false, 'parts' => []];
        }

        // the parts, from the lowest start up (of groups starting at the same place, the first first), and the move
        // closing the gaps before each gives every group in it
        $order = array_keys($reaches);
        usort($order, static fn (int $a, int $b): int => [$reaches[$a][0], $a] <=> [$reaches[$b][0], $b]);
        $closing = [];
        $parts = [];
        $gap = 0;
        $end = PHP_INT_MIN;
        foreach ($order as $group) {
            [$start, $groupEnd] = $reaches[$group];
            if ($parts === [] || $start > $end) {
                if ($parts !== []) {
                    $gap += $start - $end;
                }
                $parts[] = [$start, $groupEnd, -$gap];
            }
            $end = max($end, $groupEnd);
            $parts[count($parts) - 1][1] = $end;
            $closing[$group] = -$gap;
        }

        $asFound = self::loadReach($reaches, []);
        $shift = self::shift($span, ...$asFound);
        $moves = array_fill(0, count($reaches), $shift);
        if ($gap > 0) {
            $closed = self::loadReach($reaches, $closing);
            $closedShift = self::shift($span, ...$closed);
            // the larger overhang balanced as found, and with the gaps closed
            $larger = max(-$asFound[0] - $shift, $asFound[1] + $shift - $span);
            $closedLarger = max(-$closed[0] - $closedShift, $closed[1] + $closedShift - $span);
            if ($larger > 0 && $closedLarger < $larger) {
                foreach ($closing as $group => $move) {
                    $moves[$group] = $move + $closedShift;
                }

                return ['moves' => $moves, 'gap' => $gap, 'closed' => true, 'parts' => array_map(static fn (array $part): array => [$part[0], $part[1], $part[2] + $closedShift], $parts)];
            }
        }

        return ['moves' => $moves, 'gap' => $gap, 'closed' => false, 'parts' => [[$asFound[0], $asFound[1], $shift]]];
    }

    /**
     * Where groups reach along an axis once each is moved by the given amount (none if not given), as one load: [lowest
     * start, highest end, most negative move, most positive move].
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}> $reaches
     * @param  array<int, int>                             $moves
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function loadReach(array $reaches, array $moves): array
    {
        $min = $hi = PHP_INT_MAX;
        $max = $lo = PHP_INT_MIN;
        foreach ($reaches as $group => [$start, $end, $groupLo, $groupHi]) {
            $move = $moves[$group] ?? 0;
            $min = min($min, $start + $move);
            $max = max($max, $end + $move);
            $lo = max($lo, $groupLo - $move);
            $hi = min($hi, $groupHi - $move);
        }

        return [$min, $max, $lo, $hi];
    }

    /**
     * The position along an axis of the given span of one item of the given extent and cap, alone: at 0 if it fits
     * the span, else as far towards 0 as its far cap requires, then balanced.
     */
    private static function start(int $span, int $extent, int $cap): int
    {
        $position = min(0, $span + $cap - $extent);

        return $position + self::shift($span, $position, $position + $extent, -$cap - $position, $span + $cap - $position - $extent);
    }

    /**
     * The move along one axis of the given span that balances a load reaching from $min to $max, given that it must lie
     * between $lo and $hi (see {@see centringShift()}).
     */
    private static function shift(int $span, int $min, int $max, int $lo, int $hi): int
    {
        $before = -$min; // overhang at the low edge (negative: a gap)
        $after = $max - $span; // and at the high edge
        if ($before <= 0 && $after <= 0) {
            return 0;
        }
        // the packing is within the caps, so not moving it is always possible
        assert($lo <= 0 && $hi >= 0);
        if ($lo > 0 || $hi < 0) {
            return 0;
        }

        // the larger overhang, max(before - shift, after + shift), is least at (before - after) / 2; intdiv() rounds
        // towards 0, so of the two best shifts when that is not whole, the smaller is taken
        return max($lo, min($hi, intdiv($before - $after, 2)));
    }
}
