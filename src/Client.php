<?php

namespace PhpRedisQueue;

use PhpRedisQueue\models\Job;
use Psr\Log\LoggerInterface;

class Client
{
  protected $defaultConfig = [
    'logger' => null, // instance of Psr\Log\LoggerInterface
  ];

  protected $config = [];

  /**
   * @param \Predis\Client $redis
   * @param LoggerInterface|null $logger
   */
  public function __construct(protected \Predis\Client $redis, array $config = [])
  {
    $this->config = array_merge($this->defaultConfig, $config);

    if (isset($this->config['logger']) && !$this->config['logger'] instanceof \Psr\Log\LoggerInterface) {
      throw new \InvalidArgumentException('Logger must be an instance of Psr\Log\LoggerInterface.');
    }
  }

  /**
   * Pushes a job to the end of the queue
   * @param string $queue   Queue name
   * @param string $jobName Name of the specific job to run, defaults to `default`. Ex: `upload`
   * @param array $jobData  Data associated with this job
   * @return integer ID of job
   */
  public function push(string $queue, string $jobName = 'default', array $jobData = []): int
  {
    $job = $this->createJob($queue, $jobName, $jobData);
    $job->withMeta('status', 'pending')->save();

    $this->addToQueue($queue, $job);

    return $job->id();
  }

  /**
   * Pushes a job to the front of the queue
   * @param string $queue   Queue name
   * @param string $jobName Name of the specific job to run, defaults to `default`. Ex: `upload`
   * @param array $jobData  Data associated with this job
   * @return integer ID of job
   */
  public function pushToFront(string $queue, string $jobName = 'default', array $jobData = []): int
  {
    $job = $this->createJob($queue, $jobName, $jobData);
    $job->withMeta('status', 'pending')->save();

    $this->addToQueue($queue, $job, true);

    return $job->id();
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

    $this->addToQueue($job->queue(), $job, $front);
  }

  /**
   * Remove a job from a queue
   * @param string $queue
   * @param int $jobId
   * @return bool
   */
  public function remove(string $queue, int $jobId): bool
  {
    $result = $this->redis->lrem($this->getFullQueueName($queue), -1, $jobId);
    return $result === 1;
  }

  protected function createJob(string $queue, string $jobName = 'default', array $jobData = []): Job
  {
    return new Job($this->redis, $queue, $jobName, $jobData);
  }

  protected function addToQueue(string $queue, Job $job, bool $front = false)
  {
    $method = $front ? 'lpush' : 'rpush';
    $this->redis->$method($this->getFullQueueName($queue), $job->id());
  }

  protected function getFullQueueName(string $queue): string
  {
    return 'php-redis-queue:client:' . $queue;
  }

  protected function log(string $level, string $message, array $data = [])
  {
    if (!isset($this->config['logger'])) {
      return;
    }

    $this->config['logger']->$level($message, $data);
  }
}
