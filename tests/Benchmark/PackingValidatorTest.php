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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
     * A packed box of one item, built without the box's own checks (which, with assertions enabled, would reject the
     * illegal ones before the validator could see them).
     */
    private static function packedBox(Box $box, PackedItem $item): PackedBox
    {
        $items = new PackedItemList();
        $items->insert($item);
        $class = new ReflectionClass(PackedBox::class);
        $packedBox = $class->newInstanceWithoutConstructor();
        $class->getProperty('box')->setValue($packedBox, $box);
        $class->getProperty('items')->setValue($packedBox, $items);

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

    public function testLargeExtentLeftBoundary(): void
    {
        $box = self::box(new AllowedOverhang(sides: 200000000000000000));

        self::assertSame([], PackingValidator::problems(self::packedBox($box, self::item(-self::LIMIT))));
        self::assertSame(['item 0 hangs 150000000000000003 over the left edge, more than 15 % of its extent of 1000000000000000019'], PackingValidator::problems(self::packedBox($box, self::item(-self::LIMIT - 1))));
    }

    public function testLargeExtentRightBoundary(): void
    {
        $box = self::box(new AllowedOverhang(sides: 200000000000000000));
        $x = self::DECK - self::EXTENT + self::LIMIT; // 149,999,999,999,999,983

        self::assertSame([], PackingValidator::problems(self::packedBox($box, self::item($x))));
        self::assertSame(['item 0 hangs 150000000000000003 over the right edge, more than 15 % of its extent of 1000000000000000019'], PackingValidator::problems(self::packedBox($box, self::item($x + 1))));
    }

    public function testLargeAllowanceBoundary(): void
    {
        $box = self::box(new AllowedOverhang(sides: self::LIMIT - 1));

        self::assertSame([], PackingValidator::problems(self::packedBox($box, self::item(-self::LIMIT + 1))));
        self::assertSame(['item 0 hangs 150000000000000002 over the left edge, more than its allowance of 150000000000000001'], PackingValidator::problems(self::packedBox($box, self::item(-self::LIMIT))));
    }

    public function testLargeAllowanceBoundaryAtTheRight(): void
    {
        $box = self::box(new AllowedOverhang(sides: self::LIMIT - 1));
        $x = self::DECK - self::EXTENT + self::LIMIT - 1;

        self::assertSame([], PackingValidator::problems(self::packedBox($box, self::item($x))));
        self::assertSame(['item 0 hangs 150000000000000002 over the right edge, more than its allowance of 150000000000000001'], PackingValidator::problems(self::packedBox($box, self::item($x + 1))));
    }

    public function testFrontAndBackAllowanceAppliesAtBothEnds(): void
    {
        $box = new OverhangTestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, 1000, new AllowedOverhang(frontAndBack: 50));
        $item = new TestItem('Item', 100, 1100, 100, 0, Rotation::Never);

        self::assertSame([], PackingValidator::problems(self::packedBox($box, new PackedItem($item, 0, -50, 0, 100, 1100, 100))));
        self::assertSame(['item 0 hangs 51 over the front edge, more than its allowance of 50'], PackingValidator::problems(self::packedBox($box, new PackedItem($item, 0, -51, 0, 100, 1100, 100))));
        self::assertSame(['item 0 hangs 51 over the back edge, more than its allowance of 50'], PackingValidator::problems(self::packedBox($box, new PackedItem($item, 0, -49, 0, 100, 1100, 100))));
        self::assertSame(['item 0 hangs 1 over the left edge, more than its allowance of 0'], PackingValidator::problems(self::packedBox($box, new PackedItem($item, -1, -50, 0, 100, 1100, 100))));
    }

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: int, 4?: int}> $items [x, y, width, length, depth (100 if not given)]
     */
    private static function packing(AllowedOverhang $allowance, array $items): PackedBox
    {
        $list = new PackedItemList();
        foreach ($items as $index => [$x, $y, $width, $length]) {
            $depth = $items[$index][4] ?? 100;
            $list->insert(new PackedItem(new TestItem("Item {$index}", $width, $length, $depth, 0, Rotation::Never), $x, $y, 0, $width, $length, $depth));
        }
        $class = new ReflectionClass(PackedBox::class);
        $packedBox = $class->newInstanceWithoutConstructor();
        $class->getProperty('box')->setValue($packedBox, new OverhangTestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, 1000, $allowance));
        $class->getProperty('items')->setValue($packedBox, $list);

        return $packedBox;
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
        // balanced as far as the caps allow, and one unit short of it
        self::assertSame([], PackingValidator::centringProblems(self::packing($allowance, [[-120, 0, 1150, 1000], [915, 0, 100, 1000]])));
        self::assertSame(['the load hangs over 121 and 29 along x: moving it up by 1 would balance it better'], PackingValidator::centringProblems(self::packing($allowance, [[-121, 0, 1150, 1000], [914, 0, 100, 1000]])));
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

        self::assertSame([], PackingValidator::centringProblems(new PackedBox(new TestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, 1000), $list)));
    }

    /**
     * A packing in a 1000 × 1000 box with the allowance as it was before a move and as it is after, built without the
     * box's own checks.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4?: int}> $items [x, y, width, length, depth (100 if not given)] before
     * @param  list<array{0: int, 1: int}>                          $moves [x, y] by which each item moved
     * @return array{0: list<PackedItem>, 1: PackedBox}
     */
    private static function moved(AllowedOverhang $allowance, array $items, array $moves): array
    {
        $before = [];
        $after = new PackedItemList();
        foreach ($items as $index => [$x, $y, $width, $length]) {
            $depth = $items[$index][4] ?? 100;
            $item = new TestItem("Item {$index}", $width, $length, $depth, 0, Rotation::Never);
            [$moveX, $moveY] = $moves[$index] ?? $moves[0];
            $before[] = new PackedItem($item, $x, $y, 0, $width, $length, $depth);
            $after->insert(new PackedItem($item, $x + $moveX, $y + $moveY, 0, $width, $length, $depth));
        }
        $class = new ReflectionClass(PackedBox::class);
        $packedBox = $class->newInstanceWithoutConstructor();
        $class->getProperty('box')->setValue($packedBox, new OverhangTestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, 1000, $allowance));
        $class->getProperty('items')->setValue($packedBox, $after);

        return [$before, $packedBox];
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4?: int}> $items
     * @param  list<array{0: int, 1: int}>                          $moves
     * @return list<string>
     */
    private static function shiftProblems(AllowedOverhang $allowance, array $items, array $moves): array
    {
        return PackingValidator::shiftProblems(...self::moved($allowance, $items, $moves));
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

    public function testLargeExtentFloorSupport(): void
    {
        $box = self::box(new AllowedOverhang(sides: 200000000000000000));

        self::assertSame(1.0, PackingValidator::minimumSupport(self::packedBox($box, self::item(-self::LIMIT))));
        // 10^17 past its limit: 9 × 10^17 + 19 of its 10^18 + 19 is over the floor
        self::assertEqualsWithDelta(0.9, PackingValidator::minimumSupport(self::packedBox($box, self::item(-self::LIMIT - 100000000000000000))), 1e-9);
    }
}
