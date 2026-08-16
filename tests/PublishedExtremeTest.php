<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function ini_set;

/**
 * Strongly heterogeneous container sets: BR8–15 (30–100 item types).
 * Uses pack() rather than packBestSubset() so this suite stays a single
 * pass per instance.
 */
class PublishedExtremeTest extends TestCase
{
    use PublishedInstanceSupport;

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '-1');
        self::loadUtilisationExpected('published-expected-extreme.csv');
    }

    #[DataProvider('bischoffExtremeData')]
    #[Group('efficiency')]
    #[Group('efficiency-extreme')]
    public function testBischoff($problem, $box, $items): void
    {
        self::runPublishedTestcase($problem, $box, $items, false);
    }

    public static function bischoffExtremeData(): array
    {
        return self::bischoffCases(8, 15);
    }
}
