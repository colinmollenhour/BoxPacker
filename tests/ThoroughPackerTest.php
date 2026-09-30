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
use DVDoug\BoxPacker\Exception\NoBoxesAvailableException;
use DVDoug\BoxPacker\Test\LimitedSupplyTestBox;
use DVDoug\BoxPacker\Test\LinkedTestItem;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_count_values;
use function array_slice;
use function iterator_to_array;
use function sort;

#[CoversClass(Packer::class)]
#[CoversClass(ThoroughPacker::class)]
#[CoversClass(VolumePackerFactory::class)]
#[CoversClass(DefaultPackedBoxCostCalculator::class)]
class ThoroughPackerTest extends TestCase
{
    private static function thoroughPacker(): Packer
    {
        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setMaxBeamWidth(4);

        return $packer;
    }

    /**
     * @return list<string> box references, sorted
     */
    private static function boxReferences(PackedBoxList $packedBoxes): array
    {
        $references = [];
        foreach ($packedBoxes as $packedBox) {
            $references[] = $packedBox->box->getReference();
        }
        sort($references);

        return $references;
    }

    private static function assertValid(PackedBoxList $packedBoxes, float $support = 1.0): void
    {
        foreach ($packedBoxes as $packedBox) {
            self::assertSame([], PackingValidator::problems($packedBox));
            self::assertGreaterThanOrEqual($support, PackingValidator::minimumSupport($packedBox));
        }
    }

    private static function itemCount(PackedBoxList $packedBoxes): int
    {
        $count = 0;
        foreach ($packedBoxes as $packedBox) {
            $count += $packedBox->items->count();
        }

        return $count;
    }

    public function testSmallestSingleBoxIsChosenWhenEverythingFits(): void
    {
        $packer = self::thoroughPacker();
        $packer->addBox(new TestBox('Large', 40, 40, 40, 0, 40, 40, 40, 100000));
        $packer->addBox(new TestBox('Small', 10, 10, 10, 0, 10, 10, 10, 100000));
        $packer->addBox(new TestBox('Medium', 20, 20, 20, 0, 20, 20, 20, 100000));
        $packer->addItem(new TestItem('Cube', 10, 10, 10, 100, Rotation::BestFit), 6);

        $packedBoxes = $packer->pack();

        self::assertSame(['Medium'], self::boxReferences($packedBoxes));
        self::assertSame(6, self::itemCount($packedBoxes));
        self::assertValid($packedBoxes);
    }

