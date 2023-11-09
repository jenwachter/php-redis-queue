<?php

namespace PhpRedisQueue\managers;

use PhpRedisQueue\models\JobGroup;

class JobGroupManager extends BaseManager
{
  public function createJobGroup($total = null): JobGroup
  {
    $group = new JobGroup($this->redis);

    if (is_int($total)) {
      $group->withMeta('total', $total);
    }

    $group->save();

    return $group;
  }
}
