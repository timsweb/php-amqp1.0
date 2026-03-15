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
$mgmt->declareQueue(new QueueSpecification('example-one-shot', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-one-shot');

// Publish one message
$client->publish($address)->send(Message::create('Hello, one-shot!'));
echo "Published 1 message\n";

// Pull exactly one message using receive().
// receive() blocks until a message arrives or idleTimeout elapses, then returns null.
$consumer = $client->consume($address)->credit(1)->consumer();
$delivery = $consumer->receive();

if ($delivery !== null) {
    echo "Received: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
} else {
    echo "No message received (queue empty or timeout)\n";
}

// Detach the consumer link before tearing down the queue.
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-one-shot');
$mgmt->close();
$client->close();
