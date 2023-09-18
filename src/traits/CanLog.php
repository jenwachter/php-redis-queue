<?php

namespace PhpRedisQueue\traits;

trait CanLog
{
  protected function log(string $level, string $message, array $data = [])
  {
    if (!isset($this->config['logger'])) {
      return;
    }

    $this->config['logger']->$level($message, $data);
  }
}
