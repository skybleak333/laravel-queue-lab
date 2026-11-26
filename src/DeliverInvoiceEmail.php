<?php

namespace Lab;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Sends an outbox entry to the local email fixture and saves the receipt. */
final class DeliverInvoiceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(private readonly string $invoiceId) {}

    public function handle(): void
    {
        $outbox = DB::table('invoice_outbox')->where('invoice_id', $this->invoiceId)->whereNull('provider_message_id');
        if (! $outbox->exists()) {
            return;
        }

        $url = getenv('LAB_EMAIL_URL');
        if ($url === false || ! preg_match('~\Ahttp://127\.0\.0\.1:[0-9]{1,5}\z~', $url)) {
            throw new RuntimeException('Set LAB_EMAIL_URL to the loopback email fixture.');
        }

        $key = 'invoice-email/'.hash('sha256', $this->invoiceId);
        EventLog::write('invoice.delivery_attempted', [
            'invoice_id' => $this->invoiceId, 'attempt' => $this->attempts(), 'idempotency_key' => $key,
        ]);

        $receipt = Http::acceptJson()
            ->withHeader('Idempotency-Key', $key)
            ->withoutRedirecting()
            ->withOptions(['proxy' => ''])
            ->timeout(5)
            ->post($url.'/emails', ['invoice_id' => $this->invoiceId])
            ->throw()
            ->json('id');

        if (! is_string($receipt) || $receipt === '') {
            throw new RuntimeException('The email provider did not return a message ID.');
        }

        if ($outbox->update(['provider_message_id' => $receipt]) === 1) {
            EventLog::write('invoice.delivery_acknowledged', ['invoice_id' => $this->invoiceId, 'provider_message_id' => $receipt]);
        }
    }
}
