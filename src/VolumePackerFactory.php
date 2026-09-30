<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function hrtime;
use function max;
use function min;

/**
 * Creates the VolumePackers used during one packing run, so that every single-box packing made by the Packer and its
 * helpers uses the same strategy and search settings, and draws on one overall time budget.
 *
 * @internal
 */
class VolumePackerFactory
{
    /**
     * Point (hrtime, ns) at which the time budget runs out, null if there is no budget.
     */
    private readonly ?int $deadline;

    private ?float $callTimeLimit = null;

    /**
     * @param ?float $timeBudget total wall-clock seconds for the run (Thorough strategy only), starting now
     */
    public function __construct(
        private readonly PackingStrategy $strategy = PackingStrategy::Fast,
        private readonly int $maxBeamWidth = 16,
        private readonly float $minimumSupport = 0.75,
        private readonly ?int $searchBudget = 10000,
        ?float $timeBudget = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $beStrictAboutItemOrdering = false,
    ) {
        $this->deadline = $timeBudget === null ? null : hrtime(true) + (int) (max(0.0, $timeBudget) * 1e9);
    }

    public function create(Box $box, ItemList $items): VolumePacker
    {
        $volumePacker = new VolumePacker($box, $items);
        $volumePacker->setLogger($this->logger);
        $volumePacker->beStrictAboutItemOrdering($this->beStrictAboutItemOrdering);

        if ($this->strategy === PackingStrategy::Thorough) {
            $volumePacker->setStrategy($this->strategy);
            $volumePacker->setMaxBeamWidth($this->maxBeamWidth);
            $volumePacker->setMinimumSupport($this->minimumSupport);
            $volumePacker->setSearchBudget($this->searchBudget);
            $volumePacker->setSearchTimeLimit($this->getSearchTimeLimit());
        }

        return $volumePacker;
    }

    /**
     * Cap the search time of each VolumePacker created from now on (in addition to the overall budget).
     */
    public function setCallTimeLimit(?float $seconds): void
    {
        $this->callTimeLimit = $seconds;
    }

    public function hasTimeBudget(): bool
    {
        return $this->deadline !== null;
    }

    /**
     * Seconds left of the overall budget (never negative), null if there is no budget.
     */
    public function getRemainingTime(): ?float
    {
        return $this->deadline === null ? null : max(0.0, ($this->deadline - hrtime(true)) / 1e9);
    }

    public function isOutOfTime(): bool
    {
        return $this->deadline !== null && hrtime(true) >= $this->deadline;
    }

    private function getSearchTimeLimit(): ?float
    {
        $remaining = $this->getRemainingTime();
        if ($remaining === null) {
            return $this->callTimeLimit;
        }

        return $this->callTimeLimit === null ? $remaining : min($remaining, $this->callTimeLimit);
    }
}
