<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function max;
use function min;
use function reset;
use function sort;
use function usort;

use const PHP_INT_MAX;

/**
 * Actual packer.
 */
class VolumePacker implements LoggerAwareInterface
{
    /**
     * Above this many items the Thorough strategy no longer also runs the fast packer for comparison: on larger
     * loads the block search is consistently denser and the fast packer's run time grows quickly.
     */
    private const FAST_COMPARISON_LIMIT = 40;

    protected LoggerInterface $logger;

    protected ItemList $items;

    protected bool $singlePassMode = false;

    protected bool $packAcrossWidthOnly = false;

    private readonly LayerPacker $layerPacker;

    protected bool $beStrictAboutItemOrdering = false;

    private readonly bool $hasConstrainedItems;

    private readonly bool $hasNoRotationItems;

    protected PackingStrategy $strategy = PackingStrategy::Fast;

    protected int $maxBeamWidth = 16;

    protected ?int $searchBudget = 10000;

    protected ?float $searchTimeLimit = null;

    protected float $minimumSupport = 0.5;

    protected bool $allowAngledPlacement = false;

    private int $searchPlacements = 0;

    public function __construct(protected Box $box, ItemList $items)
    {
        $this->items = clone $items;

        $this->logger = new NullLogger();

        $this->hasConstrainedItems = $items->hasConstrainedItems();
        $this->hasNoRotationItems = $items->hasNoRotationItems();

        $this->layerPacker = new LayerPacker($this->box);
        $this->layerPacker->setLogger($this->logger);
    }

    /**
     * Sets a logger.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->layerPacker->setLogger($logger);
    }

    public function packAcrossWidthOnly(): void
    {
        $this->packAcrossWidthOnly = true;
    }

    public function beStrictAboutItemOrdering(bool $beStrict): void
    {
        $this->beStrictAboutItemOrdering = $beStrict;
        $this->layerPacker->beStrictAboutItemOrdering($beStrict);
    }

    /**
     * Fast (original layer packer, the default) or Thorough (block-building search; for up to 40 items the fast
     * packer is also tried and the denser result kept, support rules permitting).
     */
    public function setStrategy(PackingStrategy $strategy): void
    {
        $this->strategy = $strategy;
    }

    /**
     * Thorough strategy only: how widely to search. The search is repeated with beam widths 1, 2, 4... up to this
     * value, so each doubling roughly quadruples the effort. Results are deterministic for a given width.
     */
    public function setMaxBeamWidth(int $maxBeamWidth): void
    {
        $this->maxBeamWidth = max(1, $maxBeamWidth);
    }

    /**
     * Thorough strategy only: optional wall-clock limit in seconds, after which the best packing found so far is
     * used. Note that results then depend on machine speed.
     */
    public function setSearchTimeLimit(?float $seconds): void
    {
        $this->searchTimeLimit = $seconds;
    }

    /**
     * Thorough strategy only: a deterministic cap on search effort, as the number of trial block placements
     * (default 10,000); the best packing found within it is used (a search step in progress may run on to twice the
     * budget). Unlike a time limit, results do not depend on machine speed. null = no cap.
     */
    public function setSearchBudget(?int $placements): void
    {
        $this->searchBudget = $placements;
    }

    /**
     * Thorough strategy only: the minimum fraction (0-1) of each item's base that must rest on the box floor or on
     * other items. Defaults to 0.5.
     */
    public function setMinimumSupport(float $fraction): void
    {
        $this->minimumSupport = max(0.0, min(1.0, $fraction));
    }

    /**
     * @internal
     */
    public function setSinglePassMode(bool $singlePassMode): void
    {
        $this->singlePassMode = $singlePassMode;
        if ($singlePassMode) {
            $this->packAcrossWidthOnly = true;
        }
        $this->layerPacker->setSinglePassMode($singlePassMode);
    }

