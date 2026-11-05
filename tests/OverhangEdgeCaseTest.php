<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use AssertionError;
use DVDoug\BoxPacker\Benchmark\PackingValidator;
use DVDoug\BoxPacker\Test\ConstrainedPlacementMaxXTestItem;
use DVDoug\BoxPacker\Test\EdgeCaseDeckOnlyTestItem;
use DVDoug\BoxPacker\Test\OverhangTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use DVDoug\BoxPacker\Test\ZeroOverhangBox;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function ini_get;
use function sort;
use function strlen;
use function unserialize;

/**
 * Edge cases of boxes with an allowed overhang: volumes too large for an int, placement callbacks that refuse to hang
 * over, allowances that no item can use, items that cannot go in, flat items, and allowances made without the checks
 * of their constructor. Packings are checked with the independent validator.
 */
#[CoversClass(BlockPacker::class)]
#[CoversClass(VolumePacker::class)]
#[CoversClass(OverhangBounds::class)]
#[CoversClass(Packer::class)]
#[CoversClass(PackedBox::class)]
class OverhangEdgeCaseTest extends TestCase
{
    /**
     * @return array<string, array{bool}>
     */
    public static function plainOrAllZero(): array
    {
        return ['plain' => [false], 'all allowances zero' => [true]];
    }

    #[DataProvider('plainOrAllZero')]
    public function testABoxWithAVolumeTooLargeForAnIntDoesNotStopTheOthersBeingTried(bool $allZero): void
    {
        // 3,000,000³ is more than PHP_INT_MAX, so that box's inner volume is a float, as it always was. (The Thorough
        // strategy could not be given such a box before either: it needs every box's inner volume as an int)
        $small = new TestBox('Small', 10, 10, 10, 0, 10, 10, 10, 100);
        $huge = new TestBox('Huge', 3000000, 3000000, 3000000, 0, 3000000, 3000000, 3000000, 100);
        $packer = new Packer();
        $packer->addBox($allZero ? ZeroOverhangBox::wrap($small) : $small);
        $packer->addBox($allZero ? ZeroOverhangBox::wrap($huge) : $huge);
        $packer->addItem(new TestItem('Item', 1, 1, 1, 1, Rotation::BestFit));

        $packedBoxes = $packer->pack();

        self::assertCount(1, $packedBoxes);
        self::assertSame('Small', $packedBoxes->top()->box->getReference());
        self::assertSame([], PackingValidator::problems($packedBoxes->top()));
    }

    public function testItemsWhoseTotalVolumeIsTooLargeForAnIntPackIntoABoxWithAnAllowedOverhang(): void
    {
        // ten 1,000,000³ cubes are 10^19 in all, more than PHP_INT_MAX, though each cube is not, nor is the box with its
        // overhang
        $box = new OverhangTestBox('Cube', 1000000, 1000000, 1000000, 0, 1000000, 1000000, 1000000, 1000, new AllowedOverhang(sides: 1));
        $items = new ItemList();
        $items->insert(new TestItem('Cube', 1000000, 1000000, 1000000, 1, Rotation::BestFit), 10);

        foreach ([PackingStrategy::Fast, PackingStrategy::Thorough] as $strategy) {
            $packer = new VolumePacker($box, $items);
            $packer->setStrategy($strategy);
            $packedBox = $packer->pack();

            self::assertCount(1, $packedBox->items, $strategy->name);
            self::assertSame([], PackingValidator::problems($packedBox), $strategy->name);
        }

        // and so, with the Fast strategy, one to a box (the Thorough strategy's Packer adds up the volume of the items
        // as an int, as it always has)
        $packer = new Packer();
        $packer->addBox($box);
        $packer->setItems($items);
        $packedBoxes = $packer->pack();
        self::assertCount(10, $packedBoxes);
        foreach ($packedBoxes as $packedBox) {
            self::assertSame([], PackingValidator::problems($packedBox));
        }
    }

