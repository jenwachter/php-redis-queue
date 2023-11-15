<?php

namespace PhpRedisQueue\models;

class Job extends BaseModel
{
  protected string $iterator = 'id';
  protected string $keyGroup = 'jobs';

  protected function create(array $args = []): array
  {
    $data = parent::create($args);

    return array_merge($data, [
      'jobData' => $args[2],
      'queue' => $args[0],
      'jobName' => $args[1],
      'group' => $args[3] ?? null,
    ]);
  }

  public function withRerun()
  {
    if (!$this->get('runs')) {
      $this->data['runs'] = [];
    }

    // add latest run to the front of the array
    $rerunData = $this->data;
    unset($rerunData['runs']);
    array_unshift($this->data['runs'], $rerunData);

    // update datetime
    $this->withMeta('datetime', $this->getDatetime());

    // update status
    $this->withMeta('status', 'pending');

    // remove context
    $this->withoutMeta('context');

    return $this;
  }
}
