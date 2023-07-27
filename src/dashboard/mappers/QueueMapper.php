<?php

namespace PhpRedisQueue\dashboard\mappers;

use PhpRedisQueue\dashboard\models\Job;
use PhpRedisQueue\dashboard\models\Queue;

class QueueMapper extends BaseMapper
{
  public function get(string $name, $start = 0, $stop = 20)
  {
    $jobMapper = new JobMapper($this->redis);
    $jobs = $this->redis->lrange($name, $start, $stop);

    return new Queue([
      'name' => str_replace('php-redis-queue:client:', '', $name),
      'jobs' => array_map(fn ($jid) => $jobMapper->get($jid), $jobs),
    ]);
  }
}
