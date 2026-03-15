<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;

// withTlsOptions() returns a new Client clone (immutable config pattern).
// connect() returns the same instance, now connected.
//
// Common TLS options (PHP stream context keys):
//   cafile           — path to CA certificate file (PEM)
//   local_cert       — path to client certificate for mutual TLS (PEM)
//   local_pk         — path to client private key
//   verify_peer      — verify server certificate (default: true)
//   verify_peer_name — verify server hostname (default: true)
//
// Use an amqps:// URI. RabbitMQ default AMQPS port is 5671.
// Set AMQP_URI=amqps://guest:guest@localhost:5671 to run this example.

// If AMQP_URI is already amqps://, use it as-is; otherwise swap the scheme.
$tlsUri = str_starts_with(AMQP_URI, 'amqps://')
    ? AMQP_URI
    : 'amqps://' . substr(AMQP_URI, strlen('amqp://'));

$client = (new Client($tlsUri))
    ->withTlsOptions([
        'cafile'      => '/path/to/ca.pem',  // replace with your CA certificate path
        'verify_peer' => true,
    ])
    ->connect();

// Setup
$mgmt = $client->management();
$mgmt->declareQueue(new QueueSpecification('example-tls', QueueType::QUORUM));
$mgmt->close();

$address = AddressHelper::queueAddress('example-tls');
$outcome = $client->publish($address)->send(Message::create('Hello over TLS!'));
echo "Published over TLS: " . ($outcome->isAccepted() ? 'accepted' : 'not accepted') . "\n";

// Teardown
$mgmt = $client->management();
$mgmt->deleteQueue('example-tls');
$mgmt->close();
$client->close();
