<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Benchmark;

use DVDoug\BoxPacker\BookShopTest;
use DVDoug\BoxPacker\ItemList;
use DVDoug\BoxPacker\PublishedInstanceSupport;
use DVDoug\BoxPacker\Rotation;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function ceil;
use function max;
use function preg_match;
use function range;
use function str_starts_with;
use function array_keys;
use function explode;
use function trim;

/**
 * Loads the benchmark datasets into a uniform list of instances.
 *
 * Single-container instances ("fill this box as densely as possible") are scored on volume utilisation.
 * Multi-container instances ("pack everything") are scored on the number (and volume) of boxes used.
 */
final class InstanceLoader
{
    use PublishedInstanceSupport;

    /**
     * Named groups of datasets for convenience on the command line.
     */
    public const GROUPS = [
        'ecommerce' => ['loh-nee', 'br1', 'br2', 'br3', 'br4', 'ivancic'],
        'container' => ['br5', 'br6', 'br7'],
        'extreme' => ['br8', 'br9', 'br10', 'br11', 'br12', 'br13', 'br14', 'br15'],
        'bookshop' => ['bookshop-3d', 'bookshop-2d'],
        'angled' => ['overlong'],
        'published' => ['loh-nee', 'br1', 'br2', 'br3', 'br4', 'br5', 'br6', 'br7', 'br8', 'br9', 'br10', 'br11', 'br12', 'br13', 'br14', 'br15', 'ivancic'],
    ];

    /**
     * Number of item types per instance in each BR class, as used in the PHPUnit test names.
     */
    private const BR_TYPES = [1 => 3, 2 => 5, 3 => 8, 4 => 10, 5 => 12, 6 => 15, 7 => 20, 8 => 30, 9 => 40, 10 => 50, 11 => 60, 12 => 70, 13 => 80, 14 => 90, 15 => 100];

    /**
     * The name the PHPUnit suites use for an instance (and so the key in their expected-results CSVs).
     */
    public static function testName(string $dataset, string $id): string
    {
        if (preg_match('/^br(\d+)$/', $dataset, $m)) {
            return 'Bischoff #' . self::BR_TYPES[(int) $m[1]] . '-' . $id;
        }

        return match ($dataset) {
            'loh-nee' => "Loh and Nee #{$id}",
            'ivancic' => "Ivancic #{$id}",
            default => $id,
        };
    }

    /**
     * Whether a dataset's instances are single-container ('single') or pack-everything ('multi') problems.
     */
    public static function kind(string $dataset): string
    {
        if (preg_match('/^br(\d+)$/', $dataset, $m) && isset(self::BR_TYPES[(int) $m[1]])) {
            return 'single';
        }

        return match ($dataset) {
            'loh-nee' => 'single',
            'ivancic', 'bookshop-3d', 'bookshop-2d', 'overlong' => 'multi',
            default => throw new InvalidArgumentException("Unknown dataset {$dataset}"),
        };
    }

    /**
     * @return list<string>
     */
    public static function expandDatasetNames(string $spec): array
    {
        $names = [];
        foreach (explode(',', $spec) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($part === 'all') {
                foreach ([...self::GROUPS['published'], ...self::GROUPS['bookshop']] as $name) {
                    $names[$name] = true;
                }
                continue;
            }
            if (isset(self::GROUPS[$part])) {
                foreach (self::GROUPS[$part] as $name) {
                    $names[$name] = true;
                }
                continue;
            }
            if (preg_match('/^br(\d+)-(\d+)$/', $part, $m)) {
                foreach (range((int) $m[1], (int) $m[2]) as $i) {
                    $names["br{$i}"] = true;
                }
                continue;
            }
            $names[$part] = true;
        }

        return array_keys($names);
    }

