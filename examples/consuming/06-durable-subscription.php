<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Terminus\ExpiryPolicy;

// Durable subscriptions are most meaningful on stream queues, where the broker
// tracks the consumer's offset position across reconnects.
//
// durable()                    — defaults to TerminusDurability::UnsettledState;
//                                the broker remembers which messages have been settled.
// expiryPolicy(Never)          — keeps the subscription alive after detach.
// linkName('my-durable-sub')   — ties reconnects to the same subscription state.

$client = (new Client(AMQP_URI))->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-durable-sub', QueueType::STREAM));
$mgmt->close();

// Stream and queue addresses differ for streams
$queueAddress  = AddressHelper::queueAddress('example-durable-sub');
$streamAddress = AddressHelper::streamAddress('example-durable-sub');
$linkName = 'my-durable-sub';

// Publish 6 messages
$publisher = $client->publish($queueAddress);
for ($i = 1; $i <= 6; $i++) {
    $publisher->send(Message::create("Message $i"));
}
echo "Published 6 messages\n";

// First session — consume 3, then disconnect
echo "--- First connection: consuming 3 messages ---\n";

$consumer = $client->consume($streamAddress)
    ->durable()                          // TerminusDurability::UnsettledState (default)
    ->expiryPolicy(ExpiryPolicy::Never)  // keep subscription alive after detach
    ->linkName($linkName)
    ->credit(3)
    ->consumer();

for ($i = 0; $i < 3; $i++) {
    $delivery = $consumer->receive();
    if ($delivery !== null) {
        echo "  Received: " . $delivery->message()->body() . "\n";
        $delivery->context()->accept();
    }
}
$consumer->close();
$client->close();
echo "Disconnected\n";

// Second session — reconnect and resume from where we left off
echo "--- Second connection: resuming ---\n";

$client2 = (new Client(AMQP_URI))->connect();

$consumer2 = $client2->consume($streamAddress)
    ->durable()
    ->expiryPolicy(ExpiryPolicy::Never)
    ->linkName($linkName)  // same link name = resume same subscription state
    ->withIdleTimeout(2.0)
    ->credit(10)
    ->consumer();

$received = 0;
while (true) {
    $delivery = $consumer2->receive();
    if ($delivery === null) {
        break;
    }
    $received++;
    echo "  Resumed: " . $delivery->message()->body() . "\n";
    $delivery->context()->accept();
}

echo "Resumed and received $received more messages\n";
$consumer2->close();

// Teardown
$mgmt = $client2->management();
$mgmt->deleteQueue('example-durable-sub');
$mgmt->close();
$client2->close();
