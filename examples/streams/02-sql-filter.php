<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Offset;

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-stream-sql', QueueType::STREAM));
$mgmt->close();

$queueAddress  = AddressHelper::queueAddress('example-stream-sql');
$streamAddress = AddressHelper::streamAddress('example-stream-sql');

// Publish a mix of event types via application properties
$publisher = $client->publish($queueAddress);
for ($i = 1; $i <= 5; $i++) {
    $publisher->send(
        Message::create("Order $i")->withApplicationProperty('type', 'order')
    );
    $publisher->send(
        Message::create("Notification $i")->withApplicationProperty('type', 'notification')
    );
}
echo "Published 10 messages (5 orders, 5 notifications)\n";

// filterSql() sends an AMQP SQL filter expression to the broker.
// Only messages matching the expression are delivered — filtering happens
// server-side, so non-matching messages are never transferred to the client.
$consumer = $client->consume($streamAddress)
    ->offset(Offset::first())
    ->filterSql("type = 'order'")
    ->withIdleTimeout(2.0)
    ->credit(10)
    ->consumer();

echo "Consuming with filterSql(\"type = 'order'\")...\n";
$received = 0;
while (true) {
    $delivery = $consumer->receive();
    if ($delivery === null) {
        break;
    }
    $received++;
    echo "  Received: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
}

echo "Total received: $received (expected 5)\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stream-sql');
$mgmt->close();
$client->close();
