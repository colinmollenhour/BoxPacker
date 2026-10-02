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
use DVDoug\BoxPacker\Exception\TimeoutException;
use DVDoug\BoxPacker\Test\LimitedSupplyTestBox;
use DVDoug\BoxPacker\Test\LinkedTestItem;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Stringable;

use function array_count_values;
use function array_slice;
use function array_unique;
use function count;
use function iterator_to_array;
use function sort;
use function spl_object_id;
use function usort;

use const INF;

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
        $packer->setMinimumSupport(1.0);

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

    /**
     * A logger that keeps the messages logged.
     */
    private static function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /**
             * @var list<string>
             */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
    }

    /**
     * A timeout checker that times out on the given call.
     */
    private static function timeoutOnCall(int $call): TimeoutChecker
    {
        return new class($call) implements TimeoutChecker {
            public int $calls = 0;

            public function __construct(private readonly float $timeout)
            {
            }

            public function start(?float $startTime = null): void
            {
            }

            public function throwOnTimeout(?float $currentTime = null, string $message = 'Exceeded the timeout'): void
            {
                if (++$this->calls >= $this->timeout) {
                    throw new TimeoutException($message, $this->calls, $this->timeout);
                }
            }
        };
    }

    /**
     * Total inner volume of the boxes.
     */
    private static function boxVolume(PackedBoxList $packedBoxes): int
    {
        $volume = 0;
        foreach ($packedBoxes as $packedBox) {
            $volume += $packedBox->getInnerVolume();
        }

        return $volume;
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

    public function testDoesNotThrowWhenRunningShortOfALimitedBoxCanBeAvoided(): void
    {
        // filling greedily uses up the one big box early; the fast packer's solution shows everything fits
        $packer = self::thoroughPacker();
        $packer->setMinimumSupport(0.5);
        $packer->addBox(new LimitedSupplyTestBox('A', 10, 10, 10, 0, 10, 10, 10, 1000, 1));
        $packer->addBox(new TestBox('B', 10, 10, 2, 0, 10, 10, 2, 1000));
        $packer->addItem(new TestItem('X', 10, 10, 9, 1, Rotation::KeepFlat));
        $packer->addItem(new TestItem('S', 10, 10, 2, 1, Rotation::KeepFlat), 5);

        $packedBoxes = $packer->pack();

        self::assertSame(6, self::itemCount($packedBoxes));
        self::assertCount(0, $packer->getUnpackedItems());
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

    public function testTheSearchAloneNeedsNoMoreBoxesThanFastAndIsDeterministic(): void
    {
        // orders of over 200 items, for which the fast packer is not run as well, so this measures the search itself
        foreach (['2' => 3, '18' => 5] as $id => $copies) {
            $instance = InstanceLoader::load('ivancic')[$id - 1];
            $items = new ItemList();
            for ($copy = 0; $copy < $copies; ++$copy) {
                foreach ($instance['items'] as $item) {
                    $items->insert($item);
                }
            }
            self::assertGreaterThan(200, $items->count());

            $fast = Benchmark\Strategies::multi('legacy', $instance['boxes'], $items, []);
            $thoroughA = Benchmark\Strategies::multi('thorough', $instance['boxes'], $items, ['width' => '4', 'support' => '0']);
            $thoroughB = Benchmark\Strategies::multi('thorough', $instance['boxes'], $items, ['width' => '4', 'support' => '0']);

            self::assertLessThanOrEqual($fast->count(), $thoroughA->count());
            self::assertSame($items->count(), self::itemCount($thoroughA));
            self::assertEquals(self::describe($thoroughA), self::describe($thoroughB));
            self::assertValid($thoroughA, 0.0);
        }
    }

    public function testKeepsTheFastPackingWhenItIsBetterAndSupported(): void
    {
        // Ivancic #5: the fast packer's (fully supported) solution needs one container fewer than the block search's
        $instance = InstanceLoader::load('ivancic')[4];
        $fast = Benchmark\Strategies::multi('legacy', $instance['boxes'], $instance['items'], []);
        $thorough = Benchmark\Strategies::multi('thorough', $instance['boxes'], $instance['items'], []);

        self::assertLessThanOrEqual($fast->count(), $thorough->count());
        self::assertSame($instance['items']->count(), self::itemCount($thorough));
        self::assertValid($thorough, 0.5);
    }

    public function testTheFastPackingIsNotKeptIfItIsNotSupportedWellEnough(): void
    {
        // the fast packer fits everything into one box, but with items overhanging
        $boxes = [new TestBox('Small', 10, 10, 16, 0, 10, 10, 16, 100000), new TestBox('Large', 16, 10, 16, 0, 16, 10, 16, 100000)];
        $fast = new Packer();
        $thorough = new Packer();
        $thorough->setStrategy(PackingStrategy::Thorough);
        foreach ([$fast, $thorough] as $packer) {
            $packer->setMinimumSupport(1.0);
            $packer->setMaxBoxesToBalanceWeight(0);
            foreach ($boxes as $box) {
                $packer->addBox($box);
            }
            $packer->addItem(new TestItem('A', 3, 10, 8, 1, Rotation::BestFit), 4);
            $packer->addItem(new TestItem('B', 6, 5, 6, 1, Rotation::BestFit), 5);
            $packer->addItem(new TestItem('C', 6, 2, 8, 1, Rotation::BestFit), 4);
        }

        $fastBoxes = $fast->pack();
        $thoroughBoxes = $thorough->pack();

        self::assertCount(1, $fastBoxes);
        self::assertLessThan(1.0, PackingValidator::minimumSupport($fastBoxes->top()));
        self::assertCount(2, $thoroughBoxes);
        self::assertSame(13, self::itemCount($thoroughBoxes));
        self::assertValid($thoroughBoxes);
    }

    public function testAnUnusableBoxTypeDoesNotStopTheFastPackingBeingKept(): void
    {
        // Ivancic #5, where the fast packer's boxes are kept, with a box type that cannot hold anything at all
        $instance = InstanceLoader::load('ivancic')[4];
        $boxes = [...$instance['boxes'], new TestBox('Unusable', 100, 100, 100, 10, 100, 100, 100, 5)];

        $thorough = Benchmark\Strategies::multi('thorough', $boxes, $instance['items'], []);
        $withoutUnusable = Benchmark\Strategies::multi('thorough', $instance['boxes'], $instance['items'], []);

        self::assertEquals(self::describe($withoutUnusable), self::describe($thorough));
    }

    public function testTheItemsAndBoxesGivenToTheConstructorAreUsed(): void
    {
        // Ivancic #5 again: whichever packing is kept, the list of items given is the one left with what is unpacked
        $instance = InstanceLoader::load('ivancic')[4];
        $boxes = new BoxList();
        foreach ($instance['boxes'] as $box) {
            $boxes->insert($box);
        }
        $items = clone $instance['items'];
        $packer = new Packer($items, $boxes);
        $packer->setStrategy(PackingStrategy::Thorough);

        $packedBoxes = $packer->pack();

        self::assertSame($instance['items']->count(), self::itemCount($packedBoxes));
        self::assertSame($items, $packer->getUnpackedItems());
        self::assertCount(0, $items);
    }

    public function testATimeoutPartWayThroughLeavesTheItemsToPack(): void
    {
        $instance = InstanceLoader::load('ivancic')[1];
        $packer = self::thoroughPacker();
        $packer->setTimeoutChecker(self::timeoutOnCall(200));
        foreach ($instance['boxes'] as $box) {
            $packer->addBox($box);
        }
        $packer->setItems($instance['items']);

        try {
            $packer->pack();
            self::fail('Expected a timeout');
        } catch (TimeoutException) {
            self::assertSame($instance['items']->count(), $packer->getUnpackedItems()->count());
        }
    }

    public function testTheTimeoutCheckerIsAskedDuringTheSearch(): void
    {
        // everything fits in one box, so only a few box packings are needed, but each search places many blocks
        $packer = self::thoroughPacker();
        $packer->setTimeoutChecker(self::timeoutOnCall(10));
        $packer->addBox(new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 100000));
        for ($i = 1; $i <= 6; ++$i) {
            $packer->addItem(new TestItem("Item {$i}", 3 * $i + 1, 5 * $i + 2, 7 * $i + 3, 1, Rotation::BestFit), 5);
        }

        $this->expectException(TimeoutException::class);
        $packer->pack();
    }

    public function testAnInfiniteTimeLimitMeansNoLimit(): void
    {
        $instance = InstanceLoader::load('ivancic')[2];
        $unlimited = Benchmark\Strategies::multi('thorough', $instance['boxes'], $instance['items'], ['width' => '4']);

        $infinite = self::thoroughPacker();
        $infinite->setMinimumSupport(0.5);
        $infinite->setSearchTimeLimit(INF);
        $infinite->setMaxBoxesToBalanceWeight(0);
        foreach ($instance['boxes'] as $box) {
            $infinite->addBox($box);
        }
        $infinite->setItems($instance['items']);

        self::assertEquals(self::describe($unlimited), self::describe($infinite->pack()));
    }

    public function testBoxesWithNoVolumeCanBeUsed(): void
    {
        $packer = self::thoroughPacker();
        $packer->addBox(new TestBox('Envelope', 10, 10, 0, 0, 10, 10, 0, 1000));
        $packer->addItem(new TestItem('Card', 10, 10, 0, 1, Rotation::KeepFlat), 2);

        self::assertSame(2, self::itemCount($packer->pack()));
    }

    public function testEachPackedBoxIsADistinctObject(): void
    {
        // identical items packed the same way into boxes of the same type must still be separate boxes
        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setMaxBoxesToBalanceWeight(0);
        $packer->addBox(new TestBox('Box A', 11, 11, 11, 0, 11, 11, 11, 22));
        $packer->addBox(new TestBox('Box B', 14, 14, 14, 0, 14, 14, 14, 7));
        $packer->addItem(new TestItem('Item A', 5, 4, 9, 3, Rotation::BestFit), 4);
        $packer->addItem(new TestItem('Item B', 3, 5, 5, 8, Rotation::BestFit), 6);

        $ids = [];
        foreach ($packer->pack() as $packedBox) {
            $ids[] = spl_object_id($packedBox);
        }

        self::assertCount(count($ids), array_unique($ids));
    }

    public function testItemsLeftOverArePackedIntoBoxesFreedUpByTheImprovements(): void
    {
        // construction runs out of the limited boxes; improvement frees one up, and the item left over goes into it
        // (the fast packer leaves it unpacked)
        $logger = self::recordingLogger();
        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setLogger($logger);
        $packer->setMaxBoxesToBalanceWeight(0);
        $packer->throwOnUnpackableItem(false);
        $packer->addBox(new LimitedSupplyTestBox('Limited', 14, 16, 20, 0, 14, 16, 20, 32, 2));
        $packer->addBox(new TestBox('Light', 16, 19, 16, 0, 16, 19, 16, 11));
        $packer->addBox(new TestBox('Slim', 5, 16, 16, 0, 5, 16, 16, 50));
        $packer->addItem(new TestItem('A', 7, 9, 10, 18, Rotation::BestFit), 2);
        $packer->addItem(new TestItem('B', 8, 3, 13, 11, Rotation::BestFit), 5);
        $packer->addItem(new TestItem('C', 4, 11, 15, 20, Rotation::BestFit), 1);

        $packedBoxes = $packer->pack();

        self::assertContains('Packing 1 items left over into boxes freed up by the improvements', $logger->messages);
        self::assertSame(8, self::itemCount($packedBoxes));
        self::assertCount(0, $packer->getUnpackedItems());
        self::assertValid($packedBoxes, 0.5);
    }

    public function testWeightBalancingIsNotKeptIfItNeedsLargerBoxes(): void
    {
        // balancing the weight would put the items into two of the large boxes
        $unbalanced = new Packer();
        $unbalanced->setMaxBoxesToBalanceWeight(0);
        $balanced = new Packer();
        foreach ([$unbalanced, $balanced] as $packer) {
            $packer->setStrategy(PackingStrategy::Thorough);
            $packer->addBox(new TestBox('Small', 8, 8, 8, 0, 8, 8, 8, 36));
            $packer->addBox(new TestBox('Large', 19, 19, 19, 0, 19, 19, 19, 40));
            $packer->addItem(new TestItem('Heavy', 5, 7, 6, 20, Rotation::BestFit), 2);
            $packer->addItem(new TestItem('Light', 5, 5, 5, 7, Rotation::BestFit), 2);
        }

        $unbalancedBoxes = $unbalanced->pack();
        $balancedBoxes = $balanced->pack();

        self::assertSame(['Large', 'Small'], self::boxReferences($unbalancedBoxes));
        self::assertSame(self::boxVolume($unbalancedBoxes), self::boxVolume($balancedBoxes));
        self::assertCount(2, $balancedBoxes);
    }

    public function testPairsAreMadeInOrderOfTheirSum(): void
    {
        $pairsBySum = new ReflectionMethod(ThoroughPacker::class, 'pairsBySum');
        $seed = 1;
        $random = static function (int $max) use (&$seed): int { // repeatable
            $seed = ($seed * 1103515245 + 12345) % 2147483648;

            return ($seed >> 16) % ($max + 1);
        };
        for ($round = 0; $round < 50; ++$round) {
            $values = [];
            for ($i = $random(12); $i > 0; --$i) {
                $values[] = $round % 2 === 0 ? $random(5) : -$random(50) / 7;
            }
            $maxSum = $round % 3 === 0 ? 6 : INF;

            $expected = [];
            foreach ($values as $i => $a) {
                foreach ($values as $j => $b) {
                    if ($i < $j && $a + $b <= $maxSum) {
                        $expected[] = [$a + $b, $i, $j];
                    }
                }
            }
            usort($expected, static fn (array $x, array $y) => $x <=> $y);
            $actual = [];
            foreach ($pairsBySum->invoke(null, $values, $maxSum) as [$i, $j]) {
                $actual[] = [$values[$i] + $values[$j], $i, $j];
            }

            self::assertSame($expected, $actual);
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
