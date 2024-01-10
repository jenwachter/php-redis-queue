<?php

namespace PhpRedisQueue;

use PhpRedisQueue\managers\JobManager;
use PhpRedisQueue\models\Job;

class JobManagerMock extends JobManager
{
  public function createJob(string $queue, string $jobName = 'default', array $jobData = [], int|null $group = null): Job
  {
    return new JobMock($this->redis, $queue, $jobName, $jobData, $group);
  }

  public function getJob($id)
  {
    return (new JobMock($this->redis, (int) $id));
  }
}
