<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Benchmark\PackingValidator;
use DVDoug\BoxPacker\Test\OverhangTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use DVDoug\BoxPacker\Test\ZeroOverhangBox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WeakMap;

use function array_fill;
use function implode;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function rawurldecode;
use function strlen;
use function strpos;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Packer-level behaviour with an allowed overhang: box choice, capacity bounds, weight balancing, caches and output.
 */
#[CoversClass(Packer::class)]
#[CoversClass(ThoroughPacker::class)]
#[CoversClass(WeightRedistributor::class)]
#[CoversClass(PackedBox::class)]
#[CoversClass(PackedBoxList::class)]
#[CoversClass(OrientatedItemFactory::class)]
#[CoversClass(OverhangBounds::class)]
class OverhangPackerTest extends TestCase
{
    private const NO_WEIGHT_LIMIT = 1000000000;

    private static function overhangPallet(string $reference = '48x40 with overhang'): OverhangTestBox
    {
        return new OverhangTestBox($reference, 4800, 4000, 6500, 0, 4800, 4000, 6500, self::NO_WEIGHT_LIMIT, new AllowedOverhang(sides: 150, frontAndBack: 300));
    }

    /**
     * @param list<Box> $boxes
     */
    private static function pack(array $boxes, int $trampolines, PackingStrategy $strategy, int $balance = 12): Packer
    {
        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->setMinimumSupport(1.0);
        $packer->setMaxBoxesToBalanceWeight($balance);
        $packer->throwOnUnpackableItem(false);
        foreach ($boxes as $box) {
            $packer->addBox($box);
        }
        $packer->addItem(new TestItem('ACME Trampolines', 4953, 2252, 756, 1000, Rotation::BestFit), $trampolines);

        return $packer;
    }

    /**
     * @param  iterable<PackedBox> $packedBoxes
     * @return list<string>        box reference and item count of each box, all checked by the validator (as balanced too)
     */
    private static function summary(iterable $packedBoxes): array
    {
        $summary = [];
        foreach ($packedBoxes as $packedBox) {
            self::assertSame([], PackingValidator::problems($packedBox));
            self::assertSame([], PackingValidator::centringProblems($packedBox));
            self::assertSame([], PackingValidator::avoidableOverhangProblems($packedBox));
            self::assertGreaterThanOrEqual(1.0, PackingValidator::minimumSupport($packedBox));
            $summary[] = "{$packedBox->box->getReference()}: {$packedBox->items->count()}";
        }

        return $summary;
    }

    /**
     * @return array<string, array{PackingStrategy}>
     */
    public static function strategies(): array
    {
        return ['Fast' => [PackingStrategy::Fast], 'Thorough' => [PackingStrategy::Thorough]];
    }

    #[DataProvider('strategies')]
    public function testThePalletWithOverhangBeatsALargerPallet(PackingStrategy $strategy): void
    {
        // 16 ACME Trampolines cartons are more than the 48 × 40's own volume, but fit with its overhang; the plain 51 × 46 holds them
        // too, but costs more (by default, the inner volume)
        $packer = self::pack([self::overhangPallet(), new TestBox('51x46', 5100, 4600, 6500, 0, 5100, 4600, 6500, self::NO_WEIGHT_LIMIT)], 16, $strategy);

        self::assertSame(['48x40 with overhang: 16'], self::summary($packer->pack()));
    }

    #[DataProvider('strategies')]
    public function testThirtyTwoNeedTwoPallets(PackingStrategy $strategy): void
    {
        $packer = self::pack([self::overhangPallet()], 32, $strategy);

        self::assertSame(['48x40 with overhang: 16', '48x40 with overhang: 16'], self::summary($packer->pack()));
        self::assertCount(0, $packer->getUnpackedItems());
    }

