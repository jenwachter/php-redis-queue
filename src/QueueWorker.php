<?php

namespace PhpRedisQueue;

use PhpRedisQueue\managers\QueueManager;
use PhpRedisQueue\models\Job;
use PhpRedisQueue\models\JobGroup;
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

    $this->config = array_merge($this->defaultConfig, $config);

    $this->queue = new Queue($this->redis,$queueName);

    $this->queueManager = new QueueManager($this->redis);
    $this->queueManager->registerQueue($this->queue);
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

    while($id = $this->queue->check($block)) {

      $id = is_array($id) ?
        $id[1] : // blpop
        $id;     // lpop

      $job = new Job($this->redis, (int) $id);
      $job->withData('status', 'processing')->save();

      $this->redis->lpush($this->queue->processing, $id);

      $jobName = $job->get('jobName');

      if (!isset($this->callbacks[$jobName])) {
        $queueName = $this->queue->name;
        $message = "No callback set for `$jobName` job in $queueName queue.";
        $this->log('warning', $message, ['context' => $job->get()]);
        $this->onJobCompletion($job, 'failed', $message);
        continue;
      }

      $this->hook($jobName . '_before', $job->get());

      try {
        $context = call_user_func($this->callbacks[$jobName], $job->get('jobData'));
        $this->onJobCompletion($job, 'success', $context);
      } catch (\Throwable $e) {
        $context = $this->getExceptionData($e);
        $this->log('warning', 'Queue job failed', ['context' => $job->get()]);
        $this->onJobCompletion($job, 'failed', $context);
      }

      // // for testing -- only one job runs at a time
      // die();

      // sleep($this->config['wait']);
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

  protected function onJobCompletion(Job $job, string $status, $context = null)
  {
    $this->queue->onJobCompletion($job);

    $job->withData('status', $status)->save();

    if ($context) {
      $job->withData('context', $context)->save();
    }

    if ($groupId = $job->get('group')) {
      $group = new JobGroup($this->redis, $groupId);
      $group = $group->onJobComplete($job, $status === 'success');

      if ($group->get('complete')) {
        $this->hook('group_after', $group, count($group->get('failed')) === 0);
      }
    }

    $this->hook($job->get('jobName') . '_after', $job->get(), $status === 'success');
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
