<?php

namespace Lab\Tests;

use Lab\EventLog;

/**
 * Workers that are paused or in maintenance mode.
 *
 * Laravel pauses a worker while the application is in maintenance mode (`artisan down`) and
 * after SIGUSR2 until SIGCONT, and skips the queues paused with `queue:pause`. Each situation
 * has a storage directory of its own (LAB_STORAGE), so its maintenance flag and the file cache
 * with the restart and pause flags reach only its own workers. Every worker has its own queue,
 * except the two workers of "hoard", which share one. All workers start idle.
 *
 * Timeline: the workers are held (artisan down, SIGUSR2, queue:pause) and get SETTLE seconds.
 * Then come the stop signals (SIGTERM, queue:restart) and new jobs, and the workers get HOLD
 * seconds. Then the hold ends (artisan up, SIGCONT, queue:resume) and they get AFTER seconds
 * more. At the end the queues of "down-restart" are drained to see which jobs were left and
 * which were delivered again.
 */
final class PausedWorkerTest extends ExperimentTestCase
{
    /**
     * --sleep of every worker. A worker sees a new hold at its next loop, within one SLEEP.
     */
    private const SLEEP = 3;

    private const SETTLE = 2 * self::SLEEP + 1;

    private const HOLD = 15;

    private const AFTER = 15;

    /**
     * Worker name => [situation, command and options]. work-hoard-healthy shares the queue of
     * consume-hoard but not its storage, so it isn't in maintenance mode.
     */
    private const WORKERS = [
        'work-down-sigterm' => ['down-sigterm', ['queue:work']],
        'consume-down-sigterm' => ['down-sigterm', ['rabbitmq:consume']],
        'consume-force-down-sigterm' => ['down-sigterm', ['rabbitmq:consume', '--force']],
        'work-sigusr2-sigterm' => ['sigusr2-sigterm', ['queue:work']],
        'consume-sigusr2-sigterm' => ['sigusr2-sigterm', ['rabbitmq:consume']],
        'work-idle-restart' => ['idle-restart', ['queue:work']],
        'consume-idle-restart' => ['idle-restart', ['rabbitmq:consume']],
        'work-down-restart' => ['down-restart', ['queue:work']],
        'consume-down-restart' => ['down-restart', ['rabbitmq:consume']],
        'work-queue-pause' => ['queue-pause', ['queue:work']],
        'consume-queue-pause' => ['queue-pause', ['rabbitmq:consume']],
        'consume-hoard' => ['hoard', ['rabbitmq:consume']],
        'work-hoard-healthy' => ['hoard-healthy', ['queue:work']],
    ];

    /**
     * @var array<string, array{exitCode: int|null, log: list<array<string, mixed>>}>
     */
    private static array $results = [];

    /**
     * microtime() values: when the holds were in place, when the stop signals and the new jobs
     * went out, and when each situation's hold ended.
     *
     * @var array{held: float, signalled: float, released: array<string, float>}
     */
    private static array $times;

    /**
     * Job name => delivered again, for the queues of down-restart after the run.
     *
     * @var array<string, array<string, bool>>
     */
    private static array $drains = [];

