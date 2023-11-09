<?php

namespace PhpRedisQueue\managers;

use PhpRedisQueue\models\Job;
use PhpRedisQueue\models\Queue;

class JobManager extends BaseManager
{
  /**
   * Redis key that holds the hash that keeps track
   * of active queues.
   * @var string
   */
  protected string $allQueues = 'php-redis-queue:queues';

  public function createJob(string $queue, string $jobName = 'default', array $jobData = []): Job
  {
    return new Job($this->redis, $queue, $jobName, $jobData);
  }

  /**
   * Get a list of active queues and how many workers are
   * available per queue.
   * @return array
   */
  public function getJob($id)
  {
    return (new Job($this->redis, (int) $id))->get();
  }

  /**
   * Add a job to a queue
   * @param string $queueName
   * @param Job $job
   * @param bool $front
   * @return boolean       TRUE if job was successfully added to the queue
   */
  public function addJobToQueue(string $queueName, Job $job, bool $front = false): bool
  {
    $method = $front ? 'lpush' : 'rpush';
    $queue = new Queue($queueName);

    $length = $this->redis->llen($queue->pending);
    $newLength = $this->redis->$method($queue->pending, $job->id());

    return $newLength === ++$length;
  }

  /**
   * Remove a job from a queue
   * @param string $queueName
   * @param int $jobId
   * @return bool
   */
  public function removeJobFromQueue(string $queueName, int $jobId): bool
  {
    $queue = new Queue($queueName);

    $result = $this->redis->lrem($queue->pending, -1, $jobId);
    return $result === 1;
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
    $job = new Job($this->redis, $jobId);

    if (!$job->get()) {
      throw new \Exception("Job #$jobId not found. Cannot rerun.");
    }

    if ($job->status() !== 'failed') {
      throw new \Exception("Job #$jobId did not fail. Cannot rerun.");
    }

    $job->withRerun()->save();

    $queue = new Queue($job->queue());

    // remove from failed list
    $this->redis->lrem($queue->failed, -1, $job->id());

    return $this->addJobToQueue($job->queue(), $job, $front);
  }
}
