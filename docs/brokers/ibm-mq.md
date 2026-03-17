# IBM MQ

This library supports IBM MQ 9.4.2+ via its AMQP 1.0 service (`MQSeriesAMQP`). IBM MQ behaves
differently from RabbitMQ in several important ways — this page covers everything you need to
get a working setup.

## Contents

- [Differences from RabbitMQ](#differences-from-rabbitmq)
- [Docker image setup](#docker-image-setup)
- [IBM MQ configuration](#ibm-mq-configuration)
- [Library usage](#library-usage)
- [Running the integration tests](#running-the-integration-tests)

---

## Differences from RabbitMQ

| Concern | RabbitMQ | IBM MQ |
|---------|----------|--------|
| Address format | `/queues/my-queue`, `/exchanges/my-ex/key` | Bare queue name: `DEV.QUEUE.1` |
| Queue capability | Not required | `withTargetCapabilities(['queue'])` and `withSourceCapabilities(['queue'])` required — IBM MQ treats all AMQP addresses as topic strings otherwise |
| Message `to` property | Ignored when ATTACH target is set | Required — IBM MQ uses the `to` field in the message properties section to identify the destination even when the ATTACH frame specifies a target address |
| Queue declaration | Via management API | Queues are pre-configured in MQSC; there is no AMQP management API on IBM MQ |

---

## Docker image setup

The public IBM MQ image (`icr.io/ibm-messaging/mq`) does **not** include the AMQP package. You
need to build a custom image from the [ibm-messaging/mq-container](https://github.com/ibm-messaging/mq-container)
repository with `genmqpkg_incamqp=1` and an AMQP-enabled MQSC template.

### 1. Clone the build repository

```bash
git clone -b 9.4.2 https://github.com/ibm-messaging/mq-container.git /tmp/ibmmq-build
cd /tmp/ibmmq-build
```

### 2. Enable the AMQP package

In `Dockerfile-server`, find the `genmqpkg` environment block (around line 66) and ensure
`genmqpkg_incamqp` is set to `1`:

```dockerfile
ENV genmqpkg_incamqp=1 \
    genmqpkg_incjava=1 \
    genmqpkg_incjre=1 \
    ...
```

### 3. Add AMQP MQSC to the developer template

Append the following to `incubating/mqadvanced-server-dev/10-dev.mqsc.tpl`. This auto-starts
the AMQP service with the queue manager and allows client connections:

```mqsc
* AMQP service - auto-start with queue manager
ALTER SERVICE(SYSTEM.AMQP.SERVICE) CONTROL(QMGR)

* AMQP channel authentication - allow connections on the template channel
SET CHLAUTH('SYSTEM.DEF.AMQP') TYPE(ADDRESSMAP) ADDRESS('*') USERSRC(CHANNEL) CHCKCLNT({{ .ChckClnt }}) DESCR('Allows AMQP client connections') ACTION(REPLACE)
```

### 4. Build the image

```bash
COMMAND=~/.docker/bin/docker ARCH=amd64 make build-devserver
```

This produces a local image named `ibm-mqadvanced-server-dev:9.4.2.1-amd64` (version suffix
reflects the specific fix pack downloaded during the build).

> **Apple Silicon:** The IBM MQ image has no ARM64 build. Set
> `DOCKER_DEFAULT_PLATFORM=linux/amd64` before running the container to force x86 emulation
> via Rosetta.

---

## IBM MQ configuration

After the container starts, several runtime steps are required before AMQP connections will work.
The `CONTROL(QMGR)` setting in the MQSC template starts the `amqpcsea` process but does **not**
launch the Java `RunMQXRService` listener that actually accepts AMQP connections — that requires
an explicit start.

### Start the AMQP service

```bash
echo 'START SERVICE(SYSTEM.AMQP.SERVICE)' | runmqsc QM1
```

### Fix CHLAUTH for the AMQP channel

IBM MQ's backstop CHLAUTH rule (`SET CHLAUTH('*') ... USERSRC(NOACCESS)`) blocks all channels
by default. The MQSC template sets the exception for `SYSTEM.DEF.AMQP` (the template channel),
but CHLAUTH checks use the actual AMQP channel name. Add the rule for the real channel name:

```bash
echo "SET CHLAUTH('DEV.AMQP') TYPE(ADDRESSMAP) ADDRESS('*') USERSRC(CHANNEL) CHCKCLNT(REQUIRED) ACTION(REPLACE)" | runmqsc QM1
```

### Create the AMQP channel

IBM MQ's AMQP service has its own channel registry, separate from MQSC channels. Create a
channel that listens on port 5672 using `controlAMQPChannel.sh` (retry until the Java listener
is ready):

```bash
/opt/mqm/amqp/bin/controlAMQPChannel.sh \
  -qmgr=QM1 -mode=newchannel -chlname=DEV.AMQP \
  -port=5672 -chltype=amqp -trptype=tcp
```

---

## Library usage

### Connection

IBM MQ AMQP uses the same URI format. The default AMQP port is 5672:

```php
$client = new \AMQP10\Client\Client('amqp://app:passw0rd@your-ibm-mq-host:5672/');
$client->connect();
```

### Address format

IBM MQ uses bare queue names as addresses. Do **not** use the RabbitMQ `/queues/{name}` format:

```php
// Correct for IBM MQ
$address = 'DEV.QUEUE.1';

// Wrong — this is the RabbitMQ format
$address = '/queues/DEV.QUEUE.1';
```

### Publishing

Two options are required when publishing to an IBM MQ queue:

- **`withTargetCapabilities(['queue'])`** — tells IBM MQ to route to a queue rather than treat
  the address as a topic string (IBM MQ's default AMQP behaviour)
- **`withMessageToAddress()`** — populates the AMQP message properties `to` field with the
  target address; IBM MQ requires this field to identify the destination even when the target
  is set in the ATTACH frame

```php
use AMQP10\Messaging\Message;

$outcome = $client->publish('DEV.QUEUE.1')
    ->withTargetCapabilities(['queue'])
    ->withMessageToAddress()
    ->send(Message::create('Hello from PHP'));

if ($outcome->isAccepted()) {
    echo "Delivered\n";
}
```

### Consuming

Use `withSourceCapabilities(['queue'])` so IBM MQ treats the source address as a queue:

```php
use AMQP10\Messaging\InboundMessage;

$client->consume('DEV.QUEUE.1')
    ->withSourceCapabilities(['queue'])
    ->handle(function (InboundMessage $msg) {
        echo $msg->body() . "\n";
        $msg->accept();
    })
    ->stopOnSignal([SIGINT, SIGTERM])
    ->run();
```

### Single receive

```php
$consumer = $client->consume('DEV.QUEUE.1')
    ->withSourceCapabilities(['queue'])
    ->credit(1)
    ->consumer();

$msg = $consumer->receive();
if ($msg !== null) {
    echo $msg->body() . "\n";
    $msg->accept();
}
$consumer->close();
```

---

## Running the integration tests

The integration test suite includes `IbmMqPublishConsumeTest`. There are two ways to run it.

### Against the local Docker image

Build the image as described above, then run the tests. The test suite will start the container
automatically via Testcontainers and configure AMQP:

```bash
vendor/bin/phpunit --testsuite Integration --filter IbmMq
```

### Against an external IBM MQ instance

Skip the container entirely by pointing the tests at a running IBM MQ instance with AMQP
already configured:

```bash
export IBMMQ_AMQP_URI="amqp://app:passw0rd@your-ibm-mq-host:5672/"
vendor/bin/phpunit --testsuite Integration --filter IbmMq
```