    public function testItemsWhoseTotalVolumeIsTooLargeForAnIntPackIntoABoxWithoutAnAllowedOverhangToo(): void
    {
        // where the block search packs them: v4.4.1 threw a TypeError here
        $box = new TestBox('Cube', 1000000, 1000000, 1000000, 0, 1000000, 1000000, 1000000, 100000);
        $cubes = new ItemList();
        $cubes->insert(new TestItem('Cube', 1000000, 1000000, 1000000, 1, Rotation::BestFit), 10);
        // 1,200,000 long, so these only fit turned at an angle; 80 of them are 9.6 × 10^18 in all
        $boards = new ItemList();
        $boards->insert(new TestItem('Board', 1200000, 100000, 1000000, 1, Rotation::KeepFlat), 80);

        foreach ([[PackingStrategy::Thorough, $cubes, false, 1], [PackingStrategy::Fast, $boards, true, 2], [PackingStrategy::Thorough, $boards, true, 2]] as [$strategy, $items, $angled, $count]) {
            $packer = new VolumePacker($box, $items);
            $packer->setStrategy($strategy);
            $packer->setAllowAngledPlacement($angled);
            $packedBox = $packer->pack();

            self::assertCount($count, $packedBox->items, $strategy->name);
            self::assertSame([], PackingValidator::problems($packedBox), $strategy->name);
        }
    }

    /**
     * @return array<string, array{int, int, int, int, float, bool}>
     */
    public static function deckOnlyLoads(): array
    {
        return [
            // the floor: every block is anchored hanging over the corner of the box first
            'one layer' => [1000, 1000, 10, 50, 0.5, false],
            'one layer, Packer' => [1000, 1000, 10, 201, 0.5, true],
            // and spaces above the floor, whose corners are over the edges, unsupported by a load kept on the deck
            'three layers, fully supported' => [100, 100, 30, 250, 1.0, false],
            'three layers' => [100, 100, 30, 250, 0.5, false],
        ];
    }

