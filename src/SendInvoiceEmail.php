<?php

namespace Lab;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A tool with a side effect, as an agent's tools often have: here it only records each run.
 */
final class SendInvoiceEmail implements Tool
{
    public function description(): string
    {
        return 'Send the invoice to the customer by email.';
    }

    public function handle(Request $request): string
    {
        EventLog::write('tool.run', ['tool' => 'SendInvoiceEmail']);

        return 'The invoice was sent.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
