<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Benchmark\PackingValidator;
use DVDoug\BoxPacker\Benchmark\Strategies;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function explode;
use function file;

use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;

/**
 * Regression baseline for PackingStrategy::Thorough (default settings, no weight balancing) on the bookshop order
 * corpus, with items rotating freely (3D) or lying flat (2D). Excluded from the default run; run with
 * --group efficiency-thorough.
 */
#[CoversNothing]
class BookShopThoroughTest extends TestCase
{
    #[DataProvider('getSamples')]
    #[Group('efficiency')]
    #[Group('efficiency-thorough')]
    public function testThorough(array $boxes, array $items, int $expectedBoxes2D, float $expectedUtilisation2D, int $expectedBoxes3D, float $expectedUtilisation3D): void
    {
        foreach ([[Rotation::KeepFlat, $expectedBoxes2D, $expectedUtilisation2D], [Rotation::BestFit, $expectedBoxes3D, $expectedUtilisation3D]] as [$rotation, $expectedBoxes, $expectedUtilisation]) {
            $itemList = new ItemList();
            foreach ($items as $item) {
                $itemList->insert(new TestItem($item['name'], $item['width'], $item['length'], $item['depth'], $item['weight'], $rotation), $item['qty']);
            }

            $packedBoxes = Strategies::multi('thorough', $boxes, $itemList, []);

            $packed = 0;
            foreach ($packedBoxes as $packedBox) {
                $packed += $packedBox->items->count();
                self::assertSame([], PackingValidator::problems($packedBox));
                self::assertGreaterThanOrEqual(0.5, PackingValidator::minimumSupport($packedBox));
            }
            self::assertSame($itemList->count(), $packed);
            self::assertCount($expectedBoxes, $packedBoxes);
            self::assertEquals($expectedUtilisation, $packedBoxes->getVolumeUtilisation());
        }
    }

    public static function getSamples(): array
    {
        $expected = [];
        foreach (file(__DIR__ . '/data/bookshop-expected-thorough.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = explode(',', $line);
            $expected[$row[0]] = [(int) $row[1], (float) $row[2], (int) $row[3], (float) $row[4]];
        }

        $tests = [];
        foreach (BookShopTest::getSamples() as $id => $sample) {
            $tests[$id] = [$sample['boxes'], $sample['items'], ...$expected[$id]];
        }

        return $tests;
    }
}
