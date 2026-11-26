<?php

namespace Lab\Tests;

use Lab\EventLog;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the experiments share: the broker check, running bin/lab and reading event logs.
 */
abstract class ExperimentTestCase extends TestCase
{
    /**
     * A temporary directory for the event logs of one experiment.
     */
    protected static function logDirectory(string $experiment): string
    {
        $dir = sys_get_temp_dir()."/laravel-queue-lab-{$experiment}-".getmypid();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /**
     * An SQLite file with Laravel's jobs table, for the experiments on the database queue
     * (LAB_DATABASE). It replaces the file of an earlier run.
     */
    protected static function sqliteQueue(string $dir): string
    {
        $file = $dir.'/queue.sqlite';
        @unlink($file);

        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL, reserved_at INTEGER NULL, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX jobs_queue_index ON jobs (queue)');

        return $file;
    }

    /** @return array{resource, string} */
    protected static function fakeAiProvider(string $state): array
    {
        return self::serveFixture('bin/fake-ai-provider.php', $state.'/server.out', ['LAB_FAKE_STATE' => $state]);
    }

    /** @return array{resource, string} */
    protected static function serveFixture(string $router, string $output, array $env): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException("Could not reserve a loopback port for {$router}");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false) {
            throw new RuntimeException("Could not read the loopback address for {$router}");
        }

        $root = dirname(__DIR__);
        $server = proc_open(
            [PHP_BINARY, '-S', $address, $root.'/'.$router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
            $pipes,
            $root,
            $env + getenv(),
        );
        if (! is_resource($server)) {
            throw new RuntimeException("Could not start {$router}");
        }

        for ($attempt = 0; $attempt < 50 && proc_get_status($server)['running']; $attempt++) {
            if ($connection = @stream_socket_client('tcp://'.$address, timeout: 0.1)) {
                fclose($connection);

                return [$server, 'http://'.$address];
            }
            usleep(100_000);
        }

        proc_terminate($server);
        proc_close($server);
        throw new RuntimeException("{$router} did not start, see {$output}");
    }

    protected static function waitForBroker(): void
    {
        $host = getenv('LAB_AMQP_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('LAB_AMQP_PORT') ?: 5672);

        for ($i = 0; $i < 60; $i++) {
            $socket = @fsockopen($host, $port, $errno, $error, 1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            sleep(1);
        }

        throw new RuntimeException("RabbitMQ is not reachable on {$host}:{$port}. Start it with: docker compose up -d --wait");
    }

    /**
     * Runs bin/lab and expects exit code 0.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    protected static function lab(array $args, string $log, array $env = []): void
    {
        $exit = proc_close(self::start($args, $log, $log.'.out', $env));

        if ($exit !== 0) {
            throw new RuntimeException('bin/lab '.implode(' ', $args)." exited with {$exit}, see {$log}.out");
        }
    }

    /**
     * Starts bin/lab in the background. Connection options and the storage directory come only
     * from $env, never from the environment of the test run, so that every experiment runs with
     * the same settings. Without LAB_STORAGE a process gets the storage directory next to its
     * log, so a manual `bin/lab artisan down` doesn't pause the workers of an experiment.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     * @return resource
     */
    protected static function start(array $args, string $log, string $output, array $env = [])
    {
        $inherited = array_diff_key(getenv(), array_flip(['LAB_HEARTBEAT', 'LAB_IO_TIMEOUT', 'LAB_RPC_TIMEOUT', 'LAB_STORAGE', 'LAB_DATABASE_RETRY_AFTER']));
        $env += ['LAB_STORAGE' => dirname($log).'/storage'];

        $process = proc_open(
            array_merge([PHP_BINARY, dirname(__DIR__).'/bin/lab'], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
            $pipes,
            dirname(__DIR__),
            ['LAB_EVENT_LOG' => $log] + $env + $inherited,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start bin/lab '.implode(' ', $args));
        }

        return $process;
    }

    /**
     * @param  list<array<string, mixed>>  $log
     * @return list<array<string, mixed>>
     */
    protected static function events(array $log, string $event): array
    {
        return array_values(array_filter($log, static fn (array $line): bool => $line['event'] === $event));
    }

    /**
     * Waits until every worker has run its job, so that all of them are idle.
     *
     * @param  array<string, array{log: string, process: resource}>  $workers
     */
    protected static function waitUntilIdle(array $workers): void
    {
        $deadline = microtime(true) + 60;

        foreach ($workers as $name => $worker) {
            while (self::events(EventLog::read($worker['log']), 'job.end') === []) {
                if (! proc_get_status($worker['process'])['running'] || microtime(true) > $deadline) {
                    throw new RuntimeException("The {$name} worker did not run its job, see {$worker['log']}");
                }

                usleep(200_000);
            }
        }

        // Let the workers get back to waiting for the next job.
        sleep(2);
    }

    /**
     * Records when each worker exits, in seconds after $since, until $until (microtime() values).
     *
     * @param  array<string, array{process: resource, exitedAfter: float|null, exitCode: int|null}>  $workers
     */
    protected static function watch(array &$workers, float $since, float $until): void
    {
        while (microtime(true) < $until) {
            foreach ($workers as $name => $worker) {
                if ($worker['exitedAfter'] !== null) {
                    continue;
                }

                $status = proc_get_status($worker['process']);

                if (! $status['running']) {
                    $workers[$name]['exitedAfter'] = microtime(true) - $since;
                    $workers[$name]['exitCode'] = $status['exitcode'];
                }
            }

            usleep(200_000);
        }
    }

    /**
     * Kills the workers that are still running and closes their process handles.
     *
     * @param  array<string, array{process: resource}>  $workers
     */
    protected static function stopAll(array $workers): void
    {
        foreach ($workers as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process'], SIGKILL);
            }

            proc_close($worker['process']);
        }
    }
}
