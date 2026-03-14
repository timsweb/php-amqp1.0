<?php

declare(strict_types=1);

namespace AMQP10\Management;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\ExchangeNotFoundException;
use AMQP10\Exception\ManagementException;
use AMQP10\Exception\QueueNotFoundException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;

class Management
{
    private readonly SenderLink $sender;

    private readonly ReceiverLink $receiver;

    private readonly string $replyTo;

    private int $requestId = 0;

    public function __construct(
        private readonly Session $session,
        private readonly float $timeout = 30.0,
    ) {
        $this->replyTo = 'management-reply-' . bin2hex(random_bytes(8));

        $linkName = 'management-link-' . bin2hex(random_bytes(4));

        $this->sender = new SenderLink(
            $session,
            name: $linkName,
            target: '/management',
            source: $this->replyTo,
            sndSettleMode: PerformativeEncoder::SND_SETTLED,
            managementLink: true,
        );
        $this->receiver = new ReceiverLink(
            $session,
            name: $linkName,
            source: '/management',
            target: $this->replyTo,
            initialCredit: 10,
            managementLink: true,
        );

        $this->sender->attach();
        $this->receiver->attach();
    }

    public function declareExchange(ExchangeSpecification $spec): void
    {
        $path = '/exchanges/' . rawurlencode($spec->name);
        $body = $this->encodeExchangeBody($spec);
        $response = $this->request('PUT', $path, $body);
        $this->assertSuccess($response, [200, 201, 204], $path);
    }

    public function deleteExchange(string $name): void
    {
        $path = '/exchanges/' . rawurlencode($name);
        $response = $this->request('DELETE', $path, null);
        $this->assertSuccess($response, [200, 204], $path);
    }

    public function declareQueue(QueueSpecification $spec): void
    {
        $path = '/queues/' . rawurlencode($spec->name);
        $body = $this->encodeQueueBody($spec);
        $response = $this->request('PUT', $path, $body);
        $this->assertSuccess($response, [200, 201], $path);
    }

    public function deleteQueue(string $name): void
    {
        $path = '/queues/' . rawurlencode($name);
        $response = $this->request('DELETE', $path, null);
        $this->assertSuccess($response, [200, 204], $path);
    }

    public function bind(BindingSpecification $spec): void
    {
        $path = '/bindings';
        $body = $this->encodeBindingBody($spec);
        $response = $this->request('POST', $path, $body);
        $this->assertSuccess($response, [200, 201, 204], $path);
    }

    private function encodeExchangeBody(ExchangeSpecification $spec): string
    {
        $pairs = [
            TypeEncoder::encodeString('type') => TypeEncoder::encodeString($spec->type->value),
            TypeEncoder::encodeString('durable') => TypeEncoder::encodeBool($spec->durable),
            TypeEncoder::encodeString('auto_delete') => TypeEncoder::encodeBool($spec->autoDelete),
        ];

        return TypeEncoder::encodeMap($pairs);
    }

    private function encodeQueueBody(QueueSpecification $spec): string
    {
        $argsPairs = [
            TypeEncoder::encodeString('x-queue-type') => TypeEncoder::encodeString($spec->type->value),
        ];
        $pairs = [
            TypeEncoder::encodeString('durable') => TypeEncoder::encodeBool($spec->durable),
            TypeEncoder::encodeString('arguments') => TypeEncoder::encodeMap($argsPairs),
        ];

        return TypeEncoder::encodeMap($pairs);
    }

    private function encodeBindingBody(BindingSpecification $spec): string
    {
        $pairs = [
            TypeEncoder::encodeString('source') => TypeEncoder::encodeString($spec->sourceExchange),
            TypeEncoder::encodeString('destination_queue') => TypeEncoder::encodeString($spec->destinationQueue),
            TypeEncoder::encodeString('binding_key') => TypeEncoder::encodeString($spec->bindingKey ?? ''),
            TypeEncoder::encodeString('arguments') => TypeEncoder::encodeMap([]),
        ];

        return TypeEncoder::encodeMap($pairs);
    }

