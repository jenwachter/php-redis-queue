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
    return $jid;
  }

  public function setTotal(int $total)
  {
    return $this->withData('total', $total)->save();
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

    $this->withData('queued', true);
    $this->save();

    foreach ($this->get('jobs') as $id) {
      $job = new Job($this->redis, $id);
      $this->addJobToQueue($job);
    }

    return true;
  }

  public function removeFromQueue()
  {
    if ($this->get() === null) {
      // group does not exist (expired or already removed)
      return false;
    }

    // remove jobs from pending and processing lists
    array_map(fn (Job $job) => $this->jobManager->removeJobFromQueue($job), $this->getJobs());

    return true;
  }

  public function onJobComplete(int $jid, bool $success)
  {
    $metaKey = $success ? 'success' : 'failed';

    // add to success/failed
    $value = $this->get($metaKey);
    $value[] = $jid;
    $this->withData($metaKey, $value);

    // remove from pending
    $pending = $this->get('pending');
    $key = array_search($jid, $pending);
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

      if ($successfulJobs !== $totalJobs) {
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
    return array_map(fn ($jobId) => new Job($this->redis, $jobId), $jobs);
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
    // remove from failed list
    $failed = $this->get('failed');
    $key = array_search($jobId, $failed);
    if ($key !== false) {
      unset($failed[$key]);
      $this->withData('failed', array_values($failed));
    } else {
      // job did not fail - remove from success list
      $success = $this->get('success');
      $key = array_search($jobId, $success);
      if ($key !== false) {
        unset($success[$key]);
        $this->withData('success', array_values($success));
      }
    }

    // add to pending
    $pending = $this->get('pending');
    $pending[] = $jobId;
    $this->withData('pending', $pending);

    $this->withData('complete', false);

    return $this->save();
  }
}
