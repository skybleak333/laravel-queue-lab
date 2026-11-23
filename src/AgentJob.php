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

    private ?string $invoiceId = null;

    public function __construct(?string $invoiceId = null)
    {
        $this->invoiceId = $invoiceId;
        if ($invoiceId !== null) {
            $this->tries = 2;
        }
    }

    public function handle(): void
    {
        EventLog::write('agent.started', ['job_id' => $this->job?->getJobId(), 'attempt' => $this->attempts()]);
        $agent = new AnonymousAgent('You handle billing. Send invoices when asked.', [], [new SendInvoiceEmail($this->invoiceId)]);
        $prompt = $this->invoiceId === null ? 'Send the invoice for order 1042.' : "Queue the email for invoice {$this->invoiceId}.";

        try {
            $response = $agent->prompt($prompt, provider: ['primary', 'backup']);
        } catch (Throwable $e) {
            EventLog::write('agent.failed', ['exception' => $e::class]);

            throw $e;
        }

        EventLog::write('agent.answered', ['text' => $response->text]);
    }
}