    public function close(): void
    {
        $this->receiver->detach();
        $this->sender->detach();
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?string $amqpBody): array
    {
        $requestId = (string) ++$this->requestId;

        $payload = $this->buildRequestPayload($requestId, $method, $path, $amqpBody);
        $this->sender->transfer($payload);

        return $this->awaitResponse($requestId);
    }

    private function buildRequestPayload(
        string $requestId,
        string $method,
        string $path,
        ?string $amqpBody,
    ): string {
        $propsFields = [
            TypeEncoder::encodeString($requestId),
            TypeEncoder::encodeNull(),
            TypeEncoder::encodeString($path),
            TypeEncoder::encodeString($method),
            TypeEncoder::encodeString('$me'),
        ];
        $propertiesSection = TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(Descriptor::MSG_PROPERTIES),
            TypeEncoder::encodeList($propsFields),
        );

        $bodyValue = $amqpBody ?? TypeEncoder::encodeNull();
        $amqpValueSection = TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(Descriptor::MSG_AMQP_VALUE),
            $bodyValue,
        );

        return $propertiesSection . $amqpValueSection;
    }

    /** @return array<string, mixed> */
    private function awaitResponse(string $requestId): array
    {
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            if (microtime(true) >= $deadline) {
                throw new ManagementException(
                    "Timeout after {$this->timeout}s awaiting management response"
                );
            }
            $frame = $this->session->nextFrame();
            if ($frame === null) {
                if (! $this->session->transport()->isConnected()) {
                    throw new ManagementException('Connection closed awaiting management response');
                }

                continue;
            }

            $body = FrameParser::extractBody($frame);
            $performative = (new TypeDecoder($body))->decode();

            if (! is_array($performative) || ($performative['descriptor'] ?? null) !== Descriptor::TRANSFER) {
                continue;
            }

            $bodyDecoder = new TypeDecoder($body);
            $bodyDecoder->decode();
            $msgPayload = substr($body, $bodyDecoder->offset());

            if ($msgPayload === '') {
                continue;
            }

            $response = $this->decodeResponsePayload($msgPayload);

            if ((string) ($response['correlation-id'] ?? '') !== $requestId) {
                continue;
            }

            return [
                'status' => (int) ($response['subject'] ?? 0),
                'body' => $response['body'] ?? '',
            ];
        }
    }

    /** @return array<string, mixed> */
    private function decodeResponsePayload(string $payload): array
    {
        $decoder = new TypeDecoder($payload);
        $result = ['subject' => null, 'correlation-id' => null, 'body' => ''];

        while ($decoder->remaining() > 0) {
            $section = $decoder->decode();
            if (! is_array($section) || ! isset($section['descriptor'])) {
                continue;
            }

            switch ($section['descriptor']) {
                case Descriptor::MSG_PROPERTIES:
                    $fields = $section['value'] ?? [];
                    $result['subject'] = $fields[3] ?? null;
                    $result['correlation-id'] = $fields[5] ?? null;
                    break;
                case Descriptor::MSG_DATA:
                    $result['body'] = $section['value'] ?? '';
                    break;
                case Descriptor::MSG_AMQP_VALUE:
                    $val = $section['value'] ?? '';
                    if (is_string($val)) {
                        $result['body'] = $val;
                    } elseif (is_array($val)) {
                        $result['body'] = $val['value'] ?? '';
                    }
                    break;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int>  $expected
     */
    private function assertSuccess(array $response, array $expected, string $path = ''): void
    {
        if (in_array($response['status'], $expected, true)) {
            return;
        }
        $status = $response['status'];
        $body = $response['body'];
        if ($status === 404) {
            if (str_starts_with($path, '/queues/')) {
                throw new QueueNotFoundException("Queue not found: $body");
            }
            if (str_starts_with($path, '/exchanges/')) {
                throw new ExchangeNotFoundException("Exchange not found: $body");
            }
        }
        throw new ManagementException("Management request failed with status $status: $body");
    }
}
