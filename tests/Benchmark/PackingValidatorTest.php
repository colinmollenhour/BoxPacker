<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Benchmark;

use DVDoug\BoxPacker\AllowedOverhang;
use DVDoug\BoxPacker\Box;
use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedItem;
use DVDoug\BoxPacker\PackedItemList;
use DVDoug\BoxPacker\Rotation;
use DVDoug\BoxPacker\Test\OverhangTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use DVDoug\BoxPacker\Test\ZeroOverhangBox;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function deg2rad;
use function sin;

/**
 * The validator's overhang rules, including at extents so large that 100 × overhang or 15 × extent would overflow an
 * int, and its check of the balance of a packing. The expected limits are worked out by hand, not with the library's
 * helpers.
 */
#[CoversNothing]
class PackingValidatorTest extends TestCase
{
    /**
     * 10^18 + 19: 15 % of it is 1.5 × 10^17 + 2.85, so the limit is 150,000,000,000,000,002.
     */
    private const EXTENT = 1000000000000000019;

    private const LIMIT = 150000000000000002;

    private const DECK = 1000000000000000000;

    /**
     * A packed box of these items, built without the box's own checks (which, with assertions enabled, would reject
     * the illegal ones before the validator could see them).
     *
     * @param iterable<PackedItem> $items
     */
    private static function packedBox(Box $box, iterable $items): PackedBox
    {
        $list = new PackedItemList();
        foreach ($items as $item) {
            $list->insert($item);
        }
        $class = new ReflectionClass(PackedBox::class);
        $packedBox = $class->newInstanceWithoutConstructor();
        $class->getProperty('box')->setValue($packedBox, $box);
        $class->getProperty('items')->setValue($packedBox, $list);

        return $packedBox;
    }

    private static function box(AllowedOverhang $allowance): Box
    {
        return new OverhangTestBox('Box', self::DECK, 1, 1, 0, self::DECK, 1, 1, 1, $allowance);
    }

    private static function item(int $x): PackedItem
    {
        return new PackedItem(new TestItem('Item', self::EXTENT, 1, 1, 0, Rotation::Never), $x, 0, 0, self::EXTENT, 1, 1);
    }

