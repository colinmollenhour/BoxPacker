<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Benchmark;

use AssertionError;
use JsonException;
use RuntimeException;
use Throwable;

use function array_column;
use function array_filter;
use function array_flip;
use function array_slice;
use function array_sum;
use function array_values;
use function basename;
use function count;
use function dirname;
use function error_get_last;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function floor;
use function fwrite;
use function getmypid;
use function hrtime;
use function implode;
use function is_dir;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function mkdir;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function rename;
use function sort;
use function sprintf;
use function stream_get_contents;
use function strlen;
use function strnatcmp;
use function substr;
use function unlink;
use function usort;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const PHP_EOL;
use const STDERR;

/**
 * Runs benchmark datasets through a strategy, optionally in parallel worker processes, and reports.
 */
final class BenchmarkRunner
{
    /**
     * @param array<string, string> $options
     */
    public function __construct(private readonly string $strategy, private readonly array $options = [])
    {
    }

    /**
     * Solve every instance of a shard and return one result row per instance.
     *
     * @param  list<string>                          $datasets
     * @return list<array<string, int|float|string>>
     */
    public function runShard(array $datasets, int $limit, int $shard, int $shards): array
    {
        $rows = [];
        $index = 0;
        foreach ($datasets as $dataset) {
            $instances = InstanceLoader::load($dataset);
            if ($limit > 0) {
                $instances = array_slice($instances, 0, $limit);
            }
            foreach ($instances as $instance) {
                if ($index++ % $shards !== $shard) {
                    continue;
                }
                $rows[] = $this->solve($instance);
            }
        }

        return $rows;
    }

