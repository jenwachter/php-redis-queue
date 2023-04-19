<?php

namespace PhpRedisQueue\dashboard\mappers;

use PhpRedisQueue\dashboard\models\Job;
use PhpRedisQueue\dashboard\models\Queue;

class QueueMapper extends BaseMapper
{
  public function get(string $name, $start = 0, $stop = 20)
  {
    return new Queue([
      'name' => str_replace('php-redis-queue:client:', '', $name),
      'jobs' => $this->redis->lrange($name, $start, $stop),
    ]);
  }
}
