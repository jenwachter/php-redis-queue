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
   * Name of list that contains jobs that ran succesfully
   * @var string
   */
  public string $success;

  /**
   * Name of list that contains jobs that failed
   * @var string
   */
  public string $failed;

  public function __construct(protected \Predis\Client $redis, string $name)
  {
    $this->name = str_replace(':', '-', $name);

    $base = 'php-redis-queue:client:' . $this->name;

    $this->pending = $base . ':pending';
    $this->processing = $base . ':processing';
    $this->success = $base . ':success';
    $this->failed = $base . ':failed';
  }

  public function getJobs(string $which, int $limit = 50)
  {
    $jobs = $this->redis->lrange($this->$which, 0, $limit);

    return array_map(function ($jobId) {
      return json_decode($this->redis->get('php-redis-queue:jobs:'. $jobId));
    }, $jobs);
  }
}
