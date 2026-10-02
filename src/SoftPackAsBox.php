<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use JsonSerializable;

use function array_keys;
use function array_slice;
use function ceil;
use function ksort;
use function max;
use function uksort;
use function count;
use function is_iterable;

use const PHP_INT_MAX;

/**
 * A soft pack filled to a given thickness, which is a box: the contents are at most that thick, and the room for
 * them in width and length is the flat size less the seams and closure, less what goes round the contents. The
 * packers only ever see these; the Packer makes one for each thickness the order could fill a pack to.
 *
 * The box's inner size is the room inside at the fill thickness. Its outer size is the filled pack's outside at
 * that thickness; for the size of the pack as actually filled, see {@see PackedBox::getOuterDimensions()}.
 */
final readonly class SoftPackAsBox implements ProtectiveBox, JsonSerializable
{
    /**
     * The most fill thicknesses tried for one soft pack on one order.
     */
    private const MAX_CANDIDATE_THICKNESSES = 24;

    private int $innerWidth;

    private int $innerLength;

    public function __construct(public SoftPack $softPack, public int $fillThickness)
    {
        $taken = self::taken($softPack, $fillThickness);
        $this->innerWidth = max(0, $softPack->getFlatWidth() - 2 * $softPack->getSideSeamLoss() - $taken);
        $this->innerLength = max(0, $softPack->getFlatLength() - $softPack->getClosureLoss() - $taken);
    }

    /**
     * The thicknesses these items could fill the pack to, thinnest first: the heights a stack of them can reach
     * (each item in any of the depths its rotations and states allow), up to the pack's limit. Where there are
     * more than can reasonably be tried, the heights reached with the fewest items are kept.
     *
     * @param iterable<Item> $items
     *
     * @return list<int>
     */
    public static function candidateThicknesses(SoftPack $softPack, iterable $items): array
    {
        $max = $softPack->getMaxFillThickness();
        $fewestItems = [0 => 0]; // thickness => the fewest items that stack to it
        foreach ($items as $item) {
            $depths = [];
            foreach (ItemState::shapesOf($item) as [$w, $l, $d, $rotation]) {
                foreach ($rotation->permutations($w, $l, $d) as [, , $depth]) {
                    if ($depth >= 1 && $depth <= $max) {
                        $depths[$depth] = true;
                    }
                }
            }
            if ($depths === []) {
                continue;
            }
            $reached = $fewestItems;
            foreach ($fewestItems as $thickness => $count) {
                foreach ($depths as $depth => $_) {
                    if ($thickness + $depth <= $max && ($reached[$thickness + $depth] ?? PHP_INT_MAX) > $count + 1) {
                        $reached[$thickness + $depth] = $count + 1;
                    }
                }
            }
            $fewestItems = $reached;
        }
        unset($fewestItems[0]);

        if (count($fewestItems) > self::MAX_CANDIDATE_THICKNESSES) {
            uksort($fewestItems, static fn (int $a, int $b) => $fewestItems[$a] <=> $fewestItems[$b] ?: $a <=> $b);
            $fewestItems = array_slice($fewestItems, 0, self::MAX_CANDIDATE_THICKNESSES, true);
        }
        ksort($fewestItems);

        return array_keys($fewestItems);
    }

    /**
     * The outside size of the filled pack when its contents are this thick, as [width, length, thickness].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function getFilledDimensions(int $thickness): array
    {
        $taken = self::taken($this->softPack, $thickness);

        return [max(0, $this->softPack->getFlatWidth() - $taken), max(0, $this->softPack->getFlatLength() - $taken), $thickness];
    }

    public function getReference(): string
    {
        return $this->softPack->getReference();
    }

    public function getOuterWidth(): int
    {
        return $this->getFilledDimensions($this->fillThickness)[0];
    }

    public function getOuterLength(): int
    {
        return $this->getFilledDimensions($this->fillThickness)[1];
    }

    public function getOuterDepth(): int
    {
        return $this->fillThickness;
    }

    public function getEmptyWeight(): int
    {
        return $this->softPack->getEmptyWeight();
    }

    public function getInnerWidth(): int
    {
        return $this->innerWidth;
    }

    public function getInnerLength(): int
    {
        return $this->innerLength;
    }

    public function getInnerDepth(): int
    {
        return $this->fillThickness;
    }

    public function getMaxWeight(): int
    {
        return $this->softPack->getMaxWeight();
    }

    public function getProtection(): Protection
    {
        return $this->softPack->getProtection();
    }

    public function jsonSerialize(): array
    {
        $userValues = [];
        if ($this->softPack instanceof JsonSerializable) {
            $userSerialisation = $this->softPack->jsonSerialize();
            $userValues = is_iterable($userSerialisation) ? [...$userSerialisation] : ['extra' => $userSerialisation];
        }

        return [
            ...$userValues,
            'reference' => $this->getReference(),
            'flatWidth' => $this->softPack->getFlatWidth(),
            'flatLength' => $this->softPack->getFlatLength(),
            'fillThickness' => $this->fillThickness,
            'protection' => $this->getProtection(),
        ];
    }

    /**
     * How much of the flat width (and of the flat length) goes round contents this thick.
     */
    private static function taken(SoftPack $softPack, int $thickness): int
    {
        return (int) ceil($softPack->getWrapFactor() * $thickness);
    }
}