    /**
     * A 1000 × 1000 × 100 box with the allowance, or without one if null.
     */
    private static function deck(?AllowedOverhang $allowance): Box
    {
        return $allowance === null
            ? new TestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, 1000)
            : new OverhangTestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, 1000, $allowance);
    }

    /**
     * A 1100 × 100 × 100 item turned by 40°: its bounding box is 907 × 784, so it lies within a 1000 wide deck from
     * x = 0 to x = 93.
     */
    private static function rod(int $x, int $y): PackedItem
    {
        return new PackedItem(new TestItem('Rod', 1100, 100, 100, 0, Rotation::KeepFlat), $x, $y, 0, 1100, 100, 100, 40.0);
    }

    public function testLargeExtentLeftBoundary(): void
    {
        $box = self::box(new AllowedOverhang(sides: 200000000000000000));

        self::assertSame([], PackingValidator::problems(self::packedBox($box, [self::item(-self::LIMIT)])));
        self::assertSame(['item 0 hangs 150000000000000003 over the left edge, more than 15 % of its extent of 1000000000000000019'], PackingValidator::problems(self::packedBox($box, [self::item(-self::LIMIT - 1)])));
    }

    public function testLargeExtentRightBoundary(): void
    {
        $box = self::box(new AllowedOverhang(sides: 200000000000000000));
        $x = self::DECK - self::EXTENT + self::LIMIT; // 149,999,999,999,999,983

        self::assertSame([], PackingValidator::problems(self::packedBox($box, [self::item($x)])));
        self::assertSame(['item 0 hangs 150000000000000003 over the right edge, more than 15 % of its extent of 1000000000000000019'], PackingValidator::problems(self::packedBox($box, [self::item($x + 1)])));
    }

    public function testLargeAllowanceBoundary(): void
    {
        $box = self::box(new AllowedOverhang(sides: self::LIMIT - 1));

        self::assertSame([], PackingValidator::problems(self::packedBox($box, [self::item(-self::LIMIT + 1)])));
        self::assertSame(['item 0 hangs 150000000000000002 over the left edge, more than its allowance of 150000000000000001'], PackingValidator::problems(self::packedBox($box, [self::item(-self::LIMIT)])));
    }

    public function testLargeAllowanceBoundaryAtTheRight(): void
    {
        $box = self::box(new AllowedOverhang(sides: self::LIMIT - 1));
        $x = self::DECK - self::EXTENT + self::LIMIT - 1;

        self::assertSame([], PackingValidator::problems(self::packedBox($box, [self::item($x)])));
        self::assertSame(['item 0 hangs 150000000000000002 over the right edge, more than its allowance of 150000000000000001'], PackingValidator::problems(self::packedBox($box, [self::item($x + 1)])));
    }

    public function testFrontAndBackAllowanceAppliesAtBothEnds(): void
    {
        $box = self::deck(new AllowedOverhang(frontAndBack: 50));
        $item = new TestItem('Item', 100, 1100, 100, 0, Rotation::Never);

        self::assertSame([], PackingValidator::problems(self::packedBox($box, [new PackedItem($item, 0, -50, 0, 100, 1100, 100)])));
        self::assertSame(['item 0 hangs 51 over the front edge, more than its allowance of 50'], PackingValidator::problems(self::packedBox($box, [new PackedItem($item, 0, -51, 0, 100, 1100, 100)])));
        self::assertSame(['item 0 hangs 51 over the back edge, more than its allowance of 50'], PackingValidator::problems(self::packedBox($box, [new PackedItem($item, 0, -49, 0, 100, 1100, 100)])));
        self::assertSame(['item 0 hangs 1 over the left edge, more than its allowance of 0'], PackingValidator::problems(self::packedBox($box, [new PackedItem($item, -1, -50, 0, 100, 1100, 100)])));
    }

    public function testFifteenPercentAppliesAtTheFrontAndBack(): void
    {
        // 100 long, so 15 may hang over the front or the back, though the allowance is 50
        $box = self::deck(new AllowedOverhang(frontAndBack: 50));
        $item = new TestItem('Item', 100, 100, 100, 0, Rotation::Never);

        self::assertSame([], PackingValidator::problems(self::packedBox($box, [new PackedItem($item, 0, -15, 0, 100, 100, 100)])));
        self::assertSame(['item 0 hangs 16 over the front edge, more than 15 % of its extent of 100'], PackingValidator::problems(self::packedBox($box, [new PackedItem($item, 0, -16, 0, 100, 100, 100)])));
        self::assertSame([], PackingValidator::problems(self::packedBox($box, [new PackedItem($item, 0, 915, 0, 100, 100, 100)])));
        self::assertSame(['item 0 hangs 16 over the back edge, more than 15 % of its extent of 100'], PackingValidator::problems(self::packedBox($box, [new PackedItem($item, 0, 916, 0, 100, 100, 100)])));
    }

    public function testAngledItemsMustStayOnTheDeck(): void
    {
        $box = self::deck(new AllowedOverhang(sides: 100, frontAndBack: 100));

        self::assertSame([], PackingValidator::problems(self::packedBox($box, [self::rod(0, 0)])));
        self::assertSame([], PackingValidator::problems(self::packedBox($box, [self::rod(93, 216)])));
        self::assertSame(['item 0 out of bounds'], PackingValidator::problems(self::packedBox($box, [self::rod(94, 0)])));
        self::assertSame(['item 0 out of bounds'], PackingValidator::problems(self::packedBox($box, [self::rod(-1, 0)])));
        self::assertSame(['item 0 out of bounds'], PackingValidator::problems(self::packedBox($box, [self::rod(0, 217)])));
    }

    /**
     * A packing in a 1000 × 1000 × 100 box with the allowance (none if null), built without the box's own checks.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int, 4?: int, 5?: int}> $items [x, y, width, length, depth (100 if not given), z (0 if not given)]
     */
    private static function packing(?AllowedOverhang $allowance, array $items): PackedBox
    {
        $packedItems = [];
        foreach ($items as $index => [$x, $y, $width, $length]) {
            $depth = $items[$index][4] ?? 100;
            $packedItems[] = new PackedItem(new TestItem("Item {$index}", $width, $length, $depth, 0, Rotation::Never), $x, $y, $items[$index][5] ?? 0, $width, $length, $depth);
        }

        return self::packedBox(self::deck($allowance), $packedItems);
    }

    public function testItemsWithNoVolumeTakeNoSpace(): void
    {
        // with an allowed overhang, an item with a zero width or length (and a depth) inside the footprint of another
        // does not collide with it, though it is still kept within its own limits; two items with a volume still do
        self::assertSame([], PackingValidator::problems(self::packing(new AllowedOverhang(sides: 100), [[-50, 0, 1100, 500], [0, 0, 0, 500]])));
        self::assertSame([], PackingValidator::problems(self::packing(new AllowedOverhang(frontAndBack: 100), [[0, -50, 500, 1100], [0, 0, 500, 0]])));
        self::assertSame(['item 1 hangs 1 over the right edge, more than 15 % of its extent of 0'], PackingValidator::problems(self::packing(new AllowedOverhang(sides: 100), [[-50, 0, 1100, 500], [1001, 0, 0, 500]])));
        self::assertSame(['items 0 and 1 overlap'], PackingValidator::problems(self::packing(new AllowedOverhang(sides: 100), [[-50, 0, 1100, 500], [0, 0, 1, 500]])));
    }

    public function testItemsWithNoVolumeStillCollideWithoutAnAllowedOverhang(): void
    {
        // a board standing inside a carton's footprint, and a sheet lying inside it half way up: a box without an
        // allowed overhang (or with a zero one) is checked as it always was
        foreach ([null, new AllowedOverhang()] as $allowance) {
            self::assertSame(['items 0 and 1 overlap'], PackingValidator::problems(self::packing($allowance, [[0, 0, 1000, 500], [500, 0, 0, 500]])));
            self::assertSame(['items 0 and 1 overlap'], PackingValidator::problems(self::packing($allowance, [[0, 0, 1000, 500], [0, 0, 500, 500, 0, 50]])));
            // touching a face is not a collision
            self::assertSame([], PackingValidator::problems(self::packing($allowance, [[0, 0, 1000, 500], [0, 0, 0, 500]])));
        }
    }

    public function testAZeroAllowanceIsCheckedAsNone(): void
    {
        // an item past the left and the front edge is one problem in a box without an allowed overhang, so in one with
        // a zero one too (rather than an allowance and a 15 % breach at each edge)
        $item = new PackedItem(new TestItem('Item', 6, 6, 1, 0, Rotation::Never), -1, -1, 0, 6, 6, 1);
        $plain = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000);
        foreach ([$plain, ZeroOverhangBox::wrap($plain), new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang())] as $box) {
            $packedBox = self::packedBox($box, [$item]);
            self::assertSame(['item 0 out of bounds'], PackingValidator::problems($packedBox));
            self::assertSame([], PackingValidator::centringProblems($packedBox));
            self::assertSame(1.0, PackingValidator::minimumSupport($packedBox));
        }
    }

    public function testCentring(): void
    {
        $allowance = new AllowedOverhang(sides: 200, frontAndBack: 200);

        // balanced, and balanced to within one unit
        self::assertSame([], PackingValidator::centringProblems(self::packing($allowance, [[-100, -100, 1200, 1200]])));
        self::assertSame([], PackingValidator::centringProblems(self::packing($allowance, [[-77, 0, 1153, 1000]])));
        self::assertSame([], PackingValidator::centringProblems(self::packing($allowance, [[-76, 0, 1153, 1000]])));
        // nothing hangs over: gaps are not balanced
        self::assertSame([], PackingValidator::centringProblems(self::packing($allowance, [[0, 300, 500, 500]])));
        // hangs over one side only, with room to move
        self::assertSame(['the load hangs over 100 and -50 along x: moving it up by 1 would balance it better'], PackingValidator::centringProblems(self::packing($allowance, [[-100, 0, 1050, 1000]])));
        self::assertSame(['the load hangs over -50 and 100 along y: moving it down by 1 would balance it better'], PackingValidator::centringProblems(self::packing($allowance, [[0, 50, 1000, 1050]])));
        // unbalanced, but every move is held back by an item's cap
        self::assertSame([], PackingValidator::centringProblems(self::packing($allowance, [[22, 0, 1150, 500], [-15, 500, 100, 500]])));
        // balanced as far as the caps allow (the narrow item, beside the wide one, at its cap of 15 at the right), and
        // one unit short of it
        $capped = self::packing($allowance, [[-120, 0, 1150, 500], [915, 500, 100, 500]]);
        $short = self::packing($allowance, [[-121, 0, 1150, 500], [914, 500, 100, 500]]);
        self::assertSame([], PackingValidator::problems($capped));
        self::assertSame([], PackingValidator::problems($short));
        self::assertSame([], PackingValidator::centringProblems($capped));
        self::assertSame(['the load hangs over 121 and 29 along x: moving it up by 1 would balance it better'], PackingValidator::centringProblems($short));
    }

    public function testCentringKeepsAngledItemsOnTheDeck(): void
    {
        // a plank hanging 100 over the left could move right, but the angled rod beside it may not pass the right edge
        // of the deck; with one unit of room, it should move by that unit
        $box = self::deck(new AllowedOverhang(sides: 100, frontAndBack: 100));
        $plank = new PackedItem(new TestItem('Plank', 700, 100, 100, 0, Rotation::Never), -100, 0, 0, 700, 100, 100);

        self::assertSame([], PackingValidator::centringProblems(self::packedBox($box, [$plank, self::rod(93, 200)])));
        self::assertSame(['the load hangs over 100 and -1 along x: moving it up by 1 would balance it better'], PackingValidator::centringProblems(self::packedBox($box, [$plank, self::rod(92, 200)])));
    }

    public function testCentringOfItemsWithNoVolume(): void
    {
        $allowance = new AllowedOverhang(sides: 100);

        // each on its own: a sheet that fits the deck may be anywhere on it; one that does not must hang over equally,
        // and does not count towards the load
        self::assertSame([], PackingValidator::centringProblems(self::packing($allowance, [[0, 0, 1000, 1000], [300, 0, 500, 500, 0]])));
        self::assertSame([], PackingValidator::centringProblems(self::packing($allowance, [[0, 0, 1000, 1000], [-50, 0, 1100, 500, 0]])));
        self::assertSame(['Item 1 (no volume) hangs over 0 and 100 along x: moving it down by 1 would balance it better'], PackingValidator::centringProblems(self::packing($allowance, [[0, 0, 1000, 1000], [0, 0, 1100, 500, 0]])));
    }

    public function testCentringOnlyAppliesToBoxesWithAnAllowedOverhang(): void
    {
        $list = new PackedItemList();
        $list->insert(new PackedItem(new TestItem('Item', 100, 100, 100, 0, Rotation::Never), 0, 0, 0, 100, 100, 100));

        self::assertSame([], PackingValidator::centringProblems(new PackedBox(self::deck(null), $list)));
        // nor to one whose allowance is zero, even for a packing that would not be balanced with an allowance
        self::assertSame([], PackingValidator::centringProblems(self::packing(new AllowedOverhang(), [[-100, 0, 1050, 1000]])));
    }

    /**
     * A packing in a 1000 × 1000 box with the allowance (none if null) as it was before a move and as it is after,
     * built without the box's own checks.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4?: int, 5?: int}> $items [x, y, width, length, depth (100 if not given), z (0 if not given)] before
     * @param  list<array{0: int, 1: int}>                                   $moves [x, y] by which each item moved
     * @return array{0: list<PackedItem>, 1: PackedBox}
     */
    private static function moved(?AllowedOverhang $allowance, array $items, array $moves): array
    {
        $before = $after = [];
        foreach ($items as $index => [$x, $y, $width, $length]) {
            $depth = $items[$index][4] ?? 100;
            $z = $items[$index][5] ?? 0;
            $item = new TestItem("Item {$index}", $width, $length, $depth, 0, Rotation::Never);
            [$moveX, $moveY] = $moves[$index] ?? $moves[0];
            $before[] = new PackedItem($item, $x, $y, $z, $width, $length, $depth);
            $after[] = new PackedItem($item, $x + $moveX, $y + $moveY, $z, $width, $length, $depth);
        }

        return [$before, self::packedBox(self::deck($allowance), $after)];
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4?: int, 5?: int}> $items
     * @param  list<array{0: int, 1: int}>                                   $moves
     * @return list<string>
     */
    private static function shiftProblems(?AllowedOverhang $allowance, array $items, array $moves): array
    {
        return PackingValidator::shiftProblems(...self::moved($allowance, $items, $moves));
    }

    /**
     * The packing, once checked to be physically possible.
     */
    private static function possible(PackedBox $packedBox): PackedBox
    {
        self::assertSame([], PackingValidator::problems($packedBox));

        return $packedBox;
    }

    /**
     * Check that a packing and the same packing moved (see {@see moved()}) are both physically possible.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int, 4?: int, 5?: int}> $items
     * @param list<array{0: int, 1: int}>                                   $moves
     */
    private static function assertPossibleMove(?AllowedOverhang $allowance, array $items, array $moves): void
    {
        [$before, $after] = self::moved($allowance, $items, $moves);
        self::possible(self::packedBox(self::deck($allowance), $before));
        self::possible($after);
    }

    public function testShift(): void
    {
        $allowance = new AllowedOverhang(sides: 200, frontAndBack: 200);

        // 100 over the left and a gap of 50 at the right: moved by half the difference, 75; along y, nothing hangs over
        self::assertSame([], self::shiftProblems($allowance, [[-100, 0, 1050, 1000]], [[75, 0]]));
        self::assertSame(['the load was moved by 74,0, but should have been moved by 75,0'], self::shiftProblems($allowance, [[-100, 0, 1050, 1000]], [[74, 0]]));
        // and the same along y
        self::assertSame([], self::shiftProblems($allowance, [[0, -100, 1000, 1050]], [[0, 75]]));
        // nothing hangs over: not moved, though the gaps are unequal
        self::assertSame([], self::shiftProblems($allowance, [[0, 300, 500, 500]], [[0, 0]]));
        self::assertSame(['the load was moved by 250,250, but should have been moved by 0,0'], self::shiftProblems($allowance, [[0, 0, 500, 500]], [[250, 250]]));
        // a move that is not needed at all
        self::assertSame(['the load was moved by 1,0, but should have been moved by 0,0'], self::shiftProblems($allowance, [[-77, 0, 1153, 1000]], [[1, 0]]));
    }

    public function testShiftWithoutAnAllowedOverhang(): void
    {
        // nothing may hang over, so no packing is ever moved
        foreach ([null, new AllowedOverhang()] as $allowance) {
            self::assertSame([], self::shiftProblems($allowance, [[0, 0, 500, 500]], [[0, 0]]));
            self::assertSame(['the load was moved by 250,250, but should have been moved by 0,0'], self::shiftProblems($allowance, [[0, 0, 500, 500]], [[250, 250]]));
        }
    }

    public function testShiftOfAnOddDifferenceIsTheSmallerMove(): void
    {
        $allowance = new AllowedOverhang(sides: 200);

        // 150 over the left and 3 over the right: 73.5 would balance it, and 73 is the smaller of the two equally good
        // moves; mirrored, -73, not -74 (rounded towards zero, not down)
        self::assertSame([], self::shiftProblems($allowance, [[-150, 0, 1153, 1000]], [[73, 0]]));
        self::assertSame(['the load was moved by 74,0, but should have been moved by 73,0'], self::shiftProblems($allowance, [[-150, 0, 1153, 1000]], [[74, 0]]));
        self::assertSame([], self::shiftProblems($allowance, [[-3, 0, 1153, 1000]], [[-73, 0]]));
        self::assertSame(['the load was moved by -74,0, but should have been moved by -73,0'], self::shiftProblems($allowance, [[-3, 0, 1153, 1000]], [[-74, 0]]));
    }

    public function testShiftIsHeldBackByTheCaps(): void
    {
        $allowance = new AllowedOverhang(sides: 200);

        // 95 would balance the load, but the narrow item (cap 15) may only move 50 before it hangs too far over the right
        self::assertSame([], self::shiftProblems($allowance, [[-170, 0, 1150, 500], [865, 500, 100, 500]], [[50, 0]]));
        self::assertSame(['the load was moved by 95,0, but should have been moved by 50,0'], self::shiftProblems($allowance, [[-170, 0, 1150, 500], [865, 500, 100, 500]], [[95, 0]]));
        // and no move at all if every move is held back
        self::assertSame([], self::shiftProblems($allowance, [[22, 0, 1150, 500], [-15, 500, 100, 500]], [[0, 0]]));
    }

    public function testShiftOfALoadAlreadyBeyondItsLimits(): void
    {
        // 1200 wide, so 100 at each side at most: at x = -101 it is past its limit at the left, and the library leaves
        // such a packing where it is (rather than moving it 1 to the right, which would be legal)
        $allowance = new AllowedOverhang(sides: 100);

        self::assertSame(['the load was beyond its limits along x before the move'], self::shiftProblems($allowance, [[-101, 0, 1200, 1000]], [[0, 0]]));
        self::assertSame(['the load was beyond its limits along x before the move', 'the load was moved by 1,0, but should have been moved by 0,0'], self::shiftProblems($allowance, [[-101, 0, 1200, 1000]], [[1, 0]]));

        // nor are its gaps closed: 600 (cap 90) at its cap at the left and 480 (cap 72) one past its cap at the right,
        // with a gap of 83 between them, which closing would have moved by 50 and -33
        $twoGroups = [[-90, 0, 600, 500], [593, 500, 480, 500]];
        self::assertSame(['the load was beyond its limits along x before the move'], self::shiftProblems($allowance, $twoGroups, [[0, 0], [0, 0]]));
        self::assertSame(['the load was beyond its limits along x before the move', 'items were moved by different amounts: 50,0 and -33,0'], self::shiftProblems($allowance, $twoGroups, [[50, 0], [-33, 0]]));
    }

    public function testShiftMovesTheWholeLoad(): void
    {
        $allowance = new AllowedOverhang(sides: 200);

        self::assertSame(['items were moved by different amounts: 75,0 and 70,0'], self::shiftProblems($allowance, [[-100, 0, 1050, 500], [-100, 500, 1050, 500]], [[75, 0], [70, 0]]));
        // items with no volume are placed after the move, so may be anywhere
        self::assertSame([], self::shiftProblems($allowance, [[-100, 0, 1050, 500], [0, 0, 1100, 500, 0]], [[75, 0], [-50, 0]]));

        [$before, $after] = self::moved($allowance, [[-100, 0, 1050, 500], [-100, 500, 1050, 500]], [[75, 0]]);
        self::assertSame(['Item 2 was not in the packing after the move'], PackingValidator::shiftProblems([...$before, new PackedItem(new TestItem('Item 2', 1050, 500, 100, 0, Rotation::Never), -100, 0, 0, 1050, 500, 100)], $after));
        self::assertSame(['Item 1 was not in the packing before the move'], PackingValidator::shiftProblems([$before[0]], $after));
    }

    public function testShiftOnlyMovesItemsAcrossTheFloor(): void
    {
        // moved by the right amount, but also raised, or turned
        $item = new TestItem('Item', 1050, 1000, 50, 0, Rotation::BestFit);
        $before = [new PackedItem($item, -100, 0, 0, 1050, 1000, 50)];
        $box = self::deck(new AllowedOverhang(sides: 200));

        self::assertSame([], PackingValidator::shiftProblems($before, self::packedBox($box, [new PackedItem($item, -25, 0, 0, 1050, 1000, 50)])));
        self::assertSame(['Item was changed other than by moving it across the floor'], PackingValidator::shiftProblems($before, self::packedBox($box, [new PackedItem($item, -25, 0, 50, 1050, 1000, 50)])));
        self::assertSame(['Item was changed other than by moving it across the floor'], PackingValidator::shiftProblems($before, self::packedBox($box, [new PackedItem($item, -25, 0, 0, 1050, 50, 1000)])));
    }

    public function testAvoidableOverhang(): void
    {
        $allowance = new AllowedOverhang(sides: 200, frontAndBack: 200);

        // 600 at the front, hanging 90 over it, and 300 behind it after a gap: 900 in all, which fits the 1000
        self::assertSame(['the load hangs over 90 and -60 along y, though its items\' extents along it come to 900, which fits the deck\'s 1000'], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[0, -90, 500, 600], [0, 640, 500, 300]]))));
        // and the same along x
        self::assertSame(['the load hangs over 90 and -60 along x, though its items\' extents along it come to 900, which fits the deck\'s 1000'], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[-90, 0, 600, 500], [640, 0, 300, 500]]))));
        // closed up and on the deck
        self::assertSame([], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[0, 50, 500, 600], [0, 650, 500, 300]]))));
        // 600 and 480 come to 1080, more than the deck: their overhang cannot be avoided, gap or not
        self::assertSame([], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[-90, 0, 600, 500], [592, 0, 480, 500]]))));
        self::assertSame([], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[-40, 0, 600, 500], [560, 0, 480, 500]]))));
        // gaps, but nothing hanging over
        self::assertSame([], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[0, 0, 100, 100], [500, 500, 100, 100]]))));
        // touching items count once each: 400 + 600 is exactly the deck, so hanging over is avoidable
        self::assertSame(['the load hangs over 10 and -10 along x, though its items\' extents along it come to 1000, which fits the deck\'s 1000'], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[-10, 0, 400, 500], [390, 0, 600, 500]]))));
        // stretches that overlap count once (here items 0 and 2, side by side along the other axis, overlap from 350 to
        // 450 along x): with no gap, a load that fits the deck but hangs over could be moved onto it as a whole
        self::assertSame(['the load hangs over 50 and -250 along x, though its items\' extents along it come to 800, which fits the deck\'s 1000'], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[-50, 0, 500, 500], [-50, 500, 400, 500], [350, 500, 400, 500]]))));
        // a gap between them is not counted, but one unit more of the items than the deck is not avoidable
        self::assertSame(['the load hangs over 10 and -9 along x, though its items\' extents along it come to 1000, which fits the deck\'s 1000'], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[-10, 0, 400, 500], [391, 0, 600, 500]]))));
        self::assertSame([], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[-10, 0, 400, 500], [390, 0, 601, 500]]))));
    }

    public function testAvoidableOverhangLeavesOutItemsWithNoVolume(): void
    {
        $allowance = new AllowedOverhang(sides: 200, frontAndBack: 200);

        // a sheet hanging over on its own is not part of the load, and the load fits the deck
        self::assertSame([], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[0, 0, 1000, 1000], [-50, 0, 1100, 500, 0]]))));
        // nor is a sheet's extent counted with the load's
        self::assertSame(['the load hangs over 90 and -490 along y, though its items\' extents along it come to 600, which fits the deck\'s 1000'], PackingValidator::avoidableOverhangProblems(self::possible(self::packing($allowance, [[0, -90, 500, 600], [0, 600, 500, 400, 0]]))));
        // and a box without an allowed overhang has none to avoid
        $list = new PackedItemList();
        $list->insert(new PackedItem(new TestItem('Item', 100, 100, 100, 0, Rotation::Never), 0, 0, 0, 100, 100, 100));
        self::assertSame([], PackingValidator::avoidableOverhangProblems(new PackedBox(new TestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, 1000), $list)));
    }

    public function testAvoidableOverhangMeasuresAngledItemsByTheirBoundingBoxes(): void
    {
        $box = new OverhangTestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, 1000, new AllowedOverhang(sides: 100, frontAndBack: 100));
        $plank = new PackedItem(new TestItem('Plank', 300, 100, 100, 0, Rotation::Never), -45, 0, 0, 300, 100, 100);
        $rod = new PackedItem(new TestItem('Rod', 600, 50, 100, 0, Rotation::KeepFlat), 0, 0, 0, 600, 50, 100, 30.0);
        // at 30°, the rod's bounding box (about 545 wide) is narrower than its 600, and plank and rod fit the deck
        self::assertGreaterThan(500, $rod->boundingWidth);
        self::assertLessThan(600, $rod->boundingWidth);
        $atRightEdge = new PackedItem($rod->item, 1000 - $rod->boundingWidth, 200, 0, 600, 50, 100, 30.0);
        $list = new PackedItemList();
        $list->insert($plank);
        $list->insert($atRightEdge);
        $union = 300 + $rod->boundingWidth;
        self::assertSame(["the load hangs over 45 and 0 along x, though its items' extents along it come to {$union}, which fits the deck's 1000"], PackingValidator::avoidableOverhangProblems(self::possible(new PackedBox($box, $list))));
    }

    public function testAvoidableOverhangOfTheMixedAcmeTrampolinesAsFound(): void
    {
        // where the search places the mixed 12 at 2.5" / 2.5" (see OverhangPackingTest): from front to back they reach
        // 43.63", which does not fit the 40" deck, but the items come to 22.52" + 2 × 7.56" = 37.64", which does
        $list = new PackedItemList();
        foreach ([0, 756, 1512, 2268, 3024, 3780, 4536, 5292] as $z) {
            $list->insert(new PackedItem(new TestItem('Flat', 4953, 2252, 756, 0, Rotation::BestFit), -250, -250, $z, 4953, 2252, 756));
        }
        foreach ([[2601, 0], [3357, 0], [2601, 2252], [3357, 2252]] as [$y, $z]) {
            $list->insert(new PackedItem(new TestItem('On edge', 4953, 2252, 756, 0, Rotation::BestFit), -250, $y, $z, 4953, 756, 2252));
        }
        $box = new OverhangTestBox('Pallet', 4800, 4000, 6500, 0, 4800, 4000, 6500, 1000000000, new AllowedOverhang(sides: 250, frontAndBack: 250));

        self::assertSame(['the load hangs over 250 and 113 along y, though its items\' extents along it come to 3764, which fits the deck\'s 4000'], PackingValidator::avoidableOverhangProblems(self::possible(new PackedBox($box, $list))));
    }

    public function testShiftWithTheGapsClosed(): void
    {
        $allowance = new AllowedOverhang(sides: 200, frontAndBack: 200);

        // 600 (cap 90) at its cap at the front and 300 behind it after a gap of 130: the gap is closed (the 300 moves
        // forward 130) and the closed load moved back 140, onto the deck
        self::assertSame([], self::shiftProblems($allowance, [[0, -90, 500, 600], [0, 640, 500, 300]], [[0, 140], [0, 10]]));
        self::assertSame(['Item 1 at 0,640,0 was moved by 0,0, but should have been moved by 0,10'], self::shiftProblems($allowance, [[0, -90, 500, 600], [0, 640, 500, 300]], [[0, 140], [0, 0]]));
        // moving the load as a whole is not enough
        self::assertSame(['Item 0 at 0,-90,0 was moved by 0,75, but should have been moved by 0,140', 'Item 1 at 0,640,0 was moved by 0,75, but should have been moved by 0,10'], self::shiftProblems($allowance, [[0, -90, 500, 600], [0, 640, 500, 300]], [[0, 75]]));
        // items that overlap along the axis (here one on top of the other) move together
        self::assertSame([], self::shiftProblems($allowance, [[0, -90, 500, 600, 50], [0, -90, 500, 600, 50, 50], [0, 640, 500, 300]], [[0, 140], [0, 140], [0, 10]]));
        // where the load still hangs over once the gap is closed: 50 and -32, leaving 40 over each side
        self::assertSame([], self::shiftProblems($allowance, [[-90, 0, 600, 500], [592, 0, 480, 500]], [[50, 0], [-32, 0]]));
        // each of those, before and after the right move, is physically possible
        self::assertPossibleMove($allowance, [[0, -90, 500, 600], [0, 640, 500, 300]], [[0, 140], [0, 10]]);
        self::assertPossibleMove($allowance, [[0, -90, 500, 600, 50], [0, -90, 500, 600, 50, 50], [0, 640, 500, 300]], [[0, 140], [0, 140], [0, 10]]);
        self::assertPossibleMove($allowance, [[-90, 0, 600, 500], [592, 0, 480, 500]], [[50, 0], [-32, 0]]);
    }

    public function testShiftKeepsTheGapsWhereClosingThemWouldNotHelp(): void
    {
        $allowance = new AllowedOverhang(sides: 200);

        // moving the load as a whole already puts it on the deck: the gap of 115 is kept
        self::assertSame([], self::shiftProblems($allowance, [[-15, 0, 100, 100], [200, 0, 100, 100]], [[357, 0]]));
        self::assertSame(['items were moved by different amounts: 450,0 and 335,0'], self::shiftProblems($allowance, [[-15, 0, 100, 100], [200, 0, 100, 100]], [[450, 0], [335, 0]]));
        // closing the gap of 1 would leave the larger overhang 1, as moving the load as a whole does
        self::assertSame([], self::shiftProblems($allowance, [[-40, 0, 600, 500], [561, 0, 401, 500]], [[39, 0]]));
        self::assertSame(['items were moved by different amounts: 39,0 and 38,0'], self::shiftProblems($allowance, [[-40, 0, 600, 500], [561, 0, 401, 500]], [[39, 0], [38, 0]]));
        // each of those, before and after the right move, is physically possible
        self::assertPossibleMove($allowance, [[-15, 0, 100, 100], [200, 0, 100, 100]], [[357, 0]]);
        self::assertPossibleMove($allowance, [[-40, 0, 600, 500], [561, 0, 401, 500]], [[39, 0]]);
    }

    public function testLargeExtentFloorSupport(): void
    {
        $box = self::box(new AllowedOverhang(sides: 200000000000000000));

        self::assertSame(1.0, PackingValidator::minimumSupport(self::packedBox($box, [self::item(-self::LIMIT)])));
        // 10^17 past its limit: 9 × 10^17 + 19 of its 10^18 + 19 is over the floor
        self::assertEqualsWithDelta(0.9, PackingValidator::minimumSupport(self::packedBox($box, [self::item(-self::LIMIT - 100000000000000000)])), 1e-9);
    }

    public function testFloorSupportAtTheFrontAndBack(): void
    {
        // 1100 long, so 50 (the allowance) may hang over the front or the back; 10 more is unsupported
        $box = self::deck(new AllowedOverhang(frontAndBack: 50));
        $item = new TestItem('Item', 100, 1100, 100, 0, Rotation::Never);

        self::assertSame(1.0, PackingValidator::minimumSupport(self::packedBox($box, [new PackedItem($item, 0, -50, 0, 100, 1100, 100)])));
        self::assertEqualsWithDelta(1090 / 1100, PackingValidator::minimumSupport(self::packedBox($box, [new PackedItem($item, 0, -60, 0, 100, 1100, 100)])), 1e-9);
        self::assertEqualsWithDelta(1090 / 1100, PackingValidator::minimumSupport(self::packedBox($box, [new PackedItem($item, 0, -40, 0, 100, 1100, 100)])), 1e-9);
    }

    public function testFloorSupportOfAnAngledItem(): void
    {
        // on the deck, fully supported; 50 past the left edge, its corner (a right angle, between sides at 40° and 50°
        // to the edge) is unsupported: a triangle with legs 50 / cos 40° and 50 / cos 50°, so of area 50² / sin 80°
        $box = self::deck(new AllowedOverhang(sides: 100, frontAndBack: 100));

        self::assertSame(1.0, PackingValidator::minimumSupport(self::packedBox($box, [self::rod(93, 0)])));
        self::assertEqualsWithDelta(1 - 2500 / sin(deg2rad(80)) / 110000, PackingValidator::minimumSupport(self::packedBox($box, [self::rod(-50, 0)])), 1e-9);
    }
}
