<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\RightSizeTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use DVDoug\BoxPacker\Test\TestSoftPack;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function ceil;

class CostCalculatorTest extends TestCase
{
    public function testDimensionalWeight(): void
    {
        $box = new TestBox('Box', 300, 400, 200, 100, 290, 390, 190, 10000);
        $items = new PackedItemList();
        $items->insert(new PackedItem(new TestItem('Light', 100, 100, 100, 50, Rotation::BestFit), 0, 0, 0, 100, 100, 100));
        $packedBox = new PackedBox($box, $items);

        // 300 x 400 x 200 / 5000 = 4800g, more than the 150g actual
        $calculator = new DimensionalWeightCostCalculator(5000);
        self::assertSame(4800, $calculator->getBillableWeight($packedBox));
        self::assertSame(4800.0, $calculator->getCost($packedBox));

        // each dimension rounded up to a whole 25mm, plus 10mm bulge first: 325 x 425 x 225
        $calculator = new DimensionalWeightCostCalculator(5000, 25, 10);
        self::assertSame((int) ceil(325 * 425 * 225 / 5000), $calculator->getBillableWeight($packedBox));

        // actual weight when that is more
        $heavy = new PackedItemList();
        $heavy->insert(new PackedItem(new TestItem('Heavy', 100, 100, 100, 9000, Rotation::BestFit), 0, 0, 0, 100, 100, 100));
        self::assertSame(9100, (new DimensionalWeightCostCalculator(5000))->getBillableWeight(new PackedBox($box, $heavy)));

        // a rate turns weight into money
        $calculator = new DimensionalWeightCostCalculator(5000, rate: static fn (int $grams, PackedBox $packedBox): float => 3.0 + $grams / 1000);
        self::assertSame(7.8, $calculator->getCost($packedBox));
    }

    public function testFormats(): void
    {
        $largeLetter = new ParcelFormat('Large letter', 250, 353, 25, 750, 2.0);
        $smallParcel = new ParcelFormat('Small parcel', 350, 450, 160, 2000, 5.0);
        $calculator = new FormatCostCalculator([$largeLetter, $smallParcel], 50.0);

        $thin = new PackedBox(new TestBox('Thin', 353, 250, 20, 10, 350, 245, 18, 1000), new PackedItemList());
        self::assertSame($largeLetter, $calculator->getFormat($thin));
        self::assertSame(2.0, $calculator->getCost($thin));

        $thick = new PackedBox(new TestBox('Thick', 250, 353, 40, 10, 245, 350, 38, 1000), new PackedItemList());
        self::assertSame($smallParcel, $calculator->getFormat($thick));

        $huge = new PackedBox(new TestBox('Huge', 1000, 1000, 1000, 10, 990, 990, 990, 1000), new PackedItemList());
        self::assertNull($calculator->getFormat($huge));
        self::assertSame(50.0, $calculator->getCost($huge));

        // too heavy for the letter
        $heavyItems = new PackedItemList();
        $heavyItems->insert(new PackedItem(new TestItem('Lead', 10, 10, 10, 1000, Rotation::BestFit), 0, 0, 0, 10, 10, 10));
        self::assertSame($smallParcel, $calculator->getFormat(new PackedBox(new TestBox('Thin', 353, 250, 20, 10, 350, 245, 18, 2000), $heavyItems)));
    }

    public function testRightSizeBoxIsMeasuredAsMade(): void
    {
        $box = new RightSizeTestBox('Made to measure', 600, 400, 400, 200, 20000, 5);
        $items = new PackedItemList();
        $items->insert(new PackedItem(new TestItem('Item', 100, 150, 50, 500, Rotation::BestFit), 0, 0, 0, 100, 150, 50));
        $items->insert(new PackedItem(new TestItem('Item', 100, 150, 50, 500, Rotation::BestFit), 100, 0, 0, 100, 150, 50));
        $packedBox = new PackedBox($box, $items);

        self::assertSame([210, 160, 60], $packedBox->getOuterDimensions());
        self::assertSame(2016, (new DimensionalWeightCostCalculator(1000))->getBillableWeight($packedBox)); // 210 * 160 * 60 / 1000
    }

    public function testThoroughPacksARightSizeBoxSmall(): void
    {
        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setCostCalculator(new DimensionalWeightCostCalculator(5000));
        $packer->addBox(new RightSizeTestBox('Made to measure', 600, 600, 600, 200, 20000, 5));
        $packer->addItem(new TestItem('Book', 150, 200, 30, 500, Rotation::KeepFlat), 4);
        $packedBoxes = iterator_to_array($packer->pack(), false);

        self::assertCount(1, $packedBoxes);
        [$width, $length, $depth] = $packedBoxes[0]->getOuterDimensions();
        self::assertSame(150 * 200 * 120 * 1.0, ($width - 10) * ($length - 10) * ($depth - 10) * 1.0); // 4 books in a single stack or a 2x2, no more
    }

    public function testSoftPackIsChargedAsFilled(): void
    {
        $packer = new Packer();
        $packer->addSoftPack(new TestSoftPack('Mailer', 305, 394, 6, 45, 60, 1.0, 25, 5000));
        $packer->addItem(new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat), 2);
        $packedBox = $packer->pack()->top();

        self::assertSame([269, 358, 36], $packedBox->getOuterDimensions());
        self::assertSame((int) ceil(269 * 358 * 36 / 5000), (new DimensionalWeightCostCalculator(5000))->getBillableWeight($packedBox));
    }
}
