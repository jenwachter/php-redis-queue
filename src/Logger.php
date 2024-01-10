<?php

namespace PhpRedisQueue;

use Psr\Log\LoggerInterface;

class Logger
{
  protected static LoggerInterface|null $logger = null;

  public static function get(): LoggerInterface|null
  {
    return self::$logger;
  }

  public static function set(LoggerInterface $logger): void
  {
    self::$logger = $logger;
  }
}
