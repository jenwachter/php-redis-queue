<?php

namespace PhpRedisQueue\traits;

use PhpRedisQueue\Logger;
use Psr\Log\LoggerInterface;

trait CanLog
{
  protected function log(string $level, string $message, array $data = []): void
  {
    $logger = Logger::get();

    if (!$logger) {
      return;
    }

    $logger->$level($message, $data);
  }
}
