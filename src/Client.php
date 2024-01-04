<?php

namespace PhpRedisQueue;

use PhpRedisQueue\managers\JobGroupManager;
use PhpRedisQueue\traits\CanCreateJobs;
use PhpRedisQueue\traits\CanLog;

class Client
{
  use CanCreateJobs;
  use CanLog;

  /**
   * @param \Predis\Client $redis
   * @param array $config
   */
  public function __construct(protected \Predis\Client $redis, array $config = [])
  {
    if (isset($config['logger'])) {
      Logger::set($config['logger']);
      unset($config['logger']);
    }

    $this->setJobManager($redis);

    $this->jobGroupManager = new JobGroupManager($redis);
  }

  /**
   * Pushes a job to the end of the queue
   * @param string $queue   Queue name
   * @param string $jobName Name of the specific job to run, defaults to `default`. Ex: `upload`
   * @param array $jobData  Data associated with this job
   * @return int|false ID of job or FALSE on failure
   */
  public function push(string $queue, string $jobName = 'default', array $jobData = []): int|false
  {
    $job = $this->createJob($queue, $jobName, $jobData);
    return $this->addJobToQueue($job);
  }

  /**
   * Pushes a job to the front of the queue
   * @param string $queue   Queue name
   * @param string $jobName Name of the specific job to run, defaults to `default`. Ex: `upload`
   * @param array $jobData  Data associated with this job
   * @return int|false ID of job or FALSE on failure
   */
  public function pushToFront(string $queue, string $jobName = 'default', array $jobData = []): int|false
  {
    $job = $this->createJob($queue, $jobName, $jobData);
    return $this->addJobToQueue($job, true);
  }

  /**
   * Remove a job from its queue
   * @param $id
   * @return bool
   */
  public function pull($id)
  {
    return $this->jobManager->removeJobFromQueue($id);
  }

   /**
    * Rerun a job that previously failed.
    * @param int $jobId     ID of job to rerun
    * @return int           ID of new job
    * @param boolean $front Push the new job to the front of the queue?
    * @return int           ID of new job
    * @throws \Exception
    */
  public function rerun(int $jobId, bool $front = false): int
  {
    return $this->jobManager->rerun($jobId, $front);
  }

  public function createJobGroup($total = null, $data = []): models\JobGroup
  {
    return $this->jobGroupManager->createJobGroup($total, $data);
  }

  /**
   * Remove a job group
   * @param int $id
   * @return bool
   */
  public function removeJobGroup(int $id): bool
  {
    return $this->jobGroupManager->removeJobGroup($id);
  }
}
