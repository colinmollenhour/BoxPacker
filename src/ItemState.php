<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A shape an item can be packed in other than as supplied: folded a different way, rolled, compressed, nested...
 * The name is for the person packing ("roll", "fold in half") and is reported on the {@see PackedItem}.
 */
final readonly class ItemState implements JsonSerializable
{
    /**
     * @param ?Rotation $allowedRotation the rotation allowed in this state, or null for the item's own
     */
    public function __construct(
        public string $name,
        public int $width,
        public int $length,
        public int $depth,
        public ?Rotation $allowedRotation = null,
    ) {
        if ($width < 1 || $length < 1 || $depth < 1) {
            throw new InvalidArgumentException("Item state '{$name}' must have positive dimensions");
        }
    }

    /**
     * The rotation allowed in this state: its own, or the item's where it has none of its own.
     */
    public function getAllowedRotation(Item $item): Rotation
    {
        return $this->allowedRotation ?? $item->getAllowedRotation();
    }

    /**
     * Every shape an item can be packed in, as [width, length, depth, rotation, state]: its own dimensions (with a
     * null state) first, then each of its states (unless $withStates is false).
     *
     * @internal
     *
     * @return list<array{0: int, 1: int, 2: int, 3: Rotation, 4: ?ItemState}>
     */
    public static function shapesOf(Item $item, bool $withStates = true): array
    {
        $shapes = [[$item->getWidth(), $item->getLength(), $item->getDepth(), $item->getAllowedRotation(), null]];
        if ($withStates && $item instanceof ReshapableItem) {
            foreach ($item->getStates() as $state) {
                $shapes[] = [$state->width, $state->length, $state->depth, $state->getAllowedRotation($item), $state];
            }
        }

        return $shapes;
    }

    /**
     * Distinguishes items of the same dimensions that have different states, for cache keys ('' for items with
     * no states).
     *
     * @internal
     */
    public static function signatureOf(Item $item): string
    {
        if (!$item instanceof ReshapableItem) {
            return '';
        }

        $signature = '';
        foreach ($item->getStates() as $state) {
            $signature .= '|s:' . $state->name . ':' . $state->width . 'x' . $state->length . 'x' . $state->depth . ':' . $state->getAllowedRotation($item)->name;
        }

        return $signature;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'width' => $this->width,
            'length' => $this->length,
            'depth' => $this->depth,
            'allowedRotation' => $this->allowedRotation,
        ];
    }
}
