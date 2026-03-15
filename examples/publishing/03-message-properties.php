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
$mgmt->declareQueue(new QueueSpecification('example-message-properties', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-message-properties');

// Build a message with a rich set of properties.
$message = Message::create('{"event":"order.placed","orderId":42}')
    ->withMessageId('msg-001')
    ->withCorrelationId('req-abc')
    ->withSubject('order.placed')
    ->withContentType('application/json')
    ->withTtl(60_000)       // milliseconds
    ->withPriority(5)
    ->withApplicationProperty('tenant', 'acme')
    ->withApplicationProperty('region', 'eu-west');

$client->publish($address)->send($message);
echo "Published message with properties\n";

// Consume and read back to confirm round-trip.
// AMQP properties are read via property(key); application headers via applicationProperty(key).
$consumer = $client->consume($address)->credit(1)->consumer();
$delivery = $consumer->receive();

if ($delivery !== null) {
    $msg = $delivery->message();
    echo "message-id:     " . $msg->property('message-id') . "\n";
    echo "correlation-id: " . $msg->property('correlation-id') . "\n";
    echo "subject:        " . ($msg->subject() ?? '(none)') . "\n";
    echo "content-type:   " . $msg->property('content-type') . "\n";
    echo "ttl:            " . $msg->ttl() . "\n";
    echo "priority:       " . $msg->priority() . "\n";
    echo "tenant:         " . $msg->applicationProperty('tenant') . "\n";
    echo "region:         " . $msg->applicationProperty('region') . "\n";
    echo "body:           " . $msg->body() . "\n";
    $delivery->context()->accept();
}

$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-message-properties');
$mgmt->close();
$client->close();
