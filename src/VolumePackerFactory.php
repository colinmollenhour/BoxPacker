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
use function is_finite;
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
     * Time budgets longer than this (about three years), or not finite, count as no budget.
     */
    private const MAX_TIME_BUDGET = 1e8;

    /**
     * Point (hrtime, ns) at which the time budget runs out, null if there is no budget.
     */
    private readonly ?int $deadline;

    private ?float $callTimeLimit = null;

    private ?int $callBudget = null;

    private int $searchPlacements = 0;

    private int $searchesCutShort = 0;

    /**
     * @param ?float $timeBudget total wall-clock seconds for the run (Thorough strategy only), starting now
     */
    public function __construct(
        private readonly PackingStrategy $strategy = PackingStrategy::Fast,
        private readonly int $maxBeamWidth = 16,
        private readonly float $minimumSupport = 0.5,
        private readonly ?int $searchBudget = 10000,
        ?float $timeBudget = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $beStrictAboutItemOrdering = false,
        private readonly bool $allowAngledPlacement = false,
        private readonly ?TimeoutChecker $timeoutChecker = null,
    ) {
        $this->deadline = $timeBudget === null || !is_finite($timeBudget) || $timeBudget > self::MAX_TIME_BUDGET
            ? null
            : hrtime(true) + (int) (max(0.0, $timeBudget) * 1e9);
    }

    public function create(Box $box, ItemList $items): VolumePacker
    {
        $volumePacker = new VolumePacker($box, $items);
        $volumePacker->setLogger($this->logger);
        $volumePacker->beStrictAboutItemOrdering($this->beStrictAboutItemOrdering);
        $volumePacker->setTimeoutChecker($this->timeoutChecker);
        $volumePacker->setSearchListener($this->recordSearch(...));
        if ($this->allowAngledPlacement) {
            $volumePacker->setAllowAngledPlacement(true);
            $volumePacker->setMinimumSupport($this->minimumSupport);
        }

        if ($this->strategy === PackingStrategy::Thorough) {
            $volumePacker->setStrategy($this->strategy);
            $volumePacker->setMaxBeamWidth($this->maxBeamWidth);
            $volumePacker->setMinimumSupport($this->minimumSupport);
            $volumePacker->setSearchBudget($this->getCallSearchBudget());
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

    /**
     * Cap the search budget (trial placements) of each VolumePacker created from now on, below the configured one.
     */
    public function setCallBudget(?int $placements): void
    {
        $this->callBudget = $placements;
    }

    public function resetCallLimits(): void
    {
        $this->callBudget = null;
        $this->callTimeLimit = null;
    }

    /**
     * The search budget each VolumePacker created now gets (null = no cap).
     */
    public function getCallSearchBudget(): ?int
    {
        if ($this->callBudget === null || $this->searchBudget === null) {
            return $this->searchBudget ?? $this->callBudget;
        }

        return min($this->searchBudget, $this->callBudget);
    }

    /**
     * Trial placements made by the block searches of all the VolumePackers created so far.
     */
    public function getSearchPlacements(): int
    {
        return $this->searchPlacements;
    }

    /**
     * Number of those searches that were cut short by their budget or time limit.
     */
    public function getSearchesCutShort(): int
    {
        return $this->searchesCutShort;
    }

    private function recordSearch(int $placements, bool $cutShort): void
    {
        $this->searchPlacements += $placements;
        $this->searchesCutShort += $cutShort ? 1 : 0;
    }

    public function allowsAngledPlacement(): bool
    {
        return $this->allowAngledPlacement;
    }

    public function getSearchBudget(): ?int
    {
        return $this->searchBudget;
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

    /**
     * The search time limit each VolumePacker created now gets (null = no limit).
     */
    public function getSearchTimeLimit(): ?float
    {
        $remaining = $this->getRemainingTime();
        if ($remaining === null) {
            return $this->callTimeLimit;
        }

        return $this->callTimeLimit === null ? $remaining : min($remaining, $this->callTimeLimit);
    }
}
