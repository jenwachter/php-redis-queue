<?php

namespace PhpRedisQueue;

use PhpRedisQueue\models\Job;

class JobMock extends Job
{
  protected function getDatetime(): string
  {
    return '2023-01-01T10:00:00';
  }
}
