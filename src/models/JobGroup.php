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

    // have the jobs been added to their queues
    $meta['queued'] = false;

    // have all jobs in this completed?
    $meta['complete'] = false;

    // total number of jobs in this group
    $meta['total'] = null;

    // keeps track of number of successful and failed jobs
    $meta['success'] = 0;
    $meta['failed'] = 0;

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

    $job = $this->createJob($queue, $jobName, $jobData, $this->id());

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
    $newValue = $this->getMeta($metaKey) + 1;
    $this->withMeta($metaKey, $newValue);

    // see if this group is complete
    if (($this->getMeta('success') + $this->getMeta('failed')) < $this->getMeta('total')) {
      $this->log('info', 'group still has pending jobs...', [$this->data]);
      $this->save();
      return;
    }

    $this->log('info', 'Group complete', [$this->data]);
    $this->withMeta('complete', true)->save();
  }
}
