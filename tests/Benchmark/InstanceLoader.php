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
use DVDoug\BoxPacker\Test\TestItem;
use InvalidArgumentException;

use function ceil;
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
        'published' => ['loh-nee', 'br1', 'br2', 'br3', 'br4', 'br5', 'br6', 'br7', 'br8', 'br9', 'br10', 'br11', 'br12', 'br13', 'br14', 'br15', 'ivancic'],
    ];

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

        throw new InvalidArgumentException("Unknown dataset {$dataset}");
    }
}