    /**
     * @return list<array{dataset: string, id: string, kind: string, box?: \DVDoug\BoxPacker\Box, boxes?: list<\DVDoug\BoxPacker\Box>, items: ItemList, lb?: int}>
     */
    public static function load(string $dataset): array
    {
        if (preg_match('/^br(\d+)$/', $dataset, $m)) {
            $instances = [];
            foreach (self::decodeInstanceFile("br{$m[1]}.txt") as [$id, $box, $items]) {
                $instances[] = ['dataset' => $dataset, 'id' => (string) $id, 'kind' => 'single', 'box' => $box, 'items' => $items];
            }

            return $instances;
        }

        if ($dataset === 'loh-nee') {
            $instances = [];
            foreach (self::decodeInstanceFile('loh-nee.txt') as [$id, $box, $items]) {
                $instances[] = ['dataset' => $dataset, 'id' => (string) $id, 'kind' => 'single', 'box' => $box, 'items' => $items];
            }

            return $instances;
        }

        if ($dataset === 'ivancic') {
            $instances = [];
            foreach (self::decodeInstanceFile('ivancic.txt') as [$id, $box, $items]) {
                $boxVolume = $box->getInnerWidth() * $box->getInnerLength() * $box->getInnerDepth();
                $instances[] = [
                    'dataset' => $dataset,
                    'id' => (string) $id,
                    'kind' => 'multi',
                    'boxes' => [$box],
                    'items' => $items,
                    'lb' => (int) ceil($items->getVolume() / $boxVolume),
                ];
            }

            return $instances;
        }

        if (str_starts_with($dataset, 'bookshop-')) {
            $rotation = match ($dataset) {
                'bookshop-3d' => Rotation::BestFit,
                'bookshop-2d' => Rotation::KeepFlat,
                default => throw new InvalidArgumentException("Unknown dataset {$dataset}"),
            };
            $instances = [];
            foreach (BookShopTest::getSamples() as $id => $sample) {
                $items = new ItemList();
                foreach ($sample['items'] as $item) {
                    $items->insert(new TestItem($item['name'], $item['width'], $item['length'], $item['depth'], $item['weight'], $rotation), $item['qty']);
                }
                $instances[] = ['dataset' => $dataset, 'id' => (string) $id, 'kind' => 'multi', 'boxes' => $sample['boxes'], 'items' => $items];
            }

            return $instances;
        }

        if ($dataset === 'overlong') {
            return self::overlongInstances();
        }

        throw new InvalidArgumentException("Unknown dataset {$dataset}");
    }

    /**
     * Synthetic e-commerce orders (fixed seed, so always the same) in which some items are a little too long for
     * the boxes they would otherwise go in: umbrellas, poster tubes, rods. Most still fit the largest box square;
     * about one order in ten has an item too long for any box unless it is angled.
     *
     * @return list<array{dataset: string, id: string, kind: string, boxes: list<\DVDoug\BoxPacker\Box>, items: ItemList}>
     */
    private static function overlongInstances(): array
    {
        $boxes = [];
        foreach ([[300, 200, 100, 150], [400, 300, 200, 250], [500, 400, 300, 400], [600, 400, 400, 550], [800, 600, 400, 800], [1200, 800, 600, 1500]] as [$width, $length, $depth, $emptyWeight]) {
            $boxes[] = new TestBox("{$width}x{$length}x{$depth}", $width, $length, $depth, $emptyWeight, $width - 10, $length - 10, $depth - 10, 30000);
        }

        $random = new Randomizer(new Mt19937(20260930));
        $instances = [];
        for ($order = 1; $order <= 100; ++$order) {
            $items = new ItemList();
            for ($line = $random->getInt(1, 3); $line > 0; --$line) {
                $target = $boxes[$random->getInt(0, 4)];
                $long = (int) (max($target->getInnerWidth(), $target->getInnerLength()) * (1 + $random->getInt(3, 20) / 100));
                if ($order % 10 === 0 && $line === 1) {
                    $long = $random->getInt(1200, 1300); // longer than any box, square
                }
                $short = $random->getInt(20, 80);
                $items->insert(new TestItem("Long {$line}", $long, $short, $random->getInt(20, 80), $random->getInt(200, 2000), Rotation::KeepFlat), $random->getInt(1, 3));
            }
            for ($line = $random->getInt(2, 10); $line > 0; --$line) {
                $items->insert(new TestItem("Item {$line}", $random->getInt(20, 250), $random->getInt(20, 250), $random->getInt(10, 150), $random->getInt(50, 3000), $random->getInt(0, 1) === 1 ? Rotation::BestFit : Rotation::KeepFlat), $random->getInt(1, 4));
            }
            $instances[] = ['dataset' => 'overlong', 'id' => (string) $order, 'kind' => 'multi', 'boxes' => $boxes, 'items' => $items];
        }

        return $instances;
    }
}
