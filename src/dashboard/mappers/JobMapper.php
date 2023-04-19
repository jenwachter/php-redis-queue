<?php

namespace PhpRedisQueue\dashboard\mappers;

use PhpRedisQueue\dashboard\models\Job;

class JobMapper extends BaseMapper
{
  public function get($id)
  {
    $data = $this->redis->get('php-redis-queue:jobs:'. $id);

    if (!$data) {
      throw new \Exception('Not Found', 404);
    }

    return new Job ($data);
  }
}
