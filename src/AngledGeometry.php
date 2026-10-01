<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use function abs;
use function acos;
use function array_values;
use function atan2;
use function ceil;
use function cos;
use function count;
use function deg2rad;
use function floor;
use function hypot;
use function max;
use function min;
use function sin;
use function sort;

use const M_PI_2;
use const PHP_FLOAT_MAX;

/**
 * Geometry of items turned about the vertical axis by an arbitrary angle.
 *
 * Conventions: an item's footprint is width × length, turned anticlockwise (x towards y) by an angle in [0°, 90°)
 * and positioned by the minimum corner of its (axis-aligned) bounding box. Parallel items in a group are offset
 * along y only, so the group is as wide as one item and each extra item adds the same pitch to its length.
 *
 * @internal
 */
final class AngledGeometry
{
    /**
     * Tolerance for floating point comparisons (in the same units as the dimensions).
     */
    public const EPSILON = 1e-6;

    /**
     * Horizontal bands used to describe the top of an angled group as axis-aligned rectangles (for support).
     */
    private const SUPPORT_BANDS = 16;

    /**
     * Fractions along the legs of a corner triangle at which rectangles are inscribed in it.
     *
     * @var list<float>
     */
    private const TRIANGLE_STEPS = [0.25, 0.5, 0.75];

    /**
     * Smallest integer that is at least $value, forgiving floating point noise.
     */
    public static function ceilDimension(float $value): int
    {
        return (int) ceil($value - self::EPSILON);
    }

    /**
     * Smallest anticlockwise angle (radians) at which a $long × $short footprint spans no more than $span along x,
     * or null if it never does (turning past 90° only swaps the sides). 0 if it already fits.
     */
    public static function minimumAngle(int $long, int $short, int $span): ?float
    {
        if ($long <= $span) {
            return 0.0;
        }
        if ($short > $span || $span <= 0) {
            return null; // even turned all the way (90°) the short side is too long
        }

        // long·cosθ + short·sinθ = diagonal·cos(θ - φ), which falls back down to $span past its peak at θ = φ
        $diagonal = hypot($long, $short);
        $angle = atan2($short, $long) + acos($span / $diagonal);
        if ($angle >= M_PI_2) {
            return null;
        }

        // nudge so that rounding the extent up to a whole unit cannot overshoot the span
        for ($nudge = 0; $nudge < 8 && self::ceilDimension($long * cos($angle) + $short * sin($angle)) > $span; ++$nudge) {
            $angle += 1e-9 * (10 ** $nudge);
        }

        return $angle < M_PI_2 ? $angle : null;
    }

    /**
     * The narrowest arrangement of parallel $long × $short items spanning at most $span along x: the angle, the
     * width of the group, the length (along y) of one item and the pitch between items. Null if it is impossible.
     *
     * Items are positioned in whole units, so the pitch is rounded up (leaving a sliver of space between items).
     *
     * @return array{angle: float, width: int, itemLength: int, pitch: int}|null
     */
    public static function layout(int $long, int $short, int $span): ?array
    {
        $angle = self::minimumAngle($long, $short, $span);
        if ($angle === null || $angle === 0.0) {
            return null; // not needed (fits straight) or not possible
        }

        $cos = cos($angle);
        $sin = sin($angle);

        return [
            'angle' => $angle,
            'width' => self::ceilDimension($long * $cos + $short * $sin),
            'itemLength' => self::ceilDimension($long * $sin + $short * $cos),
            'pitch' => self::ceilDimension($short / $cos),
        ];
    }

    /**
     * The ways up an item may go when turned at an angle, as [long side, short side, height]: only the way it is
     * defined for KeepFlat, any for BestFit, none for Never.
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public static function uprights(Item $item): array
    {
        $w = $item->getWidth();
        $l = $item->getLength();
        $d = $item->getDepth();
        $uprights = match ($item->getAllowedRotation()) {
            Rotation::Never => [],
            Rotation::KeepFlat => [[$w, $l, $d]],
            Rotation::BestFit => [[$w, $l, $d], [$w, $d, $l], [$l, $d, $w]],
        };

        $distinct = [];
        foreach ($uprights as [$a, $b, $h]) {
            $distinct[max($a, $b) . '|' . min($a, $b) . '|' . $h] = [max($a, $b), min($a, $b), $h];
        }

        return array_values($distinct);
    }

    /**
     * Whether a single $long × $short × $height item fits a $width × $length × $depth space turned at an angle.
     */
    public static function fitsAngled(int $long, int $short, int $height, int $width, int $length, int $depth): bool
    {
        if ($height > $depth) {
            return false;
        }
        $acrossWidth = self::layout($long, $short, $width);
        if ($acrossWidth !== null && $acrossWidth['itemLength'] <= $length) {
            return true;
        }
        $acrossLength = self::layout($long, $short, $length);

        return $acrossLength !== null && $acrossLength['itemLength'] <= $width;
    }

