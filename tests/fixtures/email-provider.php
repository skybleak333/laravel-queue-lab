<?php

// Accepts messages in a database separate from the worker's outbox.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['REQUEST_URI'] !== '/emails') {
    http_response_code(404);

    return true;
}

$request = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
$invoiceId = $request['invoice_id'] ?? null;
if (! is_string($invoiceId) || $invoiceId === '') {
    http_response_code(422);

    return true;
}
$key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? 'unkeyed/'.bin2hex(random_bytes(16));
$provider = new PDO('sqlite:'.getenv('LAB_FAKE_EMAIL_DB'));
$provider->beginTransaction();

$statement = $provider->prepare('INSERT INTO accepted (idempotency_key, invoice_id, message_id) VALUES (?, ?, ?) ON CONFLICT (idempotency_key) DO NOTHING');
$statement->execute([$key, $invoiceId, 'message-'.bin2hex(random_bytes(8))]);
$statement = $provider->prepare('SELECT invoice_id, message_id FROM accepted WHERE idempotency_key = ?');
$statement->execute([$key]);
$message = $statement->fetch(PDO::FETCH_ASSOC);

if ($message['invoice_id'] !== $invoiceId) {
    $provider->rollBack();
    http_response_code(409);

    return true;
}

$loseReceipt = (int) $provider->query('SELECT COUNT(*) FROM requests')->fetchColumn() === 0;
$statement = $provider->prepare('INSERT INTO requests (idempotency_key, invoice_id, receipt_lost) VALUES (?, ?, ?)');
$statement->execute([$key, $invoiceId, (int) $loseReceipt]);
$provider->commit();

$receipt = json_encode(['id' => $message['message_id']], JSON_THROW_ON_ERROR);
header('Content-Type: application/json');
header('Content-Length: '.strlen($receipt));
// The first response ends before its advertised length, after the acceptance is committed.
echo $loseReceipt ? substr($receipt, 0, 1) : $receipt;

return true;
