<?php

namespace PhpRedisQueue;

use PhpRedisQueue\traits\UsesQueues;
use Psr\Log\LoggerInterface;

class QueueWorker
{
  use UsesQueues;

  /**
   * Default configuration that is merged with configuration passed in constructor
   * @var array
   */
  protected $defaultConfig = [
    /**
     * @var Psr\Log\LoggerInterface|null
     */
    'logger' => null,

    /**
     * Pass -1 for no queue limit
     * @var int
     */
    'processedQueueLimit' => 5000,

    /**
     * Number of seconds to wait between jobs
     * @var int
     */
    'wait' => 1,
  ];

  protected $config = [];

  /**
   * Name of the list that contains jobs waiting to be worked on
   * @var string
   */
  protected string $pending;

  /**
   * Name of list that contains jobs currently being worked on
   * @var string
   */
  protected string $processing;

  /**
   * Name of list that contains jobs that ran succesfully
   * @var string
   */
  protected string $success;

  /**
   * Name of list that contains jobs that failed
   * @var string
   */
  protected string $failed;

  /**
   * Array of callbacks used when processing work
   * @var array
   */
  protected array $callbacks = [];

  /**
   * @param \Predis\Client $redis
   * @param string $queue
   * @param array $config
   */
  public function __construct(protected \Predis\Client $redis, string $queue, array $config = [])
  {
    $this->queueName = $queue;

    $this->pending = 'php-redis-queue:client:' . $queue;
    $this->processing = $this->pending . ':processing';
    $this->success = $this->pending . ':success';
    $this->failed = $this->pending . ':failed';

    $this->config = array_merge($this->defaultConfig, $config);

    if (isset($this->config['logger']) && !$this->config['logger'] instanceof LoggerInterface) {
      throw new \InvalidArgumentException('Logger must be an instance of Psr\Log\LoggerInterface.');
    }
  }

  public function work()
  {
    while($jsonData = $this->checkQueue()) {

      $data = json_decode($jsonData, true);

      $this->saveJobWith($data, 'status', 'processing');

      $jobName = $data['meta']['jobName'];

      if (!isset($this->callbacks[$jobName])) {
        $message = "No callback set for `$jobName` job in $this->queueName queue.";
        $this->log('warning', $message, ['context' => $data]);
        $this->onJobCompletion($data, 'failed', $message);
        continue;
      }

      $this->hook($jobName . '_before', $data);

      try {
        $context = call_user_func($this->callbacks[$jobName], $data['job']);
        $this->onJobCompletion($data, 'success', $context);
      } catch (\Throwable $e) {
        $context = $this->getExceptionData($e);
        $this->log('warning', 'Queue job failed', ['data' => $data]);
        $this->onJobCompletion($data, 'failed', $context);
      }

      sleep($this->config['wait']);
    }
  }

  protected function checkQueue()
  {
    return $this->redis->blmove($this->pending, $this->processing, 'LEFT', 'LEFT', 0);
  }

  /**
   * Add a callback for a specific job.
   * @param string $name       Name of the callback. There are three types of callbacks that can be added:
   *                             * Callback to run before the job: `jobName_before`
   *                             * Callback to run the job: `jobName`
   *                             * Callback to run after the job completes: `jobName_after`
   * @param callable $callable
   * @return void
   */
  public function addCallback(string $name, callable $callable)
  {
    $this->callbacks[$name] = $callable;
  }

  protected function onJobCompletion(array $job, string $status, $context = null)
  {
    $this->removeFromProcessing($job);
    $this->moveToStatusQueue($job['meta']['id'], $status === 'success');

    $job = $this->saveJobWith($job, 'status', $status);

    if ($context) {
      $job = $this->saveJobWith($job, 'context', $context);
    }

    $this->hook($job['meta']['jobName'] . '_after', $job, $status === 'success');

    if ($status === 'success' && $job['meta']['original']) {
      // remove the old job from the failed queue
      $this->redis->lrem($this->failed, -1, json_encode($job['meta']['original']['meta']['id']));

      // remove the old job's data
      $this->deleteJob($job['meta']['original']['meta']['id']);
    }
  }

  /**
   * Remove a job from the processing queue
   * @param array $job Job data
   * @return int
   */
  protected function removeFromProcessing(array $job): int
  {
    return $this->redis->lrem($this->processing, -1, json_encode($job));
  }

  /**
   * Move a job to a status queue (success or failed)
   * @param int $id       Job ID
   * @param bool $success Success (true) or failed (false)
   * @return void
   */
  protected function moveToStatusQueue(int $id, bool $success)
  {
    $list = $success ? $this->success : $this->failed;
    $this->redis->lpush($list, $id);

    $this->trimList($list);
  }

  protected function trimList(string $list)
  {
    if ($this->config['processedQueueLimit'] === -1) {
      return;
    }

    $length = $this->redis->llen($list);

    if ($length > $this->config['processedQueueLimit']) {

      // get the IDs we're going to remove
      $ids = $this->redis->lrange($list,  $this->config['processedQueueLimit'], $length);

      // trim list
      $this->redis->ltrim($list, 0, $this->config['processedQueueLimit'] - 1);

      // remove jobs
      $ids = array_map(fn ($id) => "php-redis-queue:jobs:$id", $ids);
      $this->redis->del($ids);
    }
  }

  protected function getExceptionData(\Throwable $e)
  {
    return [
      'exception_type' => get_class($e),
      'exception_code' => $e->getCode(),
      'exception_message' => $e->getMessage(),
      // 'exception_file' => $e->getFile(),
      // 'exception_line' => $e->getLine(),
    ];
  }

  /**
   * Call a callback function
   * @param $name         string Name of function
   * @param ...$arguments mixed  Arguments to pass to the callback
   * @return void
   */
  protected function hook($name, ...$arguments): void
  {
    try {
      if (isset($this->callbacks[$name])) {
        call_user_func_array($this->callbacks[$name], $arguments);
      }
    } catch (\Throwable $e) {
      $this->log('warning', $name . '  callback failed', [
        'context' => [
          'exception' => $this->getExceptionData($e),
          'arguments' => $arguments,
        ]
      ]);
    }
  }

  protected function log(string $level, string $message, array $data = [])
  {
    if (!isset($this->config['logger'])) {
      return;
    }

    $this->config['logger']->$level($message, $data);
  }
}
