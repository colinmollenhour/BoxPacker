<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\TestCase;

use function rad2deg;

class AngledGeometryTest extends TestCase
{
    public function testMinimumAngleTouchesBothWalls(): void
    {
        // a 3-4-5 case: turned by 36.87°, 110 × 20 spans exactly 110·0.8 + 20·0.6 = 100
        $angle = AngledGeometry::minimumAngle(110, 20, 100);
        self::assertNotNull($angle);
        self::assertEqualsWithDelta(36.8699, rad2deg($angle), 0.0001);

        self::assertSame(0.0, AngledGeometry::minimumAngle(100, 20, 100)); // fits straight
        self::assertNull(AngledGeometry::minimumAngle(110, 101, 100)); // too wide either way
        self::assertNull(AngledGeometry::minimumAngle(1000, 100, 100)); // would need to turn all the way to 90°
        self::assertEqualsWithDelta(89.4, rad2deg(AngledGeometry::minimumAngle(1000, 90, 100)), 0.1); // nearly
    }

    public function testLayout(): void
    {
        $layout = AngledGeometry::layout(110, 20, 100);
        self::assertNotNull($layout);
        self::assertSame(100, $layout['width']);
        self::assertSame(82, $layout['itemLength']); // 110·0.6 + 20·0.8
        self::assertSame(25, $layout['pitch']); // 20 / 0.8

        self::assertNull(AngledGeometry::layout(90, 20, 100));
    }

    public function testLayoutNeverOvershootsTheSpan(): void
    {
        for ($long = 101; $long <= 400; $long += 7) {
            for ($short = 1; $short <= 60; $short += 3) {
                $layout = AngledGeometry::layout($long, $short, 100);
                if ($layout === null) {
                    continue;
                }
                self::assertLessThanOrEqual(100, $layout['width']);
                [$width, $length] = AngledGeometry::boundingBox($long, $short, rad2deg($layout['angle']));
                self::assertSame($layout['width'], $width);
                self::assertSame($layout['itemLength'], $length);
            }
        }
    }

    public function testParallelItemsAtThePitchTouchButDoNotOverlap(): void
    {
        $layout = AngledGeometry::layout(110, 20, 100);
        $angle = rad2deg($layout['angle']);
        $first = AngledGeometry::corners(0, 0, 110, 20, $angle);

        self::assertFalse(AngledGeometry::polygonsOverlap($first, AngledGeometry::corners(0, 25, 110, 20, $angle)));
        self::assertTrue(AngledGeometry::polygonsOverlap($first, AngledGeometry::corners(0, 24, 110, 20, $angle)));
        self::assertEqualsWithDelta(2200, AngledGeometry::area($first), 0.001);
    }

    public function testIntersectionArea(): void
    {
        $square = AngledGeometry::corners(0, 0, 10, 10, 0.0);
        self::assertEqualsWithDelta(25, AngledGeometry::intersectionArea($square, AngledGeometry::corners(5, 5, 10, 10, 0.0)), 0.0001);
        self::assertEqualsWithDelta(0, AngledGeometry::intersectionArea($square, AngledGeometry::corners(10, 0, 10, 10, 0.0)), 0.0001);

        // a diamond (square turned 45°) inside its bounding square covers half of it
        $diamond = AngledGeometry::corners(0, 0, 10, 10, 45.0);
        $bounds = AngledGeometry::corners(0, 0, 15, 15, 0.0);
        self::assertEqualsWithDelta(100, AngledGeometry::intersectionArea($diamond, $bounds), 0.0001);
    }

    public function testSupportAndCornerRectanglesAvoidTheItems(): void
    {
        $layout = AngledGeometry::layout(110, 10, 100);
        $angle = rad2deg($layout['angle']);
        $count = 3;
        $length = $layout['itemLength'] + ($count - 1) * $layout['pitch'];
        $outline = AngledGeometry::groupOutline($layout, 110, 10, $count);
        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            $items[] = AngledGeometry::corners(0, $i * $layout['pitch'], 110, 10, $angle);
        }

        $supportArea = 0;
        foreach (AngledGeometry::supportRectangles($items, 0, $length) as [$x, $y, $width, $rectangleLength]) {
            $rectangle = AngledGeometry::corners($x, $y, $width, $rectangleLength, 0.0);
            $underItems = 0;
            foreach ($items as $item) {
                $underItems += AngledGeometry::intersectionArea($rectangle, $item);
            }
            self::assertEqualsWithDelta($width * $rectangleLength, $underItems, 0.001); // wholly on top of the items
            $supportArea += $width * $rectangleLength;
        }
        self::assertGreaterThan(0.4 * 3 * 1100, $supportArea);

        $corners = AngledGeometry::cornerRectangles($outline, $layout['width'], $length);
        self::assertCount(12, $corners);
        foreach ($corners as [$x, $y, $width, $rectangleLength]) {
            self::assertGreaterThanOrEqual(0, $x);
            self::assertGreaterThanOrEqual(0, $y);
            self::assertLessThanOrEqual($layout['width'], $x + $width);
            self::assertLessThanOrEqual($length, $y + $rectangleLength);
            $rectangle = AngledGeometry::corners($x, $y, $width, $rectangleLength, 0.0);
            foreach ($items as $item) {
                self::assertFalse(AngledGeometry::polygonsOverlap($rectangle, $item));
            }
        }
    }

    public function testPackedItemBoundingBox(): void
    {
        $item = new TestItem('Rod', 110, 20, 10, 1, Rotation::KeepFlat);
        $straight = new PackedItem($item, 0, 0, 0, 110, 20, 10);
        self::assertFalse($straight->isAngled());
        self::assertSame(110, $straight->boundingWidth);
        self::assertSame(20, $straight->boundingLength);

        $angled = new PackedItem($item, 0, 0, 0, 110, 20, 10, rad2deg(AngledGeometry::minimumAngle(110, 20, 100)));
        self::assertTrue($angled->isAngled());
        self::assertSame(100, $angled->boundingWidth);
        self::assertSame(82, $angled->boundingLength);
        self::assertSame(22000, $angled->volume);
        self::assertArrayHasKey('angle', $angled->jsonSerialize());
        self::assertArrayNotHasKey('angle', $straight->jsonSerialize());
    }
}
