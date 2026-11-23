<?php

namespace Lab;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Records tool calls and optionally writes an email intent to the SQLite outbox.
 */
final class SendInvoiceEmail implements Tool
{
    public function __construct(private readonly ?string $invoiceId = null) {}

    public function description(): string
    {
        return 'Send the invoice to the customer by email.';
    }

    public function handle(Request $request): string
    {
        EventLog::write('tool.run', ['tool' => 'SendInvoiceEmail']);

        if ($this->invoiceId === null) {
            return 'The invoice was sent.';
        }

        // The job supplies the same business key on every attempt and through either provider.
        $inserted = DB::affectingStatement(
            'INSERT INTO invoice_outbox (invoice_id, queued_at) VALUES (?, ?) ON CONFLICT (invoice_id) DO NOTHING',
            [$this->invoiceId, now()->toISOString()],
        );
        EventLog::write($inserted === 1 ? 'invoice.queued' : 'invoice.already_queued', ['invoice_id' => $this->invoiceId]);

        return 'The invoice email is queued.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
