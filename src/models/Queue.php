<?php

namespace PhpRedisQueue\models;

class Queue
{
  /**
   * Queue name
   * @var string
   */
  public string $name;

  /**
   * Name of the list that contains jobs waiting to be worked on
   * @var string
   */
  public string $pending;

  /**
   * Name of list that contains jobs currently being worked on
   * @var string
   */
  public string $processing;

  /**
   * Name of list that contains processed jobs
   * @var string
   */
  public string $processed;

  public function __construct(protected \Predis\Client $redis, string $name)
  {
    $this->name = str_replace(':', '-', $name);

    $base = 'php-redis-queue:client:' . $this->name;

    $this->pending = $base . ':pending';
    $this->processing = $base . ':processing';
    $this->processed = $base . ':processed';

    // mitigate issues migrating from v1.5 to v1.6
    if ($redis->exists($this->processed) && (string) $redis->type($this->processed) !== 'list') {
      $redis->del($this->processed);
    }
  }

  public function getJobs(string $which, int $limit = 50)
  {
    $jobs = $this->redis->lrange($this->$which, 0, $limit);

    return array_map(function ($jobId) {
      return json_decode($this->redis->get('php-redis-queue:jobs:'. $jobId));
    }, $jobs);
  }

  /**
   * Check the queue for jobs
   * @param bool $block       TRUE to use blpop(); FALSE to use lpop()
   * @return array|string|null
   */
  public function check(bool $block = true)
  {
    if ($block) {
      return $this->redis->blpop($this->pending, 0);
    }

    return $this->redis->lpop($this->pending);
  }

  /**
   * Remove a job from the processing queue
   * @param array $job Job data
   * @return int
   */
  public function removeFromProcessing(Job $job): int
  {
    return $this->redis->lrem($this->processing, -1, $job->id());
  }

  /**
   * Adds the ID of the job to the processed lists
   * @return int
   */
  public function onJobCompletion(Job $job)
  {
    $this->removeFromProcessing($job);
    return $this->redis->rpush($this->processed, $job->id());
  }
}
