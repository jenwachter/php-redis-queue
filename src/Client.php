<?php

namespace PhpRedisQueue;

use PhpRedisQueue\managers\JobManager;
use PhpRedisQueue\models\Job;
use PhpRedisQueue\traits\CanLog;

class Client
{
  use CanLog;

  protected JobManager $jobManager;

  /**
   * @param \Predis\Client $redis
   * @param array $config
   */
  public function __construct(protected \Predis\Client $redis, array $config = [])
  {
    $this->jobManager = new JobManager($redis, $config);

    $this->setLogger($config);
  }

  /**
   * Creates a pending job
   * @param string $queue   Queue name
   * @param string $jobName Name of the specific job to run, defaults to `default`. Ex: `upload`
   * @param array $jobData  Data associated with this job
   * @return models\Job
   */
  public function createJob(string $queue, string $jobName = 'default', array $jobData = []): Job
  {
    $job = $this->jobManager->createJob($queue, $jobName, $jobData);
    $job->withMeta('status', 'pending')->save();

    return $job;
  }

  /**
   * Add a job to a queue
   * @param string $queue  Queue name
   * @param Job $job       Job to add
   * @param boolean $front Push the new job to the front of the queue?
   * @return false|int
   */
  public function addJobToQueue(string $queue, Job $job, $front = false): false|int
  {
    if ($this->jobManager->addJobToQueue($queue, $job, $front)) {
      return $job->id();
    }

    return false;
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
    return $this->addJobToQueue($queue, $job);
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
    return $this->addJobToQueue($queue, $job, true);
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

  /**
   * Remove a job from a queue
   * @param string $queue
   * @param int $jobId
   * @return bool
   */
  public function remove(string $queue, int $jobId): bool
  {
    return $this->jobManager->removeJobFromQueue($queue, $jobId);
  }
}
