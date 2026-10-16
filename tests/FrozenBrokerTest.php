<?php

namespace Lab\Tests;

use Lab\EventLog;
use RuntimeException;

/**
 * Idle workers while the broker stops answering.
 *
 * `docker compose pause` freezes the RabbitMQ processes. TCP stays up: Docker's port
 * forwarding and the kernel keep accepting what the client sends, but no frame comes back.
 * Each worker gets its own queue with one quick job, so it has talked to the broker and is
 * idle when the broker freezes. The workers run in parallel. The broker stays paused for
 * FREEZE seconds, then the workers are watched for AFTER more seconds.
 */
final class FrozenBrokerTest extends ExperimentTestCase
{
    private const HEARTBEAT = 15;

    private const FREEZE = 100;

    private const AFTER = 20;

    /**
     * Worker environment for an RPC timeout of 10 s. php-amqplib refuses a channel_rpc_timeout
     * above the socket read and write timeout, so those go from 3 s to 10 s as well.
     */
    private const RPC_TIMEOUT_ENV = ['LAB_IO_TIMEOUT' => '10', 'LAB_RPC_TIMEOUT' => '10'];

    /**
     * Name => [command and options, worker environment]. LAB_HEARTBEAT defaults to HEARTBEAT.
     */
    private const SCENARIOS = [
        'work' => [['queue:work'], []],
        'work-max-time' => [['queue:work', '--max-time=60'], []],
        'work-rpc-timeout' => [['queue:work'], self::RPC_TIMEOUT_ENV],
        'work-rpc-timeout-no-heartbeat' => [['queue:work'], ['LAB_IO_TIMEOUT' => '10', 'LAB_RPC_TIMEOUT' => '10', 'LAB_HEARTBEAT' => '0']],
        'consume' => [['rabbitmq:consume'], []],
        'consume-max-time' => [['rabbitmq:consume', '--max-time=60'], []],
        'consume-max-time-rpc-timeout' => [['rabbitmq:consume', '--max-time=60'], self::RPC_TIMEOUT_ENV],
    ];

    /**
     * exitedAfter: seconds from the start of the freeze to the exit, null if the worker was
     * still running AFTER seconds after the freeze. freeze: how long the broker was paused.
     *
     * @var array<string, array{exitedAfter: float|null, exitCode: int|null, log: list<array<string, mixed>>, paused: float, unpaused: float, freeze: float}>
     */
    private static array $results = [];

    public static function setUpBeforeClass(): void
    {
        // A run that was killed during the freeze leaves the broker paused, and the port still
        // accepts connections then, so waitForBroker() alone would not notice.
        self::compose('unpause', check: false);
        self::waitForBroker();

        $dir = self::logDirectory('frozen-broker');
        $workers = [];

        try {
            foreach (self::SCENARIOS as $name => [$command, $env]) {
                $queue = "frozen-broker-{$name}-".bin2hex(random_bytes(4));
                $env += ['LAB_HEARTBEAT' => (string) self::HEARTBEAT];
                $log = "{$dir}/{$name}.worker.jsonl";

                self::lab(['push', $queue, 'X'], "{$dir}/{$name}.push.jsonl", $env);

                $args = array_merge(
                    ['artisan', $command[0], 'rabbitmq', "--queue={$queue}", '--sleep=3', '--timeout=60'],
                    array_slice($command, 1),
                );

                // A worker without its own --max-time gets --max-time=600: if the test fails to stop
                // it, it ends by itself once the broker answers again.
                if (preg_grep('/^--max-time=/', $command) === []) {
                    $args[] = '--max-time=600';
                }

                $workers[$name] = [
                    'log' => $log,
                    'process' => self::start($args, $log, "{$dir}/{$name}.worker.out", $env),
                    'exitedAfter' => null,
                    'exitCode' => null,
                ];
            }

            self::waitUntilIdle($workers);

            self::compose('pause');
            $paused = microtime(true);

            try {
                self::watch($workers, $paused, $paused + self::FREEZE);
            } finally {
                // The freeze ends before the unpause: workers react as soon as the broker is back.
                $unpaused = microtime(true);
                self::unpause();
            }

            self::watch($workers, $paused, $unpaused + self::AFTER);
        } finally {
            self::stopAll($workers);
        }

        foreach ($workers as $name => $worker) {
            self::$results[$name] = [
                'exitedAfter' => $worker['exitedAfter'],
                'exitCode' => $worker['exitCode'],
                'log' => EventLog::read($worker['log']),
                'paused' => $paused,
                'unpaused' => $unpaused,
                'freeze' => $unpaused - $paused,
            ];
        }

        // The timings, next to the event logs.
        file_put_contents("{$dir}/results.json", json_encode([
            'freeze' => round($unpaused - $paused, 1),
            'paused' => $paused,
            'unpaused' => $unpaused,
            'scenarios' => array_map(static fn (array $run): array => [
                'exitedAfter' => $run['exitedAfter'] === null ? null : round($run['exitedAfter'], 1),
                'exitCode' => $run['exitCode'],
            ], self::$results),
        ], JSON_PRETTY_PRINT).PHP_EOL);
    }

