<?php

namespace Lab\Tests;

use Lab\DebouncedJob;
use Lab\EventLog;
use PDO;

/**
 * Laravel 13 debounce with maxWait, under a steady stream of dispatches.
 *
 * DebouncedJob is debounced for DEBOUNCE seconds with maxWait MAX_WAIT: while dispatches keep
 * coming, maxWait should force a run at least every MAX_WAIT seconds. The experiment dispatches
 * it every INTERVAL_MS for SECONDS to the database queue (SQLite), with one queue:work.
 * No broker is involved.
 */
final class DebounceMaxWaitTest extends ExperimentTestCase
{
    private const SECONDS = 60;

    private const INTERVAL_MS = 500;

    /**
     * --sleep of the worker, Laravel's default.
     */
    private const SLEEP = 3;

    public function testForcedRunsAreDiscardedWhileDispatchesKeepComing(): void
    {
        $dir = self::logDirectory('debounce');
        // Debounce state lives in the file cache: start without any from an earlier run.
        exec('rm -rf '.escapeshellarg($dir.'/storage').' '.escapeshellarg($dir).'/*.jsonl');
        $env = ['LAB_DATABASE' => self::database($dir), 'LAB_STORAGE' => $dir.'/storage'];

        $worker = self::start(
            ['artisan', 'queue:work', 'database', '--queue=debounce', '--sleep='.self::SLEEP, '--tries=1'],
            $dir.'/worker.jsonl', $dir.'/worker.out', $env,
        );

        try {
            sleep(2);
            self::lab(['debounce', (string) self::SECONDS, (string) self::INTERVAL_MS], $dir.'/dispatch.jsonl', $env);
            // The trailing run, DEBOUNCE seconds after the last dispatch.
            sleep(DebouncedJob::DEBOUNCE + self::SLEEP + 2);
        } finally {
            proc_terminate($worker, SIGTERM);
            proc_close($worker);
        }

        $dispatches = self::events(EventLog::read($dir.'/dispatch.jsonl'), 'debounce.dispatch');
        $log = EventLog::read($dir.'/worker.jsonl');
        $runs = self::events($log, 'debounce.run');
        $discarded = self::events($log, 'debounce.discarded');

        $start = $dispatches[0]['time'];
        $end = end($dispatches)['time'];
        $forced = count(array_filter($dispatches, static fn (array $e): bool => $e['forced']));
        $forcedDiscarded = count(array_filter($discarded, static fn (array $e): bool => $e['forced']));
        $runsWhileDispatching = array_values(array_filter(
            array_map(static fn (array $e): float => $e['time'] - $start, $runs),
            static fn (float $t): bool => $t <= $end - $start,
        ));
        $points = [0.0, ...$runsWhileDispatching, $end - $start];
        $longestGap = max(array_map(static fn (float $a, float $b): float => $b - $a, array_slice($points, 0, -1), array_slice($points, 1)));

        fwrite(STDERR, sprintf(
            "\ndebounce: %d dispatches, maxWait forced %d, %d of them discarded; %d runs while dispatching, longest wait %.1f s (maxWait %d s), %d runs in total\n",
            count($dispatches), $forced, $forcedDiscarded, count($runsWhileDispatching), $longestGap, DebouncedJob::MAX_WAIT, count($runs),
        ));

        // maxWait did force runs...
        $this->assertGreaterThanOrEqual(3, $forced);
        // ...but a forced job is discarded as superseded when the next dispatch comes before
        // the worker takes it, so the promised cap doesn't hold. A correct cap is maxWait plus
        // one --sleep of the worker and the job itself.
        $this->assertGreaterThan(0, $forcedDiscarded);
        $this->assertGreaterThan(DebouncedJob::MAX_WAIT + self::SLEEP + 1, $longestGap);
    }

    /**
     * An SQLite file with Laravel's jobs table.
     */
    private static function database(string $dir): string
    {
        $file = $dir.'/queue.sqlite';
        @unlink($file);

        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL, reserved_at INTEGER NULL, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX jobs_queue_index ON jobs (queue)');

        return $file;
    }
}
