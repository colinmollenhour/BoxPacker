<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use function file_put_contents;
use function str_replace;

use const FILE_APPEND;
use const LOCK_EX;

/**
 * Writes gitignored sibling lastrun CSVs so a baseline can be accepted by
 * copying lastrun over expected after a complete suite.
 */
trait LastrunCsvSupport
{
    /**
     * @var array<string, true>
     */
    private static array $lastrunStarted = [];

    /**
     * published-expected-ecommerce.csv → published-lastrun-ecommerce.csv
     * ivancic-expected.csv → ivancic-lastrun.csv
     * expected.csv → lastrun.csv.
     */
    protected static function lastrunPath(string $expectedBasename): string
    {
        $basename = str_replace('-expected', '-lastrun', $expectedBasename);
        if ($basename === $expectedBasename) {
            $basename = str_replace('expected', 'lastrun', $expectedBasename);
        }

        return __DIR__ . '/data/' . $basename;
    }

    /**
     * First write of this process replaces the file so a new run does not
     * append onto yesterday's output. Later rows append. We wait until the
     * first actual result rather than truncating in setUpBeforeClass, so a
     * --filter that only exercises one of a class's CSVs does not wipe the
     * other lastrun file.
     */
    protected static function appendLastrun(?string $path, string $line): void
    {
        if ($path === null) {
            return;
        }

        if (!isset(self::$lastrunStarted[$path])) {
            file_put_contents($path, $line, LOCK_EX);
            self::$lastrunStarted[$path] = true;

            return;
        }

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
