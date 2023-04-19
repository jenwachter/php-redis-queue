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
     * Array of job names that the client will take care of cleaning
     * up the job data using Client::deleteJob()
     * @var array
     */
    'manualJobCleanup' => [],

    /**
     * Pass -1 for no queue limit
     * @var int
     */
    'processedQueueLimit' => 5000,
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
    while($jsonData = $this->redis->blmove($this->pending, $this->processing, 'LEFT', 'LEFT', 0)) {

      $data = json_decode($jsonData, true);

      $jobName = $data['meta']['jobName'];

      if (!isset($this->callbacks[$jobName])) {
        $this->log('warning', 'No callback set for job', ['context' => $data]);

        $this->moveToStatusQueue($data, false);
        $this->saveJobStatus($data, 'failed');

        continue;
      }

      // update status
      $this->saveJobStatus($data, 'processing');

      // call the before callback
      $this->hook($jobName . '_before', $data);

      // perform the work
      try {
        $context = call_user_func($this->callbacks[$jobName], $data['job']);
        $success = true;
      } catch (\Throwable $e) {
        $context = $this->getExceptionData($e);
        $success = false;
        $this->log('warning', 'Queue job failed', ['data' => $data]);
      }

      // remove job from processing queue
      $this->redis->lrem($this->processing, 1, $jsonData);

      // add context
      $data['context'] = $context;

      $this->moveToStatusQueue($data, $success);

      $this->saveJobStatus($data, $success ? 'success' : 'failed');

      if ($success && $data['meta']['original']) {
        // recovered job
        $removed = $this->redis->lrem($this->failed, 1, json_encode($data['meta']['original']));
        $this->deleteJob($data['meta']['original']['meta']['id']);
      }

      // call the after callback
      $this->hook($jobName . '_after', $data, $success);

      if ($success && !in_array($jobName, $this->config['manualJobCleanup'])) {
        $this->deleteJob($data['meta']['id']);
      }

      // wait a second before checking the queue again
      sleep(1);
    }
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

  protected function moveToStatusQueue(array $data, bool $success)
  {
    $jsonData = json_encode($data);

    $list = $success ? $this->success : $this->failed;
    $this->redis->lpush($list, $jsonData);

    // trim processed lists to keep them tidy
    if ($this->config['processedQueueLimit'] > -1) {
      $this->redis->ltrim($list, 0, $this->config['processedQueueLimit']);
    }
  }

  protected function getExceptionData(\Throwable $e)
  {
    return [
      'exception_type' => get_class($e),
      'exception_code' => $e->getCode(),
      'exception_message' => $e->getMessage(),
      'exception_file' => $e->getFile(),
      'exception_line' => $e->getLine(),
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
