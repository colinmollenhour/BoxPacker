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
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

use function array_column;
use function array_filter;
use function array_map;
use function array_search;
use function array_slice;
use function count;
use function in_array;
use function ini_get;
use function ini_set;
use function intdiv;
use function iterator_to_array;
use function max;
use function min;
use function sort;
use function spl_object_id;
use function str_replace;
use function str_starts_with;

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

    private static function pallet(?AllowedOverhang $allowance, int $width = self::PALLET[0], int $length = self::PALLET[1], int $depth = self::PALLET[2], int $maxWeight = self::NO_WEIGHT_LIMIT): Box
    {
        return $allowance === null
            ? new TestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, $maxWeight)
            : new OverhangTestBox('Pallet', $width, $length, $depth, 0, $width, $length, $depth, $maxWeight, $allowance);
    }

    /**
     * The same item $quantity times (so all of one type), with a placement callback that accepts every placement and
     * records it if $recording (see {@see packNotingWhereTheSearchPlacedIt()}).
     */
    private static function trampolines(int $quantity, bool $recording = false): ItemList
    {
        $items = new ItemList();
        $items->insert($recording
            ? new ConstrainedPlacementRecordingTestItem('ACME Trampolines', ...[...self::ACME_TRAMPOLINES, 1, Rotation::BestFit])
            : new TestItem('ACME Trampolines', ...[...self::ACME_TRAMPOLINES, 1, Rotation::BestFit]), $quantity);

        return $items;
    }

    private static function thorough(Box $box, ItemList $items, float $support = 1.0, bool $angled = false, ?LoggerInterface $logger = null): PackedBox
    {
        $packer = new VolumePacker($box, $items);
        if ($logger !== null) {
            $packer->setLogger($logger);
        }
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setMinimumSupport($support);
        $packer->setAllowAngledPlacement($angled);

        return $packer->pack();
    }

    private static function fast(Box $box, ItemList $items, float $support = 1.0, bool $angled = false, ?LoggerInterface $logger = null): PackedBox
    {
        $packer = new VolumePacker($box, $items);
        if ($logger !== null) {
            $packer->setLogger($logger);
        }
        $packer->setMinimumSupport($support);
        $packer->setAllowAngledPlacement($angled);

        return $packer->pack();
    }

    /**
     * Physically valid, supported well enough and (unless a placement callback refused to let it move) balanced and on
     * the deck along every axis along which its load fits there.
     */
    private static function assertValid(PackedBox $packedBox, float $support = 1.0, bool $balanced = true): void
    {
        self::assertSame([], PackingValidator::problems($packedBox));
        self::assertGreaterThanOrEqual($support, PackingValidator::minimumSupport($packedBox));
        if ($balanced) {
            self::assertSame([], PackingValidator::centringProblems($packedBox));
            self::assertSame([], PackingValidator::avoidableOverhangProblems($packedBox));
        }
    }

    /**
     * Pack, and note where the load was before it was moved to keep it on the deck and balance its overhang: where the
     * search itself placed it, rather than worked out from the packing returned and the moves logged.
     *
     * Each item with a volume must be a {@see ConstrainedPlacementRecordingTestItem} (the items of one type may be one
     * object, inserted more than once). Its answer does not depend on the position, so it does not change the packing.
     * The items are packed twice: as usual, and with those callbacks refusing whatever they are asked from the end of
     * the block search until a layer packer starts, which is only the moved positions, with the gaps closed and with
     * the load moved as a whole (the search and the layer packers ask before or after). Refusing both keeps the packing
     * where the search placed it (see {@see testRefusingEveryMoveKeepsThePackingAsFound()}), with the same volume and
     * number of items, so the packing kept (the block search's, or the layer packer's, which is never moved) is the
     * same as the first time but for the moves. Each of its items must also be somewhere its callback accepted the
     * second time.
     *
     * Items placed at an angle cannot have placement callbacks (see {@see BlockPacker::setAllowAngledPlacement()}):
     * they are taken where the second packing has them, which is where the search placed them as long as an item with a
     * recording callback refuses every move, so at least one item must have one. Items with no volume are left out (the
     * search places them after the move), and their callbacks never refuse.
     *
     * @param  callable(Box, ItemList, float=, bool=, ?LoggerInterface=): PackedBox $pack
     * @return array{0: PackedBox, 1: list<PackedItem>}                             the packing, and the load as the search placed it
     */
    private static function packNotingWhereTheSearchPlacedIt(callable $pack, Box $box, ItemList $items): array
    {
        $recorders = [];
        foreach ($items as $item) {
            if ($item instanceof ConstrainedPlacementRecordingTestItem && $item->getWidth() * $item->getLength() * $item->getDepth() > 0) {
                $recorders[spl_object_id($item)] = $item;
            }
        }
        $packedBox = $pack($box, $items);

        $logger = new class($recorders) extends AbstractLogger {
            /**
             * For each recorder, the calls it refused: [first, last + 1] of each run of them.
             *
             * @var array<int, list<array{0: int, 1: int}>>
             */
            public array $refused = [];

            /**
             * @param array<int, ConstrainedPlacementRecordingTestItem> $recorders
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
                    foreach ($this->recorders as $id => $recorder) {
                        if (!$recorder->refusing) {
                            $recorder->refusing = true;
                            $this->refused[$id][] = [count($recorder->calls), count($recorder->calls)];
                        }
                    }
                } elseif (str_starts_with((string) $message, '[EVALUATING BOX]')) {
                    $this->stopRefusing();
                }
            }

            public function stopRefusing(): void
            {
                foreach ($this->recorders as $id => $recorder) {
                    if ($recorder->refusing) {
                        $recorder->refusing = false;
                        $this->refused[$id][count($this->refused[$id]) - 1][1] = count($recorder->calls);
                    }
                }
            }
        };
        $start = array_map(static fn (ConstrainedPlacementRecordingTestItem $recorder): int => count($recorder->calls), $recorders);
        try {
            $asFound = $pack($box, $items, 1.0, false, $logger);
        } finally {
            $logger->stopRefusing();
        }

        $before = [];
        $recorded = false;
        foreach ($asFound->items as $packedItem) {
            if ($packedItem->width * $packedItem->length * $packedItem->depth === 0) {
                continue;
            }
            $id = spl_object_id($packedItem->item);
            if ($packedItem->isAngled() && !isset($recorders[$id])) {
                $before[] = $packedItem;
                continue;
            }
            self::assertArrayHasKey($id, $recorders, "{$packedItem->item->getDescription()} has no recording placement callback");
            $recorded = true;
            $accepted = [];
            foreach (array_slice($recorders[$id]->calls, $start[$id], null, true) as $index => $call) {
                foreach ($logger->refused[$id] ?? [] as [$first, $end]) {
                    if ($index >= $first && $index < $end) {
                        continue 2;
                    }
                }
                $accepted[] = $call['proposal'];
            }
            self::assertContains([$packedItem->x, $packedItem->y, $packedItem->z, $packedItem->width, $packedItem->length, $packedItem->depth], $accepted, 'its callback accepted it there');
            $before[] = $packedItem;
        }
        self::assertTrue($recorded, 'no item packed has a recording placement callback to refuse the moves');

        return [$packedBox, $before];
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
            // Thorough mixes orientations where a uniform grid cannot: 8 flat (4953 × 2252 × 756) and beside them 2 × 2 on
            // their long edges (4953 × 756 × 2252), so 12, wholly on the deck front to back (see
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
        [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt($pack, self::pallet($allowance), self::trampolines(24, true));

        self::assertValid($packedBox);
        self::assertSame([-$allowance->sides, 4953 - $allowance->sides, -300, 4204], self::extents($before));
        self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
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

        // a second packing (of items without placement callbacks) is the same, and a balanced packing needs no further
        // move
        self::assertSame(self::sequence($packedBox), self::sequence($pack(self::pallet($allowance), self::trampolines(24))));
        $bounds = OverhangBounds::forBox($packedBox->box);
        self::assertNotNull($bounds);
        self::assertSame([0, 0], $bounds->centringShift($packedBox->items));
    }

    public function testAcmeTrampolinesThoroughMixesOrientations(): void
    {
        [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt(self::thorough(...), self::pallet(new AllowedOverhang(sides: 250, frontAndBack: 250)), self::trampolines(24, true));

        self::assertValid($packedBox);
        // the search builds it from the corner of its frame: 8 flat, hanging the whole 2.5" over the left and the front,
        // and 2 rows of 2 on their long edges anchored at the back, the back row hanging 1.13" (its cap, 15 % of 7.56")
        // over the back edge, with a gap of 5.99" between the two blocks. Moving the whole load could not help along y
        // (the flat ones are at their cap at the front and the back row at its cap at the back), but the load is only
        // 37.64" (22.52" + 2 × 7.56") from front to back
        $flat = $onEdge = [];
        foreach ([0, 756, 1512, 2268, 3024, 3780, 4536, 5292] as $z) {
            $flat[] = "ACME Trampolines -250,-250,{$z} 4953x2252x756";
        }
        foreach ([[2601, 0], [3357, 0], [2601, 2252], [3357, 2252]] as [$y, $z]) {
            $onEdge[] = "ACME Trampolines -250,{$y},{$z} 4953x756x2252";
        }
        $expected = [...$flat, ...$onEdge];
        sort($expected);
        self::assertSame($expected, self::describe($before));
        self::assertSame([-250, 4703, -250, 4113], self::extents($before));
        // so the gap is closed, moving the rows on their edges 5.99" forward against the flat ones, and the closed load
        // balanced: along x as the 16 are (0.77" and 0.76" over the sides), along y onto the deck, with gaps of 1.18" at
        // the front and 1.18" at the back
        self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
        $flat = $onEdge = [];
        foreach ([0, 756, 1512, 2268, 3024, 3780, 4536, 5292] as $z) {
            $flat[] = "ACME Trampolines -77,118,{$z} 4953x2252x756";
        }
        foreach ([[2370, 0], [3126, 0], [2370, 2252], [3126, 2252]] as [$y, $z]) {
            $onEdge[] = "ACME Trampolines -77,{$y},{$z} 4953x756x2252";
        }
        $expected = [...$flat, ...$onEdge];
        sort($expected);
        self::assertSame($expected, self::placements($packedBox));
        self::assertSame([-77, 4876, 118, 3882], self::extents($packedBox));
        $bounds = OverhangBounds::forBox($packedBox->box);
        self::assertNotNull($bounds);
        self::assertSame(250, $bounds->endCap(2252));
        self::assertSame(113, $bounds->endCap(756));
        self::assertSame([0, 0], $bounds->centringShift($packedBox->items));
    }

    public function testAcmeTrampolinesFastKeepsTheDeckOnlyPackingWhereItIsAsGood(): void
    {
        // the layer packer's 10 on the deck tie with the greedy block search's 10, so they are kept, where they were
        // packed: nothing hangs over, so nothing is moved, gaps and all. So the load before any move is the layer
        // packer's too, not the block search's (whether or not that was moved)
        [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt(self::fast(...), self::pallet(new AllowedOverhang(sides: 250, frontAndBack: 250)), self::trampolines(24, true));

        self::assertValid($packedBox);
        self::assertSame(self::placements(self::fast(self::pallet(null), self::trampolines(24))), self::placements($packedBox));
        self::assertSame([0, 4536, 0, 3764], self::extents($packedBox));
        self::assertSame(self::placements($packedBox), self::describe($before));
        self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
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
        $items->insert(new ConstrainedPlacementRecordingTestItem('Long', 1050, 500, 100, 1, Rotation::Never));

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt($pack, self::pallet(new AllowedOverhang(sides: 100), 1000, 1000, 100), $items);
            self::assertValid($packedBox);
            self::assertSame([-25, 1025, 0, 500], self::extents($packedBox));
            self::assertSame([-100, 950, 0, 500], self::extents($before));
            self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
        }
    }

    public function testALoadThatFitsTheDeckIsMovedOntoIt(): void
    {
        // a 12" cube hangs 1.8" (its cap) over the left and the front where the search puts it, in the corner of its
        // frame; it fits the pallet, so is moved onto it, with equal gaps (counted as negative overhangs) each way
        $cube = new ConstrainedPlacementRecordingTestItem('Cube', 1200, 1200, 1200, 1, Rotation::BestFit);
        $items = new ItemList();
        $items->insert($cube);

        [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt(self::thorough(...), self::pallet(new AllowedOverhang(sides: 300, frontAndBack: 300)), $items);

        self::assertValid($packedBox);
        self::assertSame([1800, 3000, 1400, 2600], self::extents($packedBox));
        // checked against where the search placed it: the final position alone would not show a wrong move onto the
        // deck (see PackingValidator::centringProblems())
        self::assertSame([-180, 1020, -180, 1020], self::extents($before));
        self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
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
        // frame is wider (195 at each edge, the caps of a 1,300 plank, which can go in the box but not with all four of
        // them, as it would take the box over its weight limit), so blocks anchored at the far corners must stop at the
        // 520s' own caps, not at the edge of the frame. That leaves a gap of 116 between the two columns and between
        // the two rows, and closing it leaves 20 over each edge
        $box = self::pallet(new AllowedOverhang(sides: 200, frontAndBack: 300), 1000, 1000, 100, 100);
        $recorders = [];
        $items = new ItemList();
        foreach ([1, 2, 3, 4] as $weight) {
            $recorders[] = $recorder = new ConstrainedPlacementRecordingTestItem("Item {$weight}", 520, 520, 100, $weight, Rotation::Never);
            $items->insert($recorder);
        }
        $items->insert(new TestItem('Plank', 1300, 100, 100, 95, Rotation::KeepFlat));

        $packedBox = self::thorough($box, $items);

        self::assertCount(4, $packedBox->items);
        self::assertValid($packedBox);
        self::assertSame([-20, 1020, -20, 1020], self::extents($packedBox));
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
        // two 550 wide items side by side (of different weights, so placed one at a time) hang 50 over each side; an
        // item as wide as both, which may only go on top of something, rests on the two, fully supported, hanging over
        // by the same 50 (its own cap would allow 165)
        $box = self::pallet(new AllowedOverhang(sides: 50), 1000, 500, 200);
        $items = new ItemList();
        $items->insert(new TestItem('Half', 550, 500, 100, 1, Rotation::Never));
        $items->insert(new TestItem('Half', 550, 500, 100, 2, Rotation::Never));
        $items->insert(new class('Whole', 1100, 500, 100, 1, Rotation::Never) extends TestItem implements ConstrainedPlacementItem {
            public function canBePacked(PackedBox $packedBox, int $proposedX, int $proposedY, int $proposedZ, int $width, int $length, int $depth): bool
            {
                return $proposedZ > 0;
            }
        });

        $packedBox = self::thorough($box, $items);

        self::assertSame(['Half -50,0,0 550x500x100', 'Half 500,0,0 550x500x100', 'Whole -50,0,100 1100x500x100'], self::placements($packedBox));
        self::assertValid($packedBox);
        self::assertSame(1.0, SupportCalculator::minimumSupport($packedBox->items, $box));
    }

    public function testPartlyRefusedBlockIsShrunkWithinItsCaps(): void
    {
        // the constrained items' callback takes only 3; the block it is asked about sits at the far (right) end of the
        // space, which reaches 60 past the deck (the plain item's cap), far more than their cap of 18, so the smaller
        // block must be placed against their cap, not the end of the space. The plain item is at its cap at the left,
        // with a gap between the two blocks: closing it leaves the load (400 + 3 × 120) on the deck, centred
        ConstrainedPlacementByCountTestItem::$limit = 3; // its default, which other test classes change
        $box = self::pallet(new AllowedOverhang(sides: 200), 1000, 100, 100);
        $limitedItem = new class('Limited', 120, 100, 100, 1, Rotation::Never) extends ConstrainedPlacementByCountTestItem {
            /**
             * @var list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}>
             */
            public array $proposals = [];

            public function canBePacked(PackedBox $packedBox, int $proposedX, int $proposedY, int $proposedZ, int $width, int $length, int $depth): bool
            {
                $this->proposals[] = [$proposedX, $proposedY, $proposedZ, $width, $length, $depth];

                return parent::canBePacked($packedBox, $proposedX, $proposedY, $proposedZ, $width, $length, $depth);
            }
        };
        $items = new ItemList();
        $items->insert(new TestItem('Plain', 400, 100, 100, 1, Rotation::Never));
        $items->insert($limitedItem, 5);

        // the block search itself (a VolumePacker would keep the layer packer's packing of the deck, as dense)
        $blockPacker = new BlockPacker($box, $items);
        $blockPacker->setMinimumSupport(1.0);
        $packedBox = $blockPacker->pack();

        self::assertCount(4, $packedBox->items);
        self::assertValid($packedBox);
        // the shrunk block of 3, where the search placed it, ends at their cap, 1018
        self::assertContains([898, 0, 0, 120, 100, 100], $limitedItem->proposals);
        self::assertSame(1018, max(array_map(static fn (array $proposal): int => $proposal[0] + $proposal[3], $limitedItem->proposals)));
        self::assertSame(['Limited 520,0,0 120x100x100', 'Limited 640,0,0 120x100x100', 'Limited 760,0,0 120x100x100', 'Plain 120,0,0 400x100x100'], self::placements($packedBox));
    }

    public function testPlacementCallbacksAreAskedInTheDeckFrame(): void
    {
        // Big (1050 wide) only fits hanging over the sides, by 50 in all; Small (cap 30 across its 200 side) goes
        // against the left edge in the band; Sheet is flat, so placed at the end
        $box = self::pallet(new AllowedOverhang(sides: 50), 1000, 800, 300);
        $load = static function (): array {
            $big = new ConstrainedPlacementRecordingTestItem('Big', 1050, 300, 100, 1, Rotation::KeepFlat);
            $small = new ConstrainedPlacementRecordingTestItem('Small', 200, 150, 100, 1, Rotation::KeepFlat);
            $sheet = new ConstrainedPlacementRecordingTestItem('Sheet', 1050, 300, 0, 1, Rotation::KeepFlat);
            $items = new ItemList();
            $items->insert($big);
            $items->insert($small, 6);
            $items->insert($sheet);

            return [$items, $big, $small, $sheet];
        };

        // the search places the load in the corner of its frame, Big 50 and Smalls 30 (their cap) over the left edge,
        // and asks each callback about its item there: in the deck's frame, so at x < 0, not at x >= 0 as in the
        // search's own frame. The load is then moved by 25
        [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt(self::thorough(...), $box, $load()[0]);
        self::assertCount(8, $packedBox->items);
        self::assertValid($packedBox);
        $smalls = [];
        foreach ([-30, 170] as $x) {
            foreach ([0, 100, 200] as $z) {
                $smalls[] = "Small {$x},650,{$z} 200x150x100";
            }
        }
        self::assertSame(['Big -50,0,0 1050x300x100', ...$smalls], self::describe($before));
        self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));

        // and every item is asked about its final placement (after the search, as the load is moved), and every
        // packing a callback is asked about is a legal one
        [$items, $big, $small, $sheet] = $load();
        $packedBox = self::thorough($box, $items);
        foreach ($packedBox->items as $packedItem) {
            $recorder = $packedItem->item;
            self::assertInstanceOf(ConstrainedPlacementRecordingTestItem::class, $recorder);
            self::assertContains([$packedItem->x, $packedItem->y, $packedItem->z, $packedItem->width, $packedItem->length, $packedItem->depth], array_column($recorder->calls, 'proposal'));
        }
        foreach ([$big, $small, $sheet] as $recorder) {
            foreach ($recorder->calls as ['context' => $context]) {
                $contextItems = new PackedItemList();
                foreach ($context as $contextItem) {
                    $contextItems->insert($contextItem);
                }
                self::assertSame([], PackingValidator::problems(new PackedBox($box, $contextItems)));
            }
        }

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
     * Plank $refuser, if given, only accepts x <= -100.
     *
     * @return array{0: BlockPacker, 1: list<ConstrainedPlacementRecordingTestItem>}
     */
    private static function planks(?int $refuser = null): array
    {
        $planks = [];
        $items = new ItemList();
        for ($i = 1; $i <= 10; ++$i) {
            $planks[] = $plank = $i === $refuser
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
        [$blockPacker, $planks] = self::planks();
        [$packedBox, $callsAtEndOfSearch, $placements] = self::packNotingTheEndOfTheSearch($blockPacker, $planks);

        self::assertCount(10, $packedBox->items);
        self::assertValid($packedBox);
        self::assertSame([-50, 1050], array_slice(self::extents($packedBox), 0, 2));
        // each plank is asked once more after the search, at its final position, with the planks placed before it in
        // the box at their final positions: exactly what the search last asked it (where it placed it, with the same
        // planks in the same order), moved by 50
        $final = [];
        foreach ($packedBox->items as $packedItem) {
            $final[$packedItem->item->getDescription()] = self::describe([$packedItem])[0];
        }
        $before = [];
        foreach ($planks as $index => $plank) {
            self::assertCount($callsAtEndOfSearch[$index] + 1, $plank->calls);
            $found = $plank->calls[$callsAtEndOfSearch[$index] - 1];
            $replay = $plank->calls[$callsAtEndOfSearch[$index]];
            self::assertSame(-100, $found['proposal'][0]);
            self::assertSame(self::call($found, 50), self::call($replay));
            self::assertSame($final[$plank->getDescription()], self::describe([new PackedItem($plank, ...$replay['proposal'])])[0]);
            $before[] = new PackedItem($plank, ...$found['proposal']);
        }
        // the move, checked independently from where the search placed the planks
        self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
        // and these calls are not counted against the search budget
        self::assertSame($placements, $blockPacker->getPlacements());
    }

    /**
     * Where in the order of placement each plank is: how many planks were in the box when the search last asked it.
     *
     * @param  list<ConstrainedPlacementRecordingTestItem> $planks
     * @param  list<int>                                   $callsAtEndOfSearch
     * @return list<int>
     */
    private static function places(array $planks, array $callsAtEndOfSearch): array
    {
        return array_map(static fn (ConstrainedPlacementRecordingTestItem $plank, int $calls): int => count($plank->calls[$calls - 1]['context']), $planks, $callsAtEndOfSearch);
    }

    public function testARefusedBalancedPositionKeepsThePackingWhereItWasFound(): void
    {
        // the plank placed last refuses x = -50, so is the last asked again, after the nine placed before it have each
        // accepted
        [$blockPacker, $planks] = self::planks();
        $last = array_search(9, self::places($planks, self::packNotingTheEndOfTheSearch($blockPacker, $planks)[1]), true);
        self::assertIsInt($last);
        [$blockPacker, $planks] = self::planks($last + 1);
        [$packedBox, $callsAtEndOfSearch] = self::packNotingTheEndOfTheSearch($blockPacker, $planks);
        self::assertSame(9, self::places($planks, $callsAtEndOfSearch)[$last]);
        foreach ($planks as $index => $plank) {
            self::assertCount($callsAtEndOfSearch[$index] + 1, $plank->calls, $plank->getDescription());
            self::assertSame(-50, $plank->calls[$callsAtEndOfSearch[$index]]['proposal'][0]);
        }
        self::assertCount(9, $planks[$last]->calls[$callsAtEndOfSearch[$last]]['context']);

        // valid, but not balanced: every plank stays at -100, as found
        self::assertCount(10, $packedBox->items);
        self::assertValid($packedBox, balanced: false);
        self::assertNotSame([], PackingValidator::centringProblems($packedBox));
        foreach ($packedBox->items as $packedItem) {
            self::assertSame(-100, $packedItem->x);
        }
    }

    public function testARefusalPartWayThroughTheReplayStopsIt(): void
    {
        [$blockPacker, $planks] = self::planks(5);
        [$packedBox, $callsAtEndOfSearch] = self::packNotingTheEndOfTheSearch($blockPacker, $planks);

        $places = self::places($planks, $callsAtEndOfSearch);
        $refuserPlace = $places[4];
        self::assertGreaterThan(0, $refuserPlace);
        self::assertLessThan(9, $refuserPlace);

        // the planks placed before plank 5 have each been asked once more, at x = -50; plank 5 has been asked, and
        // refused; those placed after it are not asked again
        foreach ($planks as $index => $plank) {
            $replayed = $places[$index] <= $refuserPlace;
            self::assertCount($callsAtEndOfSearch[$index] + ($replayed ? 1 : 0), $plank->calls, $plank->getDescription());
            if ($replayed) {
                self::assertSame(-50, $plank->calls[$callsAtEndOfSearch[$index]]['proposal'][0]);
            }
        }

        // and every plank stays where the search placed it
        self::assertValid($packedBox, balanced: false);
        $found = array_map(static fn (ConstrainedPlacementRecordingTestItem $plank, int $calls): PackedItem => new PackedItem($plank, ...$plank->calls[$calls - 1]['proposal']), $planks, $callsAtEndOfSearch);
        self::assertSame(self::describe($found), self::placements($packedBox));
    }

    public function testThePackingKeepsItsOrderOfPlacement(): void
    {
        // the search places the heavier plank first, though it is the smaller, and an iterated PackedItemList puts the
        // larger first: working out the move must not reorder the packing, whether the packing is then moved (and so
        // rebuilt in the order of placement), kept where it was found because a placement callback refused the move,
        // or not moved because it is balanced as found. With assertions enabled, a PackedBox's own checks would
        // iterate (so sort) its list, so they are turned off while packing, if they can be (they cannot be turned on
        // or off if compiled out)
        $assertions = ini_get('zend.assertions');
        foreach ([[100, false, -50], [100, true, -100], [50, false, -50]] as [$sides, $lightRefuses, $left]) {
            $heavy = new ConstrainedPlacementRecordingTestItem('Heavy', 1100, 100, 100, 100, Rotation::Never);
            $light = $lightRefuses
                ? new ConstrainedPlacementMaxXTestItem('Light', 1000, 120, 100, 1, Rotation::Never, -100)
                : new ConstrainedPlacementRecordingTestItem('Light', 1000, 120, 100, 1, Rotation::Never);
            $items = new ItemList();
            $items->insert($heavy);
            $items->insert($light);
            $blockPacker = new BlockPacker(self::pallet(new AllowedOverhang(sides: $sides), 1000, 1000, 100), $items);
            $blockPacker->setMinimumSupport(1.0);
            if ($assertions === '1') {
                ini_set('zend.assertions', '0');
            }
            try {
                $packedBox = $blockPacker->pack();
                $order = $packedBox->items->asItemArray(); // before anything iterates the list
            } finally {
                if ($assertions === '1') {
                    ini_set('zend.assertions', '1');
                }
            }

            // the order of placement, from what each was last asked: Heavy first, with an empty box; and the packed
            // box lists its items in that order
            self::assertSame([[], [$heavy]], [array_column($heavy->calls[count($heavy->calls) - 1]['context'], 'item'), array_column($light->calls[count($light->calls) - 1]['context'], 'item')]);
            self::assertSame([$heavy, $light], $order);
            self::assertValid($packedBox, balanced: !$lightRefuses);
            self::assertSame($left, self::extents($packedBox)[0]);
        }
    }

    /**
     * 24 ACME Trampolines with a placement callback (all one Item object, so one type) at 2.5" / 2.5", for the block
     * search alone: it finds the mixed 12 as without the callback (see testAcmeTrampolinesThoroughMixesOrientations()).
     * Once the search has finished, the callback refuses the proposals $refuses picks; the logger notes how many calls
     * the callback had had, the trial placements the search reported, and the stage the packing was finalised at.
     *
     * @param  Closure(array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}): bool                                                    $refuses
     * @return array{0: BlockPacker, 1: ConstrainedPlacementRecordingTestItem, 2: object{calls: int, placements: int, stage: ?string}}
     */
    private static function constrainedTrampolines(Closure $refuses): array
    {
        $trampoline = new class('ACME Trampolines', ...[...self::ACME_TRAMPOLINES, 1, Rotation::BestFit]) extends ConstrainedPlacementRecordingTestItem {
            /**
             * @var (Closure(array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}): bool)|null
             */
            public ?Closure $refuses = null;

            public function canBePacked(PackedBox $packedBox, int $proposedX, int $proposedY, int $proposedZ, int $width, int $length, int $depth): bool
            {
                parent::canBePacked($packedBox, $proposedX, $proposedY, $proposedZ, $width, $length, $depth);

                return $this->refuses === null || !($this->refuses)([$proposedX, $proposedY, $proposedZ, $width, $length, $depth]);
            }
        };
        $items = new ItemList();
        $items->insert($trampoline, 24);
        $blockPacker = new BlockPacker(self::pallet(new AllowedOverhang(sides: 250, frontAndBack: 250)), $items);
        $blockPacker->setMinimumSupport(1.0);
        $logger = new class($trampoline, $refuses) extends AbstractLogger {
            public int $calls = -1;

            public int $placements = -1;

            public ?string $stage = null;

            public function __construct(private readonly object $trampoline, private readonly Closure $refuses)
            {
            }

            /**
             * @param mixed[] $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($message === 'Block search complete') {
                    $this->calls = count($this->trampoline->calls);
                    $this->placements = $context['placements'];
                    $this->trampoline->refuses = $this->refuses;
                } elseif ($message === 'Overhang finalised') {
                    $this->stage = $context['stage'];
                }
            }
        };
        $blockPacker->setLogger($logger);

        return [$blockPacker, $trampoline, $logger];
    }

    /**
     * The mixed 12's final positions with the gap closed, and moved as a whole (the search's positions moved 1.73"
     * along x alone).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function mixedTwelve(): array
    {
        $positions = [];
        foreach ([[118, 2370, 3126], [-250, 2601, 3357]] as $stage => [$flatY, $frontRowY, $backRowY]) {
            $placements = [];
            foreach ([0, 756, 1512, 2268, 3024, 3780, 4536, 5292] as $z) {
                $placements[] = "ACME Trampolines -77,{$flatY},{$z} 4953x2252x756";
            }
            foreach ([$frontRowY, $backRowY] as $y) {
                foreach ([0, 2252] as $z) {
                    $placements[] = "ACME Trampolines -77,{$y},{$z} 4953x756x2252";
                }
            }
            sort($placements);
            $positions[$stage] = $placements;
        }

        return $positions;
    }

    public function testARefusedClosedPositionFallsBackToMovingTheLoadAsAWhole(): void
    {
        // the callback refuses the flat ones' position with the gap closed (y = 1.18"), so the packing is moved as a
        // whole instead, as it would be without the gap closing: valid and balanced, but still hanging over front and
        // back, which the closing would have avoided
        [$blockPacker, $trampoline, $log] = self::constrainedTrampolines(static fn (array $proposal): bool => $proposal[1] === 118);
        $packedBox = $blockPacker->pack();

        self::assertCount(12, $packedBox->items);
        self::assertValid($packedBox, balanced: false);
        self::assertSame([], PackingValidator::centringProblems($packedBox));
        self::assertSame(['the load hangs over 250 and 113 along y, though its items\' extents along it come to 3764, which fits the deck\'s 4000'], PackingValidator::avoidableOverhangProblems($packedBox));
        self::assertSame('balanced', $log->stage);
        self::assertSame(self::mixedTwelve()[1], self::placements($packedBox));

        // after the search: the closed positions until the first refusal, then all 12 at the positions moved as a whole
        $replay = array_column(array_slice($trampoline->calls, $log->calls), 'proposal');
        $refusal = count($replay) - 12;
        self::assertGreaterThanOrEqual(1, $refusal);
        self::assertSame(118, $replay[$refusal - 1][1]);
        foreach (array_slice($replay, 0, $refusal - 1) as $proposal) {
            self::assertNotSame(118, $proposal[1]);
        }
        $asAWhole = self::describe(array_map(static fn (array $proposal): PackedItem => new PackedItem($trampoline, ...$proposal), array_slice($replay, $refusal)));
        self::assertSame(self::mixedTwelve()[1], $asAWhole);
        // none of which is counted against the search budget
        self::assertSame($log->placements, $blockPacker->getPlacements());
    }

    public function testRefusingEveryMoveKeepsThePackingAsFound(): void
    {
        // the callback refuses x = -0.77", where both the closed and the whole-load moves put every carton: each is
        // refused at its first call, and the packing stays where the search found it
        [$blockPacker, $trampoline, $log] = self::constrainedTrampolines(static fn (array $proposal): bool => $proposal[0] === -77);
        $packedBox = $blockPacker->pack();

        self::assertCount(12, $packedBox->items);
        self::assertValid($packedBox, balanced: false);
        self::assertNotSame([], PackingValidator::centringProblems($packedBox));
        self::assertNotSame([], PackingValidator::avoidableOverhangProblems($packedBox));
        self::assertSame('as found', $log->stage);
        self::assertSame([-250, 4703, -250, 4113], self::extents($packedBox));
        self::assertSame(array_map(static fn (string $placement): string => str_replace(' -77,', ' -250,', $placement), self::mixedTwelve()[1]), self::placements($packedBox));
        $replay = array_column(array_slice($trampoline->calls, $log->calls), 'proposal');
        self::assertCount(2, $replay);
        self::assertSame([-77, 118], array_slice($replay[0], 0, 2));
        self::assertSame(-77, $replay[1][0]);
        self::assertSame($log->placements, $blockPacker->getPlacements());
    }

    public function testAcceptedClosedPositionsAreAskedOnceEach(): void
    {
        // nothing refused: each carton is asked once more after the search, at its final position, with the gap closed
        [$blockPacker, $trampoline, $log] = self::constrainedTrampolines(static fn (array $proposal): bool => false);
        $packedBox = $blockPacker->pack();

        self::assertValid($packedBox);
        self::assertSame('gaps closed', $log->stage);
        self::assertSame(self::mixedTwelve()[0], self::placements($packedBox));
        $replay = array_slice($trampoline->calls, $log->calls);
        self::assertCount(12, $replay);
        self::assertSame(self::mixedTwelve()[0], self::describe(array_map(static fn (array $call): PackedItem => new PackedItem($trampoline, ...$call['proposal']), $replay)));
        // each with the cartons asked before it in the box, where they are finally placed
        foreach ($replay as $index => $call) {
            self::assertCount($index, $call['context']);
        }
        self::assertSame($log->placements, $blockPacker->getPlacements());
    }

    /**
     * The mixed 12 (see testAcmeTrampolinesThoroughMixesOrientations()), for the block search alone, with a sheet with
     * no volume that its placement callback places only if $accepts says the cartons already in the box allow.
     *
     * @param  Closure(list<PackedItem>): bool $accepts
     * @return array{0: PackedBox, 1: ?string}
     */
    private static function trampolinesWithASheet(Closure $accepts): array
    {
        $sheet = new class('Sheet', 4000, 3000, 0, 1, Rotation::KeepFlat, $accepts) extends TestItem implements ConstrainedPlacementItem {
            public function __construct(string $description, int $width, int $length, int $depth, int $weight, Rotation $allowedRotation, private readonly Closure $accepts)
            {
                parent::__construct($description, $width, $length, $depth, $weight, $allowedRotation);
            }

            public function canBePacked(PackedBox $packedBox, int $proposedX, int $proposedY, int $proposedZ, int $width, int $length, int $depth): bool
            {
                return ($this->accepts)(iterator_to_array($packedBox->items, false));
            }
        };
        $items = self::trampolines(24);
        $items->insert($sheet);
        $blockPacker = new BlockPacker(self::pallet(new AllowedOverhang(sides: 250, frontAndBack: 250)), $items);
        $blockPacker->setMinimumSupport(1.0);
        $logger = new class extends AbstractLogger {
            public ?string $stage = null;

            /**
             * @param mixed[] $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($message === 'Overhang finalised') {
                    $this->stage = $context['stage'];
                }
            }
        };
        $blockPacker->setLogger($logger);
        $packedBox = $blockPacker->pack();

        return [$packedBox, $logger->stage];
    }

    public function testClosingTheGapsNeverPlacesFewerFlatItems(): void
    {
        // the sheet refuses to go in with the cartons where closing the gap puts them, but not where moving the load as
        // a whole does: so the load is moved as a whole, and the sheet placed
        [$packedBox, $stage] = self::trampolinesWithASheet(static fn (array $context): bool => !in_array(118, array_map(static fn (PackedItem $item): int => $item->y, $context), true));

        self::assertCount(13, $packedBox->items);
        self::assertSame('balanced', $stage);
        self::assertSame(self::mixedTwelve()[1], self::placements($packedBox, ['Sheet']));
        self::assertValid($packedBox, balanced: false);
        self::assertSame([], PackingValidator::centringProblems($packedBox));

        // where it accepts both, the gap is closed
        [$packedBox, $stage] = self::trampolinesWithASheet(static fn (array $context): bool => true);
        self::assertCount(13, $packedBox->items);
        self::assertSame('gaps closed', $stage);
        self::assertSame(self::mixedTwelve()[0], self::placements($packedBox, ['Sheet']));
        self::assertValid($packedBox);

        // and where it refuses both, the gap is closed too, the sheet left out either way
        [$packedBox, $stage] = self::trampolinesWithASheet(static fn (array $context): bool => false);
        self::assertCount(12, $packedBox->items);
        self::assertSame('gaps closed', $stage);
        self::assertSame(self::mixedTwelve()[0], self::placements($packedBox));
    }

    public function testStacksOnBothSidesOfAGapKeepTheirSupport(): void
    {
        // as in testBlocksAreAnchoredAtAllFourCornersWithinTheirCaps(), but two of each, so a row of two in front and
        // one behind, each with another row on top, with a gap of 116 between front and back: closing it moves each
        // stack whole, so every upper item stays fully supported
        $box = self::pallet(new AllowedOverhang(sides: 200, frontAndBack: 300), 1000, 1000, 200, 100);
        $items = new ItemList();
        foreach ([1, 2, 3, 4] as $weight) {
            $items->insert(new ConstrainedPlacementRecordingTestItem("Item {$weight}", 520, 520, 100, $weight, Rotation::Never), 2);
        }
        $items->insert(new TestItem('Too heavy', 1300, 1300, 100, 1000, Rotation::Never));

        [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt(self::thorough(...), $box, $items);

        self::assertCount(8, $packedBox->items);
        self::assertValid($packedBox);
        self::assertSame(1.0, PackingValidator::minimumSupport($packedBox));
        self::assertSame([-78, 962, -78, 1078], self::extents($before));
        self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
        self::assertSame([
            'Item 1 -20,500,100 520x520x100', 'Item 1 500,500,100 520x520x100',
            'Item 2 -20,-20,100 520x520x100', 'Item 2 500,-20,100 520x520x100',
            'Item 3 -20,500,0 520x520x100', 'Item 3 500,500,0 520x520x100',
            'Item 4 -20,-20,0 520x520x100', 'Item 4 500,-20,0 520x520x100',
        ], self::placements($packedBox));
    }

    public function testOverhangThatIsNeededIsKept(): void
    {
        // a 600 (cap 90) and a 480 (cap 72) side by side come to 1080 on a 1000 deck: closing the gap between them
        // leaves 40 over each side, which they need, so both are still packed
        $items = new ItemList();
        $items->insert(new ConstrainedPlacementRecordingTestItem('Wide', 600, 500, 100, 1, Rotation::Never));
        $items->insert(new ConstrainedPlacementRecordingTestItem('Narrow', 480, 500, 100, 1, Rotation::Never));

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt($pack, self::pallet(new AllowedOverhang(sides: 100), 1000, 500, 100), $items);
            self::assertCount(2, $packedBox->items);
            self::assertValid($packedBox);
            self::assertSame([-90, 1072, 0, 500], self::extents($before));
            self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
            self::assertSame(['Narrow 560,0,0 480x500x100', 'Wide -40,0,0 600x500x100'], self::placements($packedBox));
        }

        // and two 550s with placement callbacks (so each its own block), 1100 on the same deck allowing 50: both
        $halves = new ItemList();
        foreach ([1, 2] as $i) {
            $halves->insert(new ConstrainedPlacementRecordingTestItem("Half {$i}", 550, 500, 100, 1, Rotation::Never));
        }
        $blockPacker = new BlockPacker(self::pallet(new AllowedOverhang(sides: 50), 1000, 500, 100), $halves);
        $blockPacker->setMinimumSupport(1.0);
        $packedBox = $blockPacker->pack();
        self::assertCount(2, $packedBox->items);
        self::assertValid($packedBox);
        self::assertSame([-50, 1050, 0, 500], self::extents($packedBox));
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
        $items->insert(new ConstrainedPlacementRecordingTestItem('Box', 1000, 1000, 100, 1, Rotation::KeepFlat));

        foreach ([self::thorough(...), self::fast(...)] as $pack) {
            [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt($pack, $box, $items);
            self::assertCount(4, $packedBox->items);
            self::assertValid($packedBox);
            self::assertSame([-100, 900, 0, 1000], self::extents($before));
            self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
            $positions = [];
            foreach ($packedBox->items as $packedItem) {
                $positions[$packedItem->item->getDescription()] = [$packedItem->x, $packedItem->y, $packedItem->z];
            }
            self::assertSame(['Box' => [0, 0, 0], 'Deck sheet' => [0, 0, 0], 'Point' => [0, 0, 0], 'Sheet' => [-50, 0, 0]], $positions);
        }
    }

    public function testItemsWithNoVolumeTakeNoSpace(): void
    {
        // an item with a zero width (or length) but a depth, at the corner of the deck, stands inside the footprint of
        // a solid moved to hang 50 over each side (or the front and the back): it takes no space, so does not collide
        foreach ([[[1100, 500, 100], [0, 500, 100], new AllowedOverhang(sides: 100), [-50, 0]], [[500, 1100, 100], [500, 0, 100], new AllowedOverhang(frontAndBack: 100), [0, -50]]] as [$solid, $flat, $allowance, $solidAt]) {
            $items = new ItemList();
            $items->insert(new TestItem('Solid', ...[...$solid, 1, Rotation::Never]));
            $items->insert(new TestItem('Flat', ...[...$flat, 1, Rotation::Never]));

            foreach ([self::thorough(...), self::fast(...)] as $pack) {
                $packedBox = $pack(self::pallet($allowance, 1000, 1000, 100), $items);
                self::assertValid($packedBox);
                self::assertSame(["Flat 0,0,0 {$flat[0]}x{$flat[1]}x100", "Solid {$solidAt[0]},{$solidAt[1]},0 {$solid[0]}x{$solid[1]}x100"], self::placements($packedBox));
            }
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

    /**
     * Ways of packing items in order, in a single pass or across the width only, which only the layer packer does.
     *
     * @return array<string, array{callable(VolumePacker): void}>
     */
    public static function layerPackerOnlyModes(): array
    {
        return [
            'strict item ordering' => [static fn (VolumePacker $packer) => $packer->beStrictAboutItemOrdering(true)],
            'across the width only' => [static fn (VolumePacker $packer) => $packer->packAcrossWidthOnly()],
            'single pass' => [static fn (VolumePacker $packer) => $packer->setSinglePassMode(true)],
        ];
    }

    /**
     * @param callable(VolumePacker): void $mode
     */
    #[DataProvider('layerPackerOnlyModes')]
    public function testModesOnlyTheLayerPackerHasPackTheDeckOnly(callable $mode): void
    {
        // with either strategy (Thorough does not apply to these modes either), as in the box without the overhang:
        // the item that only fits with it is left out, and no block search is made
        $items = new ItemList();
        $items->insert(new TestItem('Long', 1100, 500, 100, 1, Rotation::KeepFlat));
        $items->insert(new TestItem('Small', 400, 400, 100, 1, Rotation::KeepFlat), 3);

        $plain = new VolumePacker(self::pallet(null, 1000, 1000, 500), $items);
        $mode($plain);
        $plainBox = $plain->pack();
        foreach ([PackingStrategy::Fast, PackingStrategy::Thorough] as $strategy) {
            $overhang = new VolumePacker(self::pallet(new AllowedOverhang(sides: 50), 1000, 1000, 500), $items);
            $mode($overhang);
            $overhang->setStrategy($strategy);
            $overhangBox = $overhang->pack();
            self::assertValid($overhangBox, 0.0);
            self::assertSame(self::placements($plainBox), self::placements($overhangBox), $strategy->name);
            self::assertSame(0, $overhang->getSearchPlacements(), $strategy->name);
        }
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
        // the rods are too long to fit even with the overhang, so are angled, on the deck; the planks hang over the
        // sides. The search puts the planks 15 (their cap) over the front and the rods against the back edge of the
        // deck, which angled items may not pass, with a gap between them: closing it puts the load on the deck from
        // front to back, 42 from the front edge and 43 from the back, the rods still within the deck
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
            self::assertSame([-40, 1040, 42, 957], self::extents($packedBox));
        }
    }

    public function testAnAngledBlockMovesWithTheItemsInItsCorners(): void
    {
        // as in testAngledItemsStayOnTheDeckBesideOverhangingOnes(), with cubes in the empty corners of the rods'
        // bounding box: closing the gap moves the rods and the cubes in their corners together, still within the deck.
        // The rods have no placement callback, as they could then not be angled
        $items = new ItemList();
        $items->insert(new TestItem('Rod', 1150, 50, 60, 1, Rotation::KeepFlat), 2);
        $items->insert(new ConstrainedPlacementRecordingTestItem('Cube', 150, 150, 60, 1, Rotation::KeepFlat), 4);
        $items->insert(new ConstrainedPlacementRecordingTestItem('Plank', 1080, 100, 60, 1, Rotation::KeepFlat), 2);
        $box = self::pallet(new AllowedOverhang(sides: 40, frontAndBack: 40), 1000, 1000, 60);
        $angled = static fn (Box $box, ItemList $items, float $support = 1.0, bool $angled = false, ?LoggerInterface $logger = null): PackedBox => self::thorough($box, $items, $support, true, $logger);

        [$packedBox, $before] = self::packNotingWhereTheSearchPlacedIt($angled, $box, $items);

        self::assertCount(8, $packedBox->items);
        self::assertValid($packedBox);
        self::assertSame([], PackingValidator::shiftProblems($before, $packedBox));
        self::assertSame([-40, 1040, -15, 1000], self::extents($before));
        self::assertSame([-40, 1040, 42, 957], self::extents($packedBox));
        foreach ([$before, iterator_to_array($packedBox->items, false)] as $placed) {
            $byType = [];
            foreach ($placed as $packedItem) {
                $byType[$packedItem->item->getDescription()][] = $packedItem;
            }
            [$rodsX1, $rodsX2, $rodsY1, $rodsY2] = self::extents($byType['Rod']);
            self::assertGreaterThanOrEqual(0, $rodsX1);
            self::assertLessThanOrEqual(1000, $rodsX2);
            self::assertGreaterThanOrEqual(0, $rodsY1);
            self::assertLessThanOrEqual(1000, $rodsY2);
            foreach ($byType['Cube'] as $cube) {
                self::assertGreaterThanOrEqual($rodsX1, $cube->x);
                self::assertLessThanOrEqual($rodsX2, $cube->x + $cube->width);
                self::assertGreaterThanOrEqual($rodsY1, $cube->y);
                self::assertLessThanOrEqual($rodsY2, $cube->y + $cube->length);
            }
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
     * @param  PackedBox|list<PackedItem>            $packedBox
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function extents(PackedBox|array $packedBox): array
    {
        $items = $packedBox instanceof PackedBox ? iterator_to_array($packedBox->items, false) : $packedBox;

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
     * A placement callback's call (see {@see ConstrainedPlacementRecordingTestItem::$calls}) as text, moved along x by
     * the given amount: the proposal, and the items already in the box, in order.
     *
     * @param  array{proposal: array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}, context: list<PackedItem>} $call
     * @return array{0: string, 1: list<string>}
     */
    private static function call(array $call, int $moveX = 0): array
    {
        [$x, $y, $z, $width, $length, $depth] = $call['proposal'];

        return [
            ($x + $moveX) . ",{$y},{$z} {$width}x{$length}x{$depth}",
            array_map(static fn (PackedItem $item): string => "{$item->item->getDescription()} " . ($item->x + $moveX) . ",{$item->y},{$item->z} {$item->width}x{$item->length}x{$item->depth}", $call['context']),
        ];
    }

    /**
     * Placements in the order the list iterates them: by volume, then weight, largest first (iterating a PackedItemList
     * sorts it), items alike in both keeping their order in the list. So not necessarily the order they were packed in.
     *
     * @return list<string>
     */
    private static function sequence(PackedBox $packedBox): array
    {
        return array_map(static fn (PackedItem $item) => "{$item->item->getDescription()} {$item->x},{$item->y},{$item->z} {$item->width}x{$item->length}x{$item->depth}", iterator_to_array($packedBox->items, false));
    }
}
