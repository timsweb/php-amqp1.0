<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-stop-after-duration', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-stop-after-duration');

// Pre-seed a few messages
$publisher = $client->publish($address);
for ($i = 1; $i <= 5; $i++) {
    $publisher->send(Message::create("Message $i"));
}

// Run for at most 30 seconds using a wall-clock deadline.
//
// withIdleTimeout(1.0) limits each receive() call to 1 second of blocking,
// so the wall-clock check fires responsively rather than waiting the full
// idleTimeout (default 30 s) between iterations.
$runForSeconds = 30.0;
$deadline = microtime(true) + $runForSeconds;
$received = 0;

$consumer = $client->consume($address)
    ->withIdleTimeout(1.0)
    ->credit(100)
    ->consumer();

echo "Consuming for up to {$runForSeconds}s (Ctrl+C to stop early)...\n";

while (microtime(true) < $deadline) {
    $delivery = $consumer->receive();
    if ($delivery === null) {
        continue; // idle timeout — loop back and re-check wall-clock
    }
    $received++;
    echo "Received ($received): " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
}

echo "Duration elapsed. Total received: $received\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stop-after-duration');
$mgmt->close();
$client->close();
