<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\OverhangTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\ZeroOverhangBox;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function intdiv;
use function json_encode;

use const PHP_INT_MAX;

#[CoversClass(AllowedOverhang::class)]
#[CoversClass(OverhangBounds::class)]
class AllowedOverhangTest extends TestCase
{
    private static function bounds(int $width, int $length, AllowedOverhang $allowance, int $depth = 1000): OverhangBounds
    {
        $bounds = OverhangBounds::forBox(new OverhangTestBox('Box', $width, $length, $depth, 0, $width, $length, $depth, 1000, $allowance));
        self::assertNotNull($bounds);

        return $bounds;
    }

    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function negativeAllowances(): array
    {
        return [
            'left' => [-1, 0, 0, 0],
            'right' => [0, -1, 0, 0],
            'front' => [0, 0, -1, 0],
            'back' => [0, 0, 0, -1],
        ];
    }

    #[DataProvider('negativeAllowances')]
    public function testNegativeAllowanceIsRejected(int $left, int $right, int $front, int $back): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AllowedOverhang($left, $right, $front, $back);
    }

    public function testIsZero(): void
    {
        self::assertTrue((new AllowedOverhang())->isZero());
        self::assertFalse((new AllowedOverhang(left: 1))->isZero());
        self::assertFalse((new AllowedOverhang(right: 1))->isZero());
        self::assertFalse((new AllowedOverhang(front: 1))->isZero());
        self::assertFalse((new AllowedOverhang(back: 1))->isZero());
    }

    public function testJsonShape(): void
    {
        self::assertSame('{"left":1,"right":2,"front":3,"back":4}', json_encode(new AllowedOverhang(1, 2, 3, 4)));
    }

    public function testOnlyANonZeroAllowanceIsActive(): void
    {
        $plain = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000);
        self::assertNull(OverhangBounds::forBox($plain));
        self::assertNull(OverhangBounds::forBox(new ZeroOverhangBox($plain)));
        self::assertNull(OverhangBounds::forBox(new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang())));
        self::assertNotNull(OverhangBounds::forBox(new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang(back: 1))));

        self::assertSame(1000000, OverhangBounds::capacityVolume($plain));
        self::assertSame(110 * 125 * 100, OverhangBounds::capacityVolume(new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang(4, 6, 0, 25))));
    }

    public function testCapIsFifteenPercentRoundedDownExactly(): void
    {
        for ($extent = 0; $extent <= 100000; ++$extent) {
            if (OverhangBounds::cap(PHP_INT_MAX, $extent) !== intdiv($extent * 15, 100)) {
                self::fail("cap of extent {$extent}");
            }
        }
        self::assertSame(intdiv(PHP_INT_MAX, 100) * 15 + intdiv((PHP_INT_MAX % 100) * 15, 100), OverhangBounds::cap(PHP_INT_MAX, PHP_INT_MAX));
        self::assertSame(0, OverhangBounds::cap(100, -5));
    }

    public function testCapIsTheAllowanceWhenLess(): void
    {
        self::assertSame(150, OverhangBounds::cap(150, 4953));
        self::assertSame(742, OverhangBounds::cap(1000, 4953));
        self::assertSame(0, OverhangBounds::cap(0, 4953));
    }

    public function testCapBoundaryFor2252(): void
    {
        // 15 % of 22.52" is 3.378": 3.37" may hang over, 3.38" may not
        $bounds = self::bounds(4800, 4000, new AllowedOverhang(front: 400));
        self::assertSame(337, $bounds->capFront(2252));
        self::assertTrue($bounds->admits(0, -337, 1000, 2252));
        self::assertFalse($bounds->admits(0, -338, 1000, 2252));
    }

    public function testSmallItemsGainLittle(): void
    {
        // a 12" cube on a pallet allowing 3" may hang over by 1.8" at most
        $bounds = self::bounds(4800, 4000, new AllowedOverhang(300, 300, 300, 300));
        foreach ([[-180, 0], [4800 + 180 - 1200, 0], [0, -180], [0, 4000 + 180 - 1200]] as [$x, $y]) {
            self::assertTrue($bounds->admits($x, $y, 1200, 1200));
        }
        foreach ([[-181, 0], [4800 + 181 - 1200, 0], [0, -181], [0, 4000 + 181 - 1200]] as [$x, $y]) {
            self::assertFalse($bounds->admits($x, $y, 1200, 1200));
        }
    }

    public function testExtentsUnderSevenHaveNoCap(): void
    {
        $bounds = self::bounds(100, 100, new AllowedOverhang(50, 50, 50, 50));
        for ($extent = 1; $extent < 7; ++$extent) {
            self::assertSame(0, $bounds->capLeft($extent));
            self::assertFalse($bounds->admits(-1, 0, $extent, $extent));
            self::assertFalse($bounds->admits(0, 100 - $extent + 1, $extent, $extent));
        }
        self::assertSame(1, $bounds->capLeft(7));
        self::assertTrue($bounds->admits(-1, 0, 7, 7));
    }

    public function testOneItemSpanningBothEdgesNeedsBothCaps(): void
    {
        // 1200 wide on a 1000 wide deck allowing 100 each side: only x = -100 puts each side within its cap
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(100, 100, 0, 0));
        self::assertTrue($bounds->fits(1200, 1000));
        self::assertFalse($bounds->fits(1201, 1000));
        self::assertTrue($bounds->admits(-100, 0, 1200, 1000));
        self::assertFalse($bounds->admits(-99, 0, 1200, 1000));
        self::assertFalse($bounds->admits(-101, 0, 1200, 1000));
        self::assertSame(-100, $bounds->startX(1200));

        // with larger allowances, the item's own caps bind: 1428 fits a 1000 deck (caps 214 each side), 1429 does not
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(300, 300, 0, 0));
        self::assertTrue($bounds->fits(1428, 1000));
        self::assertFalse($bounds->fits(1429, 1000));
        self::assertTrue($bounds->admits(-214, 0, 1428, 1000));
        self::assertFalse($bounds->admits(-213, 0, 1428, 1000));
        self::assertFalse($bounds->admits(-215, 0, 1428, 1000));
    }

    public function testInARowOnlyTheEndItemsOverhang(): void
    {
        // four 270 wide items (caps 40) across a 1000 wide deck allowing 50 each side: 1080 = 1000 + 40 + 40
        $bounds = self::bounds(1000, 100, new AllowedOverhang(50, 50, 0, 0));
        $xs = [-40, 230, 500, 770];
        foreach ($xs as $x) {
            self::assertTrue($bounds->admits($x, 0, 270, 100));
        }
        self::assertFalse($bounds->admits(-41, 0, 270, 100));
        self::assertFalse($bounds->admits(771, 0, 270, 100));

        $items = new ItemList();
        $items->insert(new Test\TestItem('Item', 270, 100, 100, 1, Rotation::Never), 5);
        $packer = new VolumePacker(new OverhangTestBox('Box', 1000, 100, 100, 0, 1000, 100, 100, 1000, new AllowedOverhang(50, 50, 0, 0)), $items);
        $packer->setStrategy(PackingStrategy::Thorough);
        $packedBox = $packer->pack();
        self::assertCount(4, $packedBox->items);
        self::assertSame([], Benchmark\PackingValidator::problems($packedBox));
        self::assertSame(1080, $packedBox->getUsedWidth());
    }

    public function testAsymmetricFrontAndBackWithBackZero(): void
    {
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(front: 100));
        self::assertSame(90, $bounds->capFront(600));
        self::assertSame(0, $bounds->capBack(600));
        self::assertTrue($bounds->admits(0, -90, 100, 600));
        self::assertFalse($bounds->admits(0, -91, 100, 600));
        self::assertTrue($bounds->admits(0, 400, 100, 600));
        self::assertFalse($bounds->admits(0, 401, 100, 600));
        self::assertTrue($bounds->fits(100, 1100));
        self::assertFalse($bounds->fits(100, 1101));
        self::assertSame(-100, $bounds->startY(1100));
    }

    public function testAngledItemsMustStayOnTheDeck(): void
    {
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(100, 100, 100, 100));
        $item = new Test\TestItem('Item', 1100, 100, 100, 1, Rotation::KeepFlat);
        $angled = new PackedItem($item, 0, 0, 0, 1100, 100, 100, 40.0);
        self::assertLessThanOrEqual(1000, $angled->boundingWidth);
        self::assertTrue($bounds->admitsPackedItem($angled));
        self::assertFalse($bounds->admitsPackedItem(new PackedItem($item, -1, 0, 0, 1100, 100, 100, 40.0)));
        self::assertFalse($bounds->admitsPackedItem(new PackedItem($item, 0, 1000 - $angled->boundingLength + 1, 0, 1100, 100, 100, 40.0)));
        // square, the same item may hang over
        self::assertTrue($bounds->admitsPackedItem(new PackedItem($item, -100, 0, 0, 1100, 100, 100)));
        self::assertFalse($bounds->admitsPackedItem(new PackedItem($item, -100, 0, 901, 1100, 100, 100)));
    }

    public function testEnvelopeWidthOverflowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OverhangBounds::forBox(new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang(PHP_INT_MAX - 150, 100)));
    }

    public function testEnvelopeLengthOverflowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OverhangBounds::forBox(new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang(front: PHP_INT_MAX - 50, back: 100)));
    }

    public function testEnvelopeVolumeOverflowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OverhangBounds::forBox(new OverhangTestBox('Box', 1000000, 1000000, 1000000, 0, 1000000, 1000000, 1000000, 1000, new AllowedOverhang(10000000, 0, 10000000, 0)));
    }
}