    public function testImprovementRemovesABoxTheGreedyConstructionUsed(): void
    {
        // Filling each box with as much volume as possible puts 4 light cubes and 1 heavy one into the first box,
        // leaving 3 heavy cubes that need 2 more boxes. Balancing the weight (2 heavy + 2 light per box) needs just 2.
        $logger = new class extends AbstractLogger {
            /**
             * @var list<string>
             */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $packer = self::thoroughPacker();
        $packer->setLogger($logger);
        $packer->addBox(new TestBox('Box', 10, 10, 10, 0, 10, 10, 10, 100));
        $packer->addItem(new TestItem('Heavy', 5, 5, 5, 45, Rotation::BestFit), 4);
        $packer->addItem(new TestItem('Light', 5, 5, 5, 5, Rotation::BestFit), 4);

        $packedBoxes = $packer->pack();

        self::assertCount(2, $packedBoxes);
        self::assertSame(8, self::itemCount($packedBoxes));
        self::assertContains('Emptied a Box into the other boxes', $logger->messages);
        self::assertValid($packedBoxes);
    }

    public function testLimitedSupplyBoxesAreRespected(): void
    {
        // each cube needs a box of its own (weight), and the small box is preferred, but there is only one
        $packer = self::thoroughPacker();
        $packer->addBox(new LimitedSupplyTestBox('Small', 10, 10, 10, 0, 10, 10, 10, 10, 1));
        $packer->addBox(new TestBox('Large', 10, 10, 20, 0, 10, 10, 20, 10));
        $packer->addItem(new TestItem('Cube', 10, 10, 10, 8, Rotation::BestFit), 3);

        $packedBoxes = $packer->pack();

        self::assertSame(['Large', 'Large', 'Small'], self::boxReferences($packedBoxes));
        self::assertValid($packedBoxes);
    }

    public function testRunningOutOfLimitedSupplyBoxesLeavesItemsUnpacked(): void
    {
        $packer = self::thoroughPacker();
        $packer->throwOnUnpackableItem(false);
        $packer->addBox(new LimitedSupplyTestBox('Small', 10, 10, 10, 0, 10, 10, 10, 10, 2));
        $packer->addItem(new TestItem('Cube', 10, 10, 10, 8, Rotation::BestFit), 3);

        $packedBoxes = $packer->pack();

        self::assertSame(['Small', 'Small'], self::boxReferences($packedBoxes));
        self::assertCount(1, $packer->getUnpackedItems());
    }

    public function testLinkedItemGroupsAreKeptTogether(): void
    {
        $packer = self::thoroughPacker();
        $packer->addBox(new TestBox('Box', 10, 10, 10, 0, 10, 10, 10, 100));
        for ($group = 1; $group <= 4; ++$group) {
            $packer->addItem(new LinkedTestItem("Heavy {$group}", 5, 5, 5, 45, Rotation::BestFit, "group {$group}"));
            $packer->addItem(new LinkedTestItem("Light {$group}", 5, 5, 5, 5, Rotation::BestFit, "group {$group}"));
        }

        $packedBoxes = $packer->pack();

        self::assertSame(8, self::itemCount($packedBoxes));
        self::assertCount(2, $packedBoxes);
        $boxOfGroup = [];
        foreach (iterator_to_array($packedBoxes, false) as $index => $packedBox) {
            foreach ($packedBox->items as $packedItem) {
                $group = $packedItem->item->getLinkedItemGroup();
                self::assertSame($boxOfGroup[$group] ??= $index, $index, "{$group} is split across boxes");
            }
        }
        self::assertValid($packedBoxes);
    }

    public function testCustomCostCalculatorChangesTheChosenBox(): void
    {
        $prices = new class implements PackedBoxCostCalculator {
            public function getCost(PackedBox $packedBox): float
            {
                return $packedBox->box->getReference() === 'Small but pricey' ? 5.0 : 1.0;
            }
        };
        $boxes = [
            new TestBox('Small but pricey', 10, 10, 20, 0, 10, 10, 20, 1000),
            new TestBox('Large and cheap', 20, 20, 20, 0, 20, 20, 20, 1000),
        ];

        $default = self::thoroughPacker();
        $custom = self::thoroughPacker();
        $custom->setCostCalculator($prices);
        foreach ([$default, $custom] as $packer) {
            foreach ($boxes as $box) {
                $packer->addBox($box);
            }
            $packer->addItem(new TestItem('Cube', 10, 10, 10, 10, Rotation::BestFit), 2);
        }

        self::assertSame(['Small but pricey'], self::boxReferences($default->pack()));
        self::assertSame(['Large and cheap'], self::boxReferences($custom->pack()));
    }

    public function testCustomCostCalculatorPrefersTwoCheapBoxesToOneExpensiveOne(): void
    {
        $prices = new class implements PackedBoxCostCalculator {
            public function getCost(PackedBox $packedBox): float
            {
                return $packedBox->box->getReference() === 'Double' ? 5.0 : 1.0;
            }
        };
        $boxes = [
            new TestBox('Single', 10, 10, 10, 0, 10, 10, 10, 1000),
            new TestBox('Double', 10, 10, 20, 0, 10, 10, 20, 1000),
        ];

        $default = self::thoroughPacker();
        $custom = self::thoroughPacker();
        $custom->setCostCalculator($prices);
        foreach ([$default, $custom] as $packer) {
            foreach ($boxes as $box) {
                $packer->addBox($box);
            }
            $packer->addItem(new TestItem('Cube', 10, 10, 10, 10, Rotation::BestFit), 2);
        }

        // fewest boxes first by default, cheapest first with a cost calculator
        self::assertSame(['Double'], self::boxReferences($default->pack()));
        self::assertSame(['Single', 'Single'], self::boxReferences($custom->pack()));
    }

    public function testUnpackableItemsAreLeftUnpackedWhenNotThrowing(): void
    {
        $packer = self::thoroughPacker();
        $packer->throwOnUnpackableItem(false);
        $packer->addBox(new TestBox('Box', 20, 20, 20, 0, 20, 20, 20, 1000));
        $packer->addItem(new TestItem('Too big', 30, 30, 30, 10, Rotation::BestFit));
        $packer->addItem(new TestItem('Cube', 10, 10, 10, 10, Rotation::BestFit), 12);

        $packedBoxes = $packer->pack();

        self::assertCount(2, $packedBoxes);
        self::assertSame(12, self::itemCount($packedBoxes));
        self::assertCount(1, $packer->getUnpackedItems());
        self::assertSame('Too big', $packer->getUnpackedItems()->top()->getDescription());
    }

    public function testUnpackableItemsThrowByDefault(): void
    {
        $packer = self::thoroughPacker();
        $packer->addBox(new TestBox('Box', 20, 20, 20, 0, 20, 20, 20, 1000));
        $packer->addItem(new TestItem('Too big', 30, 30, 30, 10, Rotation::BestFit));
        $packer->addItem(new TestItem('Cube', 10, 10, 10, 10, Rotation::BestFit), 12);

        $this->expectException(NoBoxesAvailableException::class);
        $packer->pack();
    }

    public function testFastStrategyIsTheDefaultAndIgnoresThoroughSettings(): void
    {
        $sample = array_slice(BookShopTest::getSamples(), 0, 20);
        foreach ($sample as $order) {
            $default = new Packer();
            $fast = new Packer();
            $fast->setStrategy(PackingStrategy::Fast);
            $fast->setMaxBeamWidth(1);
            $fast->setMinimumSupport(0.5);
            $fast->setSearchTimeLimit(0.001);
            $fast->setCostCalculator(new DefaultPackedBoxCostCalculator());
            foreach ([$default, $fast] as $packer) {
                foreach ($order['boxes'] as $box) {
                    $packer->addBox($box);
                }
                foreach ($order['items'] as $item) {
                    $packer->addItem(new TestItem($item['name'], $item['width'], $item['length'], $item['depth'], $item['weight'], Rotation::BestFit), $item['qty']);
                }
            }

            self::assertEquals(self::describe($default->pack()), self::describe($fast->pack()));
        }
    }

    public function testStrictItemOrderingUsesTheFastAlgorithm(): void
    {
        $fast = new Packer();
        $thorough = self::thoroughPacker();
        foreach ([$fast, $thorough] as $packer) {
            $packer->beStrictAboutItemOrdering(true);
            $packer->addBox(new TestBox('Box', 10, 10, 10, 0, 10, 10, 10, 100));
            $packer->addItem(new TestItem('Heavy', 5, 5, 5, 45, Rotation::BestFit), 4);
            $packer->addItem(new TestItem('Light', 5, 5, 5, 5, Rotation::BestFit), 4);
        }

        self::assertEquals(self::describe($fast->pack()), self::describe($thorough->pack()));
    }

    public function testNeverMoreBoxesThanFastAndDeterministic(): void
    {
        foreach (['2', '37'] as $id) {
            $instance = InstanceLoader::load('ivancic')[(int) $id - 1];
            $fast = Benchmark\Strategies::multi('legacy', $instance['boxes'], $instance['items'], []);
            $thoroughA = Benchmark\Strategies::multi('thorough', $instance['boxes'], $instance['items'], ['width' => '4', 'support' => '0']);
            $thoroughB = Benchmark\Strategies::multi('thorough', $instance['boxes'], $instance['items'], ['width' => '4', 'support' => '0']);

            self::assertLessThanOrEqual($fast->count(), $thoroughA->count());
            self::assertSame($instance['items']->count(), self::itemCount($thoroughA));
            self::assertEquals(self::describe($thoroughA), self::describe($thoroughB));
            self::assertValid($thoroughA, 0.0);
        }
    }

    public function testWeightBalancingDoesNotAddBoxes(): void
    {
        $withoutBalancing = self::thoroughPacker();
        $withoutBalancing->setMaxBoxesToBalanceWeight(0);
        $withBalancing = self::thoroughPacker();
        foreach ([$withoutBalancing, $withBalancing] as $packer) {
            $packer->addBox(new TestBox('Box', 10, 10, 10, 0, 10, 10, 10, 100));
            $packer->addItem(new TestItem('Heavy', 5, 5, 5, 40, Rotation::BestFit), 3);
            $packer->addItem(new TestItem('Light', 5, 5, 5, 5, Rotation::BestFit), 6);
        }

        $unbalanced = $withoutBalancing->pack();
        $balanced = $withBalancing->pack();

        self::assertCount($unbalanced->count(), $balanced);
        self::assertSame(9, self::itemCount($balanced));
        self::assertLessThanOrEqual($unbalanced->getWeightVariance(), $balanced->getWeightVariance());
        self::assertValid($balanced);
    }

    public function testTimeLimitStillPacksEverything(): void
    {
        $instance = InstanceLoader::load('ivancic')[2];
        $packer = self::thoroughPacker();
        $packer->setMaxBeamWidth(8);
        $packer->setSearchTimeLimit(0.05);
        $packer->setMaxBoxesToBalanceWeight(0);
        foreach ($instance['boxes'] as $box) {
            $packer->addBox($box);
        }
        $packer->setItems($instance['items']);

        $packedBoxes = $packer->pack();

        self::assertSame($instance['items']->count(), self::itemCount($packedBoxes));
        self::assertValid($packedBoxes);
    }

    /**
     * @return list<array{0: string, 1: array<string, int>}>
     */
    private static function describe(PackedBoxList $packedBoxes): array
    {
        $out = [];
        foreach ($packedBoxes as $packedBox) {
            $items = [];
            foreach ($packedBox->items as $packedItem) {
                $items[] = "{$packedItem->item->getDescription()} {$packedItem->x},{$packedItem->y},{$packedItem->z} {$packedItem->width}x{$packedItem->length}x{$packedItem->depth}";
            }
            $out[] = [$packedBox->box->getReference(), array_count_values($items)];
        }

        return $out;
    }
}
