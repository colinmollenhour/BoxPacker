<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Benchmark\InstanceLoader;
use DVDoug\BoxPacker\Test\ZeroOverhangBox;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function array_slice;
use function implode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * A box that opts in to an allowed overhang with every allowance zero packs exactly as the same box without it: the
 * same items in the same places, found with the same search effort, with the same JSON, visualiser URLs and used and
 * remaining space. Checked on a sample of the published instances (the full datasets are compared separately).
 */
#[CoversNothing]
class OverhangZeroChangeTest extends TestCase
{
    /**
     * Instances taken from each dataset: fewer of the Bischoff ones, whose placement callbacks make them slow to pack
     * with assertions enabled.
     */
    private const SAMPLES = [
        'loh-nee' => 2, 'br1' => 1, 'br2' => 1, 'br3' => 1, 'br4' => 1, 'br5' => 1, 'br6' => 1, 'br7' => 1,
        'ivancic' => 2, 'bookshop-3d' => 2, 'bookshop-2d' => 2,
    ];

    /**
     * Thorough search budget (a tenth of the default, to keep the suite fast; the code paths are the same).
     */
    private const SEARCH_BUDGET = 1000;

    /**
     * @var array<string, list<array{dataset: string, id: string, kind: string, box?: Box, boxes?: list<Box>, items: ItemList}>>
     */
    private static array $loaded = [];

    /**
     * @return list<array{dataset: string, id: string, kind: string, box?: Box, boxes?: list<Box>, items: ItemList}>
     */
    private static function load(string $dataset): array
    {
        return self::$loaded[$dataset] ??= array_slice(InstanceLoader::load($dataset), 0, self::SAMPLES[$dataset]);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function instances(): array
    {
        $instances = [];
        foreach (array_keys(self::SAMPLES) as $dataset) {
            foreach (self::load($dataset) as $index => $instance) {
                $instances["{$dataset} {$instance['id']}"] = [$dataset, $index];
            }
        }

        return $instances;
    }

    #[DataProvider('instances')]
    public function testZeroAllowancePacksAsWithout(string $dataset, int $index): void
    {
        foreach ([PackingStrategy::Fast, PackingStrategy::Thorough] as $strategy) {
            $plain = self::pack(self::load($dataset)[$index], $strategy, false);
            $zero = self::pack(self::load($dataset)[$index], $strategy, true);
            self::assertSame($plain, $zero, $strategy->name);
        }
    }

    /**
     * Every packed item's box, position, dimensions and angle in packing order, each box's output and space used, the
     * whole packing's output, and for a single box the search effort.
     *
     * @param array{kind: string, box?: Box, boxes?: list<Box>, items: ItemList} $instance
     */
    private static function pack(array $instance, PackingStrategy $strategy, bool $wrap): string
    {
        $wrapBox = static fn (Box $box): Box => $wrap ? ZeroOverhangBox::wrap($box) : $box;
        if ($instance['kind'] === 'single') {
            $packer = new VolumePacker($wrapBox($instance['box']), clone $instance['items']);
            $packer->setStrategy($strategy);
            $packer->setSearchBudget(self::SEARCH_BUDGET);
            $packedBox = $packer->pack();
            $packedBoxes = new PackedBoxList();
            $packedBoxes->insert($packedBox);

            return self::fingerprint($packedBoxes) . "\nsearch placements: {$packer->getSearchPlacements()}";
        }

        $packer = new Packer();
        $packer->setStrategy($strategy);
        $packer->setSearchBudget(self::SEARCH_BUDGET);
        $packer->throwOnUnpackableItem(false);
        foreach ($instance['boxes'] as $box) {
            $packer->addBox($wrapBox($box));
        }
        $packer->setItems(clone $instance['items']);

        return self::fingerprint($packer->pack()) . "\nunpacked: {$packer->getUnpackedItems()->count()}";
    }

    private static function fingerprint(PackedBoxList $packedBoxes): string
    {
        $rows = [];
        foreach ($packedBoxes as $packedBox) {
            $rows[] = 'box ' . $packedBox->box->getReference();
            foreach ($packedBox->items as $p) {
                $rows[] = implode(',', array_map('strval', [$p->item->getDescription(), $p->x, $p->y, $p->z, $p->width, $p->length, $p->depth, $p->angle]));
            }
            $rows[] = 'space ' . implode(',', array_map('strval', [
                $packedBox->getUsedWidth(), $packedBox->getUsedLength(), $packedBox->getUsedDepth(),
                $packedBox->getRemainingWidth(), $packedBox->getRemainingLength(), $packedBox->getRemainingDepth(),
                $packedBox->getUsedVolume(), $packedBox->getUnusedVolume(), $packedBox->getVolumeUtilisation(), $packedBox->getWeight(),
            ]));
            $rows[] = 'json ' . json_encode($packedBox, JSON_THROW_ON_ERROR);
            $rows[] = 'url ' . $packedBox->generateVisualisationURL();
        }
        $rows[] = 'json ' . json_encode($packedBoxes, JSON_THROW_ON_ERROR);
        $rows[] = 'url ' . $packedBoxes->generateVisualisationURL();

        return implode("\n", $rows);
    }
}
