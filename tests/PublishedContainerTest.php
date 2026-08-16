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
 * Pallet / small-container published cases: BR5–7 (12, 15, 20 item types).
 * Uses pack() rather than packBestSubset() so the default-excluded suite
 * stays a single pass per instance.
 */
class PublishedContainerTest extends TestCase
{
    use PublishedInstanceSupport;

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '-1');
        self::loadUtilisationExpected('published-expected-container.csv');
    }

    #[DataProvider('bischoffContainerData')]
    #[Group('efficiency')]
    #[Group('efficiency-container')]
    public function testBischoff($problem, $box, $items): void
    {
        self::runPublishedTestcase($problem, $box, $items, false);
    }

    public static function bischoffContainerData(): array
    {
        return self::bischoffCases(5, 7);
    }
}