    public function testQueueWorkWaitsUntilTheBrokerIsBack(): void
    {
        $run = self::$results['work'];

        $this->assertRanThroughTheFreeze($run, 'queue:work waits for basic.get-ok without a timeout');
        $this->assertSame([], self::during($run, 'exception'), 'nothing is reported while the broker is frozen');

        $this->assertExitedAfterTheFreeze($run, 0);
        $this->assertStringContainsString('Lost connection: Missed server heartbeat', self::messages(self::after($run, 'exception')));
        $this->assertSame('LostConnection', self::after($run, 'worker.stopping')[0]['reason'] ?? null);
    }

    public function testQueueWorkDoesNotCheckMaxTimeWhileItWaits(): void
    {
        $run = self::$results['work-max-time'];

        $this->assertRanThroughTheFreeze($run, '--max-time is checked only after pop() returns');
        $this->assertSame([], self::during($run, 'worker.stopping'));
    }

    public function testQueueWorkWithAnRpcTimeoutStopsOnMissedHeartbeats(): void
    {
        $run = self::$results['work-rpc-timeout'];

        $this->assertExitedDuringTheFreeze($run, 0);
        $this->assertGreaterThan(2 * self::HEARTBEAT, $run['exitedAfter'], 'php-amqplib gives up after twice the heartbeat timeout without a frame');
        $this->assertStringContainsString('Lost connection: Missed server heartbeat', self::messages(self::during($run, 'exception')));
        $this->assertSame('LostConnection', self::during($run, 'worker.stopping')[0]['reason'] ?? null);
    }

    public function testQueueWorkWithAnRpcTimeoutButNoHeartbeatKeepsWaiting(): void
    {
        $run = self::$results['work-rpc-timeout-no-heartbeat'];

        $this->assertRanThroughTheFreeze($run, 'without a heartbeat a timed-out basic_get is not a lost connection');
        $this->assertGreaterThanOrEqual(2, count(self::during($run, 'exception')), 'every basic_get times out and is reported');
        $this->assertSame([], self::during($run, 'worker.stopping'));
    }

    public function testConsumeDoesNotNoticeTheFrozenBroker(): void
    {
        $run = self::$results['consume'];

        $this->assertRanThroughTheFreeze($run, "the client's own heartbeats count as activity");
        $this->assertSame([], self::during($run, 'exception'), 'nothing is reported while the broker is frozen');
    }

    public function testConsumeStopsOnMaxTimeButWaitsForTheChannelToClose(): void
    {
        $run = self::$results['consume-max-time'];

        $this->assertSame('MaxTimeExceeded', self::during($run, 'worker.stopping')[0]['reason'] ?? null, '--max-time ends the consume loop');
        $this->assertRanThroughTheFreeze($run, 'closing the channel waits for channel.close-ok without a timeout');
        $this->assertExitedAfterTheFreeze($run, 0);
    }

