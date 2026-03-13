<?php
declare(strict_types=1);
namespace AMQP10\Terminus;

enum ExpiryPolicy: string
{
    case LinkDetach      = 'link-detach';
    case SessionEnd      = 'session-end';
    case ConnectionClose = 'connection-close';
    case Never           = 'never';
}
