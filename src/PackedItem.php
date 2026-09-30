<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use JsonSerializable;

use function is_iterable;

/**
 * A packed item.
 *
 * Normally items sit square to the box: they occupy x to x + width, y to y + length and z to z + depth. With angled
 * placement enabled, an item that is too long to fit a box any other way can be turned about the vertical axis:
 * $angle is then the anticlockwise turn (x towards y) in degrees, between 0 and 90, width, length and depth are still
 * the item's own dimensions, and x, y are the minimum corner of its bounding box, which is $boundingWidth ×
 * $boundingLength. Use those (or {@see getFootprint()}) rather than x + width when angles are possible.
 */
readonly class PackedItem implements JsonSerializable
{
    public int $volume;

    public int $weight;

    /**
     * Extent along x (the same as width unless the item is angled).
     */
    public int $boundingWidth;

    /**
     * Extent along y (the same as length unless the item is angled).
     */
    public int $boundingLength;

    public function __construct(
        public Item $item,
        public int $x,
        public int $y,
        public int $z,
        public int $width,
        public int $length,
        public int $depth,
        public float $angle = 0.0,
    ) {
        $this->volume = $width * $length * $depth;
        $this->weight = $item->getWeight();
        if ($angle == 0.0) {
            $this->boundingWidth = $width;
            $this->boundingLength = $length;
        } else {
            [$this->boundingWidth, $this->boundingLength] = AngledGeometry::boundingBox($width, $length, $angle);
        }
    }

    /**
     * Whether the item is turned at an angle to the sides of the box.
     */
    public function isAngled(): bool
    {
        return $this->angle != 0.0;
    }

    /**
     * The four corners of the item's base as [x, y] pairs, anticlockwise, starting from the one on the bottom (minimum
     * y) edge of its bounding box. For an item square to the box, that is (x, y), (x + width, y) and so on.
     *
     * @return list<array{float, float}>
     */
    public function getFootprint(): array
    {
        return AngledGeometry::corners($this->x, $this->y, $this->width, $this->length, $this->angle);
    }

    public static function fromOrientatedItem(OrientatedItem $orientatedItem, int $x, int $y, int $z): self
    {
        return new self(
            $orientatedItem->item,
            $x,
            $y,
            $z,
            $orientatedItem->width,
            $orientatedItem->length,
            $orientatedItem->depth,
        );
    }

    public function jsonSerialize(): array
    {
        $userValues = [];

        if ($this->item instanceof JsonSerializable) {
            $userSerialisation = $this->item->jsonSerialize();
            if (is_iterable($userSerialisation)) {
                $userValues = $userSerialisation;
            } else {
                $userValues = ['extra' => $userSerialisation];
            }
        }

        $angle = $this->angle != 0.0 ? ['angle' => $this->angle, 'boundingWidth' => $this->boundingWidth, 'boundingLength' => $this->boundingLength] : [];

        return [
            'x' => $this->x,
            'y' => $this->y,
            'z' => $this->z,
            'width' => $this->width,
            'length' => $this->length,
            'depth' => $this->depth,
            ...$angle,
            'item' => [
                ...$userValues,
                'description' => $this->item->getDescription(),
                'width' => $this->item->getWidth(),
                'length' => $this->item->getLength(),
                'depth' => $this->item->getDepth(),
                'allowedRotation' => $this->item->getAllowedRotation(),
            ],
        ];
    }
}
