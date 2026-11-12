<?php

namespace Lab;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Ai\AnonymousAgent;
use Throwable;

/**
 * Runs an agent with a tool, with a primary and a backup provider, in a queued job.
 */
final class AgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $agent = new AnonymousAgent('You handle billing. Send invoices when asked.', [], [new SendInvoiceEmail]);

        try {
            $response = $agent->prompt('Send the invoice for order 1042.', provider: ['primary', 'backup']);
        } catch (Throwable $e) {
            EventLog::write('agent.failed', ['exception' => $e::class]);

            throw $e;
        }

        EventLog::write('agent.answered', ['text' => $response->text]);
    }
}
