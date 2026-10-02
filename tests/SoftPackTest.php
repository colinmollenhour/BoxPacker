<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\ReshapableTestItem;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use DVDoug\BoxPacker\Test\TestSoftPack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_decode;
use function json_encode;

class SoftPackTest extends TestCase
{
    /**
     * A 305 × 394 poly mailer losing 6mm at each side seam and 45mm to the flap, closing over up to 60mm.
     */
    private static function mailer(float $wrapFactor = 1.0, int $maxFill = 60): TestSoftPack
    {
        return new TestSoftPack('Mailer 305x394', 305, 394, 6, 45, $maxFill, $wrapFactor, 25, 5000);
    }

    public function testRoomInsideShrinksAsTheFillThickens(): void
    {
        $flat = new SoftPackAsBox(self::mailer(), 10);
        self::assertSame(283, $flat->getInnerWidth()); // 305 - 2 * 6 - 10
        self::assertSame(339, $flat->getInnerLength()); // 394 - 45 - 10
        self::assertSame(10, $flat->getInnerDepth());

        $thick = new SoftPackAsBox(self::mailer(), 50);
        self::assertSame(243, $thick->getInnerWidth());
        self::assertSame(299, $thick->getInnerLength());
        self::assertSame(50, $thick->getInnerDepth());

        // soft contents round off, so less of the sheet goes round them
        $soft = new SoftPackAsBox(self::mailer(0.5), 50);
        self::assertSame(268, $soft->getInnerWidth());
        self::assertSame(324, $soft->getInnerLength());

        // the pack's weights and reference are the mailer's
        self::assertSame('Mailer 305x394', $thick->getReference());
        self::assertSame(25, $thick->getEmptyWeight());
        self::assertSame(5000, $thick->getMaxWeight());
        self::assertSame(Protection::None, $thick->getProtection());
    }

    public function testFilledSizeIsTheFlatSizeLessWhatGoesRoundTheContents(): void
    {
        $pack = new SoftPackAsBox(self::mailer(), 40);
        self::assertSame([265, 354, 40], $pack->getFilledDimensions(40));
        self::assertSame(265, $pack->getOuterWidth());
        self::assertSame(354, $pack->getOuterLength());
        self::assertSame(40, $pack->getOuterDepth());
        self::assertSame([287, 376, 18], $pack->getFilledDimensions(18));
    }

