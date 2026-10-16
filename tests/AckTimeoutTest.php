<?php

namespace Lab\Tests;

use Lab\EventLog;

/**
 * A job that runs longer than RabbitMQ's delivery acknowledgement timeout.
 *
 * Each scenario gets its own queue with a slow job A (it sleeps on its first delivery
 * only) and two quick jobs B and C. The three workers run in parallel. When a worker
 * has exited, its queue is drained with queue:work --stop-when-empty, and the drain
 * shows which messages the broker delivered again.
 */
final class AckTimeoutTest extends ExperimentTestCase
{
    private const SCENARIOS = [
        'work' => ['queue:work'],
        'consume' => ['rabbitmq:consume'],
        'consume-prefetch-1' => ['rabbitmq:consume', '--prefetch-count=1'],
    ];

    /**
     * --max-time stops a worker that would otherwise keep running, so a hang shows
     * up as a long run instead of a stuck test.
     */
    private const MAX_TIME = 420;

    /**
     * @var array<string, array{exit: int, seconds: float, worker: list<array<string, mixed>>, drain: list<array<string, mixed>>}>
     */
    private static array $results = [];

    public static function setUpBeforeClass(): void
    {
        self::waitForBroker();

        $slow = (int) (getenv('LAB_SLOW_JOB_SECONDS') ?: 150);
        $dir = self::logDirectory('ack-timeout');

        $runs = [];

        foreach (self::SCENARIOS as $name => $command) {
            $queue = "ack-timeout-{$name}-".bin2hex(random_bytes(4));

            self::lab(['push', $queue, "A:{$slow}", 'B', 'C'], "{$dir}/{$name}.push.jsonl");

            $runs[$name] = [
                'queue' => $queue,
                'log' => "{$dir}/{$name}.worker.jsonl",
                'started' => microtime(true),
                'process' => self::start(
                    array_merge(['artisan'], $command, [
                        'rabbitmq', "--queue={$queue}", '--timeout=600', '--sleep=1', '--tries=3', '--max-time='.self::MAX_TIME,
                    ]),
                    "{$dir}/{$name}.worker.jsonl",
                    "{$dir}/{$name}.worker.out",
                ),
            ];
        }

        foreach ($runs as $name => $run) {
            $exit = proc_close($run['process']);
            $seconds = microtime(true) - $run['started'];

            self::lab(
                ['artisan', 'queue:work', 'rabbitmq', "--queue={$run['queue']}", '--stop-when-empty', '--sleep=0', '--timeout=600'],
                "{$dir}/{$name}.drain.jsonl",
            );

            self::$results[$name] = [
                'exit' => $exit,
                'seconds' => $seconds,
                'worker' => EventLog::read($run['log']),
                'drain' => EventLog::read("{$dir}/{$name}.drain.jsonl"),
            ];
        }
    }

    public function testQueueWorkStopsOnTheClosedChannel(): void
    {
        $run = self::$results['work'];

        $this->assertExitedBeforeMaxTime($run);
        $this->assertSame(['A'], self::jobs($run['worker']), 'queue:work fetched only A before the channel was closed');

        $this->assertContains(406, array_column(self::events($run['worker'], 'exception'), 'code'));
        $this->assertStringContainsString(
            'Lost connection: Channel connection is closed.',
            implode("\n", array_column(self::events($run['worker'], 'exception'), 'message')),
        );
        $this->assertSame('LostConnection', self::events($run['worker'], 'worker.stopping')[0]['reason'] ?? null);

        $this->assertSame(['A' => true, 'B' => false, 'C' => false], self::deliveries($run['drain']), 'A is delivered again');
    }

    public function testConsumeRunsPrefetchedJobsAfterTheChannelIsClosed(): void
    {
        $run = self::$results['consume'];

        $this->assertExitedBeforeMaxTime($run);
        $this->assertSame(['A', 'B', 'C'], self::jobs($run['worker']), 'B and C come from the client buffer');

        $this->assertContains(406, array_column(self::events($run['worker'], 'exception'), 'code'));
        $this->assertSame([], self::events($run['worker'], 'worker.stopping'), 'the consume loop ends without WorkerStopping');

        $this->assertSame(['A' => true, 'B' => true, 'C' => true], self::deliveries($run['drain']), 'A, B and C are delivered again');
    }

    public function testConsumeWithPrefetchOneRepeatsOnlyTheSlowJob(): void
    {
        $run = self::$results['consume-prefetch-1'];

        $this->assertExitedBeforeMaxTime($run);
        $this->assertSame(['A'], self::jobs($run['worker']));

        $this->assertContains(406, array_column(self::events($run['worker'], 'exception'), 'code'));

        $this->assertSame(['A' => true, 'B' => false, 'C' => false], self::deliveries($run['drain']), 'only A is delivered again');
    }

    /**
     * @param  array{exit: int, seconds: float}  $run
     */
    private function assertExitedBeforeMaxTime(array $run): void
    {
        $this->assertSame(0, $run['exit'], 'the worker exits with code 0');
        $this->assertLessThan(self::MAX_TIME - 30, $run['seconds'], 'the worker exits on its own, not on --max-time');
    }

    /**
     * Job names in the order they finished.
     *
     * @param  list<array<string, mixed>>  $log
     * @return list<string>
     */
    private static function jobs(array $log): array
    {
        return array_column(self::events($log, 'job.end'), 'job');
    }

    /**
     * Whether each job was a redelivery, keyed by job name.
     *
     * @param  list<array<string, mixed>>  $log
     * @return array<string, bool>
     */
    private static function deliveries(array $log): array
    {
        $deliveries = array_column(self::events($log, 'job.start'), 'redelivered', 'job');
        ksort($deliveries);

        return $deliveries;
    }
}
