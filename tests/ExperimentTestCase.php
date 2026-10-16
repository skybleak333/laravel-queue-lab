<?php

namespace Lab\Tests;

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
     * Starts bin/lab in the background. Connection options come only from $env, never from
     * the environment of the test run, so that every experiment runs with the same settings.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     * @return resource
     */
    protected static function start(array $args, string $log, string $output, array $env = [])
    {
        $inherited = array_diff_key(getenv(), array_flip(['LAB_HEARTBEAT', 'LAB_IO_TIMEOUT', 'LAB_RPC_TIMEOUT']));

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
}
