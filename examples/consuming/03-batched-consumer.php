<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\InboundMessage;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-batched', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-batched');
$total = 20;

// Publish 20 messages
$publisher = $client->publish($address);
for ($i = 1; $i <= $total; $i++) {
    $publisher->send(Message::create("Message $i"));
}
echo "Published $total messages\n";

// Batched consumer using a manual receive() loop.
//
// Two independent timeout mechanisms:
//   withIdleTimeout(1.0) — each receive() call blocks at most 1 second for a frame.
//   $batchDeadline       — wall-clock deadline for the current batch window.
//
// The batch is dispatched when it reaches $maxBatch OR the wall-clock deadline is reached.
// Consumer::run() is NOT used here — the manual receive() loop is the pattern.

$maxBatch = 10;
$batchWindowSecs = 5.0;
$processed = 0;
$batchNum = 0;

$consumer = $client->consume($address)
    ->withIdleTimeout(1.0)
    ->credit($total)
    ->consumer();

while ($processed < $total) {
    /** @var InboundMessage[] $batch */
    $batch = [];
    $batchDeadline = microtime(true) + $batchWindowSecs;

    while (count($batch) < $maxBatch && microtime(true) < $batchDeadline) {
        $delivery = $consumer->receive();
        if ($delivery === null) {
            break; // idle timeout — re-check wall-clock deadline
        }
        $batch[] = $delivery;
    }

    if (empty($batch)) {
        break; // nothing arrived in this window — queue drained
    }

    $batchNum++;
    echo "Batch $batchNum: processing " . count($batch) . " messages\n";

    foreach ($batch as $delivery) {
        echo "  -> " . $delivery->body() . "\n";
        $delivery->accept();
        $processed++;
    }
}

echo "Total processed: $processed\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-batched');
$mgmt->close();
$client->close();
