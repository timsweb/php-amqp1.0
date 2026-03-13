<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Connection\Session;

class PublisherBuilder
{
    public function __construct(
        private readonly Session $session,
        private readonly string  $address,
        private readonly float   $timeout = 30.0,
    ) {}

    public function send(Message $message): Outcome
    {
        $publisher = new Publisher($this->session, $this->address, $this->timeout);
        $outcome   = $publisher->send($message);
        $publisher->close();
        return $outcome;
    }

    public function publisher(): Publisher
    {
        return new Publisher($this->session, $this->address, $this->timeout);
    }
}