    /**
     * Solve one instance. A failure is returned as a row with an 'error' (and, for a failed assertion inside the
     * library, counted as invalid) so that one bad instance does not stop the rest of the run.
     *
     * @param  array<string, mixed>            $instance
     * @return array<string, int|float|string>
     */
    public function solve(array $instance): array
    {
        $itemCount = $instance['items']->count();
        $start = hrtime(true);
        try {
            return $this->solveInstance($instance, $itemCount, $start);
        } catch (Throwable $e) {
            return [
                'dataset' => $instance['dataset'],
                'id' => $instance['id'],
                'kind' => $instance['kind'],
                'packed' => 0,
                'items' => $itemCount,
                'time' => (hrtime(true) - $start) / 1e9,
                'invalid' => $e instanceof AssertionError ? 1 : 0,
                'error' => $e::class . ': ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>            $instance
     * @return array<string, int|float|string>
     */
    private function solveInstance(array $instance, int $itemCount, int|float $start): array
    {
        if ($instance['kind'] === 'single') {
            $packedBox = Strategies::single($this->strategy, $instance['box'], $instance['items'], $this->options);
            $time = (hrtime(true) - $start) / 1e9;

            return [
                'dataset' => $instance['dataset'],
                'id' => $instance['id'],
                'kind' => 'single',
                'util' => $packedBox->getVolumeUtilisation(),
                'packed' => $packedBox->items->count(),
                'items' => $itemCount,
                'time' => $time,
                'invalid' => count(PackingValidator::problems($packedBox)),
                'support' => PackingValidator::minimumSupport($packedBox),
            ];
        }

        $packedBoxes = Strategies::multi($this->strategy, $instance['boxes'], $instance['items'], $this->options);
        $time = (hrtime(true) - $start) / 1e9;
        $packed = 0;
        $boxVolume = 0;
        $invalid = 0;
        $support = 1.0;
        foreach ($packedBoxes as $packedBox) {
            $packed += $packedBox->items->count();
            $boxVolume += $packedBox->getInnerVolume();
            $invalid += count(PackingValidator::problems($packedBox));
            $support = min($support, PackingValidator::minimumSupport($packedBox));
        }

        return [
            'dataset' => $instance['dataset'],
            'id' => $instance['id'],
            'kind' => 'multi',
            'boxes' => $packedBoxes->count(),
            'lb' => $instance['lb'] ?? 0,
            'boxVolume' => $boxVolume,
            'util' => $packedBoxes->count() > 0 ? $packedBoxes->getVolumeUtilisation() : 0.0,
            'packed' => $packed,
            'items' => $itemCount,
            'time' => $time,
            'invalid' => $invalid,
            'support' => $support,
        ];
    }

    /**
     * Fan the shards out to worker processes and merge their rows.
     *
     * @param  list<string>                          $datasets
     * @param  list<string>                          $workerArgs arguments to pass through to each worker
     * @return list<array<string, int|float|string>>
     */
    public function runParallel(string $script, array $datasets, int $limit, int $jobs, array $workerArgs): array
    {
        $processes = [];
        try {
            for ($shard = 0; $shard < $jobs; ++$shard) {
                $cmd = [PHP_BINARY, '-d', 'memory_limit=-1', '-d', 'zend.assertions=-1', '-d', 'display_errors=stderr', $script, '--worker', "--shard={$shard}/{$jobs}", ...$workerArgs];
                $pipes = [];
                $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
                if ($process === false) {
                    throw new RuntimeException('Could not start worker');
                }
                $processes[$shard] = [$process, $pipes[1]];
            }

            $rows = [];
            foreach ($processes as $shard => [$process, $stdout]) {
                $output = (string) stream_get_contents($stdout);
                fclose($stdout);
                unset($processes[$shard]);
                $exit = proc_close($process);
                if ($exit !== 0) {
                    throw new RuntimeException("Worker {$shard}/{$jobs} failed with exit code {$exit}" . self::tail($output));
                }
                try {
                    $workerRows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException("Worker {$shard}/{$jobs} returned invalid JSON: {$e->getMessage()}" . self::tail($output), previous: $e);
                }
                foreach ($workerRows as $row) {
                    $rows[] = $row;
                }
            }
        } finally {
            foreach ($processes as [$process, $stdout]) {
                fclose($stdout);
                proc_terminate($process);
                proc_close($process);
            }
        }

        return self::sortRows($rows, $datasets);
    }

    /**
     * The end of a worker's output, for error messages.
     */
    private static function tail(string $output, int $bytes = 2000): string
    {
        if ($output === '') {
            return '; no output';
        }

        return '; output' . (strlen($output) > $bytes ? " (last {$bytes} bytes)" : '') . ':' . PHP_EOL . substr($output, -$bytes);
    }

    /**
     * @param  list<array<string, int|float|string>> $rows
     * @param  list<string>                          $datasets
     * @return list<array<string, int|float|string>>
     */
    public static function sortRows(array $rows, array $datasets): array
    {
        $order = array_flip($datasets);
        usort($rows, static fn (array $a, array $b) => ($order[$a['dataset']] <=> $order[$b['dataset']]) ?: strnatcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }

    /**
     * Per-dataset aggregates. Rows that ended in an error are counted under 'errors' (and their invalid count kept)
     * but left out of every other figure.
     *
     * @param  list<array<string, int|float|string>>          $rows
     * @return array<string, array<string, int|float|string>>
     */
    public static function summarise(array $rows): array
    {
        $byDataset = [];
        foreach ($rows as $row) {
            $byDataset[$row['dataset']][] = $row;
        }

        $summary = [];
        foreach ($byDataset as $dataset => $allRows) {
            $datasetRows = array_values(array_filter($allRows, static fn (array $row) => !isset($row['error'])));
            $times = array_column($datasetRows, 'time') ?: [0.0];
            sort($times);
            $n = count($datasetRows);
            $entry = [
                'kind' => $allRows[0]['kind'],
                'n' => $n,
                'errors' => count($allRows) - $n,
                'timeMean' => array_sum($times) / max(1, $n),
                'timeP95' => $times[(int) floor(0.95 * (count($times) - 1))],
                'timeMax' => max($times),
                'unpacked' => array_sum(array_column($datasetRows, 'items')) - array_sum(array_column($datasetRows, 'packed')),
            ];
            $entry['util'] = array_sum(array_column($datasetRows, 'util')) / max(1, $n);
            $entry['invalid'] = array_sum(array_column($allRows, 'invalid'));
            $entry['support'] = min(array_column($datasetRows, 'support') ?: [1.0]);
            if ($entry['kind'] === 'multi') {
                $entry['boxes'] = array_sum(array_column($datasetRows, 'boxes'));
                $entry['lb'] = array_sum(array_column($datasetRows, 'lb'));
                $entry['boxVolume'] = array_sum(array_column($datasetRows, 'boxVolume'));
            }
            $summary[$dataset] = $entry;
        }

        return $summary;
    }

    /**
     * Pair up the rows of two runs by dataset and instance, so that aggregates are compared over the same instances.
     * Returns the matched rows of each run and the number of rows only in this run and only in the baseline.
     *
     * @param  list<array<string, int|float|string>>                                                                     $rows
     * @param  list<array<string, int|float|string>>                                                                     $baselineRows
     * @return array{0: list<array<string, int|float|string>>, 1: list<array<string, int|float|string>>, 2: int, 3: int}
     */
    public static function intersect(array $rows, array $baselineRows): array
    {
        $base = [];
        foreach ($baselineRows as $row) {
            $base[$row['dataset'] . '|' . $row['id']] = $row;
        }
        $matched = [];
        $matchedBase = [];
        foreach ($rows as $row) {
            $key = $row['dataset'] . '|' . $row['id'];
            if (isset($base[$key])) {
                $matched[] = $row;
                $matchedBase[] = $base[$key];
            }
        }

        return [$matched, $matchedBase, count($rows) - count($matched), count($baselineRows) - count($matchedBase)];
    }

    /**
     * @param array<string, array<string, int|float|string>>      $summary  shown as is
     * @param array<string, array<string, int|float|string>>|null $baseline compared against $matched
     * @param array<string, array<string, int|float|string>>|null $matched  this run over the baseline's instances (defaults to $summary)
     */
    public static function formatSummary(array $summary, ?array $baseline = null, string $title = '', ?array $matched = null): string
    {
        $matched ??= $summary;
        $lines = [];
        if ($title !== '') {
            $lines[] = $title;
        }
        $lines[] = sprintf('%-12s %5s  %-28s %-16s %8s %8s %8s %8s', 'dataset', 'n', 'result', 'vs baseline', 't mean', 't p95', 't max', 'support');
        $totals = ['n' => 0, 'time' => 0.0, 'errors' => 0];
        foreach ($summary as $dataset => $s) {
            $b = $baseline[$dataset] ?? null;
            $m = $matched[$dataset] ?? null;
            if ($m === null || $b === null || $m['n'] === 0 || $b['n'] === 0) {
                $b = null;
            }
            if ($s['kind'] === 'single') {
                $result = sprintf('util %5.1f%%', $s['util']);
                $delta = $b ? sprintf('%+5.2f pts', $m['util'] - $b['util']) : '';
            } else {
                $result = sprintf('boxes %d', $s['boxes']);
                if ($s['lb'] > 0) {
                    $result .= sprintf(' (LB %d)', $s['lb']);
                }
                $result .= sprintf(' vol %.3g', $s['boxVolume']);
                $delta = $b ? sprintf('%+d boxes %+.2f%%vol', $m['boxes'] - $b['boxes'], 100 * ($m['boxVolume'] - $b['boxVolume']) / max(1, $b['boxVolume'])) : '';
            }
            if ($s['kind'] === 'multi' && $s['unpacked'] > 0) {
                $result .= " UNPACKED {$s['unpacked']}";
            }
            if (($s['invalid'] ?? 0) > 0) {
                $result .= " INVALID {$s['invalid']}";
            }
            if (($s['errors'] ?? 0) > 0) {
                $result .= " ERRORS {$s['errors']}";
            }
            if ($b) {
                $delta .= sprintf(' t×%.2f', $m['timeMean'] / max(1e-9, $b['timeMean']));
            }
            $lines[] = sprintf('%-12s %5d  %-28s %-16s %7.3fs %7.3fs %7.3fs %7.0f%%', $dataset, $s['n'], $result, $delta, $s['timeMean'], $s['timeP95'], $s['timeMax'], 100 * ($s['support'] ?? 1));
            $totals['n'] += $s['n'];
            $totals['time'] += $s['timeMean'] * $s['n'];
            $totals['errors'] += $s['errors'] ?? 0;
        }
        $lines[] = sprintf('total instances %d, total solve time %.1fs', $totals['n'], $totals['time']) . ($totals['errors'] > 0 ? ", {$totals['errors']} failed with an error" : '');

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Per-instance win/loss counts against a baseline result file. An instance that failed with an error or produced
     * an invalid packing loses to one that did not.
     *
     * @param list<array<string, int|float|string>> $rows
     * @param list<array<string, int|float|string>> $baselineRows
     */
    public static function formatWinLoss(array $rows, array $baselineRows): string
    {
        [$matched, $matchedBase] = self::intersect($rows, $baselineRows);
        $counts = [];
        foreach ($matched as $i => $row) {
            $b = $matchedBase[$i];
            $cmp = self::failureRank($b) <=> self::failureRank($row);
            if ($cmp === 0 && !isset($row['error'])) {
                $cmp = self::compareResults($row, $b);
            }
            $counts[$row['dataset']][$cmp] = ($counts[$row['dataset']][$cmp] ?? 0) + 1;
        }
        $lines = ['per-instance vs baseline (better/same/worse):'];
        foreach ($counts as $dataset => $c) {
            $lines[] = sprintf('  %-12s %4d / %4d / %4d', $dataset, $c[1] ?? 0, $c[0] ?? 0, $c[-1] ?? 0);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * 1 if a row is better than its baseline row, 0 if the same, -1 if worse.
     *
     * @param array<string, int|float|string> $row
     * @param array<string, int|float|string> $baseline
     */
    private static function compareResults(array $row, array $baseline): int
    {
        if ($row['kind'] === 'single') {
            return $row['util'] <=> $baseline['util'];
        }
        if ($row['packed'] !== $baseline['packed']) {
            return $row['packed'] <=> $baseline['packed'];
        }

        return ($baseline['boxes'] <=> $row['boxes']) ?: ($baseline['boxVolume'] <=> $row['boxVolume']);
    }

    /**
     * 0 for a valid packing, 1 for an invalid one, 2 for an error.
     *
     * @param array<string, int|float|string> $row
     */
    private static function failureRank(array $row): int
    {
        if (isset($row['error'])) {
            return 2;
        }

        return ($row['invalid'] ?? 0) > 0 ? 1 : 0;
    }

    /**
     * Whether any row ended in an error or an invalid packing.
     *
     * @param list<array<string, int|float|string>> $rows
     */
    public static function hasFailures(array $rows): bool
    {
        foreach ($rows as $row) {
            if (self::failureRank($row) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function save(string $path, array $result): void
    {
        self::writeFile($path, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Write a file all at once: to a temporary file beside it, then renamed into place.
     */
    public static function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}: " . (error_get_last()['message'] ?? 'unknown error'));
        }
        $temp = $dir . '/.' . basename($path) . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, $contents) !== strlen($contents)) {
            $error = error_get_last()['message'] ?? 'short write';
            @unlink($temp);
            throw new RuntimeException("Cannot write {$path}: {$error}");
        }
        if (!@rename($temp, $path)) {
            $error = error_get_last()['message'] ?? 'unknown error';
            @unlink($temp);
            throw new RuntimeException("Cannot write {$path}: {$error}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadResult(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function stderr(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
