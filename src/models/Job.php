<?php

namespace PhpRedisQueue\models;

class Job extends BaseModel
{
  protected string $iterator = 'id';
  protected string $keyGroup = 'jobs';

  protected function create(array $args = []): void
  {
    parent::create($args);

    $this->data['job'] = $args[2];
  }

  protected function createMeta($args = []): array
  {
    $meta = parent::createMeta($args);

    $meta['queue'] = $args[0];
    $meta['jobName'] = $args[1];

    return $meta;
  }

  public function queue()
  {
    return $this->getMeta('queue');
  }

  public function jobName()
  {
    return $this->getMeta('jobName');
  }

  public function status()
  {
    return $this->getMeta('status');
  }

  public function jobData()
  {
    $data = $this->get();
    return $data['job'];
  }

  public function withRerun()
  {
    if (!isset($this->data['runs'])) {
      $this->data['runs'] = [];
    }

    // add latest run to the front of the array
    array_unshift($this->data['runs'], $this->data['meta']);

    // update datetime
    $this->data['meta']['datetime'] = $this->getDatetime();

    // update status
    $this->data['meta']['status'] = 'pending';

    // remove context
    unset($this->data['meta']['context']);

    return $this;
  }
}
