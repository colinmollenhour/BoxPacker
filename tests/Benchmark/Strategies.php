<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Benchmark;

use DVDoug\BoxPacker\BlockPacker;
use DVDoug\BoxPacker\Box;
use DVDoug\BoxPacker\ItemList;
use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedBoxList;
use DVDoug\BoxPacker\Packer;
use DVDoug\BoxPacker\PackingStrategy;
use DVDoug\BoxPacker\VolumePacker;
use InvalidArgumentException;

use function array_keys;
use function implode;
use function in_array;
use function is_finite;
use function is_numeric;
use function preg_match;

/**
 * The named ways the benchmark can solve an instance. Each strategy is deterministic for a given set of options
 * so results are repeatable run-to-run (time limits are only applied when explicitly asked for).
 */
final class Strategies
{
    /**
     * The options each strategy accepts.
     */
    private const OPTIONS = [
        'legacy' => ['angled'],
        'legacy-subset' => [],
        'block' => ['width', 'budget', 'support', 'time', 'rule', 'scoring', 'angled'],
        'thorough' => ['width', 'budget', 'support', 'time', 'balance', 'angled'],
    ];

    /**
     * @return array<string, string>
     */
    public static function describe(): array
    {
        return [
            'legacy' => 'Original layer packer: VolumePacker::pack() / Packer::pack() (no weight balancing; options: angled)',
            'legacy-subset' => 'Original layer packer with VolumePacker::packBestSubset() for single containers (no weight balancing; no options)',
            'block' => 'Block-building beam search engine only, single containers only; defaults width=8, support=1, no budget, unlike Thorough (options: width, budget, support, time, rule, scoring, angled)',
            'thorough' => 'PackingStrategy::Thorough: VolumePacker::pack() / Packer::pack(), library defaults except weight balancing off unless balance=N (options: width, budget, support, time, balance, angled)',
        ];
    }

    public static function supportsMulti(string $strategy): bool
    {
        self::validateStrategy($strategy);

        return $strategy !== 'block';
    }

    public static function validateStrategy(string $strategy): void
    {
        if (!isset(self::OPTIONS[$strategy])) {
            throw new InvalidArgumentException("Unknown strategy {$strategy}; known: " . implode(', ', array_keys(self::OPTIONS)));
        }
    }

