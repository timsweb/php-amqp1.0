<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\BindingSpecification;
use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

$client = (new Client(AMQP_URI))->connect();
$mgmt = $client->management();

// Declare a direct exchange
$mgmt->declareExchange(new ExchangeSpecification('example-exchange', ExchangeType::DIRECT));
echo "Declared direct exchange\n";

// Declare a quorum queue
$mgmt->declareQueue(new QueueSpecification('example-bound-queue', QueueType::QUORUM));
echo "Declared quorum queue\n";

// Bind the queue to the exchange with a routing key.
// Messages published to the exchange with routing key 'orders' are routed to this queue.
$mgmt->bind(new BindingSpecification('example-exchange', 'example-bound-queue', 'orders'));
echo "Bound queue to exchange with routing key 'orders'\n";

$mgmt->close();

// Publish to the exchange using the routing key
$exchangeAddress = AddressHelper::exchangeAddress('example-exchange', 'orders');
$outcome = $client->publish($exchangeAddress)->send(Message::create('Routed message'));
echo 'Published to exchange: ' . ($outcome->isAccepted() ? 'accepted' : 'not accepted') . "\n";

// Consume from the queue to confirm routing worked
$queueAddress = AddressHelper::queueAddress('example-bound-queue');
$consumer = $client->consume($queueAddress)->withIdleTimeout(5.0)->credit(1)->consumer();
$delivery = $consumer->receive();

if ($delivery !== null) {
    echo 'Received via exchange routing: ' . $delivery->body() . "\n";
    $delivery->accept();
}
$consumer->close();

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-bound-queue');
$mgmt->deleteExchange('example-exchange');
echo "Cleaned up\n";
$mgmt->close();
$client->close();
