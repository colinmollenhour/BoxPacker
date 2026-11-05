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
use Psr\Log\AbstractLogger;
use Stringable;

use function array_map;
use function count;
use function implode;
use function json_encode;
use function max;
use function spl_object_id;

use const JSON_THROW_ON_ERROR;

/**
 * A box that opts in to an allowed overhang with every allowance zero packs exactly as the same box without it does
 * at this version (the two are compared with each other, not with an earlier version): the same items in the same
 * places, placed in the same order and found with the same search effort, with the same JSON, visualiser URLs and
 * used and remaining space. Checked with the Fast and the Thorough strategy, with the library's default settings, with
 * angled placement allowed and with full support required (see {@see SAMPLES} for which), on a sample of the published
 * instances and of the synthetic ones with items that only fit some boxes at an angle.
 */
#[CoversNothing]
class OverhangZeroChangeTest extends TestCase
{
    /**
     * Settings other than the strategy: minimum support (the library's default if null) and whether angled placement
     * is allowed.
     */
    private const SETTINGS = [
        'defaults' => [null, false],
        'angled placement' => [null, true],
        'full support' => [1.0, false],
    ];

    private const ALL_SETTINGS = ['defaults', 'angled placement', 'full support'];

    /**
     * Instances taken from each dataset, by their place in it, and the settings each is packed with: fewer of the
     * Bischoff ones, whose placement callbacks make them slow to pack with assertions enabled, and all but the first of
     * those with the default settings only; and of the angled ones, the first, and two that the Fast strategy packs
     * with angled items, with angled placement allowed.
     */
    private const SAMPLES = [
        'loh-nee' => [[0, 1], self::ALL_SETTINGS],
        'br1' => [[0], self::ALL_SETTINGS],
        'br2' => [[0], ['defaults']],
        'br3' => [[0], ['defaults']],
        'br4' => [[0], ['defaults']],
        'br5' => [[0], ['defaults']],
        'br6' => [[0], ['defaults']],
        'br7' => [[0], ['defaults']],
        'ivancic' => [[0, 1], self::ALL_SETTINGS],
        'bookshop-3d' => [[0, 1], self::ALL_SETTINGS],
        'bookshop-2d' => [[0, 1], self::ALL_SETTINGS],
        'overlong' => [[0, 8, 9], ['angled placement']],
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
     * @return array{dataset: string, id: string, kind: string, box?: Box, boxes?: list<Box>, items: ItemList}
     */
    private static function instance(string $dataset, int $place): array
    {
        // only as many as the sample needs
        return (self::$loaded[$dataset] ??= InstanceLoader::load($dataset, max(self::SAMPLES[$dataset][0]) + 1))[$place];
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function instances(): array
    {
        $instances = [];
        foreach (self::SAMPLES as $dataset => [$places, $settings]) {
            foreach ($places as $place) {
                foreach ($settings as $name) {
                    $instances["{$dataset} {$place}, {$name}"] = [$dataset, $place, $name];
                }
            }
        }

        return $instances;
    }

    #[DataProvider('instances')]
    public function testZeroAllowancePacksAsWithout(string $dataset, int $place, string $settings): void
    {
        $instance = self::instance($dataset, $place);
        foreach ([PackingStrategy::Fast, PackingStrategy::Thorough] as $strategy) {
            $plain = self::pack($instance, $strategy, self::SETTINGS[$settings], false);
            $zero = self::pack($instance, $strategy, self::SETTINGS[$settings], true);
            self::assertSame($plain, $zero, $strategy->name);
        }
    }

    /**
     * The packing as text (see {@see fingerprint()}), with the number of trial placements of each block search made,
     * and for a single box the packer's own count of them, or for several the number of items left unpacked.
     *
     * @param array{kind: string, box?: Box, boxes?: list<Box>, items: ItemList} $instance
     * @param array{0: ?float, 1: bool}                                          $settings
     */
    private static function pack(array $instance, PackingStrategy $strategy, array $settings, bool $wrap): string
    {
        [$support, $angled] = $settings;
        $wrapBox = static fn (Box $box): Box => $wrap ? ZeroOverhangBox::wrap($box) : $box;
        $searches = new class extends AbstractLogger {
            /**
             * @var list<int>
             */
            public array $placements = [];

            /**
             * @param mixed[] $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($message === 'Block search complete') {
                    $this->placements[] = $context['placements'];
                }
            }
        };

        $packer = $instance['kind'] === 'single'
            ? new VolumePacker($wrapBox($instance['box']), clone $instance['items'])
            : new Packer();
        $packer->setLogger($searches);
        $packer->setStrategy($strategy);
        $packer->setSearchBudget(self::SEARCH_BUDGET);
        if ($support !== null) {
            $packer->setMinimumSupport($support);
        }
        $packer->setAllowAngledPlacement($angled);

        if ($packer instanceof VolumePacker) {
            $packedBoxes = new PackedBoxList();
            $packedBoxes->insert($packer->pack());
            $effort = "search placements: {$packer->getSearchPlacements()}";
        } else {
            $packer->throwOnUnpackableItem(false);
            foreach ($instance['boxes'] as $box) {
                $packer->addBox($wrapBox($box));
            }
            $packer->setItems(clone $instance['items']);
            $packedBoxes = $packer->pack();
            $effort = "unpacked: {$packer->getUnpackedItems()->count()}";
        }

        return self::fingerprint($packedBoxes) . "\nsearches: " . implode(',', $searches->placements) . "\n{$effort}";
    }

    /**
     * Each box's reference; the order of its items in its list as they were placed, read before anything here iterates
     * the list (which sorts it; with assertions enabled, the box's own checks already have), and its visualiser URL,
     * which numbers the items in that order; every placement (item,
     * position, dimensions and angle) in the order the list then iterates them (by volume, then weight, keeping the
     * order of placement among items alike in both: so with the order of the items, the placements in the order they
     * were placed); its space used and remaining; and its JSON. Then the whole packing's JSON and visualiser URL.
     */
    private static function fingerprint(PackedBoxList $packedBoxes): string
    {
        $rows = [];
        foreach ($packedBoxes as $packedBox) {
            $rows[] = 'box ' . $packedBox->box->getReference();
            $objects = [];
            $order = [];
            foreach ($packedBox->items->asItemArray() as $item) {
                $objects[spl_object_id($item)] ??= count($objects);
                $order[] = $objects[spl_object_id($item)] . ' ' . $item->getDescription();
            }
            $rows[] = 'order ' . implode(',', $order);
            $rows[] = 'url ' . $packedBox->generateVisualisationURL();
            foreach ($packedBox->items as $p) {
                $rows[] = implode(',', array_map('strval', [$p->item->getDescription(), $p->x, $p->y, $p->z, $p->width, $p->length, $p->depth, $p->angle]));
            }
            $rows[] = 'space ' . implode(',', array_map('strval', [
                $packedBox->getUsedWidth(), $packedBox->getUsedLength(), $packedBox->getUsedDepth(),
                $packedBox->getRemainingWidth(), $packedBox->getRemainingLength(), $packedBox->getRemainingDepth(),
                $packedBox->getUsedVolume(), $packedBox->getUnusedVolume(), $packedBox->getVolumeUtilisation(), $packedBox->getWeight(),
            ]));
            $rows[] = 'json ' . json_encode($packedBox, JSON_THROW_ON_ERROR);
        }
        $rows[] = 'json ' . json_encode($packedBoxes, JSON_THROW_ON_ERROR);
        $rows[] = 'url ' . $packedBoxes->generateVisualisationURL();

        return implode("\n", $rows);
    }
}
