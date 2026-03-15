<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Client\Client;
use AMQP10\Exception\ExchangeNotFoundException;
use AMQP10\Exception\QueueNotFoundException;
use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;

$client = (new Client(AMQP_URI))->connect();
$mgmt = $client->management();

// Declare resources to delete
$mgmt->declareQueue(new QueueSpecification('example-delete-queue', QueueType::QUORUM));
$mgmt->declareExchange(new ExchangeSpecification('example-delete-exchange', ExchangeType::DIRECT));
echo "Resources created\n";

// Delete them
$mgmt->deleteQueue('example-delete-queue');
echo "Deleted queue\n";

$mgmt->deleteExchange('example-delete-exchange');
echo "Deleted exchange\n";

// Attempting to delete a resource that does not exist throws a typed exception.
// Catch QueueNotFoundException / ExchangeNotFoundException for graceful handling.
try {
    $mgmt->deleteQueue('nonexistent-queue');
} catch (QueueNotFoundException $e) {
    echo 'Queue not found (expected): ' . $e->getMessage() . "\n";
}

try {
    $mgmt->deleteExchange('nonexistent-exchange');
} catch (ExchangeNotFoundException $e) {
    echo 'Exchange not found (expected): ' . $e->getMessage() . "\n";
}

$mgmt->close();
$client->close();