    public function testConsumeWithMaxTimeAndAnRpcTimeoutExitsWithAnError(): void
    {
        $run = self::$results['consume-max-time-rpc-timeout'];

        $this->assertSame('MaxTimeExceeded', self::during($run, 'worker.stopping')[0]['reason'] ?? null);
        $this->assertExitedDuringTheFreeze($run, 1);
        $this->assertContains('PhpAmqpLib\Exception\AMQPHeartbeatMissedException', array_column(self::during($run, 'exception'), 'class'));
    }

    /**
     * @param  array{exitedAfter: float|null, exitCode: int|null, freeze: float}  $run
     */
    private function assertRanThroughTheFreeze(array $run, string $message): void
    {
        $this->assertTrue(
            $run['exitedAfter'] === null || $run['exitedAfter'] >= $run['freeze'],
            sprintf('%s (exited %.1f s after the freeze began, with code %s)', $message, $run['exitedAfter'] ?? 0, var_export($run['exitCode'], true)),
        );
    }

    /**
     * @param  array{exitedAfter: float|null, exitCode: int|null, log: list<array<string, mixed>>, freeze: float}  $run
     */
    private function assertExitedDuringTheFreeze(array $run, int $code): void
    {
        $this->assertNotNull($run['exitedAfter'], 'the worker exits while the broker is frozen');
        $this->assertLessThan($run['freeze'], $run['exitedAfter'], 'the worker exits while the broker is frozen');
        $this->assertSame($code, $run['exitCode']);
        $this->assertSame([$code], array_column(self::events($run['log'], 'process.exit'), 'status'), 'bin/lab returns the same status');
    }

    /**
     * @param  array{exitedAfter: float|null, exitCode: int|null, freeze: float}  $run
     */
    private function assertExitedAfterTheFreeze(array $run, int $code): void
    {
        $this->assertNotNull($run['exitedAfter'], sprintf('the worker exits within %d s after the broker is back', self::AFTER));
        $this->assertGreaterThanOrEqual($run['freeze'], $run['exitedAfter']);
        $this->assertSame($code, $run['exitCode']);
    }

    /**
     * Events of one kind logged while the broker was paused.
     *
     * @param  array{log: list<array<string, mixed>>, paused: float, unpaused: float}  $run
     * @return list<array<string, mixed>>
     */
    private static function during(array $run, string $event): array
    {
        return array_values(array_filter(
            self::events($run['log'], $event),
            static fn (array $line): bool => $line['time'] >= $run['paused'] && $line['time'] <= $run['unpaused'],
        ));
    }

    /**
     * Events of one kind logged after the broker was back.
     *
     * @param  array{log: list<array<string, mixed>>, unpaused: float}  $run
     * @return list<array<string, mixed>>
     */
    private static function after(array $run, string $event): array
    {
        return array_values(array_filter(
            self::events($run['log'], $event),
            static fn (array $line): bool => $line['time'] > $run['unpaused'],
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private static function messages(array $events): string
    {
        return implode("\n", array_column($events, 'message'));
    }

    private static function unpause(): void
    {
        try {
            self::compose('unpause');
        } catch (RuntimeException) {
            sleep(1);
            self::compose('unpause');
        }
    }

    private static function compose(string $command, bool $check = true): void
    {
        $process = proc_open(
            ['docker', 'compose', $command, 'rabbitmq'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            dirname(__DIR__),
        );

        if (! is_resource($process)) {
            throw new RuntimeException("Could not run docker compose {$command} rabbitmq");
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        if (proc_close($process) !== 0 && $check) {
            throw new RuntimeException("docker compose {$command} rabbitmq failed: {$output}");
        }
    }
}
