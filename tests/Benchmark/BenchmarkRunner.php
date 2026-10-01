<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker\Benchmark;

use RuntimeException;

use function array_slice;
use function count;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function hrtime;
use function implode;
use function is_dir;
use function json_decode;
use function json_encode;
use function max;
use function mkdir;
use function proc_close;
use function proc_open;
use function sort;
use function sprintf;
use function stream_get_contents;
use function usort;
use function array_column;
use function array_flip;
use function array_sum;
use function dirname;
use function floor;
use function strnatcmp;
use function min;

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
     * @param  array<string, mixed>            $instance
     * @return array<string, int|float|string>
     */
    public function solve(array $instance): array
    {
        $itemCount = $instance['items']->count();
        $start = hrtime(true);
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
            'util' => $packedBoxes->getVolumeUtilisation(),
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
        for ($shard = 0; $shard < $jobs; ++$shard) {
            $cmd = [PHP_BINARY, '-d', 'memory_limit=-1', '-d', 'zend.assertions=-1', $script, '--worker', "--shard={$shard}/{$jobs}", ...$workerArgs];
            $pipes = [];
            $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
            if ($process === false) {
                throw new RuntimeException('Could not start worker');
            }
            $processes[] = [$process, $pipes[1]];
        }

        $rows = [];
        foreach ($processes as [$process, $stdout]) {
            $output = stream_get_contents($stdout);
            fclose($stdout);
            $exit = proc_close($process);
            if ($exit !== 0) {
                throw new RuntimeException("Worker failed with exit code {$exit}");
            }
            foreach (json_decode($output, true, flags: JSON_THROW_ON_ERROR) as $row) {
                $rows[] = $row;
            }
        }

        return self::sortRows($rows, $datasets);
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
        foreach ($byDataset as $dataset => $datasetRows) {
            $times = array_column($datasetRows, 'time');
            sort($times);
            $n = count($datasetRows);
            $entry = [
                'kind' => $datasetRows[0]['kind'],
                'n' => $n,
                'timeMean' => array_sum($times) / $n,
                'timeP95' => $times[(int) floor(0.95 * ($n - 1))],
                'timeMax' => max($times),
                'unpacked' => array_sum(array_column($datasetRows, 'items')) - array_sum(array_column($datasetRows, 'packed')),
            ];
            $entry['util'] = array_sum(array_column($datasetRows, 'util')) / $n;
            $entry['invalid'] = array_sum(array_column($datasetRows, 'invalid'));
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
     * @param array<string, array<string, int|float|string>>      $summary
     * @param array<string, array<string, int|float|string>>|null $baseline
     */
    public static function formatSummary(array $summary, ?array $baseline = null, string $title = ''): string
    {
        $lines = [];
        if ($title !== '') {
            $lines[] = $title;
        }
        $lines[] = sprintf('%-12s %5s  %-28s %-16s %8s %8s %8s %8s', 'dataset', 'n', 'result', 'vs baseline', 't mean', 't p95', 't max', 'support');
        $totals = ['n' => 0, 'time' => 0.0];
        foreach ($summary as $dataset => $s) {
            $b = $baseline[$dataset] ?? null;
            if ($s['kind'] === 'single') {
                $result = sprintf('util %5.1f%%', $s['util']);
                $delta = $b ? sprintf('%+5.2f pts', $s['util'] - $b['util']) : '';
            } else {
                $result = sprintf('boxes %d', $s['boxes']);
                if ($s['lb'] > 0) {
                    $result .= sprintf(' (LB %d)', $s['lb']);
                }
                $result .= sprintf(' vol %.3g', $s['boxVolume']);
                $delta = $b ? sprintf('%+d boxes %+.2f%%vol', $s['boxes'] - $b['boxes'], 100 * ($s['boxVolume'] - $b['boxVolume']) / max(1, $b['boxVolume'])) : '';
            }
            if ($s['kind'] === 'multi' && $s['unpacked'] > 0) {
                $result .= " UNPACKED {$s['unpacked']}";
            }
            if (($s['invalid'] ?? 0) > 0) {
                $result .= " INVALID {$s['invalid']}";
            }
            if ($b) {
                $delta .= sprintf(' t×%.2f', $s['timeMean'] / max(1e-9, $b['timeMean']));
            }
            $lines[] = sprintf('%-12s %5d  %-28s %-16s %7.3fs %7.3fs %7.3fs %7.0f%%', $dataset, $s['n'], $result, $delta, $s['timeMean'], $s['timeP95'], $s['timeMax'], 100 * ($s['support'] ?? 1));
            $totals['n'] += $s['n'];
            $totals['time'] += $s['timeMean'] * $s['n'];
        }
        $lines[] = sprintf('total instances %d, total solve time %.1fs', $totals['n'], $totals['time']);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Per-instance win/loss counts against a baseline result file.
     *
     * @param list<array<string, int|float|string>> $rows
     * @param list<array<string, int|float|string>> $baselineRows
     */
    public static function formatWinLoss(array $rows, array $baselineRows): string
    {
        $base = [];
        foreach ($baselineRows as $row) {
            $base[$row['dataset'] . '|' . $row['id']] = $row;
        }
        $counts = [];
        foreach ($rows as $row) {
            $key = $row['dataset'] . '|' . $row['id'];
            if (!isset($base[$key])) {
                continue;
            }
            $b = $base[$key];
            if ($row['kind'] === 'single') {
                $cmp = $row['util'] <=> $b['util'];
            } else {
                $cmp = ($b['boxes'] <=> $row['boxes']) ?: ($b['boxVolume'] <=> $row['boxVolume']);
                if ($row['packed'] !== $b['packed']) {
                    $cmp = $row['packed'] <=> $b['packed'];
                }
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
     * @param array<string, mixed> $result
     */
    public static function save(string $path, array $result): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
