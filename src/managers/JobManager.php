<?php

namespace PhpRedisQueue\managers;

use PhpRedisQueue\models\Job;
use PhpRedisQueue\models\JobGroup;
use PhpRedisQueue\models\Queue;

class JobManager extends BaseManager
{
  /**
   * Redis key that holds the hash that keeps track
   * of active queues.
   * @var string
   */
  protected string $allQueues = 'php-redis-queue:queues';

  public function createJob(string $queue, string $jobName = 'default', array $jobData = [], int|null $group = null): Job
  {
    return new Job($this->redis, $queue, $jobName, $jobData, $group);
  }

  /**
   * Get a job by ID
   * @return array
   */
  public function getJob($id)
  {
    return (new Job($this->redis, (int) $id));
  }

  /**
   * Add a job to a queue
   * @param Job $job
   * @param bool $front
   * @return boolean       TRUE if job was successfully added to the queue
   */
  public function addJobToQueue(Job $job, bool $front = false): bool
  {
    $method = $front ? 'lpush' : 'rpush';
    $queue = new Queue($this->redis, $job->get('queue'));

    $length = $this->redis->llen($queue->pending);
    $newLength = $this->redis->$method($queue->pending, $job->id());

    return $newLength === ++$length;
  }

  /**
   * Remove a job from its queue
   * @param int|Job $jobId
   * @return bool
   */
  public function removeJobFromQueue(int|Job $job): bool
  {
    if (is_int($job)) {
      $job = new Job($this->redis, $job);
    }

    if ($job->get() === null) {
      return false;
    }

    $queue = new Queue($this->redis, $job->get('queue'));

    $removedFromPending = $this->redis->lrem($queue->pending, -1, $job->id());
    $removedFromProcessing = $this->redis->lrem($queue->processing, -1, $job->id());
    $removedFromProcessed = $this->redis->lrem($queue->processed, -1, $job->id());

    return $removedFromPending === 1 || $removedFromProcessing === 1 || $removedFromProcessed === 1;
  }

  /**
   * Rerun a job that previously failed.
   * @param int $jobId     ID of job to rerun
   * @param boolean $front Push the new job to the front of the queue?
   * @return boolean       TRUE if job was successfully added to the queue
   * @throws \Exception
   */
  public function rerun(int $jobId, bool $front = false): bool
  {
    $job = $this->getJob($jobId);

    if (!$job->get()) {
      throw new \Exception("Job #$jobId not found. Cannot rerun.");
    }

    if ($job->get('status') !== 'failed') {
      throw new \Exception("Job #$jobId did not fail. Cannot rerun.");
    }

    if ($job->get('group') !== null) {
      $group = new JobGroup($this->redis, $job->get('group'));
      $group->rerunJob($jobId);
    }

    $job->withRerun()->save();
    $this->removeJobFromQueue($job);

    return $this->addJobToQueue($job, $front);
  }
}
