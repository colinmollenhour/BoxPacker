<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Benchmark\PackingValidator;
use DVDoug\BoxPacker\Test\ConstrainedPlacementNoStackingTestItem;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function iterator_to_array;
use function json_decode;
use function rawurldecode;
use function substr;
use function intdiv;
use function json_encode;
use function max;
use function min;
use function strpos;

class AngledPlacementTest extends TestCase
{
    /**
     * @return array<string, array{0: PackingStrategy}>
     */
    public static function strategies(): array
    {
        return ['fast' => [PackingStrategy::Fast], 'thorough' => [PackingStrategy::Thorough]];
    }

    #[DataProvider('strategies')]
    public function testAnItemTooLongForTheBoxIsTurnedJustEnoughToFit(PackingStrategy $strategy): void
    {
        $box = new TestBox('Box', 100, 100, 30, 0, 100, 100, 30, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 110, 20, 10, 1, Rotation::KeepFlat));

        $packedBox = self::pack($box, $items, $strategy);

        self::assertCount(1, $packedBox->items);
        $rod = iterator_to_array($packedBox->items)[0];
        self::assertTrue($rod->isAngled());
        self::assertEqualsWithDelta(36.87, $rod->angle, 0.01); // spans exactly 100: 110·cos + 20·sin
        self::assertSame(100, $rod->boundingWidth);
        self::assertSame(82, $rod->boundingLength);
        self::assertSame([110, 20, 10], [$rod->width, $rod->length, $rod->depth]);
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    #[DataProvider('strategies')]
    public function testBestSubsetAlsoAnglesItems(PackingStrategy $strategy): void
    {
        $box = new TestBox('Box', 100, 100, 30, 0, 100, 100, 30, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 110, 20, 10, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Cube', 10, 10, 10, 1, Rotation::KeepFlat), 5);

        $packer = new VolumePacker($box, $items);
        $packer->setStrategy($strategy);
        $packer->setAllowAngledPlacement(true);
        $packedBox = $packer->packBestSubset();

        self::assertCount(6, $packedBox->items);
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    #[DataProvider('strategies')]
    public function testOffByDefault(PackingStrategy $strategy): void
    {
        $box = new TestBox('Box', 100, 100, 30, 0, 100, 100, 30, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 110, 20, 10, 1, Rotation::KeepFlat));

        $packer = new VolumePacker($box, $items);
        $packer->setStrategy($strategy);

        self::assertCount(0, $packer->pack()->items);
    }

    #[DataProvider('strategies')]
    public function testItemsThatFitSquareAreNeverAngled(PackingStrategy $strategy): void
    {
        $box = new TestBox('Box', 100, 100, 30, 0, 100, 100, 30, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 100, 20, 10, 1, Rotation::KeepFlat), 10);
        $items->insert(new TestItem('Cube', 10, 10, 10, 1, Rotation::KeepFlat), 20);

        $packedBox = self::pack($box, $items, $strategy);

        self::assertCount(30, $packedBox->items);
        foreach ($packedBox->items as $packedItem) {
            self::assertFalse($packedItem->isAngled());
        }
    }

