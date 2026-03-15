<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

define('AMQP_URI', getenv('AMQP_URI') ?: 'amqp://guest:guest@localhost');