    /**
     * More items than the Thorough strategy also packs with the Fast strategy for comparison (40 for a VolumePacker,
     * 200 for a Packer), so that only the block search can pack them.
     */
    #[DataProvider('deckOnlyLoads')]
    public function testItemsWhoseCallbacksRefuseToHangOverArePackedOnTheDeck(int $width, int $length, int $depth, int $count, float $support, bool $viaPacker): void
    {
        $pack = static function (Box $box) use ($count, $support, $viaPacker): PackedBox {
            $items = new ItemList();
            for ($i = 0; $i < $count; ++$i) {
                $items->insert(new EdgeCaseDeckOnlyTestItem("Item {$i}", 10, 10, 10, 1, Rotation::BestFit));
            }
            if ($viaPacker) {
                $packer = new Packer();
                $packer->setStrategy(PackingStrategy::Thorough);
                $packer->setMinimumSupport($support);
                $packer->throwOnUnpackableItem(false);
                $packer->addBox($box);
                $packer->setItems($items);
                $packedBoxes = $packer->pack();
                self::assertCount(1, $packedBoxes);

                return $packedBoxes->top();
            }
            $packer = new VolumePacker($box, $items);
            $packer->setStrategy(PackingStrategy::Thorough);
            $packer->setMinimumSupport($support);

            return $packer->pack();
        };

        $plain = $pack(new TestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, 1000000));
        $packedBox = $pack(new OverhangTestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, 1000000, new AllowedOverhang(sides: 1, frontAndBack: 1)));

        self::assertCount($count, $plain->items);
        self::assertCount($count, $packedBox->items);
        self::assertSame([], PackingValidator::problems($packedBox));
        self::assertGreaterThanOrEqual($support, PackingValidator::minimumSupport($packedBox));
        foreach ($packedBox->items as $item) {
            self::assertTrue($item->x >= 0 && $item->x + $item->width <= $width && $item->y >= 0 && $item->y + $item->length <= $length);
        }
    }

    /**
     * @return array<string, array{int, int, int, AllowedOverhang, array{int, int, int, Rotation}, int, float, bool}>
     */
    public static function deckOnlyCopies(): array
    {
        // one item inserted many times is one type, packed in blocks of many. In each, the deck and the caps leave room
        // for a block wider than the deck with one cap ((deck + 2 caps) mod item < cap): 1,021 across with caps of 3
        // takes 51 20s, which still hang over by 2 when moved as far onto the deck as they go (as do 6 in 121 across);
        // 116 across with caps of 8 takes 2 55s
        $twenties = [1015, 1015, 10, new AllowedOverhang(sides: 3, frontAndBack: 3), [20, 20, 10, Rotation::BestFit]];
        $wide = [100, 100, 10, new AllowedOverhang(sides: 8), [55, 100, 10, Rotation::Never]];

        return [
            'VolumePacker' => [...$twenties, 100, 0.5, false],
            'Packer' => [...$twenties, 201, 0.5, true],
            'VolumePacker, three layers fully supported' => [115, 115, 30, new AllowedOverhang(sides: 3, frontAndBack: 3), [20, 20, 10, Rotation::BestFit], 75, 1.0, false],
            'VolumePacker, one to a box' => [...$wide, 41, 0.5, false],
            'Packer, one to a box' => [...$wide, 201, 0.5, true],
        ];
    }

    /**
     * As above, for one item inserted many times: more copies than the Thorough strategy also packs with the Fast
     * strategy for comparison.
     *
     * @param array{int, int, int, Rotation} $dimensions
     */
    #[DataProvider('deckOnlyCopies')]
    public function testCopiesOfAnItemWhoseCallbackRefusesToHangOverArePackedOnTheDeck(int $width, int $length, int $depth, AllowedOverhang $allowance, array $dimensions, int $count, float $support, bool $viaPacker): void
    {
        $pack = static function (Box $box) use ($dimensions, $count, $support, $viaPacker): PackedBoxList {
            $item = new EdgeCaseDeckOnlyTestItem('Item', $dimensions[0], $dimensions[1], $dimensions[2], 1, $dimensions[3]);
            $items = new ItemList();
            $items->insert($item, $count);
            if ($viaPacker) {
                $packer = new Packer();
                $packer->setStrategy(PackingStrategy::Thorough);
                $packer->setMinimumSupport($support);
                $packer->throwOnUnpackableItem(false);
                $packer->addBox($box);
                $packer->setItems($items);

                return $packer->pack();
            }
            $packer = new VolumePacker($box, $items);
            $packer->setStrategy(PackingStrategy::Thorough);
            $packer->setMinimumSupport($support);
            $packedBoxes = new PackedBoxList();
            $packedBoxes->insert($packer->pack());

            return $packedBoxes;
        };

        $plain = $pack(new TestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, 1000000));
        $packedBoxes = $pack(new OverhangTestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, 1000000, $allowance));

        self::assertSame(self::itemCounts($plain), self::itemCounts($packedBoxes));
        self::assertGreaterThan(0, $packedBoxes->top()->items->count());
        foreach ($packedBoxes as $packedBox) {
            self::assertSame([], PackingValidator::problems($packedBox));
            self::assertGreaterThanOrEqual($support, PackingValidator::minimumSupport($packedBox));
            foreach ($packedBox->items as $item) {
                self::assertTrue($item->x >= 0 && $item->x + $item->width <= $width && $item->y >= 0 && $item->y + $item->length <= $length);
            }
        }
    }

    public function testAFlatItemIsTurnedToFitTheDeckRatherThanHangOver(): void
    {
        // the sheet fits the box with its overhang as it is, 50 over each side (its cap is 150, the allowance 100), but
        // turned it fits the deck
        $box = new OverhangTestBox('Pallet', 1000, 1200, 100, 0, 1000, 1200, 100, 1000, new AllowedOverhang(sides: 100));
        $items = new ItemList();
        $items->insert(new TestItem('Sheet', 1100, 500, 0, 1, Rotation::KeepFlat));
        $packer = new VolumePacker($box, $items);
        $packer->setStrategy(PackingStrategy::Thorough);

        $packedBox = $packer->pack();

        self::assertSame(['Sheet 0,0,0 500x1100x0'], self::describe($packedBox));
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    public function testAFlatItemStandsOnEdgeToFitTheDeckRatherThanHangOver(): void
    {
        // lying flat, the only stable way, the card fits only with its overhang, 50 over each side; it fits the deck
        // standing on edge, as it stands in the box without an allowed overhang
        $items = new ItemList();
        $items->insert(new TestItem('Card', 0, 500, 1100, 1, Rotation::BestFit));
        $plain = new VolumePacker(new TestBox('Pallet', 1000, 1000, 1200, 0, 1000, 1000, 1200, 1000), $items);
        $plain->setStrategy(PackingStrategy::Thorough);
        $packer = new VolumePacker(new OverhangTestBox('Pallet', 1000, 1000, 1200, 0, 1000, 1000, 1200, 1000, new AllowedOverhang(sides: 100)), $items);
        $packer->setStrategy(PackingStrategy::Thorough);

        $packedBox = $packer->pack();

        self::assertSame(['Card 0,0,0 0x500x1100'], self::describe($plain->pack()));
        self::assertSame(['Card 0,0,0 0x500x1100'], self::describe($packedBox));
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    public function testAFlatItemWhoseCallbackRefusesTheDeckIsPlacedTheWayItAccepts(): void
    {
        // turned to fit the deck, the sheet is at x = 0, which its callback refuses; as it is, it hangs 50 over each
        // side, at x = -50, which it accepts. More sheets than the Thorough strategy also packs with the Fast strategy
        // for comparison, so that only the block search can pack them
        $sheet = new ConstrainedPlacementMaxXTestItem('Sheet', 1100, 500, 0, 1, Rotation::KeepFlat, -25);
        $items = new ItemList();
        $items->insert($sheet, 41);
        $packer = new VolumePacker(new OverhangTestBox('Pallet', 1000, 1200, 100, 0, 1000, 1200, 100, 1000, new AllowedOverhang(sides: 100)), $items);
        $packer->setStrategy(PackingStrategy::Thorough);

        $packedBox = $packer->pack();

        self::assertCount(41, $packedBox->items);
        self::assertSame(['Sheet -50,0,0 1100x500x0'], array_unique(self::describe($packedBox)));
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    public function testAnItemThatCannotGoInDoesNotChangeWhereTheOthersGo(): void
    {
        // the item too heavy for the box would widen the search frame by its cap (8,400 at each side, against the
        // carton's 255) to more than gap scoring is worked out for, so the carton would be placed by volume alone
        $box = new OverhangTestBox('Pallet', 40000, 2000, 100, 0, 40000, 2000, 100, 100, new AllowedOverhang(sides: 10000));
        $carton = new TestItem('Carton', 1700, 1400, 100, 1, Rotation::KeepFlat);

        $alone = (new BlockPacker($box, [$carton]))->pack();
        $packedBox = (new BlockPacker($box, [$carton, new TestItem('Too heavy', 56000, 100, 100, 101, Rotation::Never)]))->pack();

        self::assertSame(self::describe($alone), self::describe($packedBox));
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    public function testANegativeAllowanceMadeWithoutTheConstructorIsRejected(): void
    {
        // as unserialize() can make one, from a stored or cached box
        $allowance = unserialize('O:' . strlen(AllowedOverhang::class) . ':"' . AllowedOverhang::class . '":2:{s:5:"sides";i:-1;s:12:"frontAndBack";i:0;}');
        self::assertInstanceOf(AllowedOverhang::class, $allowance);
        self::assertSame(-1, $allowance->sides);
        $items = new ItemList();
        $items->insert(new TestItem('Item', 100, 100, 100, 1, Rotation::BestFit));
        $packer = new VolumePacker(new OverhangTestBox('Pallet', 1000, 1000, 1000, 0, 1000, 1000, 1000, 1000, $allowance), $items);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be negative');
        $packer->pack();
    }

    public function testFastDoesNotSearchWhenTheDeckHoldsEveryItem(): void
    {
        // the block search could pack no more, and the layer packing is kept on a tie
        $items = new ItemList();
        $items->insert(new TestItem('Carton', 400, 400, 400, 1, Rotation::BestFit), 4);
        $plain = new VolumePacker(new TestBox('Pallet', 1000, 1000, 1000, 0, 1000, 1000, 1000, 1000), $items);
        $packer = new VolumePacker(new OverhangTestBox('Pallet', 1000, 1000, 1000, 0, 1000, 1000, 1000, 1000, new AllowedOverhang(sides: 100, frontAndBack: 100)), $items);

        $packedBox = $packer->pack();

        self::assertCount(4, $packedBox->items);
        self::assertSame(self::describe($plain->pack()), self::describe($packedBox));
        self::assertSame(0, $packer->getSearchPlacements());
    }

    /**
     * @return array<string, array{?Item}>
     */
    public static function itemsThatCannotUseTheOverhang(): array
    {
        // each is 7 across or more, so has a cap of 1, but cannot go in the box even with its overhang, or (having no
        // volume) goes on its own on the deck, which it fits
        return [
            'none' => [null],
            'too heavy' => [new TestItem('Too heavy', 7, 1, 1, 2000, Rotation::Never)],
            'too wide' => [new TestItem('Too wide', 20, 1, 1, 1, Rotation::Never)],
            'too tall' => [new TestItem('Too tall', 7, 1, 2, 1, Rotation::Never)],
            'with no volume, fitting the deck' => [new TestItem('Card', 7, 0, 1, 1, Rotation::Never)],
        ];
    }

    #[DataProvider('itemsThatCannotUseTheOverhang')]
    public function testAnAllowanceThatNoItemCanUsePacksAsNoAllowance(?Item $cannotUseIt): void
    {
        // every other item is under 7 across, so 15 % of it rounds down to 0. The layer packer puts the 5 × 5 in first
        // (25); a block search would pack the four 3 × 3 (36) instead
        $items = new ItemList();
        $items->insert(new TestItem('Large', 5, 5, 1, 1, Rotation::Never));
        $items->insert(new TestItem('Small', 3, 3, 1, 1, Rotation::Never), 4);
        if ($cannotUseIt !== null) {
            $items->insert($cannotUseIt);
        }
        $box = new OverhangTestBox('Box', 7, 6, 1, 0, 7, 6, 1, 1000, new AllowedOverhang(sides: 1, frontAndBack: 1));

        foreach ([PackingStrategy::Fast, PackingStrategy::Thorough] as $strategy) {
            $plain = new VolumePacker(new TestBox('Box', 7, 6, 1, 0, 7, 6, 1, 1000), $items);
            $plain->setStrategy($strategy);
            $packer = new VolumePacker($box, $items);
            $packer->setStrategy($strategy);

            $packedBox = $packer->pack();

            self::assertSame(self::describe($plain->pack()), self::describe($packedBox), $strategy->name);
            self::assertSame($plain->getSearchPlacements(), $packer->getSearchPlacements(), $strategy->name);
            self::assertSame([], PackingValidator::problems($packedBox));
            if ($strategy === PackingStrategy::Fast) {
                self::assertContains('Large 0,0,0 5x5x1', self::describe($packedBox));
            }
        }
    }

    public function testWhetherAnItemCanHangOverDependsOnTheExtentsItsRotationAllows(): void
    {
        $sides = OverhangBounds::forBox(new OverhangTestBox('Box', 6, 6, 100, 0, 6, 6, 100, 1000, new AllowedOverhang(sides: 1)));
        $ends = OverhangBounds::forBox(new OverhangTestBox('Box', 6, 6, 100, 0, 6, 6, 100, 1000, new AllowedOverhang(frontAndBack: 1)));
        self::assertNotNull($sides);
        self::assertNotNull($ends);

        // 7 is the least extent with a cap of 1
        self::assertFalse($sides->canHangOver(new TestItem('Upright', 6, 6, 7, 1, Rotation::Never)));
        self::assertFalse($sides->canHangOver(new TestItem('Upright', 6, 6, 7, 1, Rotation::KeepFlat)));
        self::assertTrue($sides->canHangOver(new TestItem('Upright', 6, 6, 7, 1, Rotation::BestFit)));
        self::assertFalse($ends->canHangOver(new TestItem('Wide', 7, 6, 1, 1, Rotation::Never)));
        self::assertTrue($ends->canHangOver(new TestItem('Wide', 7, 6, 1, 1, Rotation::KeepFlat)));
        self::assertTrue($sides->canHangOver(new TestItem('Wide', 7, 6, 1, 1, Rotation::Never)));
        // only in an orientation in which it fits the box with its overhang
        self::assertFalse($sides->canHangOver(new TestItem('Too wide', 20, 1, 1, 1, Rotation::Never)));
        self::assertFalse($sides->canHangOver(new TestItem('Too tall', 7, 1, 101, 1, Rotation::Never)));
        self::assertTrue($sides->canHangOver(new TestItem('Lying down', 1, 1, 7, 1, Rotation::BestFit)));

        // an item with no volume goes on the deck if it fits it, unless its placement callback refuses that
        $seven = OverhangBounds::forBox(new OverhangTestBox('Box', 7, 7, 100, 0, 7, 7, 100, 1000, new AllowedOverhang(sides: 1)));
        self::assertNotNull($seven);
        self::assertFalse($seven->canHangOver(new TestItem('Card', 7, 0, 1, 1, Rotation::Never)));
        self::assertTrue($seven->canHangOver(new ConstrainedPlacementMaxXTestItem('Card', 7, 0, 1, 1, Rotation::Never, -1)));
        self::assertTrue($seven->canHangOver(new TestItem('Card', 8, 0, 1, 1, Rotation::Never)));
        self::assertFalse($seven->canHangOver(new TestItem('Card', 8, 0, 1, 1, Rotation::BestFit))); // standing up
    }

    public function testAnItemWithNoVolumeInsideAnotherFailsTheRealWorldCheckOnlyWithoutAnAllowedOverhang(): void
    {
        if (ini_get('zend.assertions') !== '1') {
            self::markTestSkipped('The real-world check is an assertion (zend.assertions=1)');
        }
        // a card standing on edge through the middle of a carton
        $items = new PackedItemList();
        $items->insert(new PackedItem(new TestItem('Carton', 100, 100, 100, 1, Rotation::BestFit), 0, 0, 0, 100, 100, 100));
        $items->insert(new PackedItem(new TestItem('Card', 0, 50, 50, 1, Rotation::BestFit), 50, 10, 10, 0, 50, 50));

        // with an allowed overhang, an item with no volume is placed once the load has been moved, so may lie within it
        new PackedBox(new OverhangTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000, new AllowedOverhang(sides: 10)), $items);

        $this->expectException(AssertionError::class);
        new PackedBox(new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000), $items);
    }

    /**
     * The number of items in each box, in order.
     *
     * @return list<int>
     */
    private static function itemCounts(PackedBoxList $packedBoxes): array
    {
        $counts = [];
        foreach ($packedBoxes as $packedBox) {
            $counts[] = $packedBox->items->count();
        }

        return $counts;
    }

    /**
     * Each item as "description x,y,z widthxlengthxdepth", sorted.
     *
     * @return list<string>
     */
    private static function describe(PackedBox $packedBox): array
    {
        $described = [];
        foreach ($packedBox->items as $item) {
            $described[] = "{$item->item->getDescription()} {$item->x},{$item->y},{$item->z} {$item->width}x{$item->length}x{$item->depth}";
        }
        sort($described);

        return $described;
    }
}
