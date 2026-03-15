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
$mgmt->declareQueue(new QueueSpecification('example-stream-offset', QueueType::STREAM));
$mgmt->close();

$queueAddress  = AddressHelper::queueAddress('example-stream-offset');
$streamAddress = AddressHelper::streamAddress('example-stream-offset');

// Publish 5 events
$publisher = $client->publish($queueAddress);
for ($i = 1; $i <= 5; $i++) {
    $publisher->send(Message::create("Event $i"));
}
echo "Published 5 events\n";

// Consume from the beginning of the stream using Offset::first().
//
// Available offsets:
//   Offset::first()        — start of the stream
//   Offset::last()         — last message only
//   Offset::next()         — only new messages published after attach
//   Offset::offset(n)      — specific numeric offset
//   Offset::timestamp(ms)  — messages published at or after a Unix timestamp (ms)
$consumer = $client->consume($streamAddress)
    ->offset(Offset::first())
    ->withIdleTimeout(2.0)
    ->credit(5)
    ->consumer();

echo "Consuming from Offset::first()...\n";
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

echo "Total received: $received\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stream-offset');
$mgmt->close();
$client->close();
