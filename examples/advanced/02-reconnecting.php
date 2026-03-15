<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

// withReconnect() wraps each send() in a retry loop.
// On ConnectionFailedException or RuntimeException it will:
//   1. Wait backoffMs * attempt milliseconds
//   2. Reconnect the client
//   3. Re-attach the sender link
//   4. Retry the send
//
// maxRetries: maximum reconnect attempts per send call (0 = no retry)
// backoffMs:  base backoff in milliseconds, multiplied by the attempt count
//
// To test: run this script, then stop and restart RabbitMQ while it's publishing.

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-reconnect', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-reconnect');
$publisher = $client->publish($address)->withReconnect(maxRetries: 10, backoffMs: 500);

for ($i = 1; $i <= 20; $i++) {
    $outcome = $publisher->send(Message::create("Message $i"));
    echo "Sent $i: " . ($outcome->isAccepted() ? 'accepted' : 'not accepted') . "\n";
    usleep(500_000); // 500 ms between sends — gives time to observe broker restarts
}

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-reconnect');
$mgmt->close();
$client->close();
