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
$mgmt->declareQueue(new QueueSpecification('example-basic-publish', QueueType::QUORUM));
$mgmt->close();

// Publish one message and check the broker accepted it.
$address = AddressHelper::queueAddress('example-basic-publish');
$outcome = $client->publish($address)->send(Message::create('Hello, AMQP 1.0!'));

echo $outcome->isAccepted() ? "Message accepted\n" : "Message not accepted\n";

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-basic-publish');
$mgmt->close();
$client->close();
