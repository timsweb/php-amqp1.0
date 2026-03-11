<?php

namespace AMQP10\Exception;

use AMQP10\Exception\AmqpException;

class ProtocolException extends AmqpException
{
}

class InvalidAddressException extends ProtocolException
{
}

class FrameException extends ProtocolException
{
}

class SaslException extends ProtocolException
{
}
