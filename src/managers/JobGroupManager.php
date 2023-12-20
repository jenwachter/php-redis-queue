<?php

namespace PhpRedisQueue\managers;

use PhpRedisQueue\models\JobGroup;

class JobGroupManager extends BaseManager
{
  public function createJobGroup($total = null, $data = []): JobGroup
  {
    $group = new JobGroup($this->redis);

    if (is_int($total)) {
      $group->withMeta('total', $total);
    }

    $group->withMeta('userSupplied', $data);

    $group->save();

    return $group;
  }

  /**
   * Get a job group by ID
   * @return array
   */
  public function getJobGroup($id)
  {
    return (new JobGroup($this->redis, (int) $id));
  }
}
