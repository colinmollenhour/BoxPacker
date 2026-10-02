<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use PHPUnit\Framework\TestCase;

class RotationTest extends TestCase
{
    public function testPermutations(): void
    {
        self::assertSame([[1, 2, 3]], Rotation::Never->permutations(1, 2, 3));
        self::assertSame([[1, 2, 3], [2, 1, 3]], Rotation::KeepFlat->permutations(1, 2, 3));
        self::assertSame([[2, 2, 3]], Rotation::KeepFlat->permutations(2, 2, 3));
        self::assertCount(6, Rotation::BestFit->permutations(1, 2, 3));
        self::assertCount(3, Rotation::BestFit->permutations(1, 1, 3));
        self::assertCount(3, Rotation::BestFit->permutations(1, 3, 1));
        self::assertCount(3, Rotation::BestFit->permutations(3, 1, 1));
        self::assertSame([[2, 2, 2]], Rotation::BestFit->permutations(2, 2, 2));
    }
}