    public function testItemsThatMustNotRotateOrHaveCallbacksAreNotAngled(): void
    {
        $box = new TestBox('Box', 100, 100, 30, 0, 100, 100, 30, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rigid', 110, 20, 10, 1, Rotation::Never));
        $items->insert(new ConstrainedPlacementNoStackingTestItem('Fussy', 110, 20, 10, 1, Rotation::KeepFlat));

        self::assertCount(0, self::pack($box, $items, PackingStrategy::Thorough)->items);
    }

    #[DataProvider('strategies')]
    public function testParallelItemsNestAtTheSameAngle(PackingStrategy $strategy): void
    {
        // turned 30.3°, one item spans 100 × 65; each further one adds only 12 (10 / cos 30.3°), so 3 fit in 100
        $box = new TestBox('Box', 100, 100, 10, 0, 100, 100, 10, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 110, 10, 10, 1, Rotation::KeepFlat), 3);

        $packedBox = self::pack($box, $items, $strategy);

        self::assertCount(3, $packedBox->items);
        $angles = [];
        foreach ($packedBox->items as $packedItem) {
            $angles[] = $packedItem->angle;
        }
        self::assertEqualsWithDelta($angles[0], $angles[1], 0.000001);
        self::assertEqualsWithDelta($angles[0], $angles[2], 0.000001);
        self::assertSame(89, $packedBox->getUsedLength());
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    #[DataProvider('strategies')]
    public function testTheEmptyCornersBesideAnAngledItemAreFilled(PackingStrategy $strategy): void
    {
        // the angled rod takes the whole floor's bounding box; the cubes can only go in the triangles beside it
        $box = new TestBox('Box', 100, 82, 10, 0, 100, 82, 10, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 110, 20, 10, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Cube', 15, 15, 10, 1, Rotation::KeepFlat), 12);

        $packedBox = self::pack($box, $items, $strategy);

        self::assertCount(13, $packedBox->items);
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    public function testItemsOnTopOfAngledItemsAreSupported(): void
    {
        $box = new TestBox('Box', 100, 82, 40, 0, 100, 82, 40, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 110, 20, 10, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Block', 30, 30, 10, 1, Rotation::KeepFlat), 8);

        $packer = new VolumePacker($box, $items);
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setAllowAngledPlacement(true);
        $packer->setMinimumSupport(0.75);
        $packedBox = $packer->pack();

        self::assertSame([], PackingValidator::problems($packedBox));
        self::assertGreaterThanOrEqual(0.75, PackingValidator::minimumSupport($packedBox));
        self::assertGreaterThanOrEqual(0.75, SupportCalculator::minimumSupport($packedBox->items));
    }

    public function testBothDirections(): void
    {
        // too long for the width but not the length: laid along the length instead
        $box = new TestBox('Box', 60, 100, 10, 0, 60, 100, 10, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 105, 5, 10, 1, Rotation::KeepFlat), 2);

        $packedBox = self::pack($box, $items, PackingStrategy::Thorough);

        self::assertCount(2, $packedBox->items);
        foreach ($packedBox->items as $packedItem) {
            self::assertTrue($packedItem->isAngled());
            self::assertLessThanOrEqual(60, $packedItem->x + $packedItem->boundingWidth);
            self::assertLessThanOrEqual(100, $packedItem->y + $packedItem->boundingLength);
        }
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    public function testABestFitItemIsTurnedOverAndAngled(): void
    {
        // BestFit: too tall standing on its 20 × 110 face, so laid on its 110 × 30 face and angled
        $box = new TestBox('Box', 100, 100, 20, 0, 100, 100, 20, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Plank', 20, 110, 30, 1, Rotation::BestFit));

        $packedBox = self::pack($box, $items, PackingStrategy::Thorough);

        self::assertCount(1, $packedBox->items);
        $plank = iterator_to_array($packedBox->items)[0];
        self::assertTrue($plank->isAngled());
        self::assertSame(20, $plank->depth);
        self::assertSame([], PackingValidator::problems($packedBox));
    }

    #[DataProvider('strategies')]
    public function testASmallerBoxCanBeUsed(PackingStrategy $strategy): void
    {
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->setAllowAngledPlacement(true);
        $packer->addBox(new TestBox('Small', 100, 100, 30, 10, 100, 100, 30, 10000));
        $packer->addBox(new TestBox('Big', 120, 120, 60, 50, 120, 120, 60, 10000));
        $packer->addItem(new TestItem('Umbrella', 110, 12, 12, 400, Rotation::KeepFlat), 2);
        $packer->addItem(new TestItem('Book', 20, 15, 4, 300, Rotation::KeepFlat), 10);

        $packedBoxes = $packer->pack();

        self::assertCount(1, $packedBoxes);
        self::assertSame('Small', $packedBoxes->top()->box->getReference());
        self::assertCount(12, $packedBoxes->top()->items);
        self::assertSame([], PackingValidator::problems($packedBoxes->top()));

        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new TestBox('Small', 100, 100, 30, 10, 100, 100, 30, 10000));
        $packer->addBox(new TestBox('Big', 120, 120, 60, 50, 120, 120, 60, 10000));
        $packer->addItem(new TestItem('Umbrella', 110, 12, 12, 400, Rotation::KeepFlat), 2);
        $packer->addItem(new TestItem('Book', 20, 15, 4, 300, Rotation::KeepFlat), 10);

        self::assertSame('Big', $packer->pack()->top()->box->getReference()); // without angles
    }

    public function testOutput(): void
    {
        $box = new TestBox('Box', 100, 100, 30, 0, 100, 100, 30, 1000);
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 110, 20, 10, 1, Rotation::KeepFlat));
        $packedBox = self::pack($box, $items, PackingStrategy::Thorough);

        $json = json_decode(json_encode($packedBox), true);
        self::assertEqualsWithDelta(36.87, $json['items'][0]['angle'], 0.01);
        self::assertSame(100, $json['items'][0]['boundingWidth']);
        self::assertSame(82, $json['items'][0]['boundingLength']);

        $url = $packedBox->generateVisualisationURL();
        $data = json_decode(rawurldecode(substr($url, strpos($url, '#packing=') + 9)), true);
        self::assertCount(8, $data['boxes'][0][4][0]); // the angle is an optional 8th element
        self::assertEqualsWithDelta(36.87, $data['boxes'][0][4][0][7], 0.001);
    }

    /**
     * Random boxes and items, some too long to fit square: every packing must be physically valid.
     */
    public function testRandomPackingsAreValid(): void
    {
        $random = new Randomizer(new Mt19937(42));
        $angled = 0;
        for ($run = 0; $run < 60; ++$run) {
            $boxWidth = $random->getInt(40, 150);
            $boxLength = $random->getInt(40, 150);
            $boxDepth = $random->getInt(10, 80);
            $box = new TestBox('Box', $boxWidth, $boxLength, $boxDepth, 0, $boxWidth, $boxLength, $boxDepth, 100000);
            $items = new ItemList();
            for ($type = $random->getInt(1, 4); $type > 0; --$type) {
                $long = $random->getInt(0, 1) === 1 ? (int) (max($boxWidth, $boxLength) * (1 + $random->getInt(1, 40) / 100)) : $random->getInt(5, max($boxWidth, $boxLength));
                $item = new TestItem('Type ' . $type, $long, $random->getInt(2, max(3, intdiv(min($boxWidth, $boxLength), 3))), $random->getInt(2, max(3, intdiv($boxDepth, 2))), $random->getInt(1, 10), $random->getInt(0, 1) === 1 ? Rotation::KeepFlat : Rotation::BestFit);
                $items->insert($item, $random->getInt(1, 10));
            }
            $support = [0.0, 0.5, 1.0][$run % 3];

            $packer = new VolumePacker($box, $items);
            $packer->setStrategy(PackingStrategy::Thorough);
            $packer->setAllowAngledPlacement(true);
            $packer->setMinimumSupport($support);
            $packer->setSearchBudget(1000);
            $packedBox = $packer->pack();

            self::assertSame([], PackingValidator::problems($packedBox), "run {$run}");
            self::assertGreaterThanOrEqual($support - 0.000001, PackingValidator::minimumSupport($packedBox), "run {$run}");
            foreach ($packedBox->items as $packedItem) {
                $angled += $packedItem->isAngled() ? 1 : 0;
            }
        }
        self::assertGreaterThan(20, $angled);
    }

    private static function pack(Box $box, ItemList $items, PackingStrategy $strategy): PackedBox
    {
        $packer = new VolumePacker($box, $items);
        $packer->setStrategy($strategy);
        $packer->setAllowAngledPlacement(true);

        return $packer->pack();
    }
}
