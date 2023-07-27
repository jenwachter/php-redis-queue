<?php

namespace PhpRedisQueue\models;

class Job
{
  public function __construct(protected \Predis\Client $redis, ...$args)
  {
    if (is_int($args[0])) {
      // get job based on the ID
      $data = $this->redis->get($this->key($args[0]));
      $this->data = $data ? json_decode($data, true) : null;

    } else {
      $this->data = [
        'meta' => [
          'id' => $this->createId(),
          'datetime' => $this->getDatetime(),
          'queue' => $args[0],
          'jobName' => $args[1],
        ],
        'job' => $args[2]
      ];

      $this->save();
    }
  }

  public function id()
  {
    return $this->data['meta']['id'];
  }

  public function queue()
  {
    return $this->data['meta']['queue'];
  }

  public function jobName()
  {
    return $this->data['meta']['jobName'];
  }

  public function status()
  {
    return $this->data['meta']['status'];
  }

  public function jobData()
  {
    return $this->data['job'];
  }

  public function get()
  {
    return $this->data;
  }

  public function json()
  {
    return json_encode($this->data);
  }

  public function withMeta(string $with, mixed $withValue)
  {
    $this->data['meta'][$with] = $withValue;

    return $this;
  }

  public function withRerun()
  {
    if (!isset($this->data['runs'])) {
      $this->data['runs'] = [];
    }

    $this->data['runs'][] = $this->data['meta'];

    // update datetime
    $this->data['meta']['datetime'] = $this->getDatetime();

    // update status
    $this->data['meta']['status'] = 'pending';

    // remove context
    unset($this->data['meta']['context']);

    return $this;
  }

  public function save()
  {
    return $this->redis->set($this->key(), $this->json());
  }

  /**
   * Get the key this job is/will be stored at
   * @param int|null $id
   * @return string
   */
  protected function key(int|null $id = null): string
  {
    $id = $id === null ? $this->id() : $id;
    return 'php-redis-queue:jobs:' . $id;
  }

  protected function createId(): int
  {
    return $this->redis->incr('php-redis-queue:meta:id');
  }

  protected function getDatetime(): string
  {
    $now = new \DateTime('now', new \DateTimeZone('America/New_York'));
    return $now->format('Y-m-d\TH:i:s');
  }
}
