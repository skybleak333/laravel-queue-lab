<?php

namespace Lab\Tests;

use Lab\EventLog;

/**
 * maxExceptions and a timeout, with Worker::$killOnTimeout = false.
 *
 * MaxExceptionsJob may fail twice before it is marked as failed. "throw" throws on every
 * attempt; "timeout" runs past its timeout on every attempt. With killOnTimeout off, the worker
 * doesn't exit on a timeout: it throws a TimeoutExceededException into the job instead. Both
 * jobs should fail after their second attempt. The database queue (SQLite), no broker.
 */
final class MaxExceptionsTimeoutTest extends ExperimentTestCase
{
    public function testATimeoutCountsAsTwoExceptions(): void
    {
        $dir = self::logDirectory('max-exceptions');
        exec('rm -rf '.escapeshellarg($dir.'/storage').' '.escapeshellarg($dir).'/*.jsonl');
        $env = ['LAB_DATABASE' => self::sqliteQueue($dir), 'LAB_STORAGE' => $dir.'/storage', 'LAB_KILL_ON_TIMEOUT' => '0'];

        self::lab(['max-exceptions', 'max-exceptions'], $dir.'/dispatch.jsonl', $env);
        self::lab(['artisan', 'queue:work', 'database', '--queue=max-exceptions', '--sleep=1', '--stop-when-empty'], $dir.'/worker.jsonl', $env);

        $log = EventLog::read($dir.'/worker.jsonl');
        $attempts = static fn (string $job): int => count(array_filter(self::events($log, 'job.attempt'), static fn (array $e): bool => $e['job'] === $job));
        $failed = array_column(self::events($log, 'job.failed'), 'exception', 'job');

        fwrite(STDERR, sprintf(
            "\nmax-exceptions: \"throw\" failed after %d attempts, \"timeout\" after %d (maxExceptions 2)\n",
            $attempts('throw'), $attempts('timeout'),
        ));

        // The control: two exceptions, two attempts.
        $this->assertSame(2, $attempts('throw'));
        $this->assertSame('RuntimeException', $failed['throw'] ?? null);
        // One timeout is counted twice: by the SIGALRM handler, then again when the
        // TimeoutExceededException it throws comes out of the job.
        $this->assertSame(1, $attempts('timeout'));
        $this->assertSame('Illuminate\Queue\TimeoutExceededException', $failed['timeout'] ?? null);
    }
}
