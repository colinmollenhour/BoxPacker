<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Benchmark\InstanceLoader;
use DVDoug\BoxPacker\Benchmark\PackingValidator;
use DVDoug\BoxPacker\Test\ConstrainedPlacementByCountTestItem;
use DVDoug\BoxPacker\Test\ConstrainedPlacementNoStackingTestItem;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function iterator_to_array;

#[CoversClass(BlockPacker::class)]
#[CoversClass(VolumePacker::class)]
class ThoroughVolumePackerTest extends TestCase
{
    private static function thorough(Box $box, ItemList $items, int $width = 4, float $support = 1.0): PackedBox
    {
        $packer = new VolumePacker($box, $items);
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setMaxBeamWidth($width);
        $packer->setMinimumSupport($support);

        return $packer->pack();
    }

    private static function assertValid(PackedBox $packedBox, float $support = 1.0): void
    {
        self::assertSame([], PackingValidator::problems($packedBox));
        self::assertGreaterThanOrEqual($support, PackingValidator::minimumSupport($packedBox));
    }

    public function testExactFitOfIdenticalCubes(): void
    {
        $box = new TestBox('Box', 20, 20, 20, 0, 20, 20, 20, 10000);
        $items = new ItemList();
        $items->insert(new TestItem('Cube', 10, 10, 10, 100, Rotation::BestFit), 8);

        $packedBox = self::thorough($box, $items);

        self::assertCount(8, $packedBox->items);
        self::assertSame(100.0, $packedBox->getVolumeUtilisation());
        self::assertValid($packedBox);
    }

    public function testRotationNeedsToBeUsedToFitEverything(): void
    {
        // 3 lying flat across the bottom (30 x 10 each) leave a 30 x 10 x 20 gap that 2 more fill only when stood up
        $box = new TestBox('Box', 30, 20, 20, 0, 30, 20, 20, 10000);
        $items = new ItemList();
        $items->insert(new TestItem('Plank', 30, 10, 10, 100, Rotation::BestFit), 4);

        $packedBox = self::thorough($box, $items);

        self::assertCount(4, $packedBox->items);
        self::assertValid($packedBox);
    }

    public function testKeepFlatAndNeverRotationsAreRespected(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 100000);
        $items = new ItemList();
        $items->insert(new TestItem('Flat', 60, 40, 10, 100, Rotation::KeepFlat), 10);
        $items->insert(new TestItem('Fixed', 30, 20, 50, 100, Rotation::Never), 10);

        $packedBox = self::thorough($box, $items);

