<?php

namespace PhpRedisQueue\traits;

use Psr\Log\LoggerInterface;

trait CanLog
{
  /**
   * LoggerInterface
   * @var LoggerInterface|null
   */
  protected ?LoggerInterface $logger;

  protected function setLogger($config): void
  {
    if (!isset($config['logger'])) {
      return;
    }

    if (!$config['logger'] instanceof LoggerInterface) {
      throw new \InvalidArgumentException('Logger must be an instance of Psr\Log\LoggerInterface.');
    }

    $this->logger = $config['logger'];
  }

  protected function log(string $level, string $message, array $data = []): void
  {
    if (!isset($this->logger)) {
      return;
    }

    $this->logger->$level($message, $data);
  }
}
