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
use ArgumentCountError;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function intdiv;
use function iterator_to_array;
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

    public function testConstructor(): void
    {
        $allowance = new AllowedOverhang(sides: 150, frontAndBack: 300);
        self::assertSame(150, $allowance->sides);
        self::assertSame(300, $allowance->frontAndBack);
        self::assertSame([0, 0], [(new AllowedOverhang())->sides, (new AllowedOverhang())->frontAndBack]);
        self::assertSame([150, 300], [(new AllowedOverhang(150, 300))->sides, (new AllowedOverhang(150, 300))->frontAndBack]);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function negativeAllowances(): array
    {
        return [
            'sides' => [-1, 0],
            'front and back' => [0, -1],
        ];
    }

    #[DataProvider('negativeAllowances')]
    public function testNegativeAllowanceIsRejected(int $sides, int $frontAndBack): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("sides {$sides}, frontAndBack {$frontAndBack}");
        new AllowedOverhang(sides: $sides, frontAndBack: $frontAndBack);
    }

    /**
     * @return array<string, array{list<int>}>
     */
    public static function tooManyArguments(): array
    {
        return [
            'three' => [[150, 150, 300]],
            'four (a stale left, right, front, back call)' => [[150, 150, 300, 300]],
        ];
    }

    /**
     * @param list<int> $arguments
     */
    #[DataProvider('tooManyArguments')]
    public function testMoreThanTwoArgumentsAreRejected(array $arguments): void
    {
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('AllowedOverhang takes two allowances, sides and frontAndBack (each per edge); more than two arguments (such as a stale four-value call) are rejected');
        new AllowedOverhang(...$arguments);
    }

    public function testIsZero(): void
    {
        self::assertTrue((new AllowedOverhang())->isZero());
        self::assertTrue((new AllowedOverhang(sides: 0, frontAndBack: 0))->isZero());
        self::assertFalse((new AllowedOverhang(sides: 1))->isZero());
        self::assertFalse((new AllowedOverhang(frontAndBack: 1))->isZero());
    }

    public function testJsonShape(): void
    {
        self::assertSame('{"sides":1,"frontAndBack":2}', json_encode(new AllowedOverhang(sides: 1, frontAndBack: 2)));
    }

    public function testEachAllowanceAppliesAtBothOfItsEdges(): void
    {
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(sides: 40, frontAndBack: 70));
        self::assertSame(40, $bounds->sideCap(1000));
        self::assertSame(70, $bounds->endCap(1000));
        self::assertTrue($bounds->admits(-40, -70, 1000, 1000));
        self::assertTrue($bounds->admits(40, 70, 1000, 1000));
        self::assertFalse($bounds->admits(-41, 0, 1000, 1000));
        self::assertFalse($bounds->admits(41, 0, 1000, 1000));
        self::assertFalse($bounds->admits(0, -71, 1000, 1000));
        self::assertFalse($bounds->admits(0, 71, 1000, 1000));
        self::assertSame(1080, $bounds->envelopeWidth());
        self::assertSame(1140, $bounds->envelopeLength());
        self::assertSame([[40, 40, 70, 70]], OverhangBounds::visualiserElements(new OverhangTestBox('Box', 1000, 1000, 1000, 0, 1000, 1000, 1000, 1000, new AllowedOverhang(sides: 40, frontAndBack: 70))));
    }

    public function testOnlyANonZeroAllowanceIsActive(): void
    {
        $plain = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000);
        self::assertNull(OverhangBounds::forBox($plain));
        self::assertNull(OverhangBounds::forBox(new ZeroOverhangBox($plain)));
        self::assertNull(OverhangBounds::forBox(new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang())));
        self::assertNotNull(OverhangBounds::forBox(new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang(frontAndBack: 1))));

        self::assertSame(1000000, OverhangBounds::capacityVolume($plain));
        self::assertSame(110 * 150 * 100, OverhangBounds::capacityVolume(new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang(sides: 5, frontAndBack: 25))));
    }

    public function testCapIsFifteenPercentRoundedDownExactly(): void
    {
        for ($extent = 0; $extent <= 100000; ++$extent) {
            if (OverhangBounds::cap(PHP_INT_MAX, $extent) !== intdiv($extent * 15, 100)) {
                self::fail("cap of extent {$extent}");
            }
        }
        // PHP_INT_MAX (on a 64-bit platform) × 15 = 9,223,372,036,854,775,807 × 15 = 138,350,580,552,821,637,105, and
        // / 100 = 1,383,505,805,528,216,371.05
        self::assertSame(1383505805528216371, OverhangBounds::cap(PHP_INT_MAX, PHP_INT_MAX));
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
        $bounds = self::bounds(4800, 4000, new AllowedOverhang(frontAndBack: 400));
        self::assertSame(337, $bounds->endCap(2252));
        self::assertTrue($bounds->admits(0, -337, 1000, 2252));
        self::assertFalse($bounds->admits(0, -338, 1000, 2252));
        self::assertTrue($bounds->admits(0, 4000 + 337 - 2252, 1000, 2252));
        self::assertFalse($bounds->admits(0, 4000 + 338 - 2252, 1000, 2252));
    }

    public function testSmallItemsGainLittle(): void
    {
        // a 12" cube on a pallet allowing 3" may hang over by 1.8" at most
        $bounds = self::bounds(4800, 4000, new AllowedOverhang(sides: 300, frontAndBack: 300));
        foreach ([[-180, 0], [4800 + 180 - 1200, 0], [0, -180], [0, 4000 + 180 - 1200]] as [$x, $y]) {
            self::assertTrue($bounds->admits($x, $y, 1200, 1200));
        }
        foreach ([[-181, 0], [4800 + 181 - 1200, 0], [0, -181], [0, 4000 + 181 - 1200]] as [$x, $y]) {
            self::assertFalse($bounds->admits($x, $y, 1200, 1200));
        }
    }

    public function testExtentsUnderSevenHaveNoCap(): void
    {
        $bounds = self::bounds(100, 100, new AllowedOverhang(sides: 50, frontAndBack: 50));
        for ($extent = 1; $extent < 7; ++$extent) {
            self::assertSame(0, $bounds->sideCap($extent));
            self::assertSame(0, $bounds->endCap($extent));
            self::assertFalse($bounds->admits(-1, 0, $extent, $extent));
            self::assertFalse($bounds->admits(0, 100 - $extent + 1, $extent, $extent));
        }
        self::assertSame(1, $bounds->sideCap(7));
        self::assertTrue($bounds->admits(-1, 0, 7, 7));
        self::assertTrue($bounds->admits(0, 94, 7, 7));
    }

    public function testOneItemSpanningBothEdgesNeedsBothCaps(): void
    {
        // 1200 wide on a 1000 wide deck allowing 100 each side: only x = -100 puts each side within its cap
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(sides: 100));
        self::assertTrue($bounds->fits(1200, 1000));
        self::assertFalse($bounds->fits(1201, 1000));
        self::assertTrue($bounds->admits(-100, 0, 1200, 1000));
        self::assertFalse($bounds->admits(-99, 0, 1200, 1000));
        self::assertFalse($bounds->admits(-101, 0, 1200, 1000));
        self::assertSame(-100, $bounds->startX(1200));

        // with larger allowances, the item's own caps bind: 1428 fits a 1000 deck (caps 214 each side), 1429 does not
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(sides: 300));
        self::assertTrue($bounds->fits(1428, 1000));
        self::assertFalse($bounds->fits(1429, 1000));
        self::assertTrue($bounds->admits(-214, 0, 1428, 1000));
        self::assertFalse($bounds->admits(-213, 0, 1428, 1000));
        self::assertFalse($bounds->admits(-215, 0, 1428, 1000));
    }

    public function testInARowOnlyTheEndItemsOverhang(): void
    {
        // four 270 wide items (caps 40) across a 1000 wide deck allowing 50 each side: 1080 = 1000 + 40 + 40
        $bounds = self::bounds(1000, 100, new AllowedOverhang(sides: 50));
        $xs = [-40, 230, 500, 770];
        foreach ($xs as $x) {
            self::assertTrue($bounds->admits($x, 0, 270, 100));
        }
        self::assertFalse($bounds->admits(-41, 0, 270, 100));
        self::assertFalse($bounds->admits(771, 0, 270, 100));

        $items = new ItemList();
        $items->insert(new Test\TestItem('Item', 270, 100, 100, 1, Rotation::Never), 5);
        $packer = new VolumePacker(new OverhangTestBox('Box', 1000, 100, 100, 0, 1000, 100, 100, 1000, new AllowedOverhang(sides: 50)), $items);
        $packer->setStrategy(PackingStrategy::Thorough);
        $packedBox = $packer->pack();
        self::assertCount(4, $packedBox->items);
        self::assertSame([], Benchmark\PackingValidator::problems($packedBox));
        self::assertSame([], Benchmark\PackingValidator::centringProblems($packedBox));
        self::assertSame(1080, $packedBox->getUsedWidth());
        self::assertSame($xs, array_map(static fn (PackedItem $item) => $item->x, iterator_to_array($packedBox->items, false)));
    }

    /**
     * @return array<string, array{AllowedOverhang, bool}>
     */
    public static function oneAxisOnly(): array
    {
        return [
            'sides only' => [new AllowedOverhang(sides: 100), true],
            'front and back only' => [new AllowedOverhang(frontAndBack: 100), false],
        ];
    }

    #[DataProvider('oneAxisOnly')]
    public function testOneAllowanceZero(AllowedOverhang $allowance, bool $sides): void
    {
        // the other axis is the deck alone, at both of its edges. Positions and sizes are given along the axis with the
        // allowance, then across it
        $bounds = self::bounds(1000, 1000, $allowance);
        $axes = static fn (int $along, int $across): array => $sides ? [$along, $across] : [$across, $along];
        self::assertSame(90, $sides ? $bounds->sideCap(600) : $bounds->endCap(600));
        self::assertSame(0, $sides ? $bounds->endCap(600) : $bounds->sideCap(600));
        foreach ([[-90, true], [-91, false], [490, true], [491, false]] as [$position, $admitted]) {
            self::assertSame($admitted, $bounds->admits(...$axes($position, 0), ...$axes(600, 100)));
        }
        self::assertTrue($bounds->admits(...$axes(0, 900), ...$axes(600, 100)));
        self::assertFalse($bounds->admits(...$axes(0, -1), ...$axes(600, 100)));
        self::assertFalse($bounds->admits(...$axes(0, 901), ...$axes(600, 100)));
        self::assertTrue($bounds->fits(...$axes(1200, 1000)));
        self::assertFalse($bounds->fits(...$axes(1201, 1000)));
        self::assertFalse($bounds->fits(...$axes(1000, 1001)));
        self::assertSame(-100, $sides ? $bounds->startX(1200) : $bounds->startY(1200));
        self::assertSame(0, $sides ? $bounds->startY(1000) : $bounds->startX(1000));

        // a load hanging over on the axis with an allowance is balanced on it; the other axis is never moved
        $item = new Test\TestItem('Item', 1100, 1100, 100, 1, Rotation::Never);
        [$x, $y] = $axes(-100, 0);
        [$w, $l] = $axes(1100, 1000);
        self::assertSame($axes(50, 0), $bounds->centringShift([new PackedItem($item, $x, $y, 0, $w, $l, 100)]));
    }

    public function testAngledItemsMustStayOnTheDeck(): void
    {
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(sides: 100, frontAndBack: 100));
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

    /**
     * An allowance of (PHP_INT_MAX - 101) / 2 (PHP_INT_MAX is odd): with it, a deck 101 wide or long is PHP_INT_MAX
     * wide or long with its overhang, the most that can be represented, and one 102 wide or long would be one more.
     */
    private static function largestAllowanceFor101(): int
    {
        return intdiv(PHP_INT_MAX - 101, 2);
    }

    public function testTheLargestRepresentableEnvelopeIsAccepted(): void
    {
        self::assertSame(PHP_INT_MAX - 1, self::bounds(100, 1, new AllowedOverhang(sides: self::largestAllowanceFor101()), 1)->envelopeWidth());
        // as long as its volume can be represented too: so 1 long and 1 deep
        self::assertSame(PHP_INT_MAX, self::bounds(101, 1, new AllowedOverhang(sides: self::largestAllowanceFor101()), 1)->envelopeWidth());
        self::assertSame(PHP_INT_MAX, self::bounds(1, 101, new AllowedOverhang(frontAndBack: self::largestAllowanceFor101()), 1)->envelopeLength());
    }

    public function testEnvelopeWidthOverflowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wider or longer');
        OverhangBounds::forBox(new OverhangTestBox('Box', 102, 1, 1, 0, 102, 1, 1, 1000, new AllowedOverhang(sides: self::largestAllowanceFor101())));
    }

    public function testEnvelopeLengthOverflowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wider or longer');
        OverhangBounds::forBox(new OverhangTestBox('Box', 1, 102, 1, 0, 1, 102, 1, 1000, new AllowedOverhang(frontAndBack: self::largestAllowanceFor101())));
    }

    public function testEnvelopeVolumeOverflowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('volume');
        OverhangBounds::forBox(new OverhangTestBox('Box', 1000000, 1000000, 1000000, 0, 1000000, 1000000, 1000000, 1000, new AllowedOverhang(sides: 5000000, frontAndBack: 5000000)));
    }

    /**
     * @return array<string, array{list<array{0: int, 1: int, 2: int, 3: int}>, array{0: int, 1: int}}>
     */
    public static function centringCases(): array
    {
        // each item [x, y, width, length] on a 1000 × 1000 deck allowing 200 at the sides and at the front and back
        return [
            'nothing' => [[], [0, 0]],
            'on the deck, nothing hanging over' => [[[100, 50, 500, 500]], [0, 0]],
            'flush with the deck' => [[[0, 0, 1000, 1000]], [0, 0]],
            'balanced' => [[[-100, -100, 1200, 1200]], [0, 0]],
            // 150 over the left, 3 over the right: 73.5 would balance them, so 73 (the smaller move)
            'odd difference, moved right' => [[[-150, 0, 1153, 1000]], [73, 0]],
            'odd difference, moved left' => [[[-3, 0, 1153, 1000]], [-73, 0]],
            'even difference' => [[[-150, -10, 1150, 1030]], [75, -5]],
            // hanging 100 over the front of a deck it fits: moved fully onto it, with equal gaps of 100
            'onto the deck' => [[[0, -100, 1000, 800]], [0, 200]],
            // the wide item may move 30 at most before the narrow one beside it (cap 15) hangs over the right by more
            // than its cap
            'limited by an item\'s caps' => [[[-150, 0, 1150, 500], [885, 500, 100, 500]], [30, 0]],
            // the wide item is at its cap at the right, the narrow one at its cap at the left: no move is possible
            'pinned by caps at both edges' => [[[22, 0, 1150, 500], [-15, 500, 100, 500]], [0, 0]],
            // gaps but no overhang along y: only x is moved
            'one axis only' => [[[-150, 100, 1150, 500]], [75, 0]],
        ];
    }

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $placements
     * @param array{0: int, 1: int}                       $shift
     */
    #[DataProvider('centringCases')]
    public function testCentringShift(array $placements, array $shift): void
    {
        $box = new OverhangTestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, 1000, new AllowedOverhang(sides: 200, frontAndBack: 200));
        $bounds = OverhangBounds::forBox($box);
        self::assertNotNull($bounds);
        $items = [];
        $packing = new PackedItemList();
        foreach ($placements as [$x, $y, $width, $length]) {
            $items[] = $item = new PackedItem(new Test\TestItem('Item', $width, $length, 100, 1, Rotation::Never), $x, $y, 0, $width, $length, 100);
            $packing->insert($item);
        }
        // each case is a packing that could be found: within the caps, and with no item inside another
        self::assertSame([], Benchmark\PackingValidator::problems(new PackedBox($box, $packing)));
        self::assertSame($shift, $bounds->centringShift($items));

        // and once moved, the packing needs no further move
        $moved = array_map(static fn (PackedItem $item) => new PackedItem($item->item, $item->x + $shift[0], $item->y + $shift[1], 0, $item->width, $item->length, 100), $items);
        foreach ($moved as $item) {
            self::assertTrue($bounds->admitsPackedItem($item));
        }
        self::assertSame([0, 0], $bounds->centringShift($moved));
    }

    public function testCentringKeepsAngledItemsOnTheDeck(): void
    {
        // a plank hanging 100 over the left could move 100 right, but the angled item beside it is already at the
        // right edge of the deck
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(sides: 100, frontAndBack: 100));
        $plank = new PackedItem(new Test\TestItem('Plank', 700, 100, 100, 1, Rotation::Never), -100, 0, 0, 700, 100, 100);
        $angled = new PackedItem(new Test\TestItem('Rod', 1100, 100, 100, 1, Rotation::KeepFlat), 0, 200, 0, 1100, 100, 100, 40.0);
        self::assertLessThan(1000, $angled->boundingWidth);
        $atRightEdge = new PackedItem($angled->item, 1000 - $angled->boundingWidth, 200, 0, 1100, 100, 100, 40.0);
        self::assertSame([0, 0], $bounds->centringShift([$plank, $atRightEdge]));
        // one unit of room on the deck: moved by that one unit
        $oneUnitIn = new PackedItem($angled->item, 999 - $angled->boundingWidth, 200, 0, 1100, 100, 100, 40.0);
        self::assertSame([1, 0], $bounds->centringShift([$plank, $oneUnitIn]));
    }

    public function testStartPositionsHangOverEquallyWhenTheyMust(): void
    {
        $bounds = self::bounds(1000, 1000, new AllowedOverhang(sides: 100, frontAndBack: 100));
        self::assertSame(0, $bounds->startX(1000));
        self::assertSame(0, $bounds->startX(0));
        self::assertSame(-50, $bounds->startX(1100));
        self::assertSame(-50, $bounds->startY(1101)); // 101 to share, 51 at the front and 50 at the back would also do
        self::assertSame(-100, $bounds->startX(1200));
    }
}
