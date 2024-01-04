<?php

namespace PhpRedisQueue\managers;

use PhpRedisQueue\models\JobGroup;

class JobGroupManager extends BaseManager
{
  public function createJobGroup($total = null, $data = []): JobGroup
  {
    $group = new JobGroup($this->redis);

    if (is_int($total)) {
      $group->withData('total', $total);
    }

    $group->withData('userSupplied', $data);

    $group->save();

    return $group;
  }

  public function removeJobGroup(int $id)
  {
    $group = $this->getJobGroup($id);

    if ($group->get() === null) {
      return false;
    }

    return $group->remove();
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