    /**
     * @param array<string, string> $options
     */
    public static function validateOptions(string $strategy, array $options): void
    {
        self::validateStrategy($strategy);
        foreach ($options as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, self::OPTIONS[$strategy], true)) {
                $accepted = self::OPTIONS[$strategy] === [] ? 'none' : implode(', ', self::OPTIONS[$strategy]);
                throw new InvalidArgumentException("Strategy {$strategy} does not take option {$key} (accepted: {$accepted})");
            }
            $valid = match ($key) {
                'width' => self::isInt($value) && (int) $value >= 1,
                'budget' => $value === 'none' || self::isInt($value),
                'support' => self::isFloat($value) && (float) $value >= 0 && (float) $value <= 1,
                'time' => self::isFloat($value) && (float) $value > 0,
                'rule', 'scoring', 'angled' => $value === '0' || $value === '1',
                'balance' => self::isInt($value),
            };
            if (!$valid) {
                $expected = match ($key) {
                    'width' => 'an integer of at least 1',
                    'budget' => 'a non-negative integer or none',
                    'support' => 'a number from 0 to 1',
                    'time' => 'a number of seconds greater than 0',
                    'rule', 'scoring', 'angled' => '0 or 1',
                    'balance' => 'a non-negative integer',
                };
                throw new InvalidArgumentException("Option {$key}={$value} is invalid: expected {$expected}");
            }
        }
    }

    private static function isInt(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1;
    }

    private static function isFloat(string $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }

    /**
     * @param array<string, string> $options
     */
    public static function single(string $strategy, Box $box, ItemList $items, array $options): PackedBox
    {
        return match ($strategy) {
            'legacy' => self::legacySingle($box, $items, $options)->pack(),
            'legacy-subset' => (new VolumePacker($box, $items))->packBestSubset(),
            'block' => self::blockPacker($box, $items, $options)->pack(),
            'thorough' => self::thoroughSingle($box, $items, $options)->pack(),
            default => throw new InvalidArgumentException("Unknown strategy {$strategy}"),
        };
    }

    /**
     * @param list<Box>             $boxes
     * @param array<string, string> $options
     */
    public static function multi(string $strategy, array $boxes, ItemList $items, array $options): PackedBoxList
    {
        return match ($strategy) {
            'legacy', 'legacy-subset' => self::legacyMulti($boxes, $items, $options),
            'thorough' => self::thoroughMulti($boxes, $items, $options),
            default => throw new InvalidArgumentException("Unknown strategy {$strategy}"),
        };
    }

    /**
     * @param array<string, string> $options
     */
    private static function blockPacker(Box $box, ItemList $items, array $options): BlockPacker
    {
        $packer = new BlockPacker($box, $items);
        $packer->setMaxBeamWidth((int) ($options['width'] ?? 8));
        $packer->setMinimumSupport((float) ($options['support'] ?? 1.0));
        if (isset($options['time'])) {
            $packer->setTimeLimit((float) $options['time']);
        }
        if (isset($options['rule'])) {
            $packer->setSpaceRule((int) $options['rule']);
        }
        if (isset($options['budget'])) {
            $packer->setPlacementBudget($options['budget'] === 'none' ? null : (int) $options['budget']);
        }
        if (isset($options['scoring'])) {
            $packer->setScoring((int) $options['scoring']);
        }
        $packer->setAllowAngledPlacement((bool) ($options['angled'] ?? false));

        return $packer;
    }

    /**
     * @param array<string, string> $options
     */
    private static function thoroughSingle(Box $box, ItemList $items, array $options): VolumePacker
    {
        $packer = new VolumePacker($box, $items);
        $packer->setStrategy(PackingStrategy::Thorough);
        if (isset($options['width'])) {
            $packer->setMaxBeamWidth((int) $options['width']);
        }
        if (isset($options['budget'])) {
            $packer->setSearchBudget($options['budget'] === 'none' ? null : (int) $options['budget']);
        }
        if (isset($options['support'])) {
            $packer->setMinimumSupport((float) $options['support']);
        }
        if (isset($options['time'])) {
            $packer->setSearchTimeLimit((float) $options['time']);
        }
        $packer->setAllowAngledPlacement((bool) ($options['angled'] ?? false));

        return $packer;
    }

    /**
     * @param array<string, string> $options
     */
    private static function legacySingle(Box $box, ItemList $items, array $options): VolumePacker
    {
        $packer = new VolumePacker($box, $items);
        $packer->setAllowAngledPlacement((bool) ($options['angled'] ?? false));

        return $packer;
    }

    /**
     * Weight balancing is off unless asked for (balance=N boxes), as for the legacy strategy, so this is not quite
     * the library's default configuration.
     *
     * @param list<Box>             $boxes
     * @param array<string, string> $options
     */
    private static function thoroughMulti(array $boxes, ItemList $items, array $options): PackedBoxList
    {
        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        if (isset($options['width'])) {
            $packer->setMaxBeamWidth((int) $options['width']);
        }
        if (isset($options['budget'])) {
            $packer->setSearchBudget($options['budget'] === 'none' ? null : (int) $options['budget']);
        }
        if (isset($options['support'])) {
            $packer->setMinimumSupport((float) $options['support']);
        }
        if (isset($options['time'])) {
            $packer->setSearchTimeLimit((float) $options['time']);
        }
        $packer->setMaxBoxesToBalanceWeight((int) ($options['balance'] ?? 0));
        $packer->setAllowAngledPlacement((bool) ($options['angled'] ?? false));

        $packer->throwOnUnpackableItem(false);
        foreach ($boxes as $box) {
            $packer->addBox($box);
        }
        $packer->setItems($items);

        return $packer->pack();
    }

    /**
     * @param list<Box>             $boxes
     * @param array<string, string> $options
     */
    private static function legacyMulti(array $boxes, ItemList $items, array $options): PackedBoxList
    {
        $packer = new Packer();
        $packer->setMaxBoxesToBalanceWeight(0);
        $packer->setAllowAngledPlacement((bool) ($options['angled'] ?? false));
        $packer->throwOnUnpackableItem(false);
        foreach ($boxes as $box) {
            $packer->addBox($box);
        }
        $packer->setItems($items);

        return $packer->pack();
    }
}
