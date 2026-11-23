<?php

namespace Lab\Tests;

use Lab\EventLog;
use PDO;
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

    public function testTheOutboxKeepsOneInvoiceAfterWorkerDeathAndProviderFailover(): void
    {
        $dir = self::logDirectory('invoice-outbox-'.bin2hex(random_bytes(4)));
        $state = $dir.'/fake';
        mkdir($state);
        $database = self::sqliteQueue($dir);
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE invoice_outbox (invoice_id TEXT NOT NULL PRIMARY KEY, queued_at TEXT NOT NULL)');
        $retryAfter = 3;
        $workerArgs = ['artisan', 'queue:work', 'database', '--queue=agent', '--timeout=0', '--sleep=1', '--stop-when-empty'];
        touch($state.'/pause-primary');

        [$server, $url] = self::fakeProvider($state);
        $firstWorker = null;
        try {
            $env = [
                'LAB_DATABASE' => $database,
                'LAB_DATABASE_RETRY_AFTER' => (string) $retryAfter,
                'LAB_STORAGE' => $dir.'/storage',
                'LAB_AI_URL' => $url,
            ];
            self::lab(['agent-failover', 'agent', 'invoice-1042'], $dir.'/dispatch.jsonl', $env);
            $firstWorker = self::start($workerArgs, $dir.'/first.jsonl', $dir.'/first.out', $env);

            $deadline = microtime(true) + 10;
            while (! is_file($state.'/primary-waiting')) {
                if (! proc_get_status($firstWorker)['running'] || microtime(true) > $deadline) {
                    throw new RuntimeException("The first worker did not reach the post-tool request, see {$dir}/first.out");
                }

                usleep(20_000);
                clearstatcache(true, $state.'/primary-waiting');
            }

            // The tool committed before the waiting HTTP request. The queue job is still reserved.
            $outbox = $pdo->query('SELECT invoice_id, queued_at FROM invoice_outbox')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame(['invoice-1042'], array_column($outbox, 'invoice_id'));
            $reserved = $pdo->query('SELECT id, attempts, reserved_at FROM jobs')->fetch(PDO::FETCH_ASSOC);
            $this->assertIsArray($reserved);
            $this->assertSame(1, (int) $reserved['attempts']);
            $this->assertGreaterThan(0, (int) $reserved['reserved_at']);

            $this->assertTrue(proc_terminate($firstWorker, SIGKILL));
            proc_close($firstWorker);
            $firstWorker = null;
            unlink($state.'/pause-primary');

            while (time() < (int) $reserved['reserved_at'] + $retryAfter) {
                usleep(20_000);
            }
            self::lab($workerArgs, $dir.'/retry.jsonl', $env);

            $firstLog = EventLog::read($dir.'/first.jsonl');
            $retryLog = EventLog::read($dir.'/retry.jsonl');
            $firstAttempt = self::events($firstLog, 'agent.started');
            $retryAttempt = self::events($retryLog, 'agent.started');
            $this->assertCount(1, $firstAttempt);
            $this->assertCount(1, $retryAttempt);
            $this->assertSame((string) $reserved['id'], (string) $firstAttempt[0]['job_id']);
            $this->assertSame($firstAttempt[0]['job_id'], $retryAttempt[0]['job_id']);
            $this->assertNotSame($firstAttempt[0]['pid'], $retryAttempt[0]['pid']);
            $this->assertSame(1, $firstAttempt[0]['attempt']);
            $this->assertSame(2, $retryAttempt[0]['attempt']);
            $this->assertCount(1, self::events($firstLog, 'invoice.queued'));
            $this->assertCount(0, self::events($firstLog, 'agent.answered'));
            $this->assertCount(2, self::events($retryLog, 'tool.run'));
            $this->assertCount(2, self::events($retryLog, 'invoice.already_queued'));
            $this->assertCount(1, self::events($retryLog, 'agent.failed_over'));
            $this->assertSame(['The invoice email is queued.'], array_column(self::events($retryLog, 'agent.answered'), 'text'));
            $this->assertSame(4, (int) file_get_contents($state.'/primary.count'));
            $this->assertSame(2, (int) file_get_contents($state.'/backup.count'));
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
            $this->assertSame($outbox, $pdo->query('SELECT invoice_id, queued_at FROM invoice_outbox')->fetchAll(PDO::FETCH_ASSOC));

            self::lab(['agent-failover', 'agent', 'invoice-1043'], $dir.'/next-dispatch.jsonl', $env);
            self::lab($workerArgs, $dir.'/next-worker.jsonl', $env);
            $this->assertSame(['invoice-1042', 'invoice-1043'], $pdo->query('SELECT invoice_id FROM invoice_outbox ORDER BY invoice_id')->fetchAll(PDO::FETCH_COLUMN));

            fwrite(STDERR, "\nagent-outbox: the same job ran on two workers; 3 tool calls, 1 failover, 1 row for invoice-1042.\n");
        } finally {
            if ($firstWorker !== null) {
                proc_terminate($firstWorker, SIGKILL);
                proc_close($firstWorker);
            }
            if (is_file($state.'/pause-primary')) {
                unlink($state.'/pause-primary');
            }
            proc_terminate($server);
            proc_close($server);
        }
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
