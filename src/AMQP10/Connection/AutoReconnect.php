<?php
namespace AMQP10\Connection;

use AMQP10\Exception\ConnectionFailedException;

class AutoReconnect
{
    public function __construct(
        private readonly \Closure $connect,
        private readonly int $maxRetries = 5,
        private readonly int $backoffMs  = 1000,
    ) {}

    public function run(): void
    {
        $attempt = 0;
        while (true) {
            try {
                ($this->connect)();
                return;
            } catch (ConnectionFailedException $e) {
                $attempt++;
                if ($attempt > $this->maxRetries) {
                    throw new ConnectionFailedException('Max retries exceeded', previous: $e);
                }
                if ($this->backoffMs > 0) {
                    usleep($this->backoffMs * 1000 * $attempt);
                }
            }
        }
    }
}
