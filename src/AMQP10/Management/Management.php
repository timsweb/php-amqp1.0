<?php
namespace AMQP10\Management;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\BindingException;
use AMQP10\Exception\ManagementException;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageDecoder;
use AMQP10\Messaging\MessageEncoder;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;

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

        $this->sender   = new SenderLink($session, name: 'management-sender', target: '/management');
        $this->receiver = new ReceiverLink($session, name: 'management-receiver', source: $this->replyTo, initialCredit: 10);

        $this->sender->attach();
        $this->receiver->attach();
    }

    public function declareExchange(ExchangeSpecification $spec): void
    {
        $path = '/exchanges/' . rawurlencode($spec->name);
        $body = json_encode([
            'type'        => $spec->type->value,
            'durable'     => $spec->durable,
            'auto_delete' => $spec->autoDelete,
        ]);
        $response = $this->request('PUT', $path, $body);
        $this->assertSuccess($response, [200, 201]);
    }

    public function deleteExchange(string $name): void
    {
        $path     = '/exchanges/' . rawurlencode($name);
        $response = $this->request('DELETE', $path);
        $this->assertSuccess($response, [200, 204]);
    }

    public function declareQueue(QueueSpecification $spec): void
    {
        $path = '/queues/' . rawurlencode($spec->name);
        $body = json_encode([
            'durable'   => $spec->durable,
            'arguments' => ['x-queue-type' => $spec->type->value],
        ]);
        $response = $this->request('PUT', $path, $body);
        $this->assertSuccess($response, [200, 201]);
    }

    public function deleteQueue(string $name): void
    {
        $path     = '/queues/' . rawurlencode($name);
        $response = $this->request('DELETE', $path);
        $this->assertSuccess($response, [200, 204]);
    }

    public function bind(BindingSpecification $spec): void
    {
        $path = '/bindings';
        $body = json_encode([
            'source'           => $spec->sourceExchange,
            'destination'      => $spec->destinationQueue,
            'destination_type' => 'queue',
            'routing_key'      => $spec->bindingKey ?? '',
        ]);
        $response = $this->request('POST', $path, $body);
        $this->assertSuccess($response, [200, 201]);
    }

    public function close(): void
    {
        $this->receiver->detach();
        $this->sender->detach();
    }

    private function request(string $method, string $path, ?string $jsonBody = null): array
    {
        $requestId = (string) ++$this->requestId;

        $appProps = [
            'subject'        => $method,
            'to'             => $path,
            'message-id'     => $requestId,
            'reply-to'       => $this->replyTo,
        ];

        $msg = new Message($jsonBody ?? '', applicationProperties: $appProps);
        $this->sender->transfer(MessageEncoder::encode($msg));

        return $this->awaitResponse($requestId);
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
                    throw new BindingException('Connection closed awaiting management response');
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

                $msg    = MessageDecoder::decode($msgPayload);
                $corrId = $msg->applicationProperty('correlation-id');

                if ((string)$corrId !== $requestId) {
                    // Response for a different request — re-buffer and keep looking
                    $this->pendingFrames[] = $frame;
                    continue;
                }

                // Save any remaining unprocessed frames for future requests
                // (frames after the matched one in this batch)
                $remaining = array_slice($frames, array_search($frame, $frames, true) + 1);
                $this->pendingFrames = array_merge($this->pendingFrames, $remaining);

                return [
                    'status' => (int)($msg->applicationProperty('subject') ?? 0),
                    'body'   => $msg->body(),
                ];
            }
        }
    }

    private function assertSuccess(array $response, array $expected): void
    {
        if (!in_array($response['status'], $expected, true)) {
            throw new BindingException(
                "Management request failed with status {$response['status']}: {$response['body']}"
            );
        }
    }
}
