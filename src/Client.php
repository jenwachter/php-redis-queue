<?php

namespace PhpRedisQueue;

use PhpRedisQueue\traits\UsesQueues;
use Psr\Log\LoggerInterface;

class Client
{
  use UsesQueues;

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
   * @param array $original If this is a rerun of a job, this is data associated with the original job
   * @return integer ID of job
   */
  public function push(string $queue, string $jobName = 'default', array $jobData = [], array $original = []): int
  {
    $data = $this->compileJobData($queue, $jobName, $jobData, $original);

    $this->redis->rpush('php-redis-queue:client:' . $queue, json_encode($data));

    $this->saveJobWith($data, 'status', 'pending');

    return $data['meta']['id'];
  }

  /**
   * Pushes a job to the front of the queue
   * @param string $queue   Queue name
   * @param string $jobName Name of the specific job to run, defaults to `default`. Ex: `upload`
   * @param array $jobData  Data associated with this job
   * @param array $original If this is a rerun of a job, this is data associated with the original job
   * @return integer ID of job
   */
  public function pushToFront(string $queue, string $jobName = 'default', array $jobData = [], array $original = []): int
  {
    $data = $this->compileJobData($queue, $jobName, $jobData, $original);

    $this->redis->lpush('php-redis-queue:client:' . $queue, json_encode($data));

    $this->saveJobWith($data, 'status', 'pending');

    return $data['meta']['id'];
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
    $data = $this->getJob($jobId);

    if (!$data) {
      throw new \Exception("Job #$jobId not found. Cannot rerun.");
    }

    $method = $front ? 'pushToFront' : 'push';

    return $this->$method($data['meta']['queue'], $data['meta']['jobName'], $data['job'], $data);
  }

  protected function compileJobData(string $queue, string $jobName = 'default', array $jobData = [], array $original = []): array
  {
    $id = $this->redis->incr('php-redis-queue:meta:id');

    return [
      'meta' => [
        'jobName' => $jobName,
        'queue' => $queue, // used to debug processing and procecssed queues
        'id' => $id,
        'datetime' => $this->getDatetime(),
        'original' => $original,
      ],
      'job' => $jobData
    ];
  }

  protected function getDatetime(): string
  {
    $now = new \DateTime('now', new \DateTimeZone('America/New_York'));
    return $now->format('Y-m-d\TH:i:s');
  }

  protected function log(string $level, string $message, array $data = [])
  {
    if (!isset($this->config['logger'])) {
      return;
    }

    $this->config['logger']->$level($message, $data);
  }
}
