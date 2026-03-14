<?php

declare(strict_types=1);

namespace AMQP10\Terminus;

enum TerminusDurability: int
{
    case None = 0;
    case Configuration = 1;
    case UnsettledState = 2;
}
