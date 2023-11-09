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

    $meta['queued'] = false;
    $meta['total'] = null;
    $meta['success'] = [];
    $meta['failed'] = [];

    return $meta;
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

    $job = $this->createJob($queue, $jobName, $jobData);

    // add job to the group and save
    $this->data['jobs'][] = $job->id();
    $this->save();

    if ($this->data['meta']['total'] && count($this->data['jobs']) === $this->data['meta']['total']) {
      $this->queue();
    }

    // return the new job's id
    return $job->id();
  }

  /**
   * Add the group's jobs to the queue
   * @return void
   */
  public function queue(): void
  {
    if ($this->data['meta']['queued']) {
      // the group has already been queued; return
      return;
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
  }
}
