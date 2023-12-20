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
    $this->withMeta('jobs', $jobs);
    $this->save();

    $total = $this->get('total');

    if ($total && count($this->get('jobs')) === $total) {
      $this->queue();
    }

    // return the new job's id
    return $job->id();
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
      $this->withMeta('total', count($this->get('jobs')))->save();
    }

    foreach ($this->get('jobs') as $id) {
      $job = new Job($this->redis, $id);
      $this->addJobToQueue($job);
    }

    $this->withMeta('queued', true)->save();

    return true;
  }

  public function onJobComplete(Job $job, bool $success)
  {
    $metaKey = $success ? 'success' : 'failed';

    // increment success/fail count
    $value = $this->get($metaKey);
    $value[] = $job->id();
    $this->withMeta($metaKey, $value);

    $successfulJobs = count($this->get('success'));
    $failedJobs = count($this->get('failed'));
    $totalJobs = $this->get('total');

    if ($successfulJobs + $failedJobs === $totalJobs) {
      // all jobs have run
      $this->withMeta('complete', true);
    }

    $this->save();
    return $this;
  }
}
