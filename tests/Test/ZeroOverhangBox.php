<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Test;

use DVDoug\BoxPacker\AllowedOverhang;
use DVDoug\BoxPacker\Box;
use DVDoug\BoxPacker\LimitedSupplyBox;
use DVDoug\BoxPacker\OverhangBox;
use JsonSerializable;

use function assert;

/**
 * Wraps a box so that it opts in to an allowed overhang, with every allowance zero (so that it must pack exactly as
 * the box it wraps). Use {@see wrap()} to keep the wrapped box's limited supply and JSON serialisation too.
 */
class ZeroOverhangBox implements OverhangBox
{
    public function __construct(protected readonly Box $box)
    {
    }

    /**
     * The box wrapped, also implementing LimitedSupplyBox and JsonSerializable if it does.
     */
    public static function wrap(Box $box): self
    {
        $limited = $box instanceof LimitedSupplyBox;
        $serialisable = $box instanceof JsonSerializable;

        return match (true) {
            $limited && $serialisable => new class($box) extends ZeroOverhangBox implements LimitedSupplyBox, JsonSerializable {
                public function getQuantityAvailable(): int
                {
                    return $this->limitedSupplyBox()->getQuantityAvailable();
                }

                public function jsonSerialize(): mixed
                {
                    return $this->serialisableBox()->jsonSerialize();
                }
            },
            $limited => new class($box) extends ZeroOverhangBox implements LimitedSupplyBox {
                public function getQuantityAvailable(): int
                {
                    return $this->limitedSupplyBox()->getQuantityAvailable();
                }
            },
            $serialisable => new class($box) extends ZeroOverhangBox implements JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    return $this->serialisableBox()->jsonSerialize();
                }
            },
            default => new self($box),
        };
    }

    /**
     * The box wrapped, for a wrapper that keeps its limited supply ({@see wrap()} only makes one for a box with one).
     */
    protected function limitedSupplyBox(): LimitedSupplyBox
    {
        assert($this->box instanceof LimitedSupplyBox);

        return $this->box;
    }

    /**
     * The box wrapped, for a wrapper that keeps its JSON serialisation ({@see wrap()} only makes one for a box with
     * one).
     */
    protected function serialisableBox(): JsonSerializable
    {
        assert($this->box instanceof JsonSerializable);

        return $this->box;
    }

    public function getAllowedOverhang(): AllowedOverhang
    {
        return new AllowedOverhang();
    }

    public function getReference(): string
    {
        return $this->box->getReference();
    }

    public function getOuterWidth(): int
    {
        return $this->box->getOuterWidth();
    }

    public function getOuterLength(): int
    {
        return $this->box->getOuterLength();
    }

    public function getOuterDepth(): int
    {
        return $this->box->getOuterDepth();
    }

    public function getEmptyWeight(): int
    {
        return $this->box->getEmptyWeight();
    }

    public function getInnerWidth(): int
    {
        return $this->box->getInnerWidth();
    }

    public function getInnerLength(): int
    {
        return $this->box->getInnerLength();
    }

    public function getInnerDepth(): int
    {
        return $this->box->getInnerDepth();
    }

    public function getMaxWeight(): int
    {
        return $this->box->getMaxWeight();
    }
}