    #[DataProvider('strategies')]
    public function testEveryPalletIsBalanced(PackingStrategy $strategy): void
    {
        // with Thorough, identical packings of the same items come from its cache, as packed (and so as balanced) the
        // first time: every pallet's 16 hang 0.77" and 0.76" over the sides and 2.52" over the front and the back
        $packer = self::pack([self::overhangPallet(), new TestBox('51x46', 5100, 4600, 6500, 0, 5100, 4600, 6500, self::NO_WEIGHT_LIMIT)], 48, $strategy);
        $packedBoxes = $packer->pack();

        self::assertSame(['48x40 with overhang: 16', '48x40 with overhang: 16', '48x40 with overhang: 16'], self::summary($packedBoxes));
        $layouts = [];
        foreach ($packedBoxes as $packedBox) {
            $xs = $ys = [];
            foreach ($packedBox->items as $item) {
                $xs[] = $item->x;
                $xs[] = $item->x + $item->width;
                $ys[] = $item->y;
                $ys[] = $item->y + $item->length;
            }
            self::assertSame([-77, 4876, -252, 4252], [min($xs), max($xs), min($ys), max($ys)]);
            $layouts[] = self::fingerprint([$packedBox]);
        }
        self::assertSame([$layouts[0], $layouts[0], $layouts[0]], $layouts);
    }

    public function testACachedPackingIsAsBalancedAsWhenItWasPacked(): void
    {
        $pallet = self::overhangPallet();
        $boxes = new BoxList();
        $boxes->insert($pallet);
        $quantities = new WeakMap();
        $quantities[$pallet] = 10;
        $factory = new class(PackingStrategy::Thorough, minimumSupport: 1.0) extends VolumePackerFactory {
            public int $created = 0;

            public function create(Box $box, ItemList $items): VolumePacker
            {
                ++$this->created;

                return parent::create($box, $items);
            }
        };
        $thoroughPacker = new ThoroughPacker($boxes, $quantities, $factory, new DefaultPackedBoxCostCalculator(), false);
        $item = new TestItem('ACME Trampolines', 4953, 2252, 756, 1000, Rotation::BestFit);
        $items = new ItemList();
        $items->insert($item, 16);

        // the packing returned, packed afresh (and so cached), and the same items packed again: from the cache
        $solution = $thoroughPacker->pack($items);
        self::assertGreaterThan(0, $factory->created, 'the first packing was packed afresh');
        self::assertCount(1, $solution);
        $first = $solution[0];
        $created = $factory->created;
        $cached = (new ReflectionMethod(ThoroughPacker::class, 'packItems'))->invoke($thoroughPacker, $pallet, array_fill(0, 16, $item));

        self::assertSame($created, $factory->created, 'the second packing came from the cache');
        self::assertNotSame($first, $cached);
        self::assertSame(self::fingerprint([$first]), self::fingerprint([$cached]));
        self::assertSame(['48x40 with overhang: 16'], self::summary([$first]));
        self::assertSame(['48x40 with overhang: 16'], self::summary([$cached]));
    }

    #[DataProvider('strategies')]
    public function testWeightIsBalancedAcrossPalletsLoadedPastTheirOwnVolume(PackingStrategy $strategy): void
    {
        // 15 per pallet is more than a pallet's own volume, so balancing only works if its repacks allow for the overhang
        self::assertSame(['48x40 with overhang: 16', '48x40 with overhang: 14'], self::summary(self::pack([self::overhangPallet()], 30, $strategy, 0)->pack()));

        $packedBoxes = self::pack([self::overhangPallet()], 30, $strategy)->pack();
        self::assertSame(['48x40 with overhang: 15', '48x40 with overhang: 15'], self::summary($packedBoxes));
        self::assertSame(0.0, $packedBoxes->getWeightVariance());
    }

    public function testThoroughPrefersTheCheaperPalletWhenTheLoadExceedsItsOwnVolume(): void
    {
        // more items than the Packer compares with the Fast strategy, so this is the Thorough search's own choice: the
        // tiles fill the 1000 × 1000 pallet's 1100 × 1100 envelope (four to a layer), more than its own volume holds,
        // and the plain 1100 × 1100 pallet costs more
        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setMinimumSupport(1.0);
        $packer->throwOnUnpackableItem(false);
        $packer->addBox(new OverhangTestBox('Deck', 1000, 1000, 2100, 0, 1000, 1000, 2100, self::NO_WEIGHT_LIMIT, new AllowedOverhang(sides: 50, frontAndBack: 50)));
        $packer->addBox(new TestBox('Big', 1100, 1100, 2100, 0, 1100, 1100, 2100, self::NO_WEIGHT_LIMIT));
        $packer->addItem(new TestItem('Tile', 520, 520, 10, 1, Rotation::Never), 800);

        self::assertSame(['Deck: 800'], self::summary($packer->pack()));
    }

