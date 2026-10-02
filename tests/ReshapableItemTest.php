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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_decode;
use function json_encode;

class ReshapableItemTest extends TestCase
{
    private static function tee(): ReshapableTestItem
    {
        return new ReshapableTestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat, [
            new ItemState('fold in half', 230, 150, 36),
            new ItemState('roll', 70, 230, 70, Rotation::BestFit),
        ]);
    }

    public function testStateMustHavePositiveDimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ItemState('flat', 10, 10, 0);
    }

    public function testStateRotationDefaultsToTheItems(): void
    {
        $tee = self::tee();
        self::assertSame(Rotation::KeepFlat, $tee->getStates()[0]->getAllowedRotation($tee));
        self::assertSame(Rotation::BestFit, $tee->getStates()[1]->getAllowedRotation($tee));
    }

    public function testOrientationsIncludeEveryState(): void
    {
        $box = new TestBox('Box', 1000, 1000, 1000, 0, 1000, 1000, 1000, 10000);
        $factory = new OrientatedItemFactory($box);
        $orientations = $factory->getPossibleOrientations(self::tee(), null, 1000, 1000, 1000, 0, 0, 0, new PackedItemList());

        $seen = [];
        foreach ($orientations as $orientation) {
            $seen[] = ($orientation->state?->name ?? 'own') . ':' . $orientation->width . 'x' . $orientation->length . 'x' . $orientation->depth;
        }
        self::assertSame([
            'own:230x300x18', 'own:300x230x18',
            'fold in half:230x150x36', 'fold in half:150x230x36',
            'roll:70x230x70', 'roll:230x70x70', 'roll:70x70x230',
        ], $seen);

        $factory->setUseStates(false);
        self::assertCount(2, $factory->getPossibleOrientations(self::tee(), null, 1000, 1000, 1000, 0, 0, 0, new PackedItemList()));
    }

    #[DataProvider('strategies')]
    public function testOwnShapeIsUsedWhereItFits(PackingStrategy $strategy): void
    {
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new TestBox('Box', 300, 400, 100, 120, 296, 396, 96, 10000));
        $packer->addItem(self::tee(), 3);
        $packedBox = $packer->pack()->top();

        self::assertSame(3, $packedBox->items->count());
        foreach ($packedBox->items as $packedItem) {
            self::assertNull($packedItem->state);
            self::assertSame(18, $packedItem->depth);
        }
    }

    #[DataProvider('strategies')]
    public function testAStateIsUsedWhereTheOwnShapeDoesNotFit(PackingStrategy $strategy): void
    {
        // too short for a flat tee, deep enough for a folded one
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new TestBox('Box', 250, 200, 50, 50, 240, 190, 40, 10000));
        $packer->addItem(self::tee());
        $packedBox = $packer->pack()->top();

        $packedItem = iterator_to_array($packedBox->items, false)[0];
        self::assertSame('fold in half', $packedItem->state?->name);
        self::assertSame(36, $packedItem->depth);
        self::assertSame(230 * 150, $packedItem->width * $packedItem->length);

        // only a rolled tee fits a tube
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new TestBox('Tube', 80, 80, 250, 50, 75, 75, 240, 10000));
        $packer->addItem(self::tee());
        $packedItem = iterator_to_array($packer->pack()->top()->items, false)[0];
        self::assertSame('roll', $packedItem->state?->name);
        self::assertSame(230, $packedItem->depth); // stood on end, which the state's BestFit allows
    }

    #[DataProvider('strategies')]
    public function testStatesAreUsedToPackMoreIntoABox(PackingStrategy $strategy): void
    {
        // 240 wide, 190 long, 150 deep: no flat tee fits (300 long), four folded ones stack to 144 (or four rolled)
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new TestBox('Box', 250, 200, 160, 50, 240, 190, 150, 10000));
        $packer->addItem(self::tee(), 4);
        $packedBoxes = iterator_to_array($packer->pack(), false);

        self::assertCount(1, $packedBoxes);
        self::assertSame(4, $packedBoxes[0]->items->count());
        foreach ($packedBoxes[0]->items as $packedItem) {
            self::assertNotNull($packedItem->state);
        }
    }

    public function testOwnShapeIsPreferredEvenWhenAStateWouldPackTheSameNumber(): void
    {
        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->addBox(new TestBox('Box', 300, 400, 100, 120, 296, 396, 96, 10000));
        $packer->addItem(self::tee(), 2);
        $packer->addItem(new TestItem('Cube', 100, 100, 50, 100, Rotation::BestFit));
        $packedBox = $packer->pack()->top();

        self::assertSame(3, $packedBox->items->count());
        foreach ($packedBox->items as $packedItem) {
            self::assertNull($packedItem->state);
        }
    }

    public function testStatesWorkWithBestSubsetAndVoidFilling(): void
    {
        $box = new TestBox('Box', 250, 200, 160, 50, 240, 190, 150, 10000);
        $items = new ItemList();
        $items->insert(self::tee(), 6);

        $volumePacker = new VolumePacker($box, $items);
        $packedBox = $volumePacker->packBestSubset();
        self::assertSame(4, $packedBox->items->count());
        foreach ($packedBox->items as $packedItem) {
            self::assertNotNull($packedItem->state);
        }
    }

    public function testPackedStateIsReportedInJson(): void
    {
        $packer = new Packer();
        $packer->addBox(new TestBox('Box', 250, 200, 50, 50, 240, 190, 40, 10000));
        $packer->addItem(self::tee());
        $packedBox = $packer->pack()->top();

        $json = json_decode(json_encode($packedBox), true);
        self::assertSame('fold in half', $json['items'][0]['state']);
        self::assertSame(36, $json['items'][0]['depth']);
        self::assertSame(18, $json['items'][0]['item']['depth']); // the item itself is unchanged

        $json = json_decode(json_encode(new ItemState('roll', 70, 230, 70, Rotation::BestFit)), true);
        self::assertSame(['name' => 'roll', 'width' => 70, 'length' => 230, 'depth' => 70, 'allowedRotation' => 6], $json);
    }

    public function testIdenticalItemsWithDifferentStatesAreNotConfused(): void
    {
        $foldable = new ReshapableTestItem('Foldable', 230, 300, 18, 180, Rotation::KeepFlat, [new ItemState('fold', 230, 150, 36)]);
        $rollable = new ReshapableTestItem('Rollable', 230, 300, 18, 180, Rotation::KeepFlat, [new ItemState('roll', 70, 230, 70, Rotation::BestFit)]);

        foreach ([PackingStrategy::Fast, PackingStrategy::Thorough] as $strategy) {
            $packer = new Packer();
            $packer->setStrategy($strategy);
            $packer->addBox(new TestBox('Box', 250, 250, 90, 50, 240, 240, 80, 10000)); // folded (230 x 150 x 36) beside rolled (230 x 70 x 70)
            $packer->addItem($foldable);
            $packer->addItem($rollable);
            $packedBox = $packer->pack()->top();

            self::assertSame(2, $packedBox->items->count());
            foreach ($packedBox->items as $packedItem) {
                self::assertNotNull($packedItem->state);
                self::assertContains($packedItem->state, $packedItem->item->getStates());
            }
        }
    }

    /**
     * @return array<string, array{PackingStrategy}>
     */
    public static function strategies(): array
    {
        return ['fast' => [PackingStrategy::Fast], 'thorough' => [PackingStrategy::Thorough]];
    }
}