    /**
     * Pack as many items as possible into specific given box.
     *
     * @return PackedBox packed box
     */
    public function pack(): PackedBox
    {
        if ($this->items->count() === 0) {
            return new PackedBox($this->box, new PackedItemList());
        }

        if ($this->useThorough()) {
            return $this->packThoroughWithFallback($this->packFast(...));
        }

        return $this->needsAngledPlacement() ? $this->packAngledFast() : $this->packFast();
    }

    /**
     * Allow an item that is too long to fit the box any other way to be turned about the vertical axis just enough to
     * fit, sitting at an angle to the sides of the box (see {@see PackedItem::$angle}). Identical angled items are
     * laid parallel to each other and other items can use the empty corners beside them. Items that must not be
     * rotated (Rotation::Never) and items with placement callbacks are never angled. With the Fast strategy, a box
     * that needs an angled item is packed by a quick (greedy) run of the Thorough strategy's block search.
     */
    public function setAllowAngledPlacement(bool $allow): void
    {
        $this->allowAngledPlacement = $allow;
    }

    /**
     * Fast strategy with angled placement allowed: whether some item only fits this box at an angle, in which case
     * the layer packer (which cannot angle items) is not enough.
     */
    private function needsAngledPlacement(): bool
    {
        if (!$this->allowAngledPlacement || $this->singlePassMode || $this->beStrictAboutItemOrdering || $this->packAcrossWidthOnly) {
            return false;
        }
        foreach ($this->items as $item) {
            if (AngledGeometry::onlyFitsAngled($item, $this->box)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A greedy block search (with angled placement) or the layer packer, whichever packs more.
     */
    private function packAngledFast(): PackedBox
    {
        $blockPacker = new BlockPacker($this->box, $this->items);
        $blockPacker->setLogger($this->logger);
        $blockPacker->setMaxBeamWidth(1);
        $blockPacker->setMinimumSupport($this->minimumSupport);
        $blockPacker->setAllowAngledPlacement(true);
        $angled = $blockPacker->pack();
        $this->searchPlacements += $blockPacker->getPlacements();

        return self::denser($this->packFast(), $angled);
    }

    /**
     * Thorough strategy: the block search, and for all but very large item lists also the fast packer (keeping
     * the denser of the two, provided the fast packing is supported well enough) unless the search already packed
     * everything.
     *
     * @param callable(): PackedBox $fastPacker
     */
    private function packThoroughWithFallback(callable $fastPacker): PackedBox
    {
        $thorough = $this->packThorough();
        if ($thorough->items->count() === $this->items->count() || $this->items->count() > self::FAST_COMPARISON_LIMIT) {
            return $thorough;
        }

        $fast = $fastPacker();
        if ($fast->getWeight() > $fast->box->getMaxWeight() || SupportCalculator::minimumSupport($fast->items) < $this->minimumSupport) {
            return $thorough;
        }

        return self::denser($fast, $thorough);
    }

    /**
     * Whether the block search applies: it needs the freedom to reorder items and to build the load in its own way.
     */
    private function useThorough(): bool
    {
        return $this->strategy === PackingStrategy::Thorough
            && !$this->singlePassMode
            && !$this->beStrictAboutItemOrdering
            && !$this->packAcrossWidthOnly
            && $this->items->count() > 0;
    }

    private function packThorough(): PackedBox
    {
        $blockPacker = new BlockPacker($this->box, $this->items);
        $blockPacker->setLogger($this->logger);
        $blockPacker->setMaxBeamWidth($this->maxBeamWidth);
        $blockPacker->setTimeLimit($this->searchTimeLimit);
        $blockPacker->setPlacementBudget($this->searchBudget);
        $blockPacker->setMinimumSupport($this->minimumSupport);
        $blockPacker->setAllowAngledPlacement($this->allowAngledPlacement);
        $packedBox = $blockPacker->pack();
        $this->searchPlacements += $blockPacker->getPlacements();

        return $packedBox;
    }

    /**
     * Number of trial block placements made by the thorough search in this packer so far.
     *
     * @internal
     */
    public function getSearchPlacements(): int
    {
        return $this->searchPlacements;
    }

    /**
     * The packing using more volume, then with more items.
     */
    private static function denser(PackedBox $a, PackedBox $b): PackedBox
    {
        $volumeDecider = $b->getUsedVolume() <=> $a->getUsedVolume();
        if ($volumeDecider === 0) {
            $volumeDecider = $b->items->count() <=> $a->items->count();
        }

        return $volumeDecider > 0 ? $b : $a;
    }

    /**
     * The original layer-by-layer packer.
     */
    private function packFast(): PackedBox
    {
        $orientatedItemFactory = new OrientatedItemFactory($this->box);
        $orientatedItemFactory->setLogger($this->logger);
        $this->logger->debug("[EVALUATING BOX] {$this->box->getReference()}", ['box' => $this->box]);

        // Sometimes "space available" decisions depend on orientation of the box, so try both ways
        $rotationsToTest = [false];
        if (!$this->packAcrossWidthOnly && !$this->hasNoRotationItems) {
            $rotationsToTest[] = true;
        }

        // The orientation of the first item can have an outsized effect on the rest of the placement, so special-case
        // that and try everything

        $boxPermutations = [];
        foreach ($rotationsToTest as $rotation) {
            if ($rotation) {
                $boxWidth = $this->box->getInnerLength();
                $boxLength = $this->box->getInnerWidth();
            } else {
                $boxWidth = $this->box->getInnerWidth();
                $boxLength = $this->box->getInnerLength();
            }

            $specialFirstItemOrientations = [null];
            if (!$this->singlePassMode) {
                $specialFirstItemOrientations = $orientatedItemFactory->getPossibleOrientations($this->items->top(), null, $boxWidth, $boxLength, $this->box->getInnerDepth(), 0, 0, 0, new PackedItemList()) ?: [null];
            }

            foreach ($specialFirstItemOrientations as $firstItemOrientation) {
                $boxPermutation = $this->packRotation($boxWidth, $boxLength, $firstItemOrientation);
                if ($boxPermutation->items->count() === $this->items->count()) {
                    return $boxPermutation;
                }

                $boxPermutations[] = $boxPermutation;
            }
        }

        usort($boxPermutations, static fn (PackedBox $a, PackedBox $b) => $b->getVolumeUtilisation() <=> $a->getVolumeUtilisation());

        return reset($boxPermutations);
    }

    /**
     * Pack this box maximising used volume, even if that means leaving a large
     * item out so smaller ones can fill the space more densely.
     *
     * pack() still places the largest item that fits first. This retries
     * after dropping that item from the candidate list. Leftover items are
     * those not in the returned box.
     */
    public function packBestSubset(): PackedBox
    {
        if ($this->items->count() === 0) {
            return new PackedBox($this->box, new PackedItemList());
        }

        // the block search already chooses which items to leave out
        return $this->useThorough() ? $this->packThoroughWithFallback($this->packBestSubsetFast(...)) : $this->packBestSubsetFast();
    }

    private function packBestSubsetFast(): PackedBox
    {
        $items = clone $this->items;
        $best = new PackedBox($this->box, new PackedItemList());
        $bestUsedVolume = 0;

        while ($items->count() > 0) {
            if ($bestUsedVolume > 0 && $items->getVolume() <= $bestUsedVolume) {
                break;
            }

            $attempt = new self($this->box, $items);
            $attempt->setLogger($this->logger);
            $attempt->beStrictAboutItemOrdering($this->beStrictAboutItemOrdering);
            if ($this->packAcrossWidthOnly) {
                $attempt->packAcrossWidthOnly();
            }
            $packedBox = $attempt->pack();

            if ($packedBox->getUsedVolume() > $bestUsedVolume) {
                $best = $packedBox;
                $bestUsedVolume = $packedBox->getUsedVolume();
            }

            if ($packedBox->items->count() === $items->count()) {
                break;
            }

            $items->extract();
        }

        return $best;
    }

    /**
     * Pack as many items as possible into specific given box.
     *
     * @return PackedBox packed box
     */
    private function packRotation(int $boxWidth, int $boxLength, ?OrientatedItem $firstItemOrientation): PackedBox
    {
        $this->logger->debug("[EVALUATING ROTATION] {$this->box->getReference()}", ['width' => $boxWidth, 'length' => $boxLength]);
        $this->layerPacker->setBoxIsRotated($this->box->getInnerWidth() !== $boxWidth);

        $layers = [];
        $items = clone $this->items;

        while ($items->count() > 0) {
            $layerStartDepth = self::getCurrentPackedDepth($layers);
            $packedItemList = $this->getPackedItemList($layers);

            if ($packedItemList->count() > 0) {
                $firstItemOrientation = null;
            }

            // do a preliminary layer pack to get the depth used
            $preliminaryItems = clone $items;
            $preliminaryLayer = $this->layerPacker->packLayer($preliminaryItems, clone $packedItemList, 0, 0, $layerStartDepth, $boxWidth, $boxLength, $this->box->getInnerDepth() - $layerStartDepth, 0, true, $firstItemOrientation);
            if ($preliminaryLayer->items === []) {
                break;
            }

            $preliminaryLayerDepth = $preliminaryLayer->depth;
            if ($preliminaryLayerDepth === $preliminaryLayer->items[0]->depth) { // preliminary === final
                $layers[] = $preliminaryLayer;
                $items = $preliminaryItems;
            } else { // redo with now-known-depth so that we can stack to that height from the first item
                $layers[] = $this->layerPacker->packLayer($items, $packedItemList, 0, 0, $layerStartDepth, $boxWidth, $boxLength, $this->box->getInnerDepth() - $layerStartDepth, $preliminaryLayerDepth, true, $firstItemOrientation);
            }
        }

        if (!$this->singlePassMode && $layers) {
            $layers = $this->stabiliseLayers($layers);
            $layers = $this->fillVoids($layers, $items, $boxWidth, $boxLength);
        }

        $layers = $this->correctLayerRotation($layers, $boxWidth);

        return new PackedBox($this->box, $this->getPackedItemList($layers));
    }

    /**
     * @param  PackedLayer[] $layers
     * @return PackedLayer[]
     */
    private function fillVoids(array $layers, ItemList &$items, int $boxWidth, int $boxLength): array
    {
        $voidFinder = new VoidFinder();
        $packedItemList = $this->getPackedItemList($layers);
        $voids = $voidFinder->find($boxWidth, $boxLength, $this->box->getInnerDepth(), $packedItemList);

        while ($items->count() > 0 && $voids !== []) {
            // Prefer lower positions, then larger free regions (volume, then footprint)
            usort(
                $voids,
                static fn (VoidSpace $a, VoidSpace $b): int => $a->z <=> $b->z
                        ?: $b->volume <=> $a->volume
                        ?: $b->footprint <=> $a->footprint
            );

            $minRemainingVolume = $this->minRemainingItemVolume($items);

            $progress = false;
            foreach ($voids as $void) {
                // Pure performance: skip packLayer when no remaining item can geometrically fit
                if ($void->volume < $minRemainingVolume || !$this->voidMayFitAnyRemainingItem($void, $items)) {
                    continue;
                }

                $packedBefore = $packedItemList->count();
                $layer = $this->layerPacker->packLayer(
                    $items,
                    $packedItemList,
                    $void->x,
                    $void->y,
                    $void->z,
                    $void->x + $void->width,
                    $void->y + $void->length,
                    $void->depth,
                    $void->depth,
                    false, // gap fill; stability assumed from surrounding geometry
                    null
                );

                if ($layer->items !== []) {
                    $layers[] = $layer;
                    $voids = $voidFinder->subtractItems($voids, $layer->items);
                    $progress = true;
                    $this->logger->debug(
                        'Filled void space',
                        [
                            'void' => $void,
                            'itemsPlaced' => $packedItemList->count() - $packedBefore,
                        ]
                    );
                    break;
                }
            }

            if (!$progress) {
                break;
            }
        }

        return $layers;
    }

    private function minRemainingItemVolume(ItemList $items): int
    {
        $minVolume = PHP_INT_MAX;
        foreach ($items as $item) {
            $volume = $item->getWidth() * $item->getLength() * $item->getDepth();
            if ($volume < $minVolume) {
                $minVolume = $volume;
            }
        }

        return $minVolume === PHP_INT_MAX ? 0 : $minVolume;
    }

    /**
     * Whether any remaining item can fit in the void given its allowed rotations.
     */
    private function voidMayFitAnyRemainingItem(VoidSpace $void, ItemList $items): bool
    {
        foreach ($items as $item) {
            if ($this->itemMayFitInVoid($item, $void)) {
                return true;
            }
        }

        return false;
    }

    private function itemMayFitInVoid(Item $item, VoidSpace $void): bool
    {
        $w = $item->getWidth();
        $l = $item->getLength();
        $d = $item->getDepth();

        return match ($item->getAllowedRotation()) {
            Rotation::Never => $w <= $void->width && $l <= $void->length && $d <= $void->depth,
            Rotation::KeepFlat => ($w <= $void->width && $l <= $void->length && $d <= $void->depth)
                || ($l <= $void->width && $w <= $void->length && $d <= $void->depth),
            Rotation::BestFit => $this->bestFitMayFitInVoid($w, $l, $d, $void),
        };
    }

    private function bestFitMayFitInVoid(int $w, int $l, int $d, VoidSpace $void): bool
    {
        $itemEdges = [$w, $l, $d];
        $voidEdges = [$void->width, $void->length, $void->depth];
        sort($itemEdges);
        sort($voidEdges);

        return $itemEdges[0] <= $voidEdges[0]
            && $itemEdges[1] <= $voidEdges[1]
            && $itemEdges[2] <= $voidEdges[2];
    }

    /**
     * During packing, it is quite possible that layers have been created that aren't physically stable
     * i.e. they overhang the ones below.
     *
     * This function reorders them so that the ones with the greatest surface area are placed at the bottom
     *
     * @param  PackedLayer[] $oldLayers
     * @return PackedLayer[]
     */
    private function stabiliseLayers(array $oldLayers): array
    {
        if ($this->hasConstrainedItems || $this->beStrictAboutItemOrdering) { // constraints include position, so cannot change
            return $oldLayers;
        }

        $stabiliser = new LayerStabiliser();

        return $stabiliser->stabilise($oldLayers);
    }

    /**
     * Swap back width/length of the packed items to match orientation of the box if needed.
     *
     * @param PackedLayer[] $oldLayers
     *
     * @return PackedLayer[]
     */
    private function correctLayerRotation(array $oldLayers, int $boxWidth): array
    {
        if ($this->box->getInnerWidth() === $boxWidth) {
            return $oldLayers;
        }

        $newLayers = [];
        foreach ($oldLayers as $originalLayer) {
            $newLayer = new PackedLayer();
            foreach ($originalLayer->items as $item) {
                $packedItem = new PackedItem($item->item, $item->y, $item->x, $item->z, $item->length, $item->width, $item->depth);
                $newLayer->insert($packedItem);
            }
            $newLayers[] = $newLayer;
        }

        return $newLayers;
    }

    /**
     * Generate a single list of items packed.
     * @param PackedLayer[] $layers
     */
    private function getPackedItemList(array $layers): PackedItemList
    {
        $packedItemList = new PackedItemList();
        foreach ($layers as $layer) {
            foreach ($layer->items as $packedItem) {
                $packedItemList->insert($packedItem);
            }
        }

        return $packedItemList;
    }

    /**
     * Return the current packed depth.
     *
     * @param PackedLayer[] $layers
     */
    private static function getCurrentPackedDepth(array $layers): int
    {
        $depth = 0;
        foreach ($layers as $layer) {
            $depth += $layer->depth;
        }

        return $depth;
    }
}
