<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;

$client = (new Client(AMQP_URI))->connect();
$mgmt = $client->management();

// Quorum queue — recommended for most workloads.
// Replicated across nodes, durable, no data loss on broker failure.
$mgmt->declareQueue(new QueueSpecification('example-quorum', QueueType::QUORUM));
echo "Declared quorum queue\n";

// Stream queue — append-only log, supports offset-based consumption,
// SQL filters, and Bloom filters. Messages are retained after acknowledgement.
$mgmt->declareQueue(new QueueSpecification('example-stream', QueueType::STREAM));
echo "Declared stream queue\n";

// Classic queue — legacy type. Use quorum queues for new workloads.
$mgmt->declareQueue(new QueueSpecification('example-classic', QueueType::CLASSIC));
echo "Declared classic queue\n";

// Cleanup
$mgmt->deleteQueue('example-quorum');
$mgmt->deleteQueue('example-stream');
$mgmt->deleteQueue('example-classic');
echo "Deleted all queues\n";

$mgmt->close();
$client->close();
