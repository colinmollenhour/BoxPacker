<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\BischoffConstrainedTestItem;
use DVDoug\BoxPacker\Test\BischoffTestItem;
use DVDoug\BoxPacker\Test\TestBox;

use function explode;
use function fclose;
use function feof;
use function fgetcsv;
use function fgets;
use function fopen;
use function is_array;
use function sprintf;
use function trim;

/**
 * Shared loader / runner for literature single-container and Ivancic cases.
 *
 * Concrete tests pick a BR range and expected-utilisation CSV so a default
 * PHPUnit run can omit the slow container/extreme sets without one process
 * rewriting a shared baseline file.
 *
 * Every run also writes the values it actually produced to a sibling
 * lastrun CSV (gitignored). Copy that file over the expected CSV to accept
 * a new baseline after a complete suite, not a --filter run.
 */
trait PublishedInstanceSupport
{
    use LastrunCsvSupport;

    /**
     * @var array<string, string>
     */
    protected static array $expectedResults = [];

    /**
     * @var array<string, int>
     */
    protected static array $expectedContainerCounts = [];

    protected static ?string $utilisationLastrunPath = null;

    protected static ?string $containerCountLastrunPath = null;

    protected static function loadUtilisationExpected(string $basename): void
    {
        $fp = fopen(__DIR__ . '/data/' . $basename, 'rb');
        while (!feof($fp)) {
            $data = fgetcsv($fp, escape: '');
            if (is_array($data)) {
                self::$expectedResults[$data[0]] = $data[1];
            }
        }
        fclose($fp);

        self::$utilisationLastrunPath = self::lastrunPath($basename);
    }

    protected static function loadIvancicExpected(): void
    {
        $fp = fopen(__DIR__ . '/data/ivancic-expected.csv', 'rb');
        while (!feof($fp)) {
            $data = fgetcsv($fp, escape: '');
            if (is_array($data)) {
                self::$expectedContainerCounts[$data[0]] = (int) $data[1];
            }
        }
        fclose($fp);

        self::$containerCountLastrunPath = self::lastrunPath('ivancic-expected.csv');
    }

    public static function runPublishedTestcase($problem, Box $box, ItemList $items, bool $bestSubset = true): void
    {
        $packer = new VolumePacker($box, $items);
        $packedBox = $bestSubset ? $packer->packBestSubset() : $packer->pack();
        $volumeUtilisation = $packedBox->getVolumeUtilisation();

        self::appendLastrun(self::$utilisationLastrunPath, sprintf("%s,%.1f\n", $problem, $volumeUtilisation));

        self::assertEquals(self::$expectedResults[$problem], $volumeUtilisation);
    }

    public static function runIvancicTestcase($problem, Box $box, ItemList $items): void
    {
        $itemCount = $items->count();

        $packer = new Packer();
        $packer->setMaxBoxesToBalanceWeight(0);
        $packer->addBox($box);
        $packer->setItems($items);
        $packedBoxes = $packer->pack();

        $packedItemCount = 0;
        foreach ($packedBoxes as $packedBox) {
            $packedItemCount += $packedBox->items->count();
        }

        self::appendLastrun(self::$containerCountLastrunPath, sprintf("%s,%d\n", $problem, $packedBoxes->count()));

        self::assertSame($itemCount, $packedItemCount, "{$problem} left items unpacked");
        self::assertSame(self::$expectedContainerCounts[$problem], $packedBoxes->count());
    }

    /**
     * @return array<string, array{0: string, 1: Box, 2: ItemList, 3: int}>
     */
    public static function decodeInstanceFile(string $filename): array
    {
        $data = [];

        $handle = fopen(__DIR__ . '/data/' . $filename, 'rb');
        $problemCount = trim(fgets($handle));

        for ($p = 1; $p <= $problemCount; ++$p) {
            $problemId = explode(' ', trim(fgets($handle)))[0];
            $boxDimensions = explode(' ', trim(fgets($handle)));
            $box = new TestBox(
                "Container {$problemId}",
                (int) $boxDimensions[0],
                (int) $boxDimensions[1],
                (int) $boxDimensions[2],
                1,
                (int) $boxDimensions[0],
                (int) $boxDimensions[1],
                (int) $boxDimensions[2],
                1
            );
            $itemTypeCount = trim(fgets($handle));

            $items = new ItemList();
            for ($i = 1; $i <= $itemTypeCount; ++$i) {
                $itemDimensions = explode(' ', trim(fgets($handle)));
                $item = self::createBischoffItem(
                    "Item {$itemDimensions[0]}",
                    (int) $itemDimensions[1],
                    (bool) $itemDimensions[2],
                    (int) $itemDimensions[3],
                    (bool) $itemDimensions[4],
                    (int) $itemDimensions[5],
                    (bool) $itemDimensions[6]
                );
                $items->insert($item, (int) $itemDimensions[7]);
            }
            $data[$problemId] = [$problemId, $box, $items, $itemTypeCount];
        }

        fclose($handle);

        return $data;
    }

    /**
     * @return array<string, array{0: string, 1: Box, 2: ItemList}>
     */
    protected static function bischoffCases(int $fromClass, int $toClass): array
    {
        $data = [];
        for ($i = $fromClass; $i <= $toClass; ++$i) {
            $fileData = self::decodeInstanceFile("br{$i}.txt");
            foreach ($fileData as &$problem) {
                $problem[0] = "Bischoff #{$problem[3]}-{$problem[0]}";
                $data[$problem[0]] = [$problem[0], $problem[1], $problem[2]];
            }
        }

        return $data;
    }

    /**
     * Build a fixture item from Bischoff/Ratcliff dimensions + vertical-edge flags.
     */
    public static function createBischoffItem(
        string $description,
        int $width,
        bool $widthAllowedVertical,
        int $length,
        bool $lengthAllowedVertical,
        int $depth,
        bool $depthAllowedVertical
    ): Item {
        $verticalAxes = (int) $widthAllowedVertical + (int) $lengthAllowedVertical + (int) $depthAllowedVertical;

        if ($verticalAxes === 3) {
            return new BischoffTestItem($description, $width, $length, $depth, Rotation::BestFit);
        }

        if ($verticalAxes === 1) {
            if ($depthAllowedVertical) {
                return new BischoffTestItem($description, $width, $length, $depth, Rotation::KeepFlat);
            }
            if ($widthAllowedVertical) {
                return new BischoffTestItem($description, $length, $depth, $width, Rotation::KeepFlat);
            }

            return new BischoffTestItem($description, $width, $depth, $length, Rotation::KeepFlat);
        }

        return new BischoffConstrainedTestItem(
            $description,
            $width,
            $widthAllowedVertical,
            $length,
            $lengthAllowedVertical,
            $depth,
            $depthAllowedVertical
        );
    }
}
