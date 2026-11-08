<?php

namespace Lab;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * A job allowed five attempts and two exceptions. "timeout" runs past its one-second timeout
 * on every attempt; "throw" throws on every attempt. Either way it should fail on the second
 * exception, so after two attempts.
 */
final class MaxExceptionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 2;

    public int $timeout = 1;

    public function __construct(public string $name, public string $mode) {}

    public function handle(): void
    {
        EventLog::write('job.attempt', ['job' => $this->name, 'attempt' => $this->attempts()]);

        if ($this->mode === 'throw') {
            throw new RuntimeException("{$this->name} failed");
        }

        sleep(3);
    }

    public function failed(Throwable $e): void
    {
        EventLog::write('job.failed', ['job' => $this->name, 'exception' => $e::class]);
    }
}
