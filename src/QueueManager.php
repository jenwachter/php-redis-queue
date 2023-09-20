<?php

namespace PhpRedisQueue;

use PhpRedisQueue\models\Job;
use PhpRedisQueue\models\Queue;
use PhpRedisQueue\traits\CanLog;
use Psr\Log\LoggerInterface;

class QueueManager
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

  /**
   * Get a list of active queues and how many workers are
   * available per queue.
   * @return array
   */
  public function getList()
  {
    $this->verifyQueues();

    // active queues (with or without pending jobs)
    $queues = [];
    $activeQueues = $this->redis->hgetall($this->allQueues);

    foreach ($activeQueues as $queueName) {
      if (!isset($queues[$queueName])) {
        $queues[$queueName] = [
          'name' => $queueName,
          'count' => 0,
          'pending' => 0,
          'success' => 0,
          'failed' => 0,
        ];
      }

      $queues[$queueName]['count']++;
    }

    // get jobs on all pending, failed, and success queues (active queues or not)
    $queues = $this->addJobsFromQueue('pending', $queues);
    $queues = $this->addJobsFromQueue('failed', $queues);
    $queues = $this->addJobsFromQueue('success', $queues);

    return $queues;
  }

  protected function addJobsFromQueue(string $which, array $queues)
  {
    $foundQueues = $this->redis->keys("php-redis-queue:client:*:$which");

    foreach ($foundQueues as $keyName) {
      preg_match("/php-redis-queue:client:([^:]+):$which/", $keyName, $match);

      if (!isset($match[1])) {
        var_dump("NO MATCH: " . $which);
        continue;
      }

      $queueName = $match[1];

      if (!isset($queues[$queueName])) {
        $queues[$queueName] = [
          'name' => $queueName,
          'count' => 0,
          'pending' => 0,
          'success' => 0,
          'failed' => 0,
        ];
      }

      $queues[$queueName][$which] = $this->redis->llen($keyName);
    }

    return $queues;
  }

  public function getPendingJobs(Queue $queue, int $limit = 50)
  {
    return $this->getJobsInQueue($queue, 'pending', $limit);
  }

  public function getFailedJobs(Queue $queue, int $limit = 50)
  {
    return $this->getJobsInQueue($queue, 'failed', $limit);
  }

  public function getSuccessfulJobs(Queue $queue, int $limit = 50)
  {
    return $this->getJobsInQueue($queue, 'success', $limit);
  }

  protected function getJobsInQueue(Queue $queue, string $which, $limit)
  {
    $jobs = $this->redis->lrange($queue->$which, 0, $limit);

    return array_map(function ($jobId) {
      return json_decode($this->redis->get('php-redis-queue:jobs:'. $jobId));
    }, $jobs);
  }

  public function registerQueue(Queue $queue)
  {
    // register this queue
    $this->redis->hset($this->allQueues, $this->redis->client('id'), $queue->name);

    // verify that registered queues are still running. if they aren't, remove them.
    $this->verifyQueues();
  }

  /**
   * There isn't a reliable way to tell when a blocking queue worker
   * has stopped running (PHP can't tect SIGINT or SIGTERM of a blocking
   * worker), so let's instead verify the existing queues each time
   * a new queue worker is instatntiated.
   * @return void
   */
  protected function verifyQueues()
  {
    // get an array of active client IDs as the keys
    $clients = $this->redis->client('list');
    $clientIds = array_flip(array_map(fn ($client) => $client['id'], $clients));

    // get the hash of registered queues
    $queues = $this->redis->hgetall($this->allQueues);

    // figure out which queues are inactive
    $inactive = array_keys(array_diff_key($queues, $clientIds));

    // if there are inactives, remove them
    if (!empty($inactive)) {
      $this->redis->hdel($this->allQueues, $inactive);
    }
  }
}
