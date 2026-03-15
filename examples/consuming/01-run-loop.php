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
$mgmt->declareQueue(new QueueSpecification('example-run-loop', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-run-loop');
$total = 3;

// Publish 3 messages
$publisher = $client->publish($address);
for ($i = 1; $i <= $total; $i++) {
    $publisher->send(Message::create("Message $i"));
}
echo "Published $total messages\n";

// Consume using run() with a handler closure.
//
// The handler receives InboundMessage — message data and settlement in one object.
// Call $msg->accept() to acknowledge the delivery.
// Call $msg->reject() instead to send the message to the dead-letter exchange.
//
// run() loops until receive() returns null (idle timeout, stop(), or disconnect).
// Without an explicit stop() call it will block for the full idleTimeout after the
// last message. Here we call $consumer->stop() once all expected messages are processed.
$count = 0;
$consumer = $client->consume($address)
    ->withIdleTimeout(2.0)
    ->credit($total)
    ->consumer();

$consumer->run(
    function (InboundMessage $msg) use (&$count, $total, $consumer): void {
        $count++;
        echo "Received ($count/$total): " . $msg->body() . "\n";
        $msg->accept();
        // $msg->reject(); // nack — routes to dead-letter exchange

        if ($count >= $total) {
            $consumer->stop();
        }
    }
);

echo "Run loop finished\n";
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-run-loop');
$mgmt->close();
$client->close();