    public function testThePackableVolumeBoundAllowsForTheOverhang(): void
    {
        // boards that only fit with the overhang count towards what the pallet could hold, up to its envelope's volume
        $deck = new OverhangTestBox('Deck', 1000, 1000, 1000, 0, 1000, 1000, 1000, self::NO_WEIGHT_LIMIT, new AllowedOverhang(sides: 50));
        $boxes = new BoxList();
        $boxes->insert($deck);
        $quantities = new WeakMap();
        $quantities[$deck] = 10;
        $thoroughPacker = new ThoroughPacker($boxes, $quantities, new VolumePackerFactory(PackingStrategy::Thorough), new DefaultPackedBoxCostCalculator(), false);
        (new ReflectionMethod(ThoroughPacker::class, 'initialiseBoxTypes'))->invoke($thoroughPacker);
        $bound = new ReflectionMethod(ThoroughPacker::class, 'packableVolumeBound');

        $board = new TestItem('Board', 1100, 500, 100, 1, Rotation::Never);
        self::assertSame(3 * 1100 * 500 * 100, $bound->invoke($thoroughPacker, $deck, [$board, $board, $board]));
        self::assertSame(1100 * 1000 * 1000, $bound->invoke($thoroughPacker, $deck, array_fill(0, 30, $board)));
        self::assertSame(0, $bound->invoke($thoroughPacker, $deck, [new TestItem('Too long', 1101, 500, 100, 1, Rotation::Never)]));
    }

    public function testSingleBoxPackingKeepsABoxWhoseOverhangHoldsTheItems(): void
    {
        $packer = self::pack([self::overhangPallet()], 16, PackingStrategy::Fast);

        self::assertSame(['48x40 with overhang: 16'], self::summary($packer->doBasicPacking(true)));
    }

    public function testBoxesWithTheSameInnerDimensionsDoNotShareCachedAnswers(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('ACME Trampolines', 4953, 2252, 756, 1000, Rotation::BestFit), 8);
        $items->insert(new TestItem('Tube', 300, 300, 4000, 100, Rotation::BestFit), 6);
        $items->insert(new TestItem('Carton', 1210, 820, 640, 500, Rotation::KeepFlat), 10);
        $items->insert(new TestItem('Board', 4900, 1000, 100, 500, Rotation::KeepFlat), 2); // only fits with the overhang
        $plain = new TestBox('Pallet', 4800, 4000, 6500, 0, 4800, 4000, 6500, self::NO_WEIGHT_LIMIT);
        $overhang = new OverhangTestBox('Pallet', 4800, 4000, 6500, 0, 4800, 4000, 6500, self::NO_WEIGHT_LIMIT, new AllowedOverhang(sides: 150, frontAndBack: 300));

        $run = static function (Box $box, PackingStrategy $strategy) use ($items): string {
            $packer = new Packer();
            $packer->setStrategy($strategy);
            $packer->throwOnUnpackableItem(false);
            $packer->addBox($box);
            $packer->setItems(clone $items);

            return self::fingerprint($packer->pack());
        };
        $clearCaches = static function (): void {
            (new ReflectionProperty(OrientatedItemFactory::class, 'emptyBoxStableItemOrientationCache'))->setValue(null, []);
            (new ReflectionProperty(BlockPacker::class, 'fillableCache'))->setValue(null, []);
            (new ReflectionProperty(BlockPacker::class, 'fillableCacheEntries'))->setValue(null, 0);
        };

