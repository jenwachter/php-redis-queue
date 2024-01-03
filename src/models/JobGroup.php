<?php

namespace PhpRedisQueue\models;

use PhpRedisQueue\traits\CanCreateJobs;

class JobGroup extends BaseModel
{
  use CanCreateJobs;

  protected string $iterator = 'gid';
  protected string $keyGroup = 'groups';

  public function __construct(protected \Predis\Client $redis, ...$args)
  {
    $this->setJobManager($redis);

    parent::__construct($redis, ...$args);
  }

  protected function create(array $args = []): array
  {
    $data = parent::create($args);

    return array_merge($data, [
      'jobs' => [],
      'queued' => false,
      'complete' => false,
      'total' => null,
      'pending' => [],
      'success' => [],
      'failed' => [],
      'userSupplied' => [],
    ]);
  }

  /**
   * Add a job to this group
   * @param string $queue   Queue name
   * @param string $jobName Name of the specific job to run, defaults to `default`. Ex: `upload`
   * @param array $jobData  Data associated with this job
   * @return int|false ID of job or FALSE on failure
   */
  public function push(string $queue, string $jobName = 'default', array $jobData = []): int|false
  {
    if ($this->get('queued')) {
      return false;
    }

    $job = $this->createJob($queue, $jobName, $jobData, $this->id());

    // add job to the group and save
    $jid = $job->id();

    $jobs = $this->get('jobs');
    $jobs[] = $jid;
    $this->withData('jobs', $jobs);

    $pending = $this->get('pending');
    $pending[] = $jid;
    $this->withData('pending', $pending);

    $this->save();

    $total = $this->get('total');

    if ($total && count($this->get('jobs')) === $total) {
      $this->queue();
    }

    // return the new job's id
    return $job->id();
  }

  public function setTotal(int $total)
  {
    $this->withData('total', $total);
    return $this->save();
  }

  /**
   * Add the group's jobs to the queue
   * @return bool
   */
  public function queue(): bool
  {
    if ($this->get('queued')) {
      // the group has already been queued; return
      return false;
    }

    if (!$this->get('total')) {
      // add total, if there isn't one yet
      $this->withData('total', count($this->get('jobs')));
    }

    foreach ($this->get('jobs') as $id) {
      $job = new Job($this->redis, $id);
      $this->addJobToQueue($job);
    }

    $this->withData('queued', true);

    $this->save();

    return true;
  }

  public function onJobComplete(Job $job, bool $success)
  {
    $metaKey = $success ? 'success' : 'failed';

    // add to success/failed
    $value = $this->get($metaKey);
    $value[] = $job->id();
    $this->withData($metaKey, $value);

    // remove from pending
    $pending = $this->get('pending');
    $key = array_search($job->id(), $pending);
    if ($key !== false) {
      unset($pending[$key]);
    }
    $this->withData('pending', $pending);

    $successfulJobs = count($this->get('success'));
    $failedJobs = count($this->get('failed'));
    $totalJobs = $this->get('total');

    if ($successfulJobs + $failedJobs === $totalJobs) {
      // all jobs have run
      $this->withData('complete', true);

      // expire in a week if failed
      $success = $successfulJobs === $totalJobs;
      $this->resolve($success);

      if (!$success) {
        $this->log('warning', 'Job group failed', [
          'context' => [
            'group' => $this->get()
          ]
        ]);
      }
    }

    $this->save();

    return $this;
  }

  public function getJobs()
  {
    $jobs = $this->get('jobs');

    return array_map(function ($jobId) {
      return json_decode($this->redis->get('php-redis-queue:jobs:'. $jobId));
    }, $jobs);
  }

  /**
   * @param string|null $key
   * @return mixed
   */
  public function getUserData(string|null $key = null): mixed
  {
    if ($key) {
      return $this->data['userSupplied'][$key] ?? null;
    }

    return $this->data['userSupplied'];
  }

  public function rerunJob(int $jobId)
  {
    // remove from failed
    $failed = $this->get('failed');
    $key = array_search($jobId, $failed);
    if ($key !== false) {
      unset($failed[$key]);
    }
    $this->withData('failed', $failed);

    // add to pending
    $pending = $this->get('pending');
    $pending[] = $jobId;
    $this->withData('pending', $pending);

    return $this->save();
  }

  /**
   * Resolve the group, which sets the group and all
   * associated job data to expire in 24 hours if the
   * group was successful and one week if failed.
   * @return void
   */
  public function resolve(bool $success)
  {
    $ttl = $success ?
      60 * 60 * 24 :
      60 * 60 * 24 * 7;

    // set expires on the group data
    $this->expire($ttl);

    foreach ($this->get('jobs') as $id) {
      $job = new Job($this->redis, $id);
      $job->expire($ttl);
    }
  }
}
