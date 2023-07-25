<?php

namespace PhpRedisQueue;

use PhpRedisQueue\Client;

class ClientMock extends Client
{
  protected function getDatetime(): string
  {
    return '2023-01-01T10:00:00';
  }
}
