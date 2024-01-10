<?php

namespace PhpRedisQueue;

class ClientMock extends \PhpRedisQueue\Client
{
  protected function setJobManager(\Predis\Client $redis): void
  {
    $this->jobManager = new JobManagerMock($redis);
  }
  protected function getDatetime(): string
  {
    return '2023-01-01T10:00:00';
  }
}
