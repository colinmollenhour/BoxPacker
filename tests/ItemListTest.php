<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

#[CoversClass(ItemList::class)]
class ItemListTest extends TestCase
{
    /**
     * Test that sorting of items with different dimensions works as expected i.e.
     * - Largest (by volume) first
     * - If identical volume, sort by weight.
     */
    public function testDimensionalSorting(): void
    {
        $item1 = new TestItem('Small', 20, 20, 2, 100, Rotation::BestFit);
        $item2 = new TestItem('Large', 200, 200, 20, 1000, Rotation::BestFit);
        $item3 = new TestItem('Medium', 100, 100, 10, 500, Rotation::BestFit);
        $item4 = new TestItem('Medium Heavy', 100, 100, 10, 501, Rotation::BestFit);

        $list = new ItemList();
        $list->insert($item1);
        $list->insert($item2);
        $list->insert($item3);
        $list->insert($item4);

        $sorted = iterator_to_array($list, false);
        self::assertEquals([$item2, $item4, $item3, $item1], $sorted);
    }

    /**
     * Test that sorting of items with identical dimensions works as expected i.e.
     * - Items with the same name (i.e. same type) are kept together.
     */
    public function testKeepingItemsOfSameTypeTogether(): void
    {
        $item1 = new TestItem('Item A', 20, 20, 2, 100, Rotation::BestFit);
        $item2 = new TestItem('Item B', 20, 20, 2, 100, Rotation::BestFit);
        $item3 = new TestItem('Item A', 20, 20, 2, 100, Rotation::BestFit);
        $item4 = new TestItem('Item B', 20, 20, 2, 100, Rotation::BestFit);

        $list = new ItemList();
        $list->insert($item1);
        $list->insert($item2);
        $list->insert($item3);
        $list->insert($item4);

        $sorted = iterator_to_array($list, false);
        self::assertEquals([$item1, $item3, $item2, $item4], $sorted);
    }

    /**
     * Test that we can retrieve an accurate count of items in the list.
     */
    public function testCount(): void
    {
        $itemList = new ItemList();
        self::assertCount(0, $itemList);

        $item1 = new TestItem('Item A', 20, 20, 2, 100, Rotation::BestFit);
        $itemList->insert($item1);
        self::assertCount(1, $itemList);

        $item2 = new TestItem('Item B', 20, 20, 2, 100, Rotation::BestFit);
        $itemList->insert($item2);
        self::assertCount(2, $itemList);

        $item3 = new TestItem('Item C', 20, 20, 2, 100, Rotation::BestFit);
        $itemList->insert($item3);
        self::assertCount(3, $itemList);

        $itemList->remove($item2);
        self::assertCount(2, $itemList);
    }

    public function testGetVolume(): void
    {
        $itemList = new ItemList();
        self::assertSame(0, $itemList->getVolume());

        $itemList->insert(new TestItem('Item A', 20, 20, 2, 100, Rotation::BestFit));
        self::assertSame(800, $itemList->getVolume());

        $itemList->insert(new TestItem('Item B', 10, 10, 10, 100, Rotation::BestFit), 2);
        self::assertSame(2800, $itemList->getVolume());
    }

    /**
     * Test we can peek at the "top" (next) item in the list.
     */
    public function testTop(): void
    {
        $itemList = new ItemList();
        $item1 = new TestItem('Item A', 20, 20, 2, 100, Rotation::BestFit);
        $itemList->insert($item1);

        self::assertEquals($item1, $itemList->top());
        self::assertCount(1, $itemList);
    }

    /**
     * Test that we can retrieve an accurate count of items in the list.
     */
    public function testTopN(): void
    {
        $itemList = new ItemList();

        $item1 = new TestItem('Item A', 20, 20, 2, 100, Rotation::BestFit);
        $itemList->insert($item1);

        $item2 = new TestItem('Item B', 20, 20, 2, 100, Rotation::BestFit);
        $itemList->insert($item2);

        $item3 = new TestItem('Item C', 20, 20, 2, 100, Rotation::BestFit);
        $itemList->insert($item3);

        $top2 = $itemList->topN(2);

        self::assertCount(2, $top2);
        self::assertSame($item1, $top2->extract());
        self::assertSame($item2, $top2->extract());
    }

    /**
     * Test we can retrieve the "top" (next) item in the list.
     */
    public function testExtract(): void
    {
        $itemList = new ItemList();
        $item1 = new TestItem('Item A', 20, 20, 2, 100, Rotation::BestFit);
        $itemList->insert($item1);

        self::assertEquals($item1, $itemList->extract());
        self::assertCount(0, $itemList);
    }

    public function testWithItemsKeepsTheSorter(): void
    {
        $smallestFirst = new class implements ItemSorter {
            public function compare(Item $itemA, Item $itemB): int
            {
                return $itemA->getWidth() <=> $itemB->getWidth();
            }
        };
        $big = new TestItem('Big', 10, 10, 10, 10, Rotation::BestFit);
        $small = new TestItem('Small', 1, 1, 1, 1, Rotation::BestFit);
        $list = new ItemList($smallestFirst);

        $subset = $list->withItems([$big, $small]);

        self::assertSame([$small, $big], iterator_to_array($subset, false));
        self::assertSame([$big, $small], iterator_to_array(ItemList::fromArray([$small, $big]), false));
    }
}
