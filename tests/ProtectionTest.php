<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\ProtectedTestItem;
use DVDoug\BoxPacker\Test\ProtectiveTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use DVDoug\BoxPacker\Test\TestSoftPack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function array_map;
use function array_sum;

class ProtectionTest extends TestCase
{
    public function testLevels(): void
    {
        self::assertTrue(Protection::Rigid->covers(Protection::Padded));
        self::assertTrue(Protection::Padded->covers(Protection::Padded));
        self::assertFalse(Protection::None->covers(Protection::Padded));

        self::assertSame(Protection::Rigid, Protection::offeredBy(new TestBox('Box', 1, 1, 1, 0, 1, 1, 1, 1)));
        self::assertSame(Protection::Padded, Protection::offeredBy(new ProtectiveTestBox('Bubble mailer', 1, 1, 1, 0, 1, 1, 1, 1, Protection::Padded)));
        self::assertSame(Protection::None, Protection::requiredBy(new TestItem('Item', 1, 1, 1, 1, Rotation::BestFit)));
        self::assertSame(Protection::Rigid, Protection::requiredBy(new ProtectedTestItem('Item', 1, 1, 1, 1, Rotation::BestFit, Protection::Rigid)));
    }

    #[DataProvider('strategies')]
    public function testItemsOnlyGoInContainersThatProtectThem(PackingStrategy $strategy): void
    {
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new TestBox('Box', 300, 400, 100, 120, 296, 396, 96, 10000));
        $packer->addSoftPack(new TestSoftPack('Poly mailer', 305, 394, 6, 45, 60, 1.0, 25, 5000, Protection::None));
        $packer->addSoftPack(new TestSoftPack('Bubble mailer', 305, 394, 6, 45, 60, 1.0, 45, 5000, Protection::Padded));

        // nothing special: the lightest pack
        $packer->addItem(new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat));
        $packedBox = $packer->pack()->top();
        self::assertSame('Poly mailer', $packedBox->box->getReference());

        // cushioned
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new TestBox('Box', 300, 400, 100, 120, 296, 396, 96, 10000));
        $packer->addSoftPack(new TestSoftPack('Poly mailer', 305, 394, 6, 45, 60, 1.0, 25, 5000, Protection::None));
        $packer->addSoftPack(new TestSoftPack('Bubble mailer', 305, 394, 6, 45, 60, 1.0, 45, 5000, Protection::Padded));
        $packer->addItem(new ProtectedTestItem('Paperback', 130, 200, 20, 250, Rotation::KeepFlat, Protection::Padded));
        $packedBox = $packer->pack()->top();
        self::assertSame('Bubble mailer', $packedBox->box->getReference());

        // rigid
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new TestBox('Box', 300, 400, 100, 120, 296, 396, 96, 10000));
        $packer->addSoftPack(new TestSoftPack('Poly mailer', 305, 394, 6, 45, 60, 1.0, 25, 5000, Protection::None));
        $packer->addSoftPack(new TestSoftPack('Bubble mailer', 305, 394, 6, 45, 60, 1.0, 45, 5000, Protection::Padded));
        $packer->addItem(new ProtectedTestItem('Hardback', 160, 240, 30, 600, Rotation::KeepFlat, Protection::Rigid));
        $packedBox = $packer->pack()->top();
        self::assertSame('Box', $packedBox->box->getReference());
    }

    #[DataProvider('strategies')]
    public function testAMixedOrderIsSplitByProtection(PackingStrategy $strategy): void
    {
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->addBox(new ProtectiveTestBox('Bubble mailer as a box', 260, 330, 60, 45, 250, 320, 50, 5000, Protection::Padded));
        $packer->addBox(new TestBox('Box', 300, 400, 60, 120, 296, 396, 50, 10000));
        $packer->addItem(new ProtectedTestItem('Hardback', 160, 240, 30, 600, Rotation::KeepFlat, Protection::Rigid));
        $packer->addItem(new TestItem('Tee', 230, 300, 18, 180, Rotation::KeepFlat), 2);
        $packedBoxes = iterator_to_array($packer->pack(), false);

        foreach ($packedBoxes as $packedBox) {
            foreach ($packedBox->items as $packedItem) {
                if ($packedItem->item instanceof ProtectedTestItem) {
                    self::assertSame('Box', $packedBox->box->getReference());
                }
            }
        }
        self::assertSame(3, array_sum(array_map(static fn (PackedBox $packedBox) => $packedBox->items->count(), $packedBoxes)));
    }

    public function testAnItemNoContainerCanProtectIsUnpackable(): void
    {
        $packer = new Packer();
        $packer->throwOnUnpackableItem(false);
        $packer->addSoftPack(new TestSoftPack('Poly mailer', 305, 394, 6, 45, 60, 1.0, 25, 5000, Protection::None));
        $packer->addItem(new ProtectedTestItem('Hardback', 160, 240, 30, 600, Rotation::KeepFlat, Protection::Rigid));

        self::assertSame(0, $packer->pack()->count());
        self::assertSame(1, $packer->getUnpackedItems()->count());
    }

    /**
     * @return array<string, array{PackingStrategy}>
     */
    public static function strategies(): array
    {
        return ['fast' => [PackingStrategy::Fast], 'thorough' => [PackingStrategy::Thorough]];
    }
}