        self::assertCount(20, $packedBox->items);
        self::assertValid($packedBox);
        foreach ($packedBox->items as $packedItem) {
            if ($packedItem->item->getDescription() === 'Fixed') {
                self::assertSame([30, 20, 50], [$packedItem->width, $packedItem->length, $packedItem->depth]);
            } else {
                self::assertSame(10, $packedItem->depth);
            }
        }
    }

    public function testWeightLimitIsRespected(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 100, 100, 100, 100, 1100);
        $items = new ItemList();
        $items->insert(new TestItem('Heavy', 10, 10, 10, 300, Rotation::BestFit), 10);

        $packedBox = self::thorough($box, $items);

        self::assertCount(3, $packedBox->items);
        self::assertValid($packedBox);
    }

    public function testPlacementCallbacksAreRespected(): void
    {
        ConstrainedPlacementByCountTestItem::$limit = 2;
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 100000);
        $items = new ItemList();
        $items->insert(new ConstrainedPlacementByCountTestItem('Limited', 10, 10, 10, 10, Rotation::BestFit), 5);
        $items->insert(new TestItem('Other', 10, 10, 10, 10, Rotation::BestFit), 5);

        $packedBox = self::thorough($box, $items);

        $limited = 0;
        foreach ($packedBox->items as $packedItem) {
            $limited += $packedItem->item->getDescription() === 'Limited' ? 1 : 0;
        }
        self::assertSame(2, $limited);
        self::assertCount(7, $packedBox->items);
        self::assertValid($packedBox);
    }

    public function testNoStackingCallbackIsRespected(): void
    {
        $box = new TestBox('Box', 30, 10, 100, 0, 30, 10, 100, 100000);
        $items = new ItemList();
        $items->insert(new ConstrainedPlacementNoStackingTestItem('Egg box', 10, 10, 10, 10, Rotation::BestFit), 6);

        $packedBox = self::thorough($box, $items);

        self::assertCount(3, $packedBox->items);
        foreach ($packedBox->items as $packedItem) {
            self::assertSame(0, $packedItem->z);
        }
    }

    public function testNeverWorseThanFastAndDeterministic(): void
    {
        foreach (array_slice(InstanceLoader::load('br1'), 0, 2) as $instance) {
            $fast = (new VolumePacker($instance['box'], $instance['items']))->pack();
            $thoroughA = self::thorough($instance['box'], $instance['items'], 2, 0.75);
            $thoroughB = self::thorough($instance['box'], $instance['items'], 2, 0.75);

            self::assertGreaterThanOrEqual($fast->getUsedVolume(), $thoroughA->getUsedVolume());
            self::assertEquals(self::describe($thoroughA), self::describe($thoroughB));
            self::assertValid($thoroughA, 0.75);
        }
    }

    public static function supportLevels(): array
    {
        return [[1.0], [0.75], [0.5]];
    }

    #[DataProvider('supportLevels')]
    public function testSupportIsRespected(float $support): void
    {
        foreach (array_slice(InstanceLoader::load('br4'), 0, 2) as $instance) {
            $packedBox = self::thorough($instance['box'], $instance['items'], 2, $support);
            self::assertValid($packedBox, $support);
        }
    }

    public function testItemsWithAZeroDimensionArePacked(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Sheet', 10, 10, 0, 1, Rotation::BestFit), 2);
        $items->insert(new TestItem('Cube', 10, 10, 10, 1, Rotation::BestFit));

        self::assertCount(3, self::thorough($box, $items)->items);
    }

    public function testGreedyOnlySearchStillPlacesAnItemThatLeavesUnusableSpace(): void
    {
        // a single large cube leaves gaps no item can use, so its score is negative
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Cube', 60, 60, 60, 1, Rotation::BestFit));

        self::assertCount(1, self::thorough($box, $items, 1)->items);
    }

    public function testPlacementCallbacksCountTowardsTheSearchBudget(): void
    {
        $counting = new class('Battery', 4, 15, 5, 1, Rotation::BestFit) extends TestItem implements ConstrainedPlacementItem {
            public static int $calls = 0;

            public function canBePacked(PackedBox $packedBox, int $proposedX, int $proposedY, int $proposedZ, int $width, int $length, int $depth): bool
            {
                ++self::$calls;
                $alreadyPacked = 0;
                foreach ($packedBox->items as $packedItem) {
                    $alreadyPacked += $packedItem->item instanceof self ? 1 : 0;
                }

                return $alreadyPacked < 3;
            }
        };
        $box = new TestBox('Box', 31, 22, 39, 0, 31, 22, 39, 10000);
        $items = new ItemList();
        for ($i = 0; $i < 40; ++$i) {
            $items->insert(clone $counting);
        }
        $items->insert(new TestItem('Book', 6, 19, 17, 1, Rotation::BestFit), 5);

        $packer = new VolumePacker($box, $items);
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setSearchBudget(2000);
        $packedBox = $packer->pack();

        self::assertLessThan(30000, $counting::$calls); // unbounded, this is over 400,000
        self::assertCount(8, $packedBox->items);
    }

    public function testFastNeverReusesAnOrientationTheItemDoesNotAllow(): void
    {
        // the second item has the same dimensions as the first, which is stood on its edge, but must be kept flat
        $box = new TestBox('Box', 35, 11, 27, 11, 35, 11, 27, 369);
        $items = ItemList::fromArray([new TestItem('Free', 18, 23, 2, 46, Rotation::BestFit), new TestItem('Flat', 23, 18, 2, 52, Rotation::KeepFlat)]);

        $packedBox = (new VolumePacker($box, $items))->pack();

        self::assertSame([], PackingValidator::problems($packedBox));
    }

    public function testEmptyItemListGivesEmptyBox(): void
    {
        $box = new TestBox('Box', 10, 10, 10, 0, 10, 10, 10, 100);
        $packer = new VolumePacker($box, new ItemList());
        $packer->setStrategy(PackingStrategy::Thorough);

        self::assertCount(0, $packer->pack()->items);
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int}>
     */
    private static function describe(PackedBox $packedBox): array
    {
        $out = [];
        foreach (iterator_to_array($packedBox->items, false) as $item) {
            $out[] = [$item->item->getDescription(), $item->x, $item->y, $item->z, $item->width, $item->length, $item->depth];
        }

        return $out;
    }
}
