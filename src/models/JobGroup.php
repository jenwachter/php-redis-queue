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

  protected function create(array $args = []): void
  {
    parent::create($args);

    $this->data['jobs'] = [];
  }

  protected function createMeta($args = []): array
  {
    $meta = parent::createMeta($args);

    return array_merge($meta, [
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
    if ($this->data['meta']['queued']) {
      return false;
    }

    $job = $this->createJob($queue, $jobName, $jobData, $this->id());

    // add job to the group and save
    $jid = $job->id();
    $this->data['jobs'][] = $jid;
    $this->data['meta']['pending'] = $jid;
    $this->save();

    if ($this->data['meta']['total'] && count($this->data['jobs']) === $this->data['meta']['total']) {
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
    if ($this->data['meta']['queued']) {
      // the group has already been queued; return
      return false;
    }

    if (!$this->data['meta']['total']) {
      // add total, if there isn't one yet
      $this->withMeta('total', count($this->data['jobs']))->save();
    }

    // make sure the group wasn't already triggered
    $this->log('info', 'JobGroup::queue', $this->data);

    foreach ($this->data['jobs'] as $id) {
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
    $value = $this->getMeta($metaKey);
    $value[] = $job->id();
    $this->withMeta($metaKey, $value);

    $successfulJobs = count($this->getMeta('success'));
    $failedJobs = count($this->getMeta('failed'));
    $totalJobs = $this->getMeta('total');

    if ($successfulJobs + $failedJobs === $totalJobs) {
      // all jobs have run
      $this->withMeta('complete', true);
    }

    $this->save();
    return $this;
  }
}
