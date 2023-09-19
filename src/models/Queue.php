<?php

namespace PhpRedisQueue\models;

use PhpRedisQueue\QueueManager;

class Queue
{
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

  public function __construct(public string $name)
  {
    $this->pending = 'php-redis-queue:client:' . $this->name;

    $this->processing = $this->pending . ':processing';
    $this->success = $this->pending . ':success';
    $this->failed = $this->pending . ':failed';
  }
}
