<?php

namespace Lab\Tests;

use Illuminate\Http\Client\ConnectionException;
use Lab\EventLog;
use PDO;

final class InvoiceDeliveryTest extends ExperimentTestCase
{
    public function testTheWorkerRecoversTheReceiptAfterTheProviderHasAcceptedAnInvoice(): void
    {
        $dir = self::logDirectory('invoice-delivery-'.bin2hex(random_bytes(4)));
        $database = self::sqliteQueue($dir);
        $outbox = new PDO('sqlite:'.$database);
        $outbox->exec('CREATE TABLE invoice_outbox (invoice_id TEXT NOT NULL PRIMARY KEY, queued_at TEXT NOT NULL, provider_message_id TEXT)');
        $env = ['LAB_DATABASE' => $database];
        $invoiceId = 'invoice-1042';
        $worker = ['artisan', 'queue:work', 'database', '--once'];

        mkdir($dir.'/ai');
        [$ai, $aiUrl] = self::fakeAiProvider($dir.'/ai');
        try {
            self::lab(['agent-failover', 'agent', $invoiceId], $dir.'/agent-dispatch.jsonl', $env);
            self::lab([...$worker, '--queue=agent'], $dir.'/agent-worker.jsonl', $env + ['LAB_AI_URL' => $aiUrl]);
        } finally {
            proc_terminate($ai);
            proc_close($ai);
        }

        $agentLog = EventLog::read($dir.'/agent-worker.jsonl');
        $this->assertCount(1, self::events($agentLog, 'agent.failed_over'));
        $this->assertCount(2, self::events($agentLog, 'tool.run'));
        $this->assertSame(
            [['invoice_id' => $invoiceId, 'provider_message_id' => null]],
            $outbox->query('SELECT invoice_id, provider_message_id FROM invoice_outbox')->fetchAll(PDO::FETCH_ASSOC),
        );

        $providerFile = $dir.'/provider.sqlite';
        $provider = new PDO('sqlite:'.$providerFile);
        $provider->exec('CREATE TABLE accepted (idempotency_key TEXT NOT NULL PRIMARY KEY, invoice_id TEXT NOT NULL, message_id TEXT NOT NULL)');
        $provider->exec('CREATE TABLE requests (id INTEGER PRIMARY KEY, idempotency_key TEXT NOT NULL, invoice_id TEXT NOT NULL, receipt_lost INTEGER NOT NULL)');
        [$email, $emailUrl] = self::serveFixture('tests/fixtures/email-provider.php', $dir.'/email-server.out', ['LAB_FAKE_EMAIL_DB' => $providerFile]);
        $env += ['LAB_EMAIL_URL' => $emailUrl];
        $delivery = [...$worker, '--queue=delivery'];

        try {
            self::lab(['deliver-invoice', 'delivery', $invoiceId], $dir.'/delivery-dispatch.jsonl', $env);
            $initialPayload = $outbox->query('SELECT payload FROM jobs')->fetchColumn();
            $this->assertIsString($initialPayload);
            self::lab($delivery, $dir.'/first-delivery.jsonl', $env);

            $accepted = $provider->query('SELECT * FROM accepted')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame([$invoiceId], array_column($accepted, 'invoice_id'));
            $this->assertNull($outbox->query('SELECT provider_message_id FROM invoice_outbox')->fetchColumn());
            $retried = $outbox->query('SELECT payload, attempts FROM jobs')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame([['payload' => $initialPayload, 'attempts' => 1]], $retried);
            $firstLog = EventLog::read($dir.'/first-delivery.jsonl');
            $this->assertSame([1], array_column(self::events($firstLog, 'invoice.delivery_attempted'), 'attempt'));
            $this->assertSame([ConnectionException::class], array_column(self::events($firstLog, 'exception'), 'class'));
            $this->assertCount(0, self::events($firstLog, 'invoice.delivery_acknowledged'));

            self::lab($delivery, $dir.'/retry-delivery.jsonl', $env);

            $retryLog = EventLog::read($dir.'/retry-delivery.jsonl');
            $this->assertSame([2], array_column(self::events($retryLog, 'invoice.delivery_attempted'), 'attempt'));
            $receipt = $accepted[0]['message_id'];
            $this->assertSame([$receipt], array_column(self::events($retryLog, 'invoice.delivery_acknowledged'), 'provider_message_id'));
            $this->assertSame($receipt, $outbox->query('SELECT provider_message_id FROM invoice_outbox')->fetchColumn());
            $this->assertSame(0, (int) $outbox->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
            $this->assertSame($accepted, $provider->query('SELECT * FROM accepted')->fetchAll(PDO::FETCH_ASSOC));

            self::lab(['deliver-invoice', 'delivery', $invoiceId], $dir.'/duplicate-dispatch.jsonl', $env);
            self::lab($delivery, $dir.'/duplicate-delivery.jsonl', $env);

            $requests = $provider->query('SELECT idempotency_key, invoice_id, receipt_lost FROM requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $key = $accepted[0]['idempotency_key'];
            $this->assertStringStartsWith('invoice-email/', $key);
            $this->assertSame([
                ['idempotency_key' => $key, 'invoice_id' => $invoiceId, 'receipt_lost' => 1],
                ['idempotency_key' => $key, 'invoice_id' => $invoiceId, 'receipt_lost' => 0],
            ], $requests);
            $this->assertSame(0, (int) $outbox->query('SELECT COUNT(*) FROM jobs')->fetchColumn());

            fwrite(STDERR, "\ninvoice-delivery: 2 HTTP requests, 1 provider acceptance, original receipt recovered.\n");
        } finally {
            proc_terminate($email);
            proc_close($email);
        }
    }
}
