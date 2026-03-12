<?php
namespace AMQP10\Management;

enum ExchangeType: string
{
    case DIRECT  = 'direct';
    case FANOUT  = 'fanout';
    case TOPIC   = 'topic';
    case HEADERS = 'headers';
}
