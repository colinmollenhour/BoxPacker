<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

use Behat\Behat\Context\Context;
use DVDoug\BoxPacker\AllowedOverhang;
use DVDoug\BoxPacker\Box;
use DVDoug\BoxPacker\BoxList;
use DVDoug\BoxPacker\ItemList;
use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedBoxList;
use DVDoug\BoxPacker\Packer;
use DVDoug\BoxPacker\Rotation;
use DVDoug\BoxPacker\Test\OverhangTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use DVDoug\BoxPacker\VolumePacker;
use PHPUnit\Framework\Assert;

\chdir(__DIR__ . '/../..');

/**
 * Defines application features from the specific context.
 */
class PackerContext implements Context
{
    protected Box $box;

    protected BoxList $boxList;

    protected ItemList $itemList;

    protected PackedBox $packedBox;

    protected PackedBoxList $packedBoxList;

    /**
     * Initializes context.
     *
     * Every scenario gets its own context instance.
     * You can also pass arbitrary arguments to the
     * context constructor through behat.yml.
     */
    public function __construct()
    {
        $this->boxList = new BoxList();
        $this->itemList = new ItemList();
    }

    /**
     * @Given /^there is a box "([^"]+)", which has external dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d × (\d+)g and internal dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d and has a max weight of (\d+)g$/
     */
    public function thereIsABox(
        $boxType,
        $outerWidth,
        $outerLength,
        $outerDepth,
        $emptyWeight,
        $innerWidth,
        $innerLength,
        $innerDepth,
        $maxWeight
    ): void {
        $box = new TestBox($boxType, $outerWidth, $outerLength, $outerDepth, $emptyWeight, $innerWidth, $innerLength, $innerDepth, $maxWeight);
        $this->boxList->insert($box);
    }

    /**
     * @Given /^the box "([^"]+)", which has external dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d × (\d+)g and internal dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d and has a max weight of (\d+)g$/
     */
    public function theBox(
        $boxType,
        $outerWidth,
        $outerLength,
        $outerDepth,
        $emptyWeight,
        $innerWidth,
        $innerLength,
        $innerDepth,
        $maxWeight
    ): void {
        $box = new TestBox($boxType, $outerWidth, $outerLength, $outerDepth, $emptyWeight, $innerWidth, $innerLength, $innerDepth, $maxWeight);
        $this->box = $box;
    }

    /**
     * @Given /^there is a box "([^"]+)", which has external dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d × (\d+)g and internal dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d and has a max weight of (\d+)g and an allowed overhang of (\d+)mm at the sides and (\d+)mm at the front and back$/
     */
    public function thereIsABoxWithAnAllowedOverhang(
        $boxType,
        $outerWidth,
        $outerLength,
        $outerDepth,
        $emptyWeight,
        $innerWidth,
        $innerLength,
        $innerDepth,
        $maxWeight,
        $sides,
        $frontAndBack
    ): void {
        $box = new OverhangTestBox($boxType, $outerWidth, $outerLength, $outerDepth, $emptyWeight, $innerWidth, $innerLength, $innerDepth, $maxWeight, new AllowedOverhang(sides: $sides, frontAndBack: $frontAndBack));
        $this->boxList->insert($box);
    }

    /**
     * @Given /^the box "([^"]+)", which has external dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d × (\d+)g and internal dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d and has a max weight of (\d+)g and an allowed overhang of (\d+)mm at the sides and (\d+)mm at the front and back$/
     */
    public function theBoxWithAnAllowedOverhang(
        $boxType,
        $outerWidth,
        $outerLength,
        $outerDepth,
        $emptyWeight,
        $innerWidth,
        $innerLength,
        $innerDepth,
        $maxWeight,
        $sides,
        $frontAndBack
    ): void {
        $this->box = new OverhangTestBox($boxType, $outerWidth, $outerLength, $outerDepth, $emptyWeight, $innerWidth, $innerLength, $innerDepth, $maxWeight, new AllowedOverhang(sides: $sides, frontAndBack: $frontAndBack));
    }

    /**
     * @When /^I add (\d+) x "([^"]+)" with dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d × (\d+)g$/
     */
    public function thereIsAnItem(
        $qty,
        $itemName,
        $width,
        $length,
        $depth,
        $weight
    ): void {
        $item = new TestItem($itemName, $width, $length, $depth, $weight, Rotation::BestFit);
        $this->itemList->insert($item, $qty);
    }

    /**
     * @When /^I add (\d+) x keep flat "([^"]+)" with dimensions (\d+)mm w × (\d+)mm l × (\d+)mm d × (\d+)g$/
     */
    public function thereIsAKeepFlatItem(
        $qty,
        $itemName,
        $width,
        $length,
        $depth,
        $weight
    ): void {
        $item = new TestItem($itemName, $width, $length, $depth, $weight, Rotation::KeepFlat);
        $this->itemList->insert($item, $qty);
    }

    /**
     * @When I do a packing
     */
    public function iDoAPacking(): void
    {
        $packer = new Packer();
        $packer->setBoxes($this->boxList);
        $packer->setItems($this->itemList);
        $this->packedBoxList = $packer->pack();
    }

    /**
     * @When I do a volume-only packing
     */
    public function iDoAVolumePacking(): void
    {
        $packer = new VolumePacker($this->box, $this->itemList);
        $this->packedBox = $packer->pack();
    }

    /**
     * @Then /^I should have (\d+) boxes of type "([^"]+)"$/
     */
    public function thereExistsBoxes(
        $qty,
        $boxType
    ): void {
        $foundBoxes = 0;

        foreach ($this->packedBoxList as $packedBox) {
            if ($packedBox->box->getReference() === $boxType) {
                ++$foundBoxes;
            }
        }

        Assert::assertEquals($qty, $foundBoxes);
    }

    /**
     * @Then /^the packed box should have (\d+) items of type "([^"]+)"$/
     */
    public function thePackedBoxShouldHaveItems(
        $qty,
        $itemType
    ): void {
        $foundItems = 0;

        foreach ($this->packedBox->items as $packedItem) {
            if ($packedItem->item->getDescription() === $itemType) {
                ++$foundItems;
            }
        }

        Assert::assertEquals($qty, $foundItems);
    }

    /**
     * @Then /^the packed box's items should reach from (-?\d+)mm to (-?\d+)mm across its width and from (-?\d+)mm to (-?\d+)mm along its length$/
     */
    public function thePackedBoxsItemsShouldReach(
        $minX,
        $maxX,
        $minY,
        $maxY
    ): void {
        $xs = $ys = [];
        foreach ($this->packedBox->items as $packedItem) {
            $xs[] = $packedItem->x;
            $xs[] = $packedItem->x + $packedItem->boundingWidth;
            $ys[] = $packedItem->y;
            $ys[] = $packedItem->y + $packedItem->boundingLength;
        }

        Assert::assertSame([(int) $minX, (int) $maxX, (int) $minY, (int) $maxY], [\min($xs), \max($xs), \min($ys), \max($ys)]);
    }

    /**
     * @Transform /^(\d+)$/
     */
    public function castStringToNumber($string)
    {
        return (int) $string;
    }
}
