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

/**
 * The named ways the benchmark can solve an instance. Each strategy is deterministic for a given set of options
 * so results are repeatable run-to-run (time limits are only applied when explicitly asked for).
 */
final class Strategies
{
    /**
     * @return array<string, string>
     */
    public static function describe(): array
    {
        return [
            'legacy' => 'Original layer packer: VolumePacker::pack() / Packer::pack() (no weight balancing)',
            'legacy-subset' => 'Original layer packer with VolumePacker::packBestSubset() for single containers',
            'block' => 'Block-building beam search (options: width, support, time)',
            'thorough' => 'PackingStrategy::Thorough: VolumePacker::pack() / Packer::pack() (options: width, support, time, balance)',
        ];
    }

    /**
     * @param array<string, string> $options
     */
    public static function single(string $strategy, Box $box, ItemList $items, array $options): PackedBox
    {
        return match ($strategy) {
            'legacy' => (new VolumePacker($box, $items))->pack(),
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
            'legacy', 'legacy-subset' => self::legacyMulti($boxes, $items),
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

        return $packer;
    }

    /**
     * @param array<string, string> $options
     */
    private static function thoroughSingle(Box $box, ItemList $items, array $options): VolumePacker
    {
        $packer = new VolumePacker($box, $items);
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setMaxBeamWidth((int) ($options['width'] ?? 8));
        $packer->setMinimumSupport((float) ($options['support'] ?? 1.0));
        $packer->setSearchTimeLimit(isset($options['time']) ? (float) $options['time'] : null);

        return $packer;
    }

    /**
     * Weight balancing is off unless asked for (balance=N boxes), as for the legacy strategy.
     *
     * @param list<Box>             $boxes
     * @param array<string, string> $options
     */
    private static function thoroughMulti(array $boxes, ItemList $items, array $options): PackedBoxList
    {
        $packer = new Packer();
        $packer->setStrategy(PackingStrategy::Thorough);
        $packer->setMaxBeamWidth((int) ($options['width'] ?? 8));
        $packer->setMinimumSupport((float) ($options['support'] ?? 1.0));
        $packer->setSearchTimeLimit(isset($options['time']) ? (float) $options['time'] : null);
        $packer->setMaxBoxesToBalanceWeight((int) ($options['balance'] ?? 0));
        $packer->throwOnUnpackableItem(false);
        foreach ($boxes as $box) {
            $packer->addBox($box);
        }
        $packer->setItems($items);

        return $packer->pack();
    }

    /**
     * @param list<Box> $boxes
     */
    private static function legacyMulti(array $boxes, ItemList $items): PackedBoxList
    {
        $packer = new Packer();
        $packer->setMaxBoxesToBalanceWeight(0);
        $packer->throwOnUnpackableItem(false);
        foreach ($boxes as $box) {
            $packer->addBox($box);
        }
        $packer->setItems($items);

        return $packer->pack();
    }
}
