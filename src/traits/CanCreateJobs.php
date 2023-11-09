<?php

namespace PhpRedisQueue\traits;

use PhpRedisQueue\managers\JobManager;
use PhpRedisQueue\models\Job;

trait CanCreateJobs
{
  protected JobManager $jobManager;

  protected function setJobManager(\Predis\Client $redis): void
  {
    $this->jobManager = new JobManager($redis);
  }

  /**
   * Creates a pending job
   * @param string $queue   Queue name
   * @param string $jobName Name of the specific job to run, defaults to `default`. Ex: `upload`
   * @param array $jobData  Data associated with this job
   * @return Job
   */
  public function createJob(string $queue, string $jobName = 'default', array $jobData = []): Job
  {
    $job = $this->jobManager->createJob($queue, $jobName, $jobData);
    $job->withMeta('status', 'pending')->save();

    return $job;
  }

  /**
   * Add a job to a queue
   * @param Job $job    Job to add
   * @param bool $front Push the new job to the front of the queue?
   * @return false|int
   */
  public function addJobToQueue(Job $job, bool $front = false): false|int
  {
    if ($this->jobManager->addJobToQueue($job, $front)) {
      return $job->id();
    }

    return false;
  }
}
