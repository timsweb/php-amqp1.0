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
$mgmt->declareQueue(new QueueSpecification('example-stream-bloom', QueueType::STREAM));
$mgmt->close();

$queueAddress  = AddressHelper::queueAddress('example-stream-bloom');
$streamAddress = AddressHelper::streamAddress('example-stream-bloom');

// The annotation key 'x-stream-filter-value' is the RabbitMQ Bloom filter tag.
// Messages without this annotation are "unfiltered" — they can be received by passing
// matchUnfiltered: true as the second argument to filterBloom().
$publisher = $client->publish($queueAddress);

for ($i = 1; $i <= 3; $i++) {
    $publisher->send(
        Message::create("EU order $i")->withAnnotation('x-stream-filter-value', 'eu')
    );
}
for ($i = 1; $i <= 3; $i++) {
    $publisher->send(
        Message::create("US order $i")->withAnnotation('x-stream-filter-value', 'us')
    );
}
for ($i = 1; $i <= 2; $i++) {
    $publisher->send(Message::create("Untagged event $i")); // no filter annotation
}
echo "Published 8 messages (3 eu, 3 us, 2 untagged)\n";

// Consume only EU messages.
// Pass filterBloom(['eu', 'us']) to match multiple values.
// Pass true as second argument to also receive untagged messages.
$consumer = $client->consume($streamAddress)
    ->offset(Offset::first())
    ->filterBloom('eu')
    ->withIdleTimeout(2.0)
    ->credit(10)
    ->consumer();

echo "Consuming with filterBloom('eu')...\n";
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

echo "Total received: $received (expected 3)\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stream-bloom');
$mgmt->close();
$client->close();
