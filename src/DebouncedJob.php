<?php

namespace Lab;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DebounceFor;

/**
 * A debounced job, like a search index rebuild after a product change: dispatched on every
 * change, run once things are quiet for DEBOUNCE seconds, but at least every MAX_WAIT seconds.
 */
#[DebounceFor(self::DEBOUNCE, maxWait: self::MAX_WAIT)]
final class DebouncedJob implements ShouldQueue
{
    use Queueable;

    public const DEBOUNCE = 2;

    public const MAX_WAIT = 6;

    public function __construct(public float $dispatchedAt) {}

    public function debounceId(): string
    {
        return 'product-1';
    }

    public function handle(): void
    {
        usleep(300_000);

        // Laravel queues the job with no delay when maxWait forces it to run.
        EventLog::write('debounce.run', ['dispatched_at' => $this->dispatchedAt, 'forced' => $this->delay === 0]);
    }
}
