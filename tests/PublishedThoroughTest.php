<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Benchmark\PackingValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function ini_set;
use function sprintf;

/**
 * Regression baseline for PackingStrategy::Thorough (default settings) on the published single-container sets:
 * Loh/Nee and BR1-15. The search is deterministic, so every instance must reproduce its recorded utilisation and
 * pass the independent validity checks. Excluded from the default run (it takes a while); run with
 * --group efficiency-thorough.
 */
class PublishedThoroughTest extends TestCase
{
    use PublishedInstanceSupport;

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '-1');
        self::loadUtilisationExpected('published-expected-thorough.csv');
    }

    #[DataProvider('publishedData')]
    #[Group('efficiency')]
    #[Group('efficiency-thorough')]
    public function testThorough(string $problem, Box $box, ItemList $items): void
    {
        $packer = new VolumePacker($box, $items);
        $packer->setStrategy(PackingStrategy::Thorough);
        $packedBox = $packer->pack();
        $volumeUtilisation = $packedBox->getVolumeUtilisation();

        self::appendLastrun(self::$utilisationLastrunPath, sprintf("%s,%.1f\n", $problem, $volumeUtilisation));

        self::assertSame([], PackingValidator::problems($packedBox));
        self::assertGreaterThanOrEqual(0.5, PackingValidator::minimumSupport($packedBox));
        self::assertEquals(self::$expectedResults[$problem], $volumeUtilisation);
    }

    public static function publishedData(): array
    {
        $data = [];
        foreach (self::decodeInstanceFile('loh-nee.txt') as $problem) {
            $name = "Loh and Nee #{$problem[0]}";
            $data[$name] = [$name, $problem[1], $problem[2]];
        }

        return $data + self::bischoffCases(1, 15);
    }
}