        foreach ([PackingStrategy::Fast, PackingStrategy::Thorough] as $strategy) {
            $clearCaches();
            $freshPlain = $run($plain, $strategy);
            $clearCaches();
            $freshOverhang = $run($overhang, $strategy);
            self::assertNotSame($freshPlain, $freshOverhang);

            foreach ([$overhang, $plain, $overhang, $plain] as $box) {
                self::assertSame($box === $plain ? $freshPlain : $freshOverhang, $run($box, $strategy));
            }
        }
    }

    public function testUsedAndRemainingSpaceWithItemsHangingOver(): void
    {
        $box = new OverhangTestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, self::NO_WEIGHT_LIMIT, new AllowedOverhang(sides: 100, frontAndBack: 100));
        $item = new TestItem('Item', 1080, 520, 100, 1, Rotation::Never);
        $items = new PackedItemList();
        $items->insert(new PackedItem($item, -40, -20, 0, 1080, 520, 100));
        $items->insert(new PackedItem($item, -40, 500, 0, 1080, 520, 100));
        $packedBox = new PackedBox($box, $items);

        self::assertSame([], PackingValidator::problems($packedBox));
        self::assertSame(1080, $packedBox->getUsedWidth());
        self::assertSame(1040, $packedBox->getUsedLength());
        self::assertSame(100, $packedBox->getUsedDepth());
        self::assertSame(-80, $packedBox->getRemainingWidth());
        self::assertSame(-40, $packedBox->getRemainingLength());
        self::assertSame(100000000, $packedBox->getInnerVolume());
        self::assertSame(-12320000, $packedBox->getUnusedVolume());
        self::assertSame(112.3, $packedBox->getVolumeUtilisation());

        // used space starts at 0 even if no item does
        $inset = new PackedItemList();
        $inset->insert(new PackedItem($item, -40, 200, 0, 1080, 520, 100));
        self::assertSame(720, (new PackedBox($box, $inset))->getUsedLength());
    }

    public function testOutputCarriesTheAllowanceOnlyWhenActive(): void
    {
        $item = new TestItem('Item', 1080, 520, 100, 1, Rotation::Never);
        $items = new PackedItemList();
        $items->insert(new PackedItem($item, -40, -20, 0, 1080, 520, 100));
        $packedBox = new PackedBox(new OverhangTestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, self::NO_WEIGHT_LIMIT, new AllowedOverhang(sides: 40, frontAndBack: 20)), $items);
        $list = new PackedBoxList();
        $list->insert($packedBox);

        self::assertSame(['sides' => 40, 'frontAndBack' => 20], json_decode(json_encode($packedBox, JSON_THROW_ON_ERROR), true)['box']['allowedOverhang']);
        // the visualiser draws each edge: [left, right, front, back]
        self::assertSame([40, 40, 20, 20], self::visualiserBoxes($packedBox->generateVisualisationURL())[0][5]);
        self::assertSame([40, 40, 20, 20], self::visualiserBoxes($list->generateVisualisationURL())[0][5]);
    }

    public function testOutputIsUnchangedForABoxWithoutAnOverhangOrWithAZeroOne(): void
    {
        $box = new TestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, self::NO_WEIGHT_LIMIT);
        $item = new TestItem('Item', 500, 400, 100, 1, Rotation::KeepFlat);
        $output = [];
        foreach ([$box, ZeroOverhangBox::wrap($box), new OverhangTestBox('Box', 1000, 1000, 100, 0, 1000, 1000, 100, self::NO_WEIGHT_LIMIT, new AllowedOverhang())] as $candidate) {
            $items = new PackedItemList();
            $items->insert(new PackedItem($item, 0, 0, 0, 500, 400, 100));
            $items->insert(new PackedItem($item, 500, 0, 0, 400, 500, 100));
            $packedBox = new PackedBox($candidate, $items);
            $list = new PackedBoxList();
            $list->insert($packedBox);
            $output[] = [json_encode($packedBox, JSON_THROW_ON_ERROR), $packedBox->generateVisualisationURL(), json_encode($list, JSON_THROW_ON_ERROR), $list->generateVisualisationURL()];
        }

        self::assertSame($output[0], $output[1]);
        self::assertSame($output[0], $output[2]);
        self::assertCount(5, self::visualiserBoxes($output[0][1])[0]);
    }

    /**
     * @return list<list<mixed>>
     */
    private static function visualiserBoxes(string $url): array
    {
        $marker = '#packing=';

        return json_decode(rawurldecode(substr($url, strpos($url, $marker) + strlen($marker))), true, flags: JSON_THROW_ON_ERROR)['boxes'];
    }

    /**
     * @param iterable<PackedBox> $packedBoxes
     */
    private static function fingerprint(iterable $packedBoxes): string
    {
        $rows = [];
        foreach ($packedBoxes as $packedBox) {
            $rows[] = $packedBox->box->getReference();
            foreach ($packedBox->items as $p) {
                $rows[] = implode(',', [$p->item->getDescription(), $p->x, $p->y, $p->z, $p->width, $p->length, $p->depth, $p->angle]);
            }
        }

        return implode("\n", $rows);
    }
}
