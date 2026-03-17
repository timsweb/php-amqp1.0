<?php

declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Client\Client;
use Closure;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Testcontainers\Container\Container;
use Testcontainers\Wait\WaitForLog;
use Throwable;
use RuntimeException;

/**
 * Base class for integration tests that need a live IBM MQ broker.
 *
 * Uses a locally-built IBM MQ Docker image that includes the MQSeriesAMQP package.
 *
 * ## Building the image (one-time setup)
 *
 * The prebuilt icr.io/ibm-messaging/mq image omits the AMQP package. Build a
 * custom image from the ibm-messaging/mq-container repo with AMQP enabled:
 *
 *   git clone -b 9.4.2 https://github.com/ibm-messaging/mq-container.git /tmp/ibmmq-build
 *   # Set genmqpkg_incamqp=1 in Dockerfile-server (line 68)
 *   # Add AMQP MQSC to incubating/mqadvanced-server-dev/10-dev.mqsc.tpl (see below)
 *   cd /tmp/ibmmq-build && COMMAND=~/.docker/bin/docker ARCH=amd64 make build-devserver
 *
 * MQSC to append to 10-dev.mqsc.tpl:
 *   ALTER SERVICE(SYSTEM.AMQP.SERVICE) CONTROL(QMGR)
 *   SET CHLAUTH('SYSTEM.DEF.AMQP') TYPE(ADDRESSMAP) ADDRESS('*') USERSRC(CHANNEL) CHCKCLNT({{ .ChckClnt }}) DESCR('Allows AMQP client connections') ACTION(REPLACE)
 *
 * ## Running against an external IBM MQ instance
 *
 * If you already have IBM MQ with AMQP enabled, skip Testcontainers entirely:
 *
 *   export IBMMQ_AMQP_URI="amqp://app:passw0rd@your-ibm-mq-host:5672/"
 *   vendor/bin/phpunit --testsuite Integration --filter IbmMq
 *
 * ## AMQP 1.0 addressing
 *
 * IBM MQ uses bare queue names as addresses (e.g. "DEV.QUEUE.1").
 * Do NOT use AddressHelper — it generates RabbitMQ-specific /queues/{name} paths.
 */
abstract class IbmMqTestCase extends TestCase
{
    private const LOCAL_IMAGE = 'ibm-mqadvanced-server-dev:9.4.2.1-amd64';

    private static ?Container $container = null;

    private static string $amqpUri = '';

    private static int $hostPort = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Prefer an externally-provided URI (e.g. a real IBM MQ instance).
        $externalUri = getenv('IBMMQ_AMQP_URI');
        if ($externalUri !== false && $externalUri !== '') {
            self::$amqpUri = $externalUri;

            return;
        }

        // Fall back to the locally-built Testcontainers image.
        if (!self::localImageExists()) {
            return; // tests will be skipped individually via requireIbmMq()
        }

        if (self::$container !== null) {
            return;
        }

        self::$hostPort = self::findFreePort();

        // The local image has no ARM64 build; force x86 emulation on Apple Silicon.
        putenv('DOCKER_DEFAULT_PLATFORM=linux/amd64');

        self::$container = Container::make(self::LOCAL_IMAGE)
            ->withEnvironment('LICENSE', 'accept')
            ->withEnvironment('MQ_QMGR_NAME', 'QM1')
            ->withEnvironment('MQ_APP_PASSWORD', 'passw0rd')
            ->withPort((string) self::$hostPort, '5672')
            ->withWait(new WaitForLog('Started queue manager'));

        self::$container->run();

        // CONTROL(QMGR) starts amqpcsea but does NOT launch the Java RunMQXRService
        // listener process. Explicitly start the AMQP service via runmqsc.
        self::$container->execute([
            'bash', '-c', "echo 'START SERVICE(SYSTEM.AMQP.SERVICE)' | runmqsc QM1",
        ]);

