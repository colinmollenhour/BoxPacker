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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function explode;
use function file;
use function ini_set;
use function sprintf;

use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;

/**
 * Regression baseline for PackingStrategy::Thorough (default settings) on the published sets: Loh/Nee and BR1-15
 * (single container, utilisation) and Ivancic (containers needed). The search is deterministic, so every instance
 * must reproduce its recorded result and pass the independent validity checks. Excluded from the default run (it
 * takes a while); run with --group efficiency-thorough.
 */
class PublishedThoroughTest extends TestCase
{
    use PublishedInstanceSupport;

    /**
     * @var array<string, int>
     */
    private static array $expectedIvancic = [];

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '-1');
        self::loadUtilisationExpected('published-expected-thorough.csv');
        foreach (file(__DIR__ . '/data/ivancic-expected-thorough.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$problem, $containers] = explode(',', $line);
            self::$expectedIvancic[$problem] = (int) $containers;
        }
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

    #[DataProvider('ivancicData')]
    #[Group('efficiency')]
    #[Group('efficiency-thorough')]
    public function testIvancic(string $problem, Box $box, ItemList $items): void
    {
        $packedBoxes = Strategies::multi('thorough', [$box], $items, []);

        $packed = 0;
        foreach ($packedBoxes as $packedBox) {
            $packed += $packedBox->items->count();
            self::assertSame([], PackingValidator::problems($packedBox));
            self::assertGreaterThanOrEqual(0.5, PackingValidator::minimumSupport($packedBox));
        }
        self::assertSame($items->count(), $packed);
        self::assertSame(self::$expectedIvancic[$problem], $packedBoxes->count());
    }

    public static function ivancicData(): array
    {
        $data = [];
        foreach (self::decodeInstanceFile('ivancic.txt') as $problem) {
            $name = "Ivancic #{$problem[0]}";
            $data[$name] = [$name, $problem[1], $problem[2]];
        }

        return $data;
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
