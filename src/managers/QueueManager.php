<?php

namespace PhpRedisQueue\managers;

use PhpRedisQueue\models\Queue;

class QueueManager extends BaseManager
{
  /**
   * Redis key that holds the hash that keeps track
   * of active queues.
   * @var string
   */
  protected string $allQueues = 'php-redis-queue:queues';

  /**
   * Get queues with an active worker
   * @return array
   */
  public function getActiveQueues()
  {
    return $this->redis->hgetall($this->allQueues);
  }

  /**
   * Get a list of all (active or not) queues, how many workers are available
   * per queue, and number of pending and processed jobs.
   * @return array
   */
  public function getList()
  {
    $this->verifyQueues();

    // active queues (with or without pending jobs)
    $queues = [];
    $activeQueues = $this->getActiveQueues();

    foreach ($activeQueues as $queueName) {
      if (!isset($queues[$queueName])) {
        $queues[$queueName] = [
          'name' => $queueName,
          'count' => 0,
          'pending' => 0,
          'processed' => 0,
        ];
      }

      $queues[$queueName]['count']++;
    }

    // get jobs on all pending queues (active queues or not)
    $queues = $this->addJobsFromQueue($queues);

    return $queues;
  }

  protected function addJobsFromQueue(array $queues)
  {
    foreach (['pending', 'processed'] as $which) {

      $foundQueues = $this->redis->keys("php-redis-queue:client:*:$which");

      foreach ($foundQueues as $keyName) {
        preg_match("/php-redis-queue:client:([^:]+):$which/", $keyName, $match);

        if (!isset($match[1])) {
          continue;
        }

        $queueName = $match[1];

        if (!isset($queues[$queueName])) {
          $queues[$queueName] = [
            'name' => $queueName,
            'count' => 0,
            'pending' => 0,
            'processed' => 0,
          ];
        }

        $queues[$queueName][$which] = $which === 'processed' ? $this->redis->get($keyName) : $this->redis->llen($keyName);
      }
    }


    return $queues;
  }

  /**
   * Get a job by ID
   * @return array
   */
  public function getQueue(string $name)
  {
    return (new Queue($this->redis, $name));
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
   * has stopped running (PHP can't detect SIGINT or SIGTERM of a blocking
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
