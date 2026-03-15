<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\InboundMessage;

// Requires ext-pcntl for signal handling. Without it, stopOnSignal() is a no-op
// and the consumer will run until stopped by other means.
if (! extension_loaded('pcntl')) {
    echo "Warning: ext-pcntl not loaded — signal handling is disabled\n";
}

$client = (new Client(AMQP_URI))->connect();

// Setup — queue is not pre-seeded; publish messages separately to observe live consumption.
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-stop-on-signal', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-stop-on-signal');
$received = 0;

echo "Consumer running. Publish a message to the queue, or press Ctrl+C to stop.\n";

// stopOnSignal() registers OS signal handlers via the revolt event loop.
// The optional closure receives the signal number as its only argument —
// use it for logging, cleanup, or status output before shutdown.
$client->consume($address)
    ->withIdleTimeout(1.0)
    ->handle(function (InboundMessage $msg) use (&$received): void {
        $received++;
        echo "Received ($received): " . $msg->body() . "\n";
        $msg->accept();
    })
    ->stopOnSignal(
        [SIGINT, SIGTERM],
        function (int $signal): void {
            echo "\nCaught signal $signal — shutting down gracefully\n";
        }
    )
    ->run();

echo "Consumer stopped. Total received: $received\n";

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-stop-on-signal');
$mgmt->close();
$client->close();
