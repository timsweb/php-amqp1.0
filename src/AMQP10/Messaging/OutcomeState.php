<?php

namespace AMQP10\Messaging;

enum OutcomeState
{
    case ACCEPTED;
    case REJECTED;
    case RELEASED;
    case MODIFIED;
}
