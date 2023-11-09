<?php

namespace PhpRedisQueue;

use PhpRedisQueue\managers\QueueManager;
use PhpRedisQueue\models\Job;
use PhpRedisQueue\models\Queue;
use PhpRedisQueue\traits\CanLog;

class QueueWorker
{
  use CanLog;

  protected Queue $queue;

  protected QueueManager $queueManager;

  /**
   * Default configuration that is merged with configuration passed in constructor
   * @var array
   */
  protected array $defaultConfig = [
    /**
     * Prevents PHP from timing out due to blpop()
     * Pass NULL to ignore this setting and use
     * your server's default setting (usually 60)
     * See: https://www.php.net/manual/en/filesystem.configuration.php#ini.default-socket-timeout
     * @var int
     */
    'default_socket_timeout' => -1,

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

    /**
     * Number of seconds to wait between jobs
     * @var int
     */
    'wait' => 1,
  ];

  protected array $config = [];

  /**
   * Array of callbacks used when processing work
   * @var array
   */
  protected array $callbacks = [];

  /**
   * @param \Predis\Client $redis
   * @param string $queueName
   * @param array $config
   */
  public function __construct(protected \Predis\Client $redis, string $queueName, array $config = [])
  {
    if (isset($config['logger'])) {
      Logger::set($config['logger']);
      unset($config['logger']);
    }

    $this->queue = new Queue($queueName);

    $this->queueManager = new QueueManager($this->redis);
    $this->queueManager->registerQueue($this->queue);

    $this->config = array_merge($this->defaultConfig, $config);
  }

   /**
    * Undocumented function
    * @param boolean $block
    * @return void
    */
  public function work(bool $block = true)
  {
    if ($block && $this->config['default_socket_timeout'] !== null) {
      ini_set('default_socket_timeout', $this->config['default_socket_timeout']);
    }

    while ($id = $this->redis->rpop($this->queue->processing)) {
      $this->redis->lpush($this->queue->pending, $id);
    }

    while($id = $this->checkQueue($block)) {

      $id = is_array($id) ?
        $id[1] : // blpop
        $id;     // lpop

      $job = new Job($this->redis, (int) $id);
      $job->withMeta('status', 'processing')->save();

      $this->redis->lpush($this->queue->processing, $id);

      $jobName = $job->jobName();

      if (!isset($this->callbacks[$jobName])) {
        $queueName = $this->queue->name;
        $message = "No callback set for `$jobName` job in $queueName queue.";
        $this->log('warning', $message, ['context' => $job->get()]);
        $this->onJobCompletion($job, 'failed', $message);
        continue;
      }

      $this->hook($jobName . '_before', $job->get());

      try {
        $context = call_user_func($this->callbacks[$jobName], $job->jobData());
        $this->onJobCompletion($job, 'success', $context);
      } catch (\Throwable $e) {
        $context = $this->getExceptionData($e);
        $this->log('warning', 'Queue job failed', ['context' => $job->get()]);
        $this->onJobCompletion($job, 'failed', $context);
      }

      sleep($this->config['wait']);
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

  protected function checkQueue(bool $block = true)
  {
    if ($block) {
      return $this->redis->blpop($this->queue->pending, 0);
    }

    return $this->redis->lpop($this->queue->pending);
  }

  protected function onJobCompletion(Job $job, string $status, $context = null)
  {
    $this->removeFromProcessing($job);
    $this->moveToStatusQueue($job, $status === 'success');

    $job->withMeta('status', $status)->save();

    if ($context) {
      $job->withMeta('context', $context)->save();
    }

    $this->hook($job->jobName() . '_after', $job->get(), $status === 'success');

    // if ($status === 'success' && $job['meta']['original']) {
    //   // remove the old job from the failed queue
    //   $this->redis->lrem($this->failed, -1, json_encode($job['meta']['original']['meta']['id']));
    //
    //   // remove the old job's data
    //   $this->deleteJob($job['meta']['original']['meta']['id']);
    // }
  }

  /**
   * Remove a job from the processing queue
   * @param array $job Job data
   * @return int
   */
  protected function removeFromProcessing(Job $job): int
  {
    return $this->redis->lrem($this->queue->processing, -1, $job->id());
  }

  /**
   * Move a job to a status queue (success or failed)
   * @param Job $job      Job
   * @param bool $success Success (true) or failed (false)
   * @return void
   */
  protected function moveToStatusQueue(Job $job, bool $success)
  {
    $list = $success ? $this->queue->success : $this->queue->failed;
    $this->redis->lpush($list, $job->id());

    $this->trimList($list);
  }

  protected function trimList(string $list)
  {
    $limit = $list === $this->queue->success ? 'successListLimit' : 'failedListLimit';
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
}
