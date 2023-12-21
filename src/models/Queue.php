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

  /**
   * Default configuration that is merged with configuration passed in constructor
   * @var array
   */
  protected array $defaultConfig = [
    /**
     * Length limit for failed job list
     * @var int
     */
    'failedListLimit' => 500,

    /**
     * Length limit for success job list
     * @var int
     */
    'successListLimit' => 500,
  ];

  protected array $config = [];

  public function __construct(protected \Predis\Client $redis, string $name, array $config = [])
  {
    $this->name = str_replace(':', '-', $name);

    $this->config = array_merge($this->defaultConfig, $config);

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
   * Move a job to a status queue (success or failed)
   * @param Job $job      Job
   * @param bool $success Success (true) or failed (false)
   * @return void
   */
  public function moveToStatusQueue(Job $job, bool $success)
  {
    $list = $success ? $this->success : $this->failed;
    $this->redis->lpush($list, $job->id());

    $this->trimList($list);
  }

  public function trimList(string $list)
  {
    $limit = $list === $this->success ? 'successListLimit' : 'failedListLimit';
    $limit = $this->config[$limit];

    if ($limit === -1) {
      return;
    }

    $length = $this->redis->llen($list);

    if ($length > $limit) {

      // get the IDs we're going to remove
      $ids = $this->redis->lrange($list,  $limit, $length);

      // trim list
      $this->redis->ltrim($list, 0, $limit - 1);

      // remove jobs
      $ids = array_map(fn ($id) => "php-redis-queue:jobs:$id", $ids);
      $this->redis->del($ids);
    }
  }
}
