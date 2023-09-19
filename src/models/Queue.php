<?php

namespace PhpRedisQueue\models;

use PhpRedisQueue\QueueManager;

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

  public function __construct(string $name)
  {
    $this->name = str_replace(':', '-', $name);

    $base = 'php-redis-queue:client:' . $this->name;

    $this->pending = $base . ':pending';
    $this->processing = $base . ':processing';
    $this->success = $base . ':success';
    $this->failed = $base . ':failed';
  }
}
