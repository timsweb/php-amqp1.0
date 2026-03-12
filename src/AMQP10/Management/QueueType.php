<?php
namespace AMQP10\Management;

enum QueueType: string
{
    case CLASSIC = 'classic';
    case QUORUM  = 'quorum';
    case STREAM  = 'stream';
}
