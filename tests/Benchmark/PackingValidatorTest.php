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
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The validator's overhang rules at extents so large that 100 × overhang or 15 × extent would overflow an int. The
 * expected limits are worked out by hand, not with the library's helpers.
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
        $box = self::box(new AllowedOverhang(left: 200000000000000000));

        self::assertSame([], PackingValidator::problems(self::packedBox($box, self::item(-self::LIMIT))));
        self::assertSame(['item 0 hangs 150000000000000003 over the left edge, more than 15 % of its extent of 1000000000000000019'], PackingValidator::problems(self::packedBox($box, self::item(-self::LIMIT - 1))));
    }

    public function testLargeExtentRightBoundary(): void
    {
        $box = self::box(new AllowedOverhang(right: 200000000000000000));
        $x = self::DECK - self::EXTENT + self::LIMIT; // 149,999,999,999,999,983

        self::assertSame([], PackingValidator::problems(self::packedBox($box, self::item($x))));
        self::assertSame(['item 0 hangs 150000000000000003 over the right edge, more than 15 % of its extent of 1000000000000000019'], PackingValidator::problems(self::packedBox($box, self::item($x + 1))));
    }

    public function testLargeAllowanceBoundary(): void
    {
        $box = self::box(new AllowedOverhang(left: self::LIMIT - 1));

        self::assertSame([], PackingValidator::problems(self::packedBox($box, self::item(-self::LIMIT + 1))));
        self::assertSame(['item 0 hangs 150000000000000002 over the left edge, more than its allowance of 150000000000000001'], PackingValidator::problems(self::packedBox($box, self::item(-self::LIMIT))));
    }

    public function testLargeExtentFloorSupport(): void
    {
        $box = self::box(new AllowedOverhang(left: 200000000000000000));

        self::assertSame(1.0, PackingValidator::minimumSupport(self::packedBox($box, self::item(-self::LIMIT))));
        // 10^17 past its limit: 9 × 10^17 + 19 of its 10^18 + 19 is over the floor
        self::assertEqualsWithDelta(0.9, PackingValidator::minimumSupport(self::packedBox($box, self::item(-self::LIMIT - 100000000000000000))), 1e-9);
    }
}