    /**
     * Whether an item fits the box only when turned at an angle: no allowed orientation fits it square, but one
     * turned about the vertical axis does. Items with placement callbacks are never angled.
     */
    public static function onlyFitsAngled(Item $item, Box $box): bool
    {
        if ($item instanceof ConstrainedPlacementItem) {
            return false;
        }
        $width = $box->getInnerWidth();
        $length = $box->getInnerLength();
        $depth = $box->getInnerDepth();
        $w = $item->getWidth();
        $l = $item->getLength();
        $d = $item->getDepth();
        $orientations = match ($item->getAllowedRotation()) {
            Rotation::Never => [[$w, $l, $d]],
            Rotation::KeepFlat => [[$w, $l, $d], [$l, $w, $d]],
            Rotation::BestFit => [[$w, $l, $d], [$l, $w, $d], [$w, $d, $l], [$l, $d, $w], [$d, $w, $l], [$d, $l, $w]],
        };
        foreach ($orientations as [$ow, $ol, $oh]) {
            if ($ow <= $width && $ol <= $length && $oh <= $depth) {
                return false;
            }
        }
        foreach (self::uprights($item) as [$long, $short, $height]) {
            if (self::fitsAngled($long, $short, $height, $width, $length, $depth)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bounding box (width, length) of a width × length footprint turned by $angleDegrees.
     *
     * @return array{int, int}
     */
    public static function boundingBox(int $width, int $length, float $angleDegrees): array
    {
        if ($angleDegrees == 0.0) {
            return [$width, $length];
        }
        $angle = deg2rad($angleDegrees);
        $cos = cos($angle);
        $sin = sin($angle);

        return [self::ceilDimension($width * $cos + $length * $sin), self::ceilDimension($width * $sin + $length * $cos)];
    }

    /**
     * Corners of a footprint, anticlockwise, starting from the one on the bottom (minimum y) edge of the bounding box.
     *
     * @return list<array{float, float}>
     */
    public static function corners(int $x, int $y, int $width, int $length, float $angleDegrees): array
    {
        if ($angleDegrees == 0.0) {
            return [[(float) $x, (float) $y], [(float) ($x + $width), (float) $y], [(float) ($x + $width), (float) ($y + $length)], [(float) $x, (float) ($y + $length)]];
        }
        $angle = deg2rad($angleDegrees);
        $cos = cos($angle);
        $sin = sin($angle);
        $x0 = $x + $length * $sin;
        $x1 = $x0 + $width * $cos;
        $y1 = $y + $width * $sin;

        return [[$x0, (float) $y], [$x1, $y1], [$x1 - $length * $sin, $y1 + $length * $cos], [(float) $x, $y + $length * $cos]];
    }

    /**
     * Corners of a packed item's footprint, see {@see PackedItem::getFootprint()}.
     *
     * @return list<array{float, float}>
     */
    public static function footprint(PackedItem $item): array
    {
        return $item->getFootprint();
    }

    /**
     * Whether two convex polygons overlap by more than touching (separating axis test).
     *
     * @param list<array{float, float}> $a
     * @param list<array{float, float}> $b
     */
    public static function polygonsOverlap(array $a, array $b): bool
    {
        foreach ([$a, $b] as $polygon) {
            $count = count($polygon);
            for ($i = 0; $i < $count; ++$i) {
                $p = $polygon[$i];
                $q = $polygon[($i + 1) % $count];
                $axisX = $p[1] - $q[1];
                $axisY = $q[0] - $p[0];
                $length = hypot($axisX, $axisY);
                if ($length < self::EPSILON) {
                    continue;
                }
                [$minA, $maxA] = self::project($a, $axisX / $length, $axisY / $length);
                [$minB, $maxB] = self::project($b, $axisX / $length, $axisY / $length);
                if ($maxA <= $minB + self::EPSILON || $maxB <= $minA + self::EPSILON) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Area of the intersection of two convex polygons (both anticlockwise).
     *
     * @param list<array{float, float}> $subject
     * @param list<array{float, float}> $clip
     */
    public static function intersectionArea(array $subject, array $clip): float
    {
        $output = $subject;
        $count = count($clip);
        for ($i = 0; $i < $count && $output; ++$i) {
            $edgeStart = $clip[$i];
            $edgeEnd = $clip[($i + 1) % $count];
            $input = $output;
            $output = [];
            $inputCount = count($input);
            for ($j = 0; $j < $inputCount; ++$j) {
                $current = $input[$j];
                $previous = $input[($j + $inputCount - 1) % $inputCount];
                $currentInside = self::side($edgeStart, $edgeEnd, $current) >= -self::EPSILON;
                $previousInside = self::side($edgeStart, $edgeEnd, $previous) >= -self::EPSILON;
                if ($currentInside) {
                    if (!$previousInside) {
                        $output[] = self::crossing($previous, $current, $edgeStart, $edgeEnd);
                    }
                    $output[] = $current;
                } elseif ($previousInside) {
                    $output[] = self::crossing($previous, $current, $edgeStart, $edgeEnd);
                }
            }
        }

        return self::area($output);
    }

    /**
     * @param list<array{float, float}> $polygon
     */
    public static function area(array $polygon): float
    {
        $count = count($polygon);
        $sum = 0.0;
        for ($i = 0; $i < $count; ++$i) {
            $p = $polygon[$i];
            $q = $polygon[($i + 1) % $count];
            $sum += $p[0] * $q[1] - $q[0] * $p[1];
        }

        return abs($sum) / 2;
    }

    /**
     * Whether a polygon lies within the rectangle [0, width] × [0, length].
     *
     * @param list<array{float, float}> $polygon
     */
    public static function withinRectangle(array $polygon, int $width, int $length): bool
    {
        foreach ($polygon as [$x, $y]) {
            if ($x < -self::EPSILON || $y < -self::EPSILON || $x > $width + self::EPSILON || $y > $length + self::EPSILON) {
                return false;
            }
        }

        return true;
    }

    /**
     * Outline of a group of $count parallel $long × $short items laid out by {@see layout()}, relative to the
     * group's bounding box, anticlockwise.
     *
     * @param array{angle: float, width: int, itemLength: int, pitch: int} $layout
     *
     * @return list<array{float, float}>
     */
    public static function groupOutline(array $layout, int $long, int $short, int $count): array
    {
        $cos = cos($layout['angle']);
        $sin = sin($layout['angle']);
        $shift = ($count - 1) * $layout['pitch'];

        return [
            [$short * $sin, 0.0],
            [$short * $sin + $long * $cos, $long * $sin],
            [$short * $sin + $long * $cos, $long * $sin + $shift],
            [$long * $cos, $long * $sin + $short * $cos + $shift],
            [0.0, $short * $cos + $shift],
            [0.0, $short * $cos],
        ];
    }

    /**
     * Axis-aligned rectangles (in whole units) lying within the union of some convex polygons, in horizontal bands
     * between $bottom and $top along y: what an item placed on top of angled items can rest on.
     *
     * @param list<list<array{float, float}>> $polygons
     *
     * @return list<array{int, int, int, int}> x, y, width, length
     */
    public static function supportRectangles(array $polygons, float $bottom, float $top): array
    {
        $rectangles = [];
        for ($band = 0; $band < self::SUPPORT_BANDS; ++$band) {
            $y0 = (int) ceil($bottom + ($top - $bottom) * $band / self::SUPPORT_BANDS - self::EPSILON);
            $y1 = (int) floor($bottom + ($top - $bottom) * ($band + 1) / self::SUPPORT_BANDS + self::EPSILON);
            if ($y1 <= $y0) {
                continue;
            }
            // what each polygon covers all the way across the band (being convex, what it covers at both edges),
            // merged where polygons meet
            $intervals = [];
            foreach ($polygons as $polygon) {
                [$left0, $right0] = self::span($polygon, $y0);
                [$left1, $right1] = self::span($polygon, $y1);
                $left = max($left0, $left1);
                $right = min($right0, $right1);
                if ($right > $left) {
                    $intervals[] = [$left, $right];
                }
            }
            foreach (self::mergeIntervals($intervals) as [$left, $right]) {
                $left = (int) ceil($left - self::EPSILON);
                $right = (int) floor($right + self::EPSILON);
                if ($right > $left) {
                    $rectangles[] = [$left, $y0, $right - $left, $y1 - $y0];
                }
            }
        }

        return $rectangles;
    }

    /**
     * Axis-aligned rectangles, relative to the group's bounding box, inscribed in the empty triangles in its four
     * corners. They overlap each other (each is as large as it can be at its step), like maximal free spaces.
     *
     * @param list<array{float, float}> $outline from {@see groupOutline()}
     *
     * @return list<array{int, int, int, int}> x, y, width, length
     */
    public static function cornerRectangles(array $outline, int $width, int $length): array
    {
        [$bottom, $right, $rightTop, $top, $left, $leftBottom] = $outline;
        $triangles = [
            // corner, leg along x, leg along y (signed: the direction the leg runs from the corner)
            [0, 0, $bottom[0], $leftBottom[1]],
            [$width, 0, -($width - $bottom[0]), $right[1]],
            [$width, $length, -($width - $top[0]), -($length - $rightTop[1])],
            [0, $length, $top[0], -($length - $left[1])],
        ];

        $rectangles = [];
        foreach ($triangles as [$cornerX, $cornerY, $legX, $legY]) {
            foreach (self::TRIANGLE_STEPS as $step) {
                $rectangleWidth = (int) floor(abs($legX) * (1 - $step) + self::EPSILON);
                $rectangleLength = (int) floor(abs($legY) * $step + self::EPSILON);
                if ($rectangleWidth <= 0 || $rectangleLength <= 0) {
                    continue;
                }
                $rectangles[] = [
                    $legX >= 0 ? $cornerX : $cornerX - $rectangleWidth,
                    $legY >= 0 ? $cornerY : $cornerY - $rectangleLength,
                    $rectangleWidth,
                    $rectangleLength,
                ];
            }
        }

        return $rectangles;
    }

    /**
     * Overlapping or touching intervals combined, sorted.
     *
     * @param list<array{float, float}> $intervals
     *
     * @return list<array{float, float}>
     */
    private static function mergeIntervals(array $intervals): array
    {
        sort($intervals);
        $merged = [];
        foreach ($intervals as [$left, $right]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $left <= $merged[$last][1] + self::EPSILON) {
                $merged[$last][1] = max($merged[$last][1], $right);
            } else {
                $merged[] = [$left, $right];
            }
        }

        return $merged;
    }

    /**
     * Leftmost and rightmost x of a convex polygon at height y.
     *
     * @param list<array{float, float}> $polygon
     *
     * @return array{float, float}
     */
    private static function span(array $polygon, float $y): array
    {
        $left = PHP_FLOAT_MAX;
        $right = -PHP_FLOAT_MAX;
        $count = count($polygon);
        for ($i = 0; $i < $count; ++$i) {
            [$x0, $y0] = $polygon[$i];
            [$x1, $y1] = $polygon[($i + 1) % $count];
            if ($y < min($y0, $y1) - self::EPSILON || $y > max($y0, $y1) + self::EPSILON) {
                continue;
            }
            if (abs($y1 - $y0) < self::EPSILON) {
                $left = min($left, $x0, $x1);
                $right = max($right, $x0, $x1);
                continue;
            }
            $x = $x0 + ($x1 - $x0) * ($y - $y0) / ($y1 - $y0);
            $left = min($left, $x);
            $right = max($right, $x);
        }

        return [$left, $right];
    }

    /**
     * @param list<array{float, float}> $polygon
     *
     * @return array{float, float}
     */
    private static function project(array $polygon, float $axisX, float $axisY): array
    {
        $min = PHP_FLOAT_MAX;
        $max = -PHP_FLOAT_MAX;
        foreach ($polygon as [$x, $y]) {
            $value = $x * $axisX + $y * $axisY;
            $min = min($min, $value);
            $max = max($max, $value);
        }

        return [$min, $max];
    }

    /**
     * Positive when $point is to the left of the directed edge (inside, for an anticlockwise polygon).
     *
     * @param array{float, float} $edgeStart
     * @param array{float, float} $edgeEnd
     * @param array{float, float} $point
     */
    private static function side(array $edgeStart, array $edgeEnd, array $point): float
    {
        return ($edgeEnd[0] - $edgeStart[0]) * ($point[1] - $edgeStart[1]) - ($edgeEnd[1] - $edgeStart[1]) * ($point[0] - $edgeStart[0]);
    }

    /**
     * Where the segment from $p to $q crosses the line through the edge.
     *
     * @param array{float, float} $p
     * @param array{float, float} $q
     * @param array{float, float} $edgeStart
     * @param array{float, float} $edgeEnd
     *
     * @return array{float, float}
     */
    private static function crossing(array $p, array $q, array $edgeStart, array $edgeEnd): array
    {
        $sideP = self::side($edgeStart, $edgeEnd, $p);
        $sideQ = self::side($edgeStart, $edgeEnd, $q);
        $t = $sideP / ($sideP - $sideQ);

        return [$p[0] + ($q[0] - $p[0]) * $t, $p[1] + ($q[1] - $p[1]) * $t];
    }
}