    public function testCandidateThicknessesAreTheStackHeightsTheItemsCanReach(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat), 2);
        $items->insert(new TestItem('Book', 150, 200, 25, 400, Rotation::KeepFlat));

        self::assertSame([18, 25, 36, 43, 61], SoftPackAsBox::candidateThicknesses(self::mailer(maxFill: 100), $items));

        // capped by the pack
        self::assertSame([18, 25, 36], SoftPackAsBox::candidateThicknesses(self::mailer(maxFill: 40), $items));
    }

    public function testCandidateThicknessesIncludeEveryEdgeARotationOrStateCanStand(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('Cube-ish', 10, 20, 30, 1, Rotation::BestFit));
        self::assertSame([10, 20, 30], SoftPackAsBox::candidateThicknesses(self::mailer(), $items));

        $items = new ItemList();
        $items->insert(new ReshapableTestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat, [new ItemState('fold', 230, 150, 36)]));
        self::assertSame([18, 36], SoftPackAsBox::candidateThicknesses(self::mailer(), $items));
    }

    public function testManyCandidateThicknessesAreThinnedToThoseFewItemsReach(): void
    {
        $items = new ItemList();
        for ($i = 1; $i <= 40; ++$i) {
            $items->insert(new TestItem("Item {$i}", 50, 50, $i, 10, Rotation::KeepFlat));
        }
        $thicknesses = SoftPackAsBox::candidateThicknesses(self::mailer(maxFill: 500), $items);
        self::assertCount(24, $thicknesses);
        self::assertSame(1, $thicknesses[0]);
        self::assertSame(24, $thicknesses[23]); // every height up to 24 is reached by a single item
    }

    #[DataProvider('strategies')]
    public function testASoftPackIsUsedWhenItemsFitItAndABoxWhenTheyDoNot(PackingStrategy $strategy): void
    {
        $tee = new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat);
        $box = new TestBox('Box', 300, 400, 100, 120, 296, 396, 96, 10000);

        foreach ([1 => 18, 2 => 36] as $qty => $expectedThickness) {
            $packer = new Packer();
            $packer->setStrategy($strategy);
            $packer->addBox($box);
            $packer->addSoftPack(self::mailer());
            $packer->addItem($tee, $qty);
            $packedBoxes = iterator_to_array($packer->pack(), false);

            self::assertCount(1, $packedBoxes);
            self::assertInstanceOf(SoftPackAsBox::class, $packedBoxes[0]->box);
            self::assertSame($expectedThickness, $packedBoxes[0]->box->fillThickness);
            self::assertSame($qty, $packedBoxes[0]->items->count());
            self::assertSame([305 - $expectedThickness, 394 - $expectedThickness, $expectedThickness], $packedBoxes[0]->getOuterDimensions());
        }

        // three stacked are 54mm thick, and at that thickness the mailer is only 295mm long inside
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox($box);
        $packer->addSoftPack(self::mailer());
        $packer->addItem($tee, 3);
        $packedBoxes = iterator_to_array($packer->pack(), false);
        self::assertCount(1, $packedBoxes);
        self::assertSame($box, $packedBoxes[0]->box);
    }

    #[DataProvider('strategies')]
    public function testTheThicknessCapIsRespected(PackingStrategy $strategy): void
    {
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new TestBox('Box', 300, 400, 100, 120, 296, 396, 96, 10000));
        $packer->addSoftPack(self::mailer(maxFill: 25)); // large letter
        $packer->addItem(new TestItem('Book', 150, 200, 30, 400, Rotation::KeepFlat));
        $packedBoxes = iterator_to_array($packer->pack(), false);

        self::assertCount(1, $packedBoxes);
        self::assertInstanceOf(TestBox::class, $packedBoxes[0]->box);
    }

    public function testSoftPacksAreInUnlimitedSupplyAndTheBoxListIsLeftAsItWas(): void
    {
        $packer = new Packer();
        $packer->addSoftPack(self::mailer());
        $packer->addItem(new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat), 10);
        $packedBoxes = $packer->pack();
        self::assertSame(5, $packedBoxes->count());
        foreach ($packedBoxes as $packedBox) {
            self::assertInstanceOf(SoftPackAsBox::class, $packedBox->box);
        }

        // and again, with the same packer
        $packer->addItem(new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat), 2);
        self::assertSame(1, $packer->pack()->count());
    }

    public function testSoftPackWithNoItemsOrNoRoom(): void
    {
        $packer = new Packer();
        $packer->addSoftPack(self::mailer());
        self::assertSame(0, $packer->pack()->count());

        $packer = new Packer();
        $packer->throwOnUnpackableItem(false);
        $packer->addSoftPack(new TestSoftPack('Tiny', 50, 50, 5, 10, 60, 1.0, 5, 1000));
        $packer->addItem(new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat));
        self::assertSame(0, $packer->pack()->count());
        self::assertSame(1, $packer->getUnpackedItems()->count());
    }

    public function testACostCalculatorCanKeepAPackUnderAThicknessBand(): void
    {
        // Royal Mail style: a large letter (up to 25mm thick) is much cheaper than a small parcel
        $costCalculator = new FormatCostCalculator([
            new ParcelFormat('Large letter', 250, 353, 25, 750, 2.0),
            new ParcelFormat('Small parcel', 350, 450, 160, 2000, 5.0),
        ], 100.0);

        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setCostCalculator($costCalculator);
        $packer->addBox(new TestBox('Box', 300, 400, 100, 120, 296, 396, 96, 10000));
        $packer->addSoftPack(new TestSoftPack('Large letter mailer', 250, 353, 5, 30, 40, 1.0, 10, 750));
        $packer->addItem(new TestItem('Magazine', 200, 280, 12, 150, Rotation::KeepFlat), 4);
        $packedBoxes = iterator_to_array($packer->pack(), false);

        // two large letters (2 x 2.0) beat one small parcel (5.0) or a box
        self::assertCount(2, $packedBoxes);
        foreach ($packedBoxes as $packedBox) {
            self::assertInstanceOf(SoftPackAsBox::class, $packedBox->box);
            self::assertSame(24, $packedBox->getUsedDepth());
            self::assertSame('Large letter', $costCalculator->getFormat($packedBox)?->name);
        }
    }

    public function testJsonIncludesTheFill(): void
    {
        $packer = new Packer();
        $packer->addSoftPack(self::mailer());
        $packer->addItem(new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat));
        $packedBox = $packer->pack()->top();

        $json = json_decode(json_encode($packedBox), true);
        self::assertSame('Mailer 305x394', $json['box']['reference']);
        self::assertSame(305, $json['box']['flatWidth']);
        self::assertSame(394, $json['box']['flatLength']);
        self::assertSame(18, $json['box']['fillThickness']);
        self::assertSame(0, $json['box']['protection']);
        self::assertSame('poly', $json['box']['material']); // the user's own serialisation is kept
        self::assertSame(275, $json['box']['innerWidth']);
    }

    /**
     * @return array<string, array{PackingStrategy}>
     */
    public static function strategies(): array
    {
        return ['fast' => [PackingStrategy::Fast], 'thorough' => [PackingStrategy::Thorough]];
    }
}
