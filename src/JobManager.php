<?php

namespace PhpRedisQueue;

use PhpRedisQueue\models\Job;
use PhpRedisQueue\models\Queue;
use PhpRedisQueue\traits\CanLog;
use Psr\Log\LoggerInterface;

class JobManager
{
  use CanLog;

  /**
   * Default configuration that is merged with configuration passed in constructor
   * @var array
   */
  protected $defaultConfig = [
    /**
     * @var Psr\Log\LoggerInterface|null
     */
    'logger' => null,
  ];

  /**
   * Redis key that holds the hash that keeps track
   * of active queues.
   * @var string
   */
  protected string $allQueues = 'php-redis-queue:queues';

  public function __construct(protected \Predis\Client $redis, array $config = [])
  {
    $this->config = array_merge($this->defaultConfig, $config);

    if (isset($this->config['logger']) && !$this->config['logger'] instanceof LoggerInterface) {
      throw new \InvalidArgumentException('Logger must be an instance of Psr\Log\LoggerInterface.');
    }
  }

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
   * @return void
   */
  public function addJobToQueue(string $queueName, Job $job, bool $front = false)
  {
    $method = $front ? 'lpush' : 'rpush';
    $queue = new Queue($queueName);

    $this->redis->$method($queue->pending, $job->id());
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
   * @return int           ID of new job
   * @param boolean $front Push the new job to the front of the queue?
   * @return int           ID of new job
   * @throws \Exception
   */
  public function rerun(int $jobId, bool $front = false)
  {
    $job = new Job($this->redis, $jobId);

    if (!$job->get()) {
      throw new \Exception("Job #$jobId not found. Cannot rerun.");
    }

    if ($job->status() !== 'failed') {
      throw new \Exception("Job #$jobId did not fail. Cannot rerun.");
    }

    $job->withRerun()->save();

    // remove from failed list
    $this->redis->lrem('php-redis-queue:client:'. $job->queue() .':failed', -1, $job->id());

    $this->addJobToQueue($job->queue(), $job, $front);
  }
}
