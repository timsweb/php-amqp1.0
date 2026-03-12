<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Connection\Session;

class PublisherBuilder
{
    public function __construct(
        private readonly Session $session,
        private readonly string  $address,
    ) {}

    public function send(Message $message): Outcome
    {
        $publisher = new Publisher($this->session, $this->address);
        $outcome   = $publisher->send($message);
        $publisher->close();
        return $outcome;
    }
}
