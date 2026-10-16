<?php

namespace Lab;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sleeps for the given number of seconds on its first delivery only. A copy that the
 * broker delivers again finishes at once, so an experiment can count deliveries.
 */
final class SlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $name, public int $seconds = 0) {}

    public function handle(): void
    {
        $redelivered = (bool) $this->job->getRabbitMQMessage()->isRedelivered();

        EventLog::write('job.start', ['job' => $this->name, 'redelivered' => $redelivered]);

        if ($this->seconds > 0 && ! $redelivered) {
            sleep($this->seconds);
        }

        EventLog::write('job.end', ['job' => $this->name]);
    }
}
