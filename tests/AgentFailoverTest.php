<?php

namespace Lab\Tests;

use Lab\EventLog;
use RuntimeException;

/**
 * A Laravel AI SDK agent in a queued job, with a primary and a backup provider.
 *
 * Both providers are a fake OpenAI-compatible server (bin/fake-ai-provider.php). The primary
 * answers the first step with a call of the SendInvoiceEmail tool and the second with 429. The
 * backup answers with the same tool call, then with the final text. How often does the tool run?
 * The database queue (SQLite), no broker, no real model.
 */
final class AgentFailoverTest extends ExperimentTestCase
{
    public function testAToolRunsAgainAfterAProviderFailover(): void
    {
        $dir = self::logDirectory('agent-failover');
        exec('rm -rf '.escapeshellarg($dir.'/storage').' '.escapeshellarg($dir.'/fake').' '.escapeshellarg($dir).'/*.jsonl');
        mkdir($dir.'/fake');

        [$server, $url] = self::fakeProvider($dir.'/fake');

        try {
            $env = ['LAB_DATABASE' => self::sqliteQueue($dir), 'LAB_STORAGE' => $dir.'/storage', 'LAB_AI_URL' => $url];

            self::lab(['agent-failover', 'agent'], $dir.'/dispatch.jsonl', $env);
            self::lab(['artisan', 'queue:work', 'database', '--queue=agent', '--sleep=1', '--stop-when-empty'], $dir.'/worker.jsonl', $env);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        $log = EventLog::read($dir.'/worker.jsonl');
        $requests = static fn (string $provider): int => (int) @file_get_contents("{$dir}/fake/{$provider}.count");
        $toolRuns = count(self::events($log, 'tool.run'));

        fwrite(STDERR, sprintf(
            "\nagent-failover: SendInvoiceEmail ran %d times; requests: primary %d, backup %d; failed over: %d; answered: %d; job failed: %d\n",
            $toolRuns, $requests('primary'), $requests('backup'),
            count(self::events($log, 'agent.failed_over')), count(self::events($log, 'agent.answered')), count(self::events($log, 'job.failed')),
        ));

        // The primary ran the tool and then failed; the SDK started the whole run over on the
        // backup, whose model asked for the tool again.
        $this->assertSame(2, $requests('primary'));
        $this->assertSame(2, $requests('backup'));
        $this->assertCount(1, self::events($log, 'agent.failed_over'));
        $this->assertCount(1, self::events($log, 'agent.answered'));
        $this->assertSame(2, $toolRuns);
    }

    /**
     * Starts `php -S` with the fake provider on a free port.
     *
     * @return array{resource, string}
     */
    private static function fakeProvider(string $state): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__).'/bin/fake-ai-provider.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $state.'/server.out', 'w'], 2 => ['file', $state.'/server.out', 'a']],
            $pipes,
            null,
            ['LAB_FAKE_STATE' => $state] + getenv(),
        );

        if (! is_resource($server)) {
            throw new RuntimeException('Could not start the fake provider');
        }

        for ($i = 0; $i < 50; $i++) {
            if ($connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) {
                fclose($connection);

                return [$server, "http://127.0.0.1:{$port}"];
            }

            usleep(100_000);
        }

        throw new RuntimeException('The fake provider did not start');
    }
}
