<?php

namespace PhpRedisQueue\managers;

use PhpRedisQueue\traits\CanLog;

class BaseManager
{
  use CanLog;

  public function __construct(protected \Predis\Client $redis, array $config = [])
  {
    $this->setLogger($config);
  }
}
