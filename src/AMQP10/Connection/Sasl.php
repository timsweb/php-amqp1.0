<?php
namespace AMQP10\Connection;

readonly class Sasl
{
    private function __construct(
        private string $mechanism,
        private string $initialResponse,
    ) {}

    public static function plain(string $username, string $password): self
    {
        return new self('PLAIN', "\x00$username\x00$password");
    }

    public static function external(): self
    {
        return new self('EXTERNAL', '');
    }

    public function mechanism(): string
    {
        return $this->mechanism;
    }

    public function initialResponse(): string
    {
        return $this->initialResponse;
    }
}
