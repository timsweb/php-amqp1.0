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
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;

/**
 * AMQP 1.0 management client using the HTTP-over-AMQP protocol.
 *
 * RabbitMQ exposes a management node at /management.
 * Clients send request messages and receive response messages
 * using HTTP-like subject fields (method/status) and JSON bodies.
 */
class Management
{
    private readonly SenderLink   $sender;
    private readonly ReceiverLink $receiver;
    private readonly FrameParser  $parser;
    private readonly string       $replyTo;
    private int     $requestId    = 0;
    /** @var string[] Frames read from transport but not yet matched to a request. */
    private array $pendingFrames  = [];

    public function __construct(private readonly Session $session)
    {
        $this->replyTo  = 'management-reply-' . bin2hex(random_bytes(8));
        $this->parser   = new FrameParser();

        // RabbitMQ AMQP 1.0 management protocol requires paired links:
        // - Both links share the same link name and clientTerminusAddress (= replyTo).
        // - Sender: role=false, snd-settle-mode=settled(1), source=replyTo, target=/management
        // - Receiver: role=true, source=/management, target=replyTo
        // - Both must carry properties map: {symbol,"paired"} => true
        $linkName = 'management-link-' . bin2hex(random_bytes(4));

        $this->sender   = new SenderLink(
            $session,
            name:          $linkName,
            target:        '/management',
            source:        $this->replyTo,
            sndSettleMode: \AMQP10\Protocol\PerformativeEncoder::SND_SETTLED,
            managementLink: true,
        );
        $this->receiver = new ReceiverLink(
            $session,
            name:          $linkName,
            source:        '/management',
            target:        $this->replyTo,
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
        $path     = '/exchanges/' . rawurlencode($name);
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
        $path     = '/queues/' . rawurlencode($name);
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

    /**
     * Encode an exchange declaration body as an AMQP map.
     * RabbitMQ expects: type (utf8), durable (bool), auto_delete (bool)
     */
    private function encodeExchangeBody(ExchangeSpecification $spec): string
    {
        $pairs = [
            TypeEncoder::encodeString('type')        => TypeEncoder::encodeString($spec->type->value),
            TypeEncoder::encodeString('durable')     => TypeEncoder::encodeBool($spec->durable),
            TypeEncoder::encodeString('auto_delete') => TypeEncoder::encodeBool($spec->autoDelete),
        ];
        return TypeEncoder::encodeMap($pairs);
    }

    /**
     * Encode a queue declaration body as an AMQP map.
     * RabbitMQ expects: durable (bool), arguments (AMQP map of queue args)
     */
    private function encodeQueueBody(QueueSpecification $spec): string
    {
        $argsPairs = [
            TypeEncoder::encodeString('x-queue-type') => TypeEncoder::encodeString($spec->type->value),
        ];
        $pairs = [
            TypeEncoder::encodeString('durable')   => TypeEncoder::encodeBool($spec->durable),
            TypeEncoder::encodeString('arguments') => TypeEncoder::encodeMap($argsPairs),
        ];
        return TypeEncoder::encodeMap($pairs);
    }

    /**
     * Encode a binding body as an AMQP map.
     * RabbitMQ expects: source (utf8), destination_queue (utf8), binding_key (utf8), arguments (map)
     */
    private function encodeBindingBody(BindingSpecification $spec): string
    {
        $pairs = [
            TypeEncoder::encodeString('source')             => TypeEncoder::encodeString($spec->sourceExchange),
            TypeEncoder::encodeString('destination_queue')  => TypeEncoder::encodeString($spec->destinationQueue),
            TypeEncoder::encodeString('binding_key')        => TypeEncoder::encodeString($spec->bindingKey ?? ''),
            TypeEncoder::encodeString('arguments')          => TypeEncoder::encodeMap([]),
        ];
        return TypeEncoder::encodeMap($pairs);
    }

    public function close(): void
    {
        $this->receiver->detach();
        $this->sender->detach();
    }

    /**
     * @param string|null $amqpBody Pre-encoded AMQP value (e.g. a map), or null for requests with no body.
     */
    private function request(string $method, string $path, ?string $amqpBody): array
    {
        $requestId = (string) ++$this->requestId;

        // RabbitMQ management protocol: routing metadata goes in AMQP properties section,
        // not in application-properties. reply_to must be "$me" (paired link routing).
        $payload = $this->buildRequestPayload($requestId, $method, $path, $amqpBody);
        $this->sender->transfer($payload);

        return $this->awaitResponse($requestId);
    }

    /**
     * Build the raw AMQP message sections for a management request.
     * Properties section carries: message-id, to (path), subject (method), reply-to ("$me").
     * Body is an amqp-value section containing a pre-encoded AMQP value (or null for no body).
     */
    private function buildRequestPayload(
        string  $requestId,
        string  $method,
        string  $path,
        ?string $amqpBody,
    ): string {
        // AMQP properties section fields (spec §3.2.4):
        // [0]=message-id [1]=user-id [2]=to [3]=subject [4]=reply-to [5]=correlation-id ...
        $propsFields = [
            TypeEncoder::encodeString($requestId), // message-id
            TypeEncoder::encodeNull(),              // user-id
            TypeEncoder::encodeString($path),       // to
            TypeEncoder::encodeString($method),     // subject
            TypeEncoder::encodeString('$me'),       // reply-to ("$me" = paired receiver link)
        ];
        $propertiesSection = TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(Descriptor::MSG_PROPERTIES),
            TypeEncoder::encodeList($propsFields),
        );

        // amqp-value section with the pre-encoded AMQP body (or null for DELETE/no-body requests)
        $bodyValue = $amqpBody ?? TypeEncoder::encodeNull();
        $amqpValueSection = TypeEncoder::encodeDescribed(
            TypeEncoder::encodeUlong(Descriptor::MSG_AMQP_VALUE),
            $bodyValue,
        );

        return $propertiesSection . $amqpValueSection;
    }

    private function awaitResponse(string $requestId): array
    {
        $transport = $this->session->transport();

        while (true) {
            // First drain any previously buffered frames before reading more data.
            $frames = $this->pendingFrames;
            $this->pendingFrames = [];

            if (empty($frames)) {
                $data = $transport->read(4096);
                if ($data === null) {
                    throw new ManagementException('Connection closed awaiting management response');
                }
                if ($data !== '') {
                    $this->parser->feed($data);
                }
                $frames = $this->parser->readyFrames();
            }

            foreach ($frames as $frame) {
                $body         = FrameParser::extractBody($frame);
                $performative = (new TypeDecoder($body))->decode();

                if (!is_array($performative) || ($performative['descriptor'] ?? null) !== Descriptor::TRANSFER) {
                    // Not a TRANSFER — skip (e.g. ATTACH, FLOW frames)
                    continue;
                }

                // After the TRANSFER performative comes the message payload.
                // Skip past the performative bytes to get to the message sections.
                $bodyDecoder = new TypeDecoder($body);
                $bodyDecoder->decode(); // consume performative
                $msgPayload  = substr($body, $bodyDecoder->offset());

                if ($msgPayload === '') {
                    continue;
                }

                // Decode management response: status comes from properties.subject,
                // correlation_id from properties.correlation-id, body from data/amqp-value.
                $response = $this->decodeResponsePayload($msgPayload);

                if ((string)($response['correlation-id'] ?? '') !== $requestId) {
                    // Response for a different request — re-buffer and keep looking
                    $this->pendingFrames[] = $frame;
                    continue;
                }

                // Save any remaining unprocessed frames for future requests
                $remaining = array_slice($frames, array_search($frame, $frames, true) + 1);
                $this->pendingFrames = array_merge($this->pendingFrames, $remaining);

                return [
                    'status' => (int)($response['subject'] ?? 0),
                    'body'   => $response['body'] ?? '',
                ];
            }
        }
    }

    /**
     * Decode a management response message payload.
     * Returns ['subject' => statusCode, 'correlation-id' => id, 'body' => string]
     */
    private function decodeResponsePayload(string $payload): array
    {
        $decoder = new TypeDecoder($payload);
        $result  = ['subject' => null, 'correlation-id' => null, 'body' => ''];

        while ($decoder->remaining() > 0) {
            $section = $decoder->decode();
            if (!is_array($section) || !isset($section['descriptor'])) {
                continue;
            }

            switch ($section['descriptor']) {
                case Descriptor::MSG_PROPERTIES:
                    // Properties list: [0]=msg-id [1]=user-id [2]=to [3]=subject
                    //                  [4]=reply-to [5]=correlation-id
                    $fields = $section['value'] ?? [];
                    $result['subject']        = $fields[3] ?? null; // subject = status code string
                    $result['correlation-id'] = $fields[5] ?? null; // correlation-id
                    break;

                case Descriptor::MSG_DATA:
                    // data section — value is the raw binary string
                    $result['body'] = $section['value'] ?? '';
                    break;

                case Descriptor::MSG_AMQP_VALUE:
                    // amqp-value section — RabbitMQ uses this for error bodies
                    $val = $section['value'] ?? '';
                    if (is_string($val)) {
                        $result['body'] = $val;
                    } elseif (is_array($val)) {
                        // might be a described type {descriptor, value}
                        $result['body'] = $val['value'] ?? '';
                    }
                    break;
            }
        }

        return $result;
    }

    private function assertSuccess(array $response, array $expected, string $path = ''): void
    {
        if (in_array($response['status'], $expected, true)) {
            return;
        }
        $status = $response['status'];
        $body   = $response['body'];
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