    public static function setUpBeforeClass(): void
    {
        self::waitForBroker();

        $dir = self::logDirectory('paused-worker');
        $workers = [];
        $queues = [];
        $released = [];

        foreach (array_keys(self::WORKERS) as $name) {
            $queues[$name] = "paused-worker-{$name}-".bin2hex(random_bytes(4));
        }

        $queues['work-hoard-healthy'] = $queues['consume-hoard'];

        // A paused consumer ignores SIGINT too: on Ctrl+C, kill the workers before exiting.
        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function () use (&$workers): void {
                self::stopAll($workers);

                exit(130);
            });
        }

        try {
            // consume-hoard runs X first; work-hoard-healthy starts once X is taken.
            foreach (self::WORKERS as $name => [$situation]) {
                if ($name !== 'work-hoard-healthy') {
                    self::lab(['push', $queues[$name], 'X'], "{$dir}/{$name}.push.jsonl", self::storage($dir, $situation));
                    $workers[$name] = self::worker($dir, $name, $queues[$name]);
                }
            }

            self::waitUntilIdle($workers);

            $workers['work-hoard-healthy'] = self::worker($dir, 'work-hoard-healthy', $queues['work-hoard-healthy']);

            // Hold the workers.
            foreach (['down-sigterm', 'down-restart', 'hoard'] as $situation) {
                self::lab(['artisan', 'down'], "{$dir}/{$situation}.down.jsonl", self::storage($dir, $situation));
            }

            self::signal($workers, 'sigusr2-sigterm', SIGUSR2);

            foreach (['work-queue-pause', 'consume-queue-pause'] as $name) {
                self::lab(['artisan', 'queue:pause', "rabbitmq:{$queues[$name]}"], "{$dir}/{$name}.pause.jsonl", self::storage($dir, 'queue-pause'));
            }

            $held = microtime(true);
            sleep(self::SETTLE);

            // Stop signals and new jobs.
            $signalled = microtime(true);
            self::signal($workers, 'down-sigterm', SIGTERM);
            self::signal($workers, 'sigusr2-sigterm', SIGTERM);

            foreach (['idle-restart', 'down-restart'] as $situation) {
                self::lab(['artisan', 'queue:restart'], "{$dir}/{$situation}.restart.jsonl", self::storage($dir, $situation));
            }

            foreach (['work-down-restart', 'consume-down-restart'] as $name) {
                self::lab(['push', $queues[$name], 'Y1', 'Y2', 'Y3'], "{$dir}/{$name}.push-y.jsonl", self::storage($dir, 'down-restart'));
            }

            foreach (['work-queue-pause', 'consume-queue-pause'] as $name) {
                self::lab(['push', $queues[$name], 'Z'], "{$dir}/{$name}.push-z.jsonl", self::storage($dir, 'queue-pause'));
            }

            self::lab(['push', $queues['consume-hoard'], 'H1', 'H2', 'H3'], "{$dir}/hoard.push-h.jsonl", self::storage($dir, 'hoard'));

            self::watch($workers, $signalled, $signalled + self::HOLD);

            // End the holds, each situation on its own clock.
            foreach (['down-sigterm', 'down-restart', 'hoard'] as $situation) {
                self::lab(['artisan', 'up'], "{$dir}/{$situation}.up.jsonl", self::storage($dir, $situation));
                $released[$situation] = microtime(true);
            }

            self::signal($workers, 'sigusr2-sigterm', SIGCONT);
            $released['sigusr2-sigterm'] = microtime(true);

            foreach (['work-queue-pause', 'consume-queue-pause'] as $name) {
                self::lab(['artisan', 'queue:resume', "rabbitmq:{$queues[$name]}"], "{$dir}/{$name}.resume.jsonl", self::storage($dir, 'queue-pause'));
            }

            $released['queue-pause'] = microtime(true);

            self::watch($workers, $signalled, max($released) + self::AFTER);
        } finally {
            self::stopAll($workers);

            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, SIG_DFL);
            }
        }

        foreach (['work-down-restart', 'consume-down-restart'] as $name) {
            self::$drains[$name] = self::drain($dir, $name, $queues[$name]);
        }

        foreach ($workers as $name => $worker) {
            self::$results[$name] = ['exitCode' => $worker['exitCode'], 'log' => EventLog::read($worker['log'])];
        }

        self::$times = ['held' => $held, 'signalled' => $signalled, 'released' => $released];

        // The timings in seconds after the stop signals, next to the event logs.
        file_put_contents("{$dir}/results.json", json_encode([
            'signalledAt' => $signalled,
            'held' => round($held - $signalled, 2),
            'released' => array_map(static fn (float $time): float => round($time - $signalled, 2), $released),
            'workers' => array_map(static function (array $run) use ($signalled): array {
                $stopping = self::events($run['log'], 'worker.stopping')[0] ?? null;

                return [
                    'stoppedAfter' => $stopping === null ? null : round($stopping['time'] - $signalled, 2),
                    'reason' => $stopping['reason'] ?? null,
                    'exitCode' => $run['exitCode'],
                    'jobs' => array_column(self::events($run['log'], 'job.end'), 'job'),
                ];
            }, self::$results),
            'drains' => self::$drains,
        ], JSON_PRETTY_PRINT).PHP_EOL);
    }

    public function testQueueWorkInMaintenanceModeStopsOnSigtermAtOnce(): void
    {
        $run = self::$results['work-down-sigterm'];

        $this->assertWasHeld($run);
        $this->assertStopsWithin($run, self::$times['signalled'], 1, 'Interrupted');
    }

    public function testConsumeInMaintenanceModeIgnoresSigtermUntilTheAppIsUp(): void
    {
        $run = self::$results['consume-down-sigterm'];
        $released = self::$times['released']['down-sigterm'];

        $this->assertNoStopBefore($run, $released);
        $this->assertStopsWithin($run, $released, self::AFTER, 'Interrupted');
    }

    public function testConsumeWithForceInMaintenanceModeStopsOnSigtermAtOnce(): void
    {
        $this->assertStopsWithin(self::$results['consume-force-down-sigterm'], self::$times['signalled'], 1, 'Interrupted');
    }

    public function testQueueWorkPausedWithSigusr2StopsOnSigtermAtOnce(): void
    {
        $run = self::$results['work-sigusr2-sigterm'];

        $this->assertNotEmpty(self::events($run['log'], 'worker.pausing'), 'SIGUSR2 reached the worker');
        $this->assertWasHeld($run);
        $this->assertStopsWithin($run, self::$times['signalled'], 1, 'Interrupted');
    }

    public function testConsumePausedWithSigusr2IgnoresSigtermUntilSigcont(): void
    {
        $run = self::$results['consume-sigusr2-sigterm'];
        $released = self::$times['released']['sigusr2-sigterm'];

        $this->assertNotEmpty(self::events($run['log'], 'worker.pausing'), 'SIGUSR2 reached the worker');
        $this->assertNoStopBefore($run, $released);
        $this->assertStopsWithin($run, $released, self::AFTER, 'Interrupted');
    }

    public function testIdleWorkersStopOnQueueRestartWithinOneSleep(): void
    {
        foreach (['work-idle-restart', 'consume-idle-restart'] as $name) {
            $this->assertStopsWithin(self::$results[$name], self::$times['signalled'], self::SLEEP + 1, 'ReceivedRestartSignal');
        }
    }

    public function testQueueWorkInMaintenanceModeStopsOnQueueRestartAndLeavesTheNewJobs(): void
    {
        $run = self::$results['work-down-restart'];

        $this->assertWasHeld($run);
        $this->assertStopsWithin($run, self::$times['signalled'], self::SLEEP + 1, 'ReceivedRestartSignal');
        $this->assertSame([], self::jobs($run['log'], 'Y'));
        $this->assertSame(['Y1' => false, 'Y2' => false, 'Y3' => false], self::$drains['work-down-restart'], 'the next worker gets all three');
    }

    public function testConsumeInMaintenanceModeRunsOneNewJobAfterTheAppIsUpThenRestarts(): void
    {
        $run = self::$results['consume-down-restart'];
        $released = self::$times['released']['down-restart'];

        $this->assertNoStopBefore($run, $released);
        $this->assertStopsWithin($run, $released, self::AFTER, 'ReceivedRestartSignal');

        $this->assertSame(['Y1'], self::jobs($run['log'], 'Y'), 'the old process runs the first new job');
        $y1 = array_values(array_filter(self::events($run['log'], 'job.start'), static fn (array $line): bool => $line['job'] === 'Y1'))[0];
        $this->assertGreaterThan($released, $y1['time'], 'Y1 runs after the app is up');
        $this->assertLessThan(self::events($run['log'], 'worker.stopping')[0]['time'], $y1['time'], 'and before the restart');

        $this->assertSame(['Y2' => true, 'Y3' => true], self::$drains['consume-down-restart'], 'the other two go back to the queue');
    }

    public function testQueueWorkSkipsAPausedQueue(): void
    {
        $run = self::$results['work-queue-pause'];
        $resumed = self::$times['released']['queue-pause'];

        $this->assertNotEmpty(self::events($run['log'], 'worker.queue_paused'), 'the worker sees the paused queue');
        $this->assertSame([], self::jobs($run['log'], 'Z', before: $resumed), 'no job from a paused queue');
        $this->assertSame(['Z'], self::jobs($run['log'], 'Z'), 'Z runs after queue:resume');
    }

    public function testConsumeIgnoresAPausedQueue(): void
    {
        $run = self::$results['consume-queue-pause'];

        $this->assertSame(['Z'], self::jobs($run['log'], 'Z', before: self::$times['released']['queue-pause']), 'rabbitmq:consume runs Z from the paused queue');
    }

    public function testConsumeInMaintenanceModeHoldsJobsThatAnotherWorkerCannotTake(): void
    {
        $paused = self::$results['consume-hoard'];
        $healthy = self::$results['work-hoard-healthy'];
        $released = self::$times['released']['hoard'];

        $this->assertNotEmpty(self::events($healthy['log'], 'job.popping'), 'the other worker asks for jobs');
        $this->assertSame([], self::jobs($healthy['log'], 'H'), 'but never gets one');
        $this->assertSame([], self::jobs($paused['log'], 'H', before: $released), 'the paused consumer runs none while the app is down');
        $this->assertSame(['H1', 'H2', 'H3'], self::jobs($paused['log'], 'H'), 'it runs all three after artisan up');
    }

    /**
     * A held queue:work doesn't ask for jobs: no job.popping in a window longer than SLEEP that
     * starts once the hold has reached the worker.
     *
     * @param  array{log: list<array<string, mixed>>}  $run
     */
    private function assertWasHeld(array $run): void
    {
        $from = self::$times['held'] + self::SLEEP + 0.5;

        $popping = array_filter(
            self::events($run['log'], 'job.popping'),
            static fn (array $line): bool => $line['time'] > $from && $line['time'] < self::$times['signalled'],
        );

        $this->assertSame([], array_values($popping), 'the worker was held');
    }

    /**
     * @param  array{exitCode: int|null, log: list<array<string, mixed>>}  $run
     */
    private function assertStopsWithin(array $run, float $since, float $seconds, string $reason): void
    {
        $stopping = self::events($run['log'], 'worker.stopping')[0] ?? null;

        $this->assertNotNull($stopping, 'the worker stops');
        $this->assertSame($reason, $stopping['reason']);
        $this->assertGreaterThanOrEqual($since, $stopping['time']);
        $this->assertLessThanOrEqual($since + $seconds, $stopping['time'], sprintf('the worker stops within %s s', $seconds));
        $this->assertSame(0, $run['exitCode'], 'the process exits with code 0');
    }

    /**
     * @param  array{log: list<array<string, mixed>>}  $run
     */
    private function assertNoStopBefore(array $run, float $time): void
    {
        $stopping = self::events($run['log'], 'worker.stopping')[0] ?? null;

        $this->assertTrue(
            $stopping === null || $stopping['time'] >= $time,
            sprintf('the worker keeps running while it is held (it stopped %.1f s after the signal)', ($stopping['time'] ?? 0) - self::$times['signalled']),
        );
    }

    /**
     * Names of the finished jobs that start with $prefix, in order, optionally only those that
     * finished before $before.
     *
     * @param  list<array<string, mixed>>  $log
     * @return list<string>
     */
    private static function jobs(array $log, string $prefix, ?float $before = null): array
    {
        $ended = array_filter(
            self::events($log, 'job.end'),
            static fn (array $line): bool => str_starts_with($line['job'], $prefix) && ($before === null || $line['time'] < $before),
        );

        return array_values(array_column($ended, 'job'));
    }

    /**
     * Drains a queue with queue:work --stop-when-empty and returns, by job name, whether each
     * job was delivered again.
     *
     * @return array<string, bool>
     */
    private static function drain(string $dir, string $name, string $queue): array
    {
        $log = "{$dir}/{$name}.drain.jsonl";

        self::lab(
            ['artisan', 'queue:work', 'rabbitmq', "--queue={$queue}", '--stop-when-empty', '--sleep=0', '--timeout=60'],
            $log,
            ['LAB_STORAGE' => "{$dir}/storage-drain"],
        );

        $deliveries = array_column(self::events(EventLog::read($log), 'job.start'), 'redelivered', 'job');
        ksort($deliveries);

        return $deliveries;
    }

    /**
     * @return array{situation: string, log: string, process: resource, exitedAfter: null, exitCode: null}
     */
    private static function worker(string $dir, string $name, string $queue): array
    {
        [$situation, $command] = self::WORKERS[$name];
        $log = "{$dir}/{$name}.worker.jsonl";

        return [
            'situation' => $situation,
            'log' => $log,
            'process' => self::start(
                array_merge(
                    ['artisan', $command[0], 'rabbitmq', "--queue={$queue}", '--sleep='.self::SLEEP, '--timeout=60', '--max-time=300'],
                    array_slice($command, 1),
                ),
                $log,
                "{$dir}/{$name}.worker.out",
                self::storage($dir, $situation),
            ),
            'exitedAfter' => null,
            'exitCode' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function storage(string $dir, string $situation): array
    {
        return ['LAB_STORAGE' => "{$dir}/storage-{$situation}"];
    }

    /**
     * Sends a signal to the running workers of one situation.
     *
     * @param  array<string, array{situation: string, process: resource}>  $workers
     */
    private static function signal(array $workers, string $situation, int $signal): void
    {
        foreach ($workers as $worker) {
            if ($worker['situation'] === $situation && proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process'], $signal);
            }
        }
    }
}
