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
$mgmt->declareQueue(new QueueSpecification('example-fire-and-forget', QueueType::QUORUM));
$mgmt->close();

// fireAndForget() uses pre-settled delivery mode: the broker does not send a
// disposition frame back. This gives higher throughput at the cost of no
// per-message delivery guarantee.
$address = AddressHelper::queueAddress('example-fire-and-forget');
$publisher = $client->publish($address)->fireAndForget();

for ($i = 1; $i <= 5; $i++) {
    $publisher->send(Message::create("Message $i"));
    echo "Sent message $i (no ack waited)\n";
}

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-fire-and-forget');
$mgmt->close();
$client->close();