        // The MQSC template sets CHLAUTH for SYSTEM.DEF.AMQP (the template channel),
        // but CHLAUTH checks use the actual AMQP channel name (DEV.AMQP).
        // Add the rule now so incoming connections are not blocked by the back-stop.
        self::$container->execute([
            'bash', '-c',
            "echo \"SET CHLAUTH('DEV.AMQP') TYPE(ADDRESSMAP) ADDRESS('*')"
            . ' USERSRC(CHANNEL) CHCKCLNT(REQUIRED) ACTION(REPLACE)" | runmqsc QM1',
        ]);

        // The AMQP service uses its own channel registry (separate from MQSC channels),
        // so we must create a channel that listens on port 5672.
        // Retry until the Java listener is ready to accept the command.
        self::retryUntilSuccess(
            fn() => self::$container->execute([
                'bash', '-c',
                '/opt/mqm/amqp/bin/controlAMQPChannel.sh'
                . ' -qmgr=QM1 -mode=newchannel -chlname=DEV.AMQP -port=5672 -chltype=amqp -trptype=tcp'
                . ' 2>&1 | grep -q "completed successfully"',
            ]),
            maxWaitSeconds: 60,
        );

        // Wait until port 5672 is accepting connections on the mapped host port.
        self::waitForPort('localhost', self::$hostPort);

        self::$amqpUri = sprintf('amqp://app:passw0rd@localhost:%d/', self::$hostPort);
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$container !== null) {
            try {
                self::$container->stop();
            } catch (Throwable) {
            }
            try {
                self::$container->remove();
            } catch (Throwable) {
            }
            self::$container = null;
            self::$amqpUri = '';
            self::$hostPort = 0;
        }
    }

    protected function requireIbmMq(): void
    {
        if (self::$amqpUri === '') {
            $this->markTestSkipped(
                'IBM MQ not available. Either set IBMMQ_AMQP_URI or build the local image: '
                . self::LOCAL_IMAGE
            );
        }
    }

    protected function purgeQueue(string $queueName): void
    {
        if (self::$container !== null) {
            self::$container->execute([
                'bash', '-c',
                "echo 'PURGE QLOCAL(" . $queueName . ")' | runmqsc QM1",
            ]);
        }
    }

    protected function amqpUri(): string
    {
        return self::$amqpUri;
    }

    protected function newClient(): Client
    {
        return new Client($this->amqpUri());
    }

    /**
     * Run a test body inside the Revolt event loop.
     */
    protected function runInEventLoop(Closure $fn): void
    {
        $exception = null;
        EventLoop::queue(function () use ($fn, &$exception): void {
            try {
                $fn();
            } catch (Throwable $e) {
                $exception = $e;
            }
        });
        EventLoop::run();
        if ($exception !== null) {
            throw $exception;
        }
    }

    private static function localImageExists(): bool
    {
        foreach (['/usr/local/bin/docker', '/usr/bin/docker', getenv('HOME') . '/.docker/bin/docker'] as $candidate) {
            if (is_executable($candidate)) {
                $output = shell_exec($candidate . ' image inspect ' . escapeshellarg(self::LOCAL_IMAGE) . ' 2>/dev/null');

                return $output !== null && $output !== '';
            }
        }

        return false;
    }

    private static function retryUntilSuccess(callable $fn, int $maxWaitSeconds = 30): void
    {
        $deadline = time() + $maxWaitSeconds;
        while (time() < $deadline) {
            try {
                $fn();

                return;
            } catch (Throwable) {
                usleep(500_000);
            }
        }
        throw new RuntimeException("IBM MQ AMQP channel creation did not succeed within {$maxWaitSeconds}s");
    }

    private static function waitForPort(string $host, int $port, int $maxWaitSeconds = 30): void
    {
        $deadline = time() + $maxWaitSeconds;
        while (time() < $deadline) {
            $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(500_000);
        }
        throw new RuntimeException("IBM MQ AMQP port $host:$port did not open within {$maxWaitSeconds}s");
    }

    private static function findFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return 15673;
        }
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return (int) $port;
    }
}
