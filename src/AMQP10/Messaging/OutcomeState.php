<?php

declare(strict_types=1);

namespace AMQP10\Messaging;

enum OutcomeState
{
    case ACCEPTED;
    case REJECTED;
    case RELEASED;
    case MODIFIED;
}
