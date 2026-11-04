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
use DVDoug\BoxPacker\Test\ConstrainedPlacementMaxXTestItem;
use DVDoug\BoxPacker\Test\ConstrainedPlacementRecordingTestItem;
use DVDoug\BoxPacker\Test\OverhangTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function count;
use function in_array;
use function intdiv;
use function iterator_to_array;
use function max;
use function min;
use function sort;
use function usort;

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

    private static function pallet(?AllowedOverhang $allowance, int $width = self::PALLET[0], int $length = self::PALLET[1], int $depth = self::PALLET[2], int $maxWeight = self::NO_WEIGHT_LIMIT): Box
    {
        return $allowance === null
            ? new TestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, $maxWeight)
            : new OverhangTestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, $maxWeight, $allowance);
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

    /**
     * Physically valid, supported well enough and (unless a placement callback refused it) balanced.
     */
    private static function assertValid(PackedBox $packedBox, float $support = 1.0, bool $balanced = true): void
    {
        self::assertSame([], PackingValidator::problems($packedBox));
        self::assertGreaterThanOrEqual($support, PackingValidator::minimumSupport($packedBox));
        if ($balanced) {
            self::assertSame([], PackingValidator::centringProblems($packedBox));
        }
    }

    /**
     * Independent oracle for the acceptance table: the best uniform grid (one orientation for the whole load), as the
     * WMS slot grid counts it. Per axis, n items of extent u fit a deck span S with an allowance a at each of its two
     * edges when n × u <= S + 2 × min(a, 15 % of u).
     */
    private static function uniformGrid(AllowedOverhang $allowance): int
    {
        [$a, $b, $c] = self::ACME_TRAMPOLINES;
        [$width, $length, $height] = self::PALLET;
        $best = 0;
        foreach ([[$a, $b, $c], [$b, $a, $c], [$a, $c, $b], [$c, $a, $b], [$b, $c, $a], [$c, $b, $a]] as [$u, $v, $h]) {
            $nx = intdiv($width + 2 * min($allowance->sides, intdiv(15 * $u, 100)), $u);
            $ny = intdiv($length + 2 * min($allowance->frontAndBack, intdiv(15 * $v, 100)), $v);
            $best = max($best, $nx * $ny * intdiv($height, $h));
        }

        return $best;
    }

    /**
     * Allowances (sides, front and back), the brief's uniform-grid count, and what Thorough and Fast find.
     *
     * @return array<string, array{?AllowedOverhang, int, int, int}>
     */
    public static function acmeTrampolinesTable(): array
    {
        return [
            'none' => [null, 10, 10, 10],
            '1.5 / 3' => [new AllowedOverhang(sides: 150, frontAndBack: 300), 16, 16, 16],
            '1 / 3' => [new AllowedOverhang(sides: 100, frontAndBack: 300), 16, 16, 16],
            // Thorough mixes orientations where a uniform grid cannot: 8 flat (4953 × 2252 × 756) hanging over the
            // front by the allowance, and behind them 2 × 2 on their long edges (4953 × 756 × 2252), so 12 (see
            // testAcmeTrampolinesThoroughMixesOrientations()). Each item is within its caps (the validator checks every
            // one) and fully supported. The table's 10 is the best uniform grid; Fast keeps the layer packer's 10 on the
            // deck, as the greedy block search finds no more
            '2.5 / 2.5' => [new AllowedOverhang(sides: 250, frontAndBack: 250), 10, 12, 10],
        ];
    }

    #[DataProvider('acmeTrampolinesTable')]
    public function testAcmeTrampolinesUniformGridOracle(?AllowedOverhang $allowance, int $grid, int $thorough, int $fast): void
    {
        self::assertSame($grid, self::uniformGrid($allowance ?? new AllowedOverhang()));
    }

    #[DataProvider('acmeTrampolinesTable')]
    public function testAcmeTrampolinesThorough(?AllowedOverhang $allowance, int $grid, int $thorough, int $fast): void
    {
        $packedBox = self::thorough(self::pallet($allowance), self::trampolines(24));

        self::assertValid($packedBox);
        self::assertGreaterThanOrEqual($grid, $packedBox->items->count());
        self::assertCount($thorough, $packedBox->items);
    }

    #[DataProvider('acmeTrampolinesTable')]
    public function testAcmeTrampolinesFast(?AllowedOverhang $allowance, int $grid, int $thorough, int $fast): void
    {
        $deckOnly = self::fast(self::pallet(null), self::trampolines(24));
        $packedBox = self::fast(self::pallet($allowance), self::trampolines(24));

        self::assertValid($packedBox);
        self::assertGreaterThanOrEqual($deckOnly->items->count(), $packedBox->items->count());
        self::assertGreaterThanOrEqual($grid, $packedBox->items->count());
        self::assertCount($fast, $packedBox->items);
    }

    /**
     * @return array<string, array{AllowedOverhang, callable(Box, ItemList): PackedBox}>
     */
    public static function acmeTrampolinesSixteen(): array
    {
        $rows = [];
        foreach (['1.5 / 3' => new AllowedOverhang(sides: 150, frontAndBack: 300), '1 / 3' => new AllowedOverhang(sides: 100, frontAndBack: 300)] as $name => $allowance) {
            $rows["{$name} Thorough"] = [$allowance, self::thorough(...)];
            $rows["{$name} Fast"] = [$allowance, self::fast(...)];
        }

        return $rows;
    }

    /**
     * @param callable(Box, ItemList): PackedBox $pack
     */
    #[DataProvider('acmeTrampolinesSixteen')]
    public function testAcmeTrampolinesSixteenIsBalanced(AllowedOverhang $allowance, callable $pack): void
    {
        // the search builds the 1 × 2 × 8 grid from the corner of its frame (150 or 100 over the left, 300 over the
        // front); moved by 73 or 23 along x and 48 along y, it hangs 0.77" over the left and 0.76" over the right (of
        // 1.53", the odd unit going to the smaller move), and 2.52" over the front and the back
        $packedBox = $pack(self::pallet($allowance), self::trampolines(24));

        self::assertValid($packedBox);
        self::assertSame([-77, 4876, -252, 4252], self::extents($packedBox));
        self::assertSame(4953, $packedBox->getUsedWidth());
        self::assertSame(4504, $packedBox->getUsedLength());
        self::assertSame(-153, $packedBox->getRemainingWidth());
        self::assertSame(-504, $packedBox->getRemainingLength());
        self::assertSame(6048, $packedBox->getUsedDepth());
        self::assertSame(108.1, $packedBox->getVolumeUtilisation());
        self::assertLessThan(0, $packedBox->getUnusedVolume());
        self::assertSame(1.0, SupportCalculator::minimumSupport($packedBox->items, $packedBox->box));
        foreach ($packedBox->items as $item) {
            self::assertSame([4953, 2252, 756], [$item->width, $item->length, $item->depth]);
        }

        // a second packing is the same, and a balanced packing needs no further move
        self::assertSame(self::sequence($packedBox), self::sequence($pack(self::pallet($allowance), self::trampolines(24))));
        $bounds = OverhangBounds::forBox($packedBox->box);
        self::assertNotNull($bounds);
        self::assertSame([0, 0], $bounds->centringShift($packedBox->items));
    }

    public function testAcmeTrampolinesThoroughMixesOrientations(): void
    {
        $packedBox = self::thorough(self::pallet(new AllowedOverhang(sides: 250, frontAndBack: 250)), self::trampolines(24));

        self::assertValid($packedBox);
        // 8 flat, hanging the whole 2.5" over the front, and behind them 2 rows of 2 on their long edges, the back row
        // hanging 1.13" (its cap, 15 % of 7.56") over the back. Along x the load is balanced as the 16 are (0.77" and
        // 0.76"). Along y it cannot move: the flat ones are at their cap at the front and the back row at its cap at the
        // back, so it stays 2.5" over the front and 1.13" over the back, with a gap of 5.99" between the two blocks
        // that moving the whole load cannot close
        $flat = $onEdge = [];
        foreach ([0, 756, 1512, 2268, 3024, 3780, 4536, 5292] as $z) {
            $flat[] = "ACME Trampolines -77,-250,{$z} 4953x2252x756";
        }
        foreach ([[2601, 0], [3357, 0], [2601, 2252], [3357, 2252]] as [$y, $z]) {
            $onEdge[] = "ACME Trampolines -77,{$y},{$z} 4953x756x2252";
        }
        $expected = [...$flat, ...$onEdge];
        sort($expected);
        self::assertSame($expected, self::placements($packedBox));
        self::assertSame([-77, 4876, -250, 4113], self::extents($packedBox));
        $bounds = OverhangBounds::forBox($packedBox->box);
        self::assertNotNull($bounds);
        self::assertSame(250, $bounds->endCap(2252));
        self::assertSame(113, $bounds->endCap(756));
        self::assertSame([0, 0], $bounds->centringShift($packedBox->items));
    }

    public function testAcmeTrampolinesFastKeepsTheDeckOnlyPackingWhereItIsAsGood(): void
    {
        // the layer packer's 10 on the deck ties with the greedy block search's 10, so it is kept, where it was packed:
        // nothing hangs over, so nothing is moved, gaps and all
        $packedBox = self::fast(self::pallet(new AllowedOverhang(sides: 250, frontAndBack: 250)), self::trampolines(24));

        self::assertValid($packedBox);
        self::assertSame(self::placements(self::fast(self::pallet(null), self::trampolines(24))), self::placements($packedBox));
        self::assertSame([0, 4536, 0, 3764], self::extents($packedBox));
    }

    public function testAnItemThatOnlyFitsWithOverhangIsPacked(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('Long', 1100, 500, 100, 1, Rotation::KeepFlat));

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            self::assertCount(0, $pack(self::pallet(null, 1000, 1000, 500), $items)->items);
            $packedBox = $pack(self::pallet(new AllowedOverhang(sides: 50), 1000, 1000, 500), $items);
            self::assertCount(1, $packedBox->items);
            self::assertValid($packedBox);
            self::assertSame([-50, 0], [self::first($packedBox)->x, self::first($packedBox)->y]);
        }
    }

    public function testAnAxisOnWhichNothingHangsOverIsNotMoved(): void
    {
        // the search puts the item in the corner of its frame, 100 over the left; moved 75 to hang 25 over each side.
        // Along y there is no allowance, so the item stays at the front, with a gap behind it
        $items = new ItemList();
        $items->insert(new TestItem('Long', 1050, 500, 100, 1, Rotation::Never));

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            $packedBox = $pack(self::pallet(new AllowedOverhang(sides: 100), 1000, 1000, 100), $items);
            self::assertValid($packedBox);
            self::assertSame([-25, 1025, 0, 500], self::extents($packedBox));
        }
    }

    public function testALoadThatFitsTheDeckIsMovedOntoIt(): void
    {
        // a 12" cube hangs 1.8" (its cap) over the left and the front where the search puts it, in the corner of its
        // frame; it fits the pallet, so is moved onto it, with equal gaps (counted as negative overhangs) each way
        $cube = new ConstrainedPlacementRecordingTestItem('Cube', 1200, 1200, 1200, 1, Rotation::BestFit);
        $items = new ItemList();
        $items->insert($cube);

        $packedBox = self::thorough(self::pallet(new AllowedOverhang(sides: 300, frontAndBack: 300)), $items);

        self::assertValid($packedBox);
        self::assertSame([1800, 3000, 1400, 2600], self::extents($packedBox));
        self::assertContains([-180, -180, 0, 1200, 1200, 1200], array_column($cube->calls, 'proposal'));
        self::assertSame([1800, 1400, 0, 1200, 1200, 1200], $cube->calls[count($cube->calls) - 1]['proposal']);
        // used space still starts at 0, so the gap before the load counts as used
        self::assertSame(3000, $packedBox->getUsedWidth());
        self::assertSame(1800, $packedBox->getRemainingWidth());
        self::assertSame(2600, $packedBox->getUsedLength());
        self::assertSame(1400, $packedBox->getRemainingLength());
    }

    public function testBlocksAreAnchoredAtAllFourCornersWithinTheirCaps(): void
    {
        // four 520 × 520 items (caps 78) on a 1000 × 1000 deck: two across each way need 40 of overhang. The search
        // frame is wider (195 at each edge, the caps of an item too heavy to pack), so blocks anchored at the far
        // corners must stop at the 520s' own caps, not at the edge of the frame. The load is then balanced already
        $box = self::pallet(new AllowedOverhang(sides: 200, frontAndBack: 300), 1000, 1000, 100, 100);
        $recorders = [];
        $items = new ItemList();
        foreach ([1, 2, 3, 4] as $weight) {
            $recorders[] = $recorder = new ConstrainedPlacementRecordingTestItem("Item {$weight}", 520, 520, 100, $weight, Rotation::Never);
            $items->insert($recorder);
        }
        $items->insert(new TestItem('Too heavy', 1300, 1300, 100, 1000, Rotation::Never));

        $packedBox = self::thorough($box, $items);

        self::assertCount(4, $packedBox->items);
        self::assertValid($packedBox);
        self::assertSame([-78, 1078, -78, 1078], self::extents($packedBox));
        $proposals = [];
        foreach ($recorders as $recorder) {
            foreach (array_slice($recorder->calls, 1) as ['proposal' => [$x, $y]]) { // after the stability probe
                $proposals[] = [$x, $y];
            }
        }
        foreach ([[-78, -78], [558, -78], [-78, 558], [558, 558]] as $corner) {
            self::assertContains($corner, $proposals);
        }
        foreach ($proposals as [$x, $y]) {
            self::assertGreaterThanOrEqual(-78, min($x, $y));
            self::assertLessThanOrEqual(1078, max($x, $y) + 520);
        }
    }

    public function testRaisedItemsAreCappedToo(): void
    {
        // a base hanging 150 over the left edge where the search puts it (its cap is 172); narrow items on top of it,
        // fully supported anywhere on it, may still hang over by only 15 of their own 100, so 10 of them fit across
        // it, not 11. Balancing would move the load 75 to the right, but the rightmost narrow item may only move 30
        // before it hangs over by more than its cap: so 30, as far as the caps allow
        $box = self::pallet(new AllowedOverhang(sides: 150), 1000, 1000, 200);
        $items = new ItemList();
        $items->insert(new TestItem('Base', 1150, 1000, 100, 1, Rotation::Never));
        $items->insert(new TestItem('Narrow', 100, 1000, 100, 1, Rotation::Never), 12);

        $packedBox = self::thorough($box, $items);

        self::assertValid($packedBox);
        $narrow = array_filter(iterator_to_array($packedBox->items, false), static fn (PackedItem $item) => $item->item->getDescription() === 'Narrow');
        self::assertCount(10, $narrow);
        self::assertSame(15, min(array_map(static fn (PackedItem $item) => $item->x, $narrow)));
        self::assertSame(1015, max(array_map(static fn (PackedItem $item) => $item->x + $item->width, $narrow)));
        self::assertSame(-120, self::first($packedBox)->x);
    }

    public function testFullSupportAboveTheOverhang(): void
    {
        // two 550 wide items side by side hang 50 over each side; an item as wide as both rests on them, fully
        // supported, hanging over by the same 50 (its own cap would allow 165)
        $box = self::pallet(new AllowedOverhang(sides: 50), 1000, 500, 200);
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
        // space, which reaches 60 past the deck (the plain item's cap), far more than their cap of 18, so the smaller
        // block must be placed against their cap, not the end of the space. The plain item is then at its cap at the
        // left, so the load cannot move
        ConstrainedPlacementByCountTestItem::$limit = 3;
        $box = self::pallet(new AllowedOverhang(sides: 200), 1000, 100, 100);
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
        self::assertSame(-60, self::first($packedBox)->x);
    }

    public function testPlacementCallbacksAreAskedInTheDeckFrame(): void
    {
        // Big (1050 wide) only fits hanging over the sides, by 50 in all; Small (cap 30 across its 200 side) goes
        // against the left edge in the band; Sheet is flat, so placed at the end
        $box = self::pallet(new AllowedOverhang(sides: 50), 1000, 800, 300);
        $big = new ConstrainedPlacementRecordingTestItem('Big', 1050, 300, 100, 1, Rotation::KeepFlat);
        $small = new ConstrainedPlacementRecordingTestItem('Small', 200, 150, 100, 1, Rotation::KeepFlat);
        $sheet = new ConstrainedPlacementRecordingTestItem('Sheet', 1050, 300, 0, 1, Rotation::KeepFlat);
        $items = new ItemList();
        $items->insert($big);
        $items->insert($small, 6);
        $items->insert($sheet);

        $packedBox = self::thorough($box, $items);

        self::assertCount(8, $packedBox->items);
        self::assertValid($packedBox);
        foreach ($packedBox->items as $packedItem) {
            $recorder = $packedItem->item;
            self::assertInstanceOf(ConstrainedPlacementRecordingTestItem::class, $recorder);
            self::assertContains([$packedItem->x, $packedItem->y, $packedItem->z, $packedItem->width, $packedItem->length, $packedItem->depth], array_column($recorder->calls, 'proposal'));
        }

        $inTheBand = false;
        foreach ([$big, $small, $sheet] as $recorder) {
            foreach ($recorder->calls as ['proposal' => $proposal, 'context' => $context]) {
                $inTheBand = $inTheBand || ($proposal[0] < 0 && $context !== []);
                $contextItems = new PackedItemList();
                foreach ($context as $contextItem) {
                    $contextItems->insert($contextItem);
                }
                self::assertSame([], PackingValidator::problems(new PackedBox($box, $contextItems)));
            }
        }
        self::assertTrue($inTheBand, 'a block in the band past the left edge was proposed');

        // the stability probes, in the empty box where each item would go on its own: hanging over both sides equally
        // if it must, and z = 0
        self::assertSame(['proposal' => [-25, 0, 0, 1050, 300, 100], 'context' => []], $big->calls[0]);
        self::assertSame(['proposal' => [0, 0, 0, 200, 150, 100], 'context' => []], $small->calls[0]);
        self::assertSame(['proposal' => [-25, 0, 0, 1050, 300, 0], 'context' => []], $sheet->calls[0]);
        // and the flat item, placed after everything else, on its own in the same way, with the others where they are
        // finally placed
        $lastCall = $sheet->calls[count($sheet->calls) - 1];
        self::assertSame([-25, 0, 0, 1050, 300, 0], $lastCall['proposal']);
        self::assertSame(self::placements($packedBox, ['Sheet']), self::describe($lastCall['context']));
    }

    /**
     * Ten 1100 × 100 planks, each with a callback (so each its own type), across a 1000 wide deck allowing 100 at the
     * sides: the search puts them all at x = -100, the corner of its frame, and balancing would move them to x = -50.
     * The last one, if so asked, only accepts x <= -100.
     *
     * @return array{0: BlockPacker, 1: list<ConstrainedPlacementRecordingTestItem>}
     */
    private static function planks(bool $lastRefusesToMove): array
    {
        $planks = [];
        $items = new ItemList();
        for ($i = 1; $i <= 10; ++$i) {
            $planks[] = $plank = $lastRefusesToMove && $i === 10
                ? new ConstrainedPlacementMaxXTestItem("Plank {$i}", 1100, 100, 100, 1, Rotation::Never, -100)
                : new ConstrainedPlacementRecordingTestItem("Plank {$i}", 1100, 100, 100, 1, Rotation::Never);
            $items->insert($plank);
        }
        $blockPacker = new BlockPacker(self::pallet(new AllowedOverhang(sides: 100), 1000, 1000, 100), $items);
        $blockPacker->setMinimumSupport(1.0);
        $blockPacker->setMaxBeamWidth(1);

        return [$blockPacker, $planks];
    }

    /**
     * Pack with the block search alone, noting how many times each recorder had been asked, and the trial placements
     * the search reported, when the search finished.
     *
     * @param  list<ConstrainedPlacementRecordingTestItem> $recorders
     * @return array{0: PackedBox, 1: list<int>, 2: int}
     */
    private static function packNotingTheEndOfTheSearch(BlockPacker $blockPacker, array $recorders): array
    {
        $logger = new class($recorders) extends AbstractLogger {
            /**
             * @var list<int>
             */
            public array $calls = [];

            public int $placements = -1;

            /**
             * @param list<ConstrainedPlacementRecordingTestItem> $recorders
             */
            public function __construct(private readonly array $recorders)
            {
            }

            /**
             * @param mixed[] $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($message === 'Block search complete') {
                    $this->calls = array_map(static fn (ConstrainedPlacementRecordingTestItem $recorder) => count($recorder->calls), $this->recorders);
                    $this->placements = $context['placements'];
                }
            }
        };
        $blockPacker->setLogger($logger);
        $packedBox = $blockPacker->pack();

        return [$packedBox, $logger->calls, $logger->placements];
    }

    public function testCallbacksAreAskedAgainAtTheBalancedPositions(): void
    {
        [$blockPacker, $planks] = self::planks(false);
        [$packedBox, $callsAtEndOfSearch, $placements] = self::packNotingTheEndOfTheSearch($blockPacker, $planks);

        self::assertCount(10, $packedBox->items);
        self::assertValid($packedBox);
        self::assertSame([-50, 1050], array_slice(self::extents($packedBox), 0, 2));
        // each plank is asked once more after the search, at its final position, with only the planks placed before it
        // in the box, at their final positions: the same order and context as during the search
        $final = [];
        foreach ($packedBox->items as $packedItem) {
            $final[$packedItem->item->getDescription()] = self::describe([$packedItem])[0];
        }
        $replays = [];
        foreach ($planks as $index => $plank) {
            self::assertCount($callsAtEndOfSearch[$index] + 1, $plank->calls);
            ['proposal' => $proposal, 'context' => $context] = $plank->calls[count($plank->calls) - 1];
            self::assertSame($final[$plank->getDescription()], self::describe([new PackedItem($plank, ...$proposal)])[0]);
            $replays[] = [$plank->getDescription(), self::describe($context)];
        }
        usort($replays, static fn (array $a, array $b): int => count($a[1]) <=> count($b[1]));
        $before = [];
        foreach ($replays as [$description, $context]) {
            sort($before);
            self::assertSame($before, $context);
            $before[] = $final[$description];
        }
        // and these calls are not counted against the search budget
        self::assertSame($placements, $blockPacker->getPlacements());
    }

    public function testARefusedBalancedPositionKeepsThePackingWhereItWasFound(): void
    {
        [$blockPacker, $planks] = self::planks(true);
        [$packedBox] = self::packNotingTheEndOfTheSearch($blockPacker, $planks);

        // valid, but not balanced: the last plank refused x = -50, so every plank stays at -100, as found
        self::assertCount(10, $packedBox->items);
        self::assertValid($packedBox, balanced: false);
        self::assertNotSame([], PackingValidator::centringProblems($packedBox));
        foreach ($packedBox->items as $packedItem) {
            self::assertSame(-100, $packedItem->x);
        }
        $refusal = $planks[9]->calls[count($planks[9]->calls) - 1]['proposal'];
        self::assertSame(-50, $refusal[0]);
    }

    public function testNoMoveNoFurtherCallbacks(): void
    {
        // balanced as found (two 550s hang 50 over each side): nobody is asked again
        $halves = [];
        $items = new ItemList();
        foreach ([1, 2] as $i) {
            $halves[] = $half = new ConstrainedPlacementRecordingTestItem("Half {$i}", 550, 500, 100, 1, Rotation::Never);
            $items->insert($half);
        }
        $blockPacker = new BlockPacker(self::pallet(new AllowedOverhang(sides: 50), 1000, 500, 100), $items);
        $blockPacker->setMinimumSupport(1.0);

        [$packedBox, $callsAtEndOfSearch] = self::packNotingTheEndOfTheSearch($blockPacker, $halves);

        self::assertValid($packedBox);
        self::assertSame([-50, 1050, 0, 500], self::extents($packedBox));
        self::assertSame($callsAtEndOfSearch, array_map(static fn (ConstrainedPlacementRecordingTestItem $half) => count($half->calls), $halves));
    }

    public function testFlatItems(): void
    {
        // a sheet that only fits with the overhang, hanging over each side equally on its own; one that fits the deck,
        // and an item with no size at all, at the corner; neither holds back the box, which the search puts in the
        // corner of its frame (100 over the left) and which is moved onto the deck
        $box = self::pallet(new AllowedOverhang(sides: 100), 1000, 1000, 100);
        $items = new ItemList();
        $items->insert(new TestItem('Sheet', 1100, 500, 0, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Deck sheet', 900, 500, 0, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Point', 0, 0, 0, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Box', 1000, 1000, 100, 1, Rotation::KeepFlat));

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            $packedBox = $pack($box, $items);
            self::assertCount(4, $packedBox->items);
            self::assertValid($packedBox);
            $positions = [];
            foreach ($packedBox->items as $packedItem) {
                $positions[$packedItem->item->getDescription()] = [$packedItem->x, $packedItem->y, $packedItem->z];
            }
            self::assertSame(['Box' => [0, 0, 0], 'Deck sheet' => [0, 0, 0], 'Point' => [0, 0, 0], 'Sheet' => [-50, 0, 0]], $positions);
        }
    }

    public function testFloorSupportCountsTheDeckAndTheCaps(): void
    {
        $box = self::pallet(new AllowedOverhang(sides: 50), 1000, 500, 200);
        $item = new TestItem('Half', 550, 500, 100, 1, Rotation::Never);

        $legal = new PackedItemList();
        $legal->insert(new PackedItem($item, -50, 0, 0, 550, 500, 100));
        self::assertSame(1.0, SupportCalculator::minimumSupport($legal, $box));

        $beyond = new PackedItemList();
        $beyond->insert(new PackedItem($item, -51, 0, 0, 550, 500, 100));
        self::assertEqualsWithDelta(549 / 550, SupportCalculator::minimumSupport($beyond, $box), 1e-9);
        self::assertSame(1.0, SupportCalculator::minimumSupport($beyond)); // without the box, floor items are not checked
        self::assertSame(1.0, SupportCalculator::minimumSupport($beyond, self::pallet(null, 1000, 500, 200)));

        // and at the far edge, by the same allowance
        $far = new PackedItemList();
        $far->insert(new PackedItem($item, 501, 0, 0, 550, 500, 100));
        self::assertEqualsWithDelta(549 / 550, SupportCalculator::minimumSupport($far, $box), 1e-9);
    }

    public function testFastNeverPacksLessThanTheDeckAlone(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('A', 300, 200, 150, 10, Rotation::BestFit), 7);
        $items->insert(new TestItem('B', 420, 260, 110, 10, Rotation::KeepFlat), 5);
        $items->insert(new TestItem('C', 120, 90, 60, 10, Rotation::BestFit), 20);
        $items->insert(new TestItem('D', 1050, 300, 100, 10, Rotation::KeepFlat), 2);

        foreach ([new AllowedOverhang(sides: 30, frontAndBack: 30), new AllowedOverhang(sides: 100), new AllowedOverhang(frontAndBack: 500)] as $allowance) {
            $deckOnly = self::fast(self::pallet(null, 1000, 800, 600), $items, 0.5);
            $packedBox = self::fast(self::pallet($allowance, 1000, 800, 600), $items, 0.5);
            self::assertValid($packedBox, 0.5);
            self::assertGreaterThanOrEqual($deckOnly->getUsedVolume(), $packedBox->getUsedVolume());
        }
    }

    public function testAllowancesBeyondTheLargestCapChangeNothing(): void
    {
        // the largest extent across any edge is D's 1050, whose cap of 157 is all that any item can use; with 1,000,000
        // the envelope would be far wider than MAX_FILLABLE_TABLE, and Thorough would lose its gap scoring
        $items = new ItemList();
        $items->insert(new TestItem('A', 300, 200, 150, 10, Rotation::BestFit), 7);
        $items->insert(new TestItem('B', 420, 260, 110, 10, Rotation::KeepFlat), 5);
        $items->insert(new TestItem('C', 120, 90, 60, 10, Rotation::BestFit), 20);
        $items->insert(new TestItem('D', 1050, 300, 100, 10, Rotation::KeepFlat), 2);
        $tight = self::pallet(new AllowedOverhang(sides: 157, frontAndBack: 157), 1000, 800, 600);
        $huge = self::pallet(new AllowedOverhang(sides: 1000000, frontAndBack: 1000000), 1000, 800, 600);

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            $packedBox = $pack($huge, $items, 0.5);
            self::assertValid($packedBox, 0.5);
            self::assertSame(self::sequence($pack($tight, $items, 0.5)), self::sequence($packedBox));
        }

        // the block search on its own (a VolumePacker could keep the layer packer's packing of the deck for both)
        $packings = [];
        foreach ([$tight, $huge] as $box) {
            $blockPacker = new BlockPacker($box, $items);
            $blockPacker->setMinimumSupport(0.5);
            $packedBox = $blockPacker->pack();
            self::assertValid($packedBox, 0.5);
            $packings[] = self::sequence($packedBox);
        }
        self::assertSame($packings[0], $packings[1]);
    }

    public function testStrictOrderingPacksTheDeckOnly(): void
    {
        $items = new ItemList();
        $items->insert(new TestItem('Long', 1100, 500, 100, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Small', 400, 400, 100, 1, Rotation::KeepFlat), 3);

        $plain = new VolumePacker(self::pallet(null, 1000, 1000, 500), $items);
        $plain->beStrictAboutItemOrdering(true);
        $overhang = new VolumePacker(self::pallet(new AllowedOverhang(sides: 50), 1000, 1000, 500), $items);
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
            $packer = new VolumePacker(self::pallet(new AllowedOverhang(sides: 50), 1000, 1000, 200), $items);
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

            $packedBox = $pack(self::pallet(new AllowedOverhang(sides: 50), 1000, 1000, 100), $items, 1.0, true);
            self::assertCount(3, $packedBox->items);
            self::assertValid($packedBox);
            foreach ($packedBox->items as $packedItem) {
                self::assertFalse($packedItem->isAngled());
            }
        }
    }

    public function testAngledItemsStayOnTheDeckBesideOverhangingOnes(): void
    {
        // the rods are too long to fit even with the overhang, so are angled, on the deck; the planks hang over. The
        // planks hang 15 (their cap) over the front, but the load cannot move back, as the rods reach the back edge of
        // the deck, which angled items may not pass
        $rod = new TestItem('Rod', 1150, 50, 60, 1, Rotation::KeepFlat);
        $items = new ItemList();
        $items->insert($rod, 2);
        $items->insert(new TestItem('Plank', 1080, 100, 60, 1, Rotation::KeepFlat), 2);
        $box = self::pallet(new AllowedOverhang(sides: 40, frontAndBack: 40), 1000, 1000, 60);
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
            self::assertSame(-15, self::extents($packedBox)[2]);
            self::assertSame(1000, self::extents($packedBox)[3]);
        }
    }

    public function testOnlyFitsAngledIsOverhangAware(): void
    {
        // 1100 across a 1000 deck needs 50 at each side
        $item = new TestItem('Long', 1100, 100, 100, 1, Rotation::KeepFlat);
        self::assertTrue(AngledGeometry::onlyFitsAngled($item, self::pallet(null, 1000, 1000, 100)));
        self::assertFalse(AngledGeometry::onlyFitsAngled($item, self::pallet(new AllowedOverhang(sides: 50), 1000, 1000, 100)));
        self::assertTrue(AngledGeometry::onlyFitsAngled($item, self::pallet(new AllowedOverhang(sides: 49), 1000, 1000, 100)));
    }

    private static function first(PackedBox $packedBox): PackedItem
    {
        return iterator_to_array($packedBox->items, false)[0];
    }

    /**
     * Where the items reach: [min x, max x, min y, max y], by their bounding boxes.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function extents(PackedBox $packedBox): array
    {
        $items = iterator_to_array($packedBox->items, false);

        return [
            min(array_map(static fn (PackedItem $item) => $item->x, $items)),
            max(array_map(static fn (PackedItem $item) => $item->x + $item->boundingWidth, $items)),
            min(array_map(static fn (PackedItem $item) => $item->y, $items)),
            max(array_map(static fn (PackedItem $item) => $item->y + $item->boundingLength, $items)),
        ];
    }

    /**
     * @param  iterable<PackedItem> $items
     * @return list<string>         sorted
     */
    private static function describe(iterable $items): array
    {
        $descriptions = [];
        foreach ($items as $item) {
            $descriptions[] = "{$item->item->getDescription()} {$item->x},{$item->y},{$item->z} {$item->width}x{$item->length}x{$item->depth}";
        }
        sort($descriptions);

        return $descriptions;
    }

    /**
     * @param  list<string> $except descriptions of items to leave out
     * @return list<string>
     */
    private static function placements(PackedBox $packedBox, array $except = []): array
    {
        return self::describe(array_filter(iterator_to_array($packedBox->items, false), static fn (PackedItem $item) => !in_array($item->item->getDescription(), $except, true)));
    }

    /**
     * Placements in the order they were packed.
     *
     * @return list<string>
     */
    private static function sequence(PackedBox $packedBox): array
    {
        return array_map(static fn (PackedItem $item) => "{$item->item->getDescription()} {$item->x},{$item->y},{$item->z} {$item->width}x{$item->length}x{$item->depth}", iterator_to_array($packedBox->items, false));
    }
}
