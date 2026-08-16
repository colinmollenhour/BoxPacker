<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function ini_set;

/**
 * E-commerce-shaped published cases: Loh/Nee, BR1–4 (3–10 types), Ivancic.
 *
 * Loh/Nee and Bischoff use VolumePacker::packBestSubset(). Ivancic uses
 * Packer::pack() (pack everything, minimise container count).
 */
class PublishedEcommerceTest extends TestCase
{
    use PublishedInstanceSupport;

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '-1');
        self::loadUtilisationExpected('published-expected-ecommerce.csv');
        self::loadIvancicExpected();
    }

    /**
     * H.T. Loh & A.Y.C. Nee, 1992, A packing algorithm for hexahedral
     * boxes, Proc. Industrial Automation 92 Conf. Singapore, 115-126.
     */
    #[DataProvider('lohAndNeeData')]
    #[Group('efficiency')]
    #[Group('efficiency-ecommerce')]
    public function testLohAndNee($problem, $box, $items): void
    {
        self::runPublishedTestcase($problem, $box, $items);
    }

    public static function lohAndNeeData(): array
    {
        $data = [];
        $fileData = self::decodeInstanceFile('loh-nee.txt');
        foreach ($fileData as &$problem) {
            $problem[0] = "Loh and Nee #{$problem[0]}";
            $data[$problem[0]] = [$problem[0], $problem[1], $problem[2]];
        }

        return $data;
    }

    /**
     * Bischoff/Ratcliff BR1–4 (3, 5, 8, 10 item types).
     */
    #[DataProvider('bischoffEcommerceData')]
    #[Group('efficiency')]
    #[Group('efficiency-ecommerce')]
    public function testBischoff($problem, $box, $items): void
    {
        self::runPublishedTestcase($problem, $box, $items);
    }

    public static function bischoffEcommerceData(): array
    {
        return self::bischoffCases(1, 4);
    }

    /**
     * N. Ivancic, K. Mathur & B.B. Mohanty, J. of Manuf. & Ops. Man., 1989.
     * Multiple identical containers; score is container count.
     */
    #[DataProvider('ivancicData')]
    #[Group('efficiency')]
    #[Group('efficiency-ecommerce')]
    public function testIvancic($problem, $box, $items): void
    {
        self::runIvancicTestcase($problem, $box, $items);
    }

    public static function ivancicData(): array
    {
        $data = [];
        $fileData = self::decodeInstanceFile('ivancic.txt');
        foreach ($fileData as &$problem) {
            $problem[0] = "Ivancic #{$problem[0]}";
            $data[$problem[0]] = [$problem[0], $problem[1], $problem[2]];
        }

        return $data;
    }
}
