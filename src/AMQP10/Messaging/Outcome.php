<?php
declare(strict_types=1);

namespace AMQP10\Messaging;

readonly class Outcome
{
    private function __construct(public readonly OutcomeState $state) {}

    public static function accepted(): self
    {
        return new self(OutcomeState::ACCEPTED);
    }

    public static function rejected(): self
    {
        return new self(OutcomeState::REJECTED);
    }

    public static function released(): self
    {
        return new self(OutcomeState::RELEASED);
    }

    public static function modified(): self
    {
        return new self(OutcomeState::MODIFIED);
    }

    public function isAccepted(): bool
    {
        return $this->state === OutcomeState::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->state === OutcomeState::REJECTED;
    }

    public function isReleased(): bool
    {
        return $this->state === OutcomeState::RELEASED;
    }

    public function isModified(): bool
    {
        return $this->state === OutcomeState::MODIFIED;
    }
}
