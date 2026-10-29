<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Benchmark\PackingValidator;
use DVDoug\BoxPacker\Test\ConstrainedPlacementByCountTestItem;
use DVDoug\BoxPacker\Test\OverhangTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function count;
use function intdiv;
use function iterator_to_array;
use function max;
use function min;
use function sort;

/**
 * Packing boxes with an allowed overhang (Thorough and Fast), checked with the independent validator throughout, as
 * assertions are normally compiled out.
 */
#[CoversClass(BlockPacker::class)]
#[CoversClass(VolumePacker::class)]
#[CoversClass(OverhangBounds::class)]
#[CoversClass(SupportCalculator::class)]
#[CoversClass(AngledGeometry::class)]
class OverhangPackingTest extends TestCase
{
    /**
     * The canonical case, in hundredths of an inch: a 49.53" × 22.52" × 7.56" item on a 48" × 40" pallet loaded to 65".
     */
    private const ACME_TRAMPOLINES = [4953, 2252, 756];

    private const PALLET = [4800, 4000, 6500];

    private const NO_WEIGHT_LIMIT = 1000000000;

    protected function tearDown(): void
    {
        ConstrainedPlacementByCountTestItem::$limit = 3;
    }

    private static function pallet(?AllowedOverhang $allowance, int $width = self::PALLET[0], int $length = self::PALLET[1], int $depth = self::PALLET[2]): Box
    {
        return $allowance === null
            ? new TestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, self::NO_WEIGHT_LIMIT)
            : new OverhangTestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, self::NO_WEIGHT_LIMIT, $allowance);
    }

    private static function trampolines(int $quantity): ItemList
    {
        $items = new ItemList();
        $items->insert(new TestItem('ACME Trampolines', ...[...self::ACME_TRAMPOLINES, 1, Rotation::BestFit]), $quantity);

        return $items;
    }

    private static function thorough(Box $box, ItemList $items, float $support = 1.0, bool $angled = false): PackedBox
    {
        $packer = new VolumePacker($box, $items);
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setMinimumSupport($support);
        $packer->setAllowAngledPlacement($angled);

        return $packer->pack();
    }

    private static function fast(Box $box, ItemList $items, float $support = 1.0, bool $angled = false): PackedBox
    {
        $packer = new VolumePacker($box, $items);
        $packer->setMinimumSupport($support);
        $packer->setAllowAngledPlacement($angled);

        return $packer->pack();
    }

    private static function assertValid(PackedBox $packedBox, float $support = 1.0): void
    {
        self::assertSame([], PackingValidator::problems($packedBox));
        self::assertGreaterThanOrEqual($support, PackingValidator::minimumSupport($packedBox));
    }

    /**
     * Independent oracle for the acceptance table: the best uniform grid (one orientation for the whole load), as the
     * WMS slot grid counts it. Per axis, n items of extent u fit a deck span S with allowances a and b when
     * n × u <= S + min(a, 15 % of u) + min(b, 15 % of u).
     */
    private static function uniformGrid(AllowedOverhang $allowance): int
    {
        [$a, $b, $c] = self::ACME_TRAMPOLINES;
        [$width, $length, $height] = self::PALLET;
        $best = 0;
        foreach ([[$a, $b, $c], [$b, $a, $c], [$a, $c, $b], [$c, $a, $b], [$b, $c, $a], [$c, $b, $a]] as [$u, $v, $h]) {
            $capU = intdiv(15 * $u, 100);
            $capV = intdiv(15 * $v, 100);
            $nx = intdiv($width + min($allowance->left, $capU) + min($allowance->right, $capU), $u);
            $ny = intdiv($length + min($allowance->front, $capV) + min($allowance->back, $capV), $v);
            $best = max($best, $nx * $ny * intdiv($height, $h));
        }

        return $best;
    }

    /**
     * Allowances (left, right, front, back), the brief's uniform-grid count, and what Thorough finds.
     *
     * @return array<string, array{?AllowedOverhang, int, int}>
     */
    public static function acmeTrampolinesTable(): array
    {
        return [
            'none' => [null, 10, 10],
            '1.5 / 3 / 3' => [new AllowedOverhang(150, 150, 300, 300), 16, 16],
            // Thorough mixes orientations where a uniform grid cannot: 8 flat (4953 × 2252 × 756) hanging 3" over the
            // front, and beside them on the deck 2 × 2 on their long edges (4953 × 756 × 2252), so 12. Each item is
            // within its caps (the validator checks every one) and fully supported. The table's 10 is the best
            // uniform grid.
            '1.5 / 3 / 0' => [new AllowedOverhang(150, 150, 300, 0), 10, 12],
            '2.5 / 2.5 / 2.5' => [new AllowedOverhang(250, 250, 250, 250), 10, 12],
        ];
    }

    #[DataProvider('acmeTrampolinesTable')]
    public function testAcmeTrampolinesUniformGridOracle(?AllowedOverhang $allowance, int $grid, int $thorough): void
    {
        self::assertSame($grid, self::uniformGrid($allowance ?? new AllowedOverhang()));
    }

    #[DataProvider('acmeTrampolinesTable')]
    public function testAcmeTrampolinesThorough(?AllowedOverhang $allowance, int $grid, int $thorough): void
    {
        $packedBox = self::thorough(self::pallet($allowance), self::trampolines(24));

        self::assertValid($packedBox);
        self::assertGreaterThanOrEqual($grid, $packedBox->items->count());
        self::assertCount($thorough, $packedBox->items);
    }

    #[DataProvider('acmeTrampolinesTable')]
    public function testAcmeTrampolinesFast(?AllowedOverhang $allowance, int $grid, int $thorough): void
    {
        $deckOnly = self::fast(self::pallet(null), self::trampolines(24));
        $packedBox = self::fast(self::pallet($allowance), self::trampolines(24));

        self::assertValid($packedBox);
        self::assertGreaterThanOrEqual($deckOnly->items->count(), $packedBox->items->count());
        // the greedy block search takes the largest block first: 10 on their long edges (1 × 5 × 2) in rows 3 and 4
        self::assertCount($grid, $packedBox->items);
    }

    public function testAcmeTrampolinesSixteenHangsOverAllFourEdges(): void
    {
        $packedBox = self::thorough(self::pallet(new AllowedOverhang(150, 150, 300, 300)), self::trampolines(24));

        self::assertSame(4953, $packedBox->getUsedWidth());
        self::assertSame(4504, $packedBox->getUsedLength());
        self::assertSame(-153, $packedBox->getRemainingWidth());
        self::assertSame(-504, $packedBox->getRemainingLength());
        self::assertSame(6048, $packedBox->getUsedDepth());
        self::assertSame(108.1, $packedBox->getVolumeUtilisation());
        self::assertLessThan(0, $packedBox->getUnusedVolume());
        self::assertSame(1.0, SupportCalculator::minimumSupport($packedBox->items, $packedBox->box));
    }

    public function testAnItemThatOnlyFitsWithOverhangIsPacked(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('Long', 1100, 500, 100, 1, Rotation::KeepFlat));

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            self::assertCount(0, $pack(self::pallet(null, 1000, 1000, 500), $items)->items);
            $packedBox = $pack(self::pallet(new AllowedOverhang(50, 50, 0, 0), 1000, 1000, 500), $items);
            self::assertCount(1, $packedBox->items);
            self::assertValid($packedBox);
            self::assertSame(-50, self::first($packedBox)->x);
        }
    }

    public function testBlocksAreAnchoredAtAllFourCornersWithinTheirCaps(): void
    {
        // four 520 × 520 items (caps 78) on a 1000 × 1000 deck: two across each way need 40 of overhang, and the right
        // and back allowances are far larger than the items' caps, so blocks anchored at the far corners must stop at
        // the caps, not at the edge of the envelope
        $box = self::pallet(new AllowedOverhang(10, 200, 20, 300), 1000, 1000, 100);
        $items = new ItemList();
        foreach ([1, 2, 3, 4] as $weight) {
            $items->insert(new TestItem("Item {$weight}", 520, 520, 100, $weight, Rotation::Never));
        }

        $packedBox = self::thorough($box, $items);

        self::assertCount(4, $packedBox->items);
        self::assertValid($packedBox);
        $packed = iterator_to_array($packedBox->items, false);
        self::assertSame(-10, min(array_map(static fn (PackedItem $item) => $item->x, $packed)));
        self::assertSame(1078, max(array_map(static fn (PackedItem $item) => $item->x + $item->width, $packed)));
        self::assertSame(-20, min(array_map(static fn (PackedItem $item) => $item->y, $packed)));
        self::assertSame(1078, max(array_map(static fn (PackedItem $item) => $item->y + $item->length, $packed)));
    }

    public function testRaisedItemsAreCappedToo(): void
    {
        // a base hanging 150 over the left edge (its cap is 172); narrow items on top of it, fully supported anywhere
        // on it, may still hang over by only 15 of their own 100, so 10 of them fit across it, not 11
        $box = self::pallet(new AllowedOverhang(left: 150), 1000, 1000, 200);
        $items = new ItemList();
        $items->insert(new TestItem('Base', 1150, 1000, 100, 1, Rotation::Never));
        $items->insert(new TestItem('Narrow', 100, 1000, 100, 1, Rotation::Never), 12);

        $packedBox = self::thorough($box, $items);

        self::assertValid($packedBox);
        $narrow = array_filter(iterator_to_array($packedBox->items, false), static fn (PackedItem $item) => $item->item->getDescription() === 'Narrow');
        self::assertCount(10, $narrow);
        self::assertSame(-15, min(array_map(static fn (PackedItem $item) => $item->x, $narrow)));
        self::assertSame(-150, self::first($packedBox)->x);
    }

    public function testFullSupportAboveTheOverhang(): void
    {
        // two 550 wide items side by side hang 50 over each side; an item as wide as both rests on them, fully
        // supported, hanging over by the same 50 (its own cap would allow 165)
        $box = self::pallet(new AllowedOverhang(50, 50, 0, 0), 1000, 500, 200);
        $items = new ItemList();
        $items->insert(new TestItem('Half', 550, 500, 100, 1, Rotation::Never), 2);
        $items->insert(new TestItem('Whole', 1100, 500, 100, 1, Rotation::Never));

        $packedBox = self::thorough($box, $items);

        self::assertCount(3, $packedBox->items);
        self::assertValid($packedBox);
        self::assertSame(1.0, SupportCalculator::minimumSupport($packedBox->items, $box));
    }

    public function testPartlyRefusedBlockIsShrunkWithinItsCaps(): void
    {
        // the constrained items' callback takes only 3; the block it is asked about sits at the far (right) end of the
        // space, whose right allowance of 200 is far more than their cap of 18, so the smaller block must be placed
        // against the cap, not the envelope
        ConstrainedPlacementByCountTestItem::$limit = 3;
        $box = self::pallet(new AllowedOverhang(right: 200), 1000, 100, 100);
        $items = new ItemList();
        $items->insert(new TestItem('Plain', 400, 100, 100, 1, Rotation::Never));
        $items->insert(new ConstrainedPlacementByCountTestItem('Limited', 120, 100, 100, 1, Rotation::Never), 5);

        // the block search itself (a VolumePacker would keep the layer packer's packing of the deck, as dense)
        $blockPacker = new BlockPacker($box, $items);
        $blockPacker->setMinimumSupport(1.0);
        $packedBox = $blockPacker->pack();

        self::assertCount(4, $packedBox->items);
        self::assertValid($packedBox);
        $limited = array_filter(iterator_to_array($packedBox->items, false), static fn (PackedItem $item) => $item->item->getDescription() === 'Limited');
        self::assertCount(3, $limited);
        self::assertSame(1018, max(array_map(static fn (PackedItem $item) => $item->x + $item->width, $limited)));
    }

    public function testFlatItems(): void
    {
        // a sheet that only fits with the overhang, and items with no size at all
        $box = self::pallet(new AllowedOverhang(left: 100), 1000, 1000, 100);
        $items = new ItemList();
        $items->insert(new TestItem('Sheet', 1100, 500, 0, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Point', 0, 0, 0, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Box', 1000, 1000, 100, 1, Rotation::KeepFlat));

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            $packedBox = $pack($box, $items);
            self::assertCount(3, $packedBox->items);
            self::assertValid($packedBox);
            foreach ($packedBox->items as $packedItem) {
                if ($packedItem->item->getDescription() !== 'Box') {
                    self::assertSame([$packedItem->item->getDescription() === 'Sheet' ? -100 : 0, 0, 0], [$packedItem->x, $packedItem->y, $packedItem->z]);
                }
            }
        }
    }

    public function testFloorSupportCountsTheDeckAndTheCaps(): void
    {
        $box = self::pallet(new AllowedOverhang(50, 50, 0, 0), 1000, 500, 200);
        $item = new TestItem('Half', 550, 500, 100, 1, Rotation::Never);

        $legal = new PackedItemList();
        $legal->insert(new PackedItem($item, -50, 0, 0, 550, 500, 100));
        self::assertSame(1.0, SupportCalculator::minimumSupport($legal, $box));

        $beyond = new PackedItemList();
        $beyond->insert(new PackedItem($item, -51, 0, 0, 550, 500, 100));
        self::assertEqualsWithDelta(549 / 550, SupportCalculator::minimumSupport($beyond, $box), 1e-9);
        self::assertSame(1.0, SupportCalculator::minimumSupport($beyond)); // without the box, floor items are not checked
        self::assertSame(1.0, SupportCalculator::minimumSupport($beyond, self::pallet(null, 1000, 500, 200)));
    }

    public function testFastNeverPacksLessThanTheDeckAlone(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('A', 300, 200, 150, 10, Rotation::BestFit), 7);
        $items->insert(new TestItem('B', 420, 260, 110, 10, Rotation::KeepFlat), 5);
        $items->insert(new TestItem('C', 120, 90, 60, 10, Rotation::BestFit), 20);
        $items->insert(new TestItem('D', 1050, 300, 100, 10, Rotation::KeepFlat), 2);

        foreach ([new AllowedOverhang(30, 30, 30, 30), new AllowedOverhang(100, 0, 0, 100), new AllowedOverhang(0, 0, 0, 500)] as $allowance) {
            $deckOnly = self::fast(self::pallet(null, 1000, 800, 600), $items, 0.5);
            $packedBox = self::fast(self::pallet($allowance, 1000, 800, 600), $items, 0.5);
            self::assertValid($packedBox, 0.5);
            self::assertGreaterThanOrEqual($deckOnly->getUsedVolume(), $packedBox->getUsedVolume());
        }
    }

    public function testStrictOrderingPacksTheDeckOnly(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('Long', 1100, 500, 100, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Small', 400, 400, 100, 1, Rotation::KeepFlat), 3);

        $plain = new VolumePacker(self::pallet(null, 1000, 1000, 500), $items);
        $plain->beStrictAboutItemOrdering(true);
        $overhang = new VolumePacker(self::pallet(new AllowedOverhang(50, 50, 0, 0), 1000, 1000, 500), $items);
        $overhang->beStrictAboutItemOrdering(true);
        $overhang->setStrategy(PackingStrategy::Thorough); // which does not apply to strict ordering either

        $plainBox = $plain->pack();
        $overhangBox = $overhang->pack();
        self::assertValid($overhangBox, 0.0);
        self::assertSame(self::placements($plainBox), self::placements($overhangBox));
        self::assertSame(0, $overhang->getSearchPlacements());
    }

    public function testPackBestSubsetUsesTheOverhang(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('Long', 1100, 500, 100, 1, Rotation::KeepFlat), 2);
        $items->insert(new TestItem('Small', 300, 300, 300, 1, Rotation::KeepFlat), 2);

        foreach ([PackingStrategy::Fast, PackingStrategy::Thorough] as $strategy) {
            $packer = new VolumePacker(self::pallet(new AllowedOverhang(50, 50, 0, 0), 1000, 1000, 200), $items);
            $packer->setStrategy($strategy);
            $packedBox = $packer->packBestSubset();
            self::assertValid($packedBox, 0.5);
            self::assertCount(2, array_filter(iterator_to_array($packedBox->items, false), static fn (PackedItem $item) => $item->item->getDescription() === 'Long'));
        }
    }

    public function testAnItemThatFitsSquareWithOverhangIsNotAngled(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('Long', 1100, 100, 100, 1, Rotation::KeepFlat), 3);

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            $plain = $pack(self::pallet(null, 1000, 1000, 100), $items, 1.0, true);
            self::assertTrue(self::first($plain)->isAngled()); // on the deck alone it only fits at an angle

            $packedBox = $pack(self::pallet(new AllowedOverhang(50, 50, 0, 0), 1000, 1000, 100), $items, 1.0, true);
            self::assertCount(3, $packedBox->items);
            self::assertValid($packedBox);
            foreach ($packedBox->items as $packedItem) {
                self::assertFalse($packedItem->isAngled());
            }
        }
    }

    public function testAngledItemsStayOnTheDeckBesideOverhangingOnes(): void
    {
        // the rods are too long to fit even with the overhang, so are angled, on the deck; the planks hang over
        $rod = new TestItem('Rod', 1150, 50, 60, 1, Rotation::KeepFlat);
        $items = new ItemList();
        $items->insert($rod, 2);
        $items->insert(new TestItem('Plank', 1080, 100, 60, 1, Rotation::KeepFlat), 2);
        $box = self::pallet(new AllowedOverhang(40, 40, 40, 40), 1000, 1000, 60);
        self::assertTrue(AngledGeometry::onlyFitsAngled($rod, $box));

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            $packedBox = $pack($box, $items, 1.0, true);
            self::assertValid($packedBox);
            $byType = [];
            foreach ($packedBox->items as $packedItem) {
                $byType[$packedItem->item->getDescription()][] = $packedItem;
            }
            self::assertCount(2, $byType['Rod']);
            self::assertCount(2, $byType['Plank']);
            foreach ($byType['Rod'] as $rod) {
                self::assertTrue($rod->isAngled());
                self::assertGreaterThanOrEqual(0, $rod->x);
                self::assertLessThanOrEqual(1000, $rod->x + $rod->boundingWidth);
            }
            foreach ($byType['Plank'] as $plank) {
                self::assertFalse($plank->isAngled());
                self::assertSame(-40, $plank->x);
            }
        }
    }

    public function testOnlyFitsAngledIsOverhangAware(): void
    {
        $item = new TestItem('Long', 1100, 100, 100, 1, Rotation::KeepFlat);
        self::assertTrue(AngledGeometry::onlyFitsAngled($item, self::pallet(null, 1000, 1000, 100)));
        self::assertFalse(AngledGeometry::onlyFitsAngled($item, self::pallet(new AllowedOverhang(50, 50, 0, 0), 1000, 1000, 100)));
        self::assertTrue(AngledGeometry::onlyFitsAngled($item, self::pallet(new AllowedOverhang(49, 50, 0, 0), 1000, 1000, 100)));
    }

    private static function first(PackedBox $packedBox): PackedItem
    {
        return iterator_to_array($packedBox->items, false)[0];
    }

    /**
     * @return list<string>
     */
    private static function placements(PackedBox $packedBox): array
    {
        $placements = array_map(static fn (PackedItem $item) => "{$item->item->getDescription()} {$item->x},{$item->y},{$item->z} {$item->width}x{$item->length}x{$item->depth}", iterator_to_array($packedBox->items, false));
        sort($placements);

        return $placements;
    }
}
