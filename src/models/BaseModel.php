<?php

namespace PhpRedisQueue\models;

use PhpRedisQueue\traits\CanLog;

class BaseModel
{
  use CanLog;

  protected string $modelIdentifier;

  protected array|null $data;

  public function __construct(protected \Predis\Client $redis, ...$args)
  {
    if (isset($args[0]) && is_int($args[0])) {
      $this->load($args[0]);
    } else {
      $this->create($args);
      $this->save();
    }
  }

  /**
   * Create the model data
   * @param array $args
   * @return void
   */
  protected function create(array $args = []): void
  {
    $this->data = ['meta' => $this->createMeta($args)];
  }

  /**
   * Create the model's metadata
   * @param array $args
   * @return array
   */
  protected function createMeta(array $args = []): array
  {
    return [
      'id' => $this->createId(),
      'datetime' => $this->getDatetime(),
    ];
  }

  /**
   * Load an existing model's data, using the given ID
   * @param int $id
   * @return void
   */
  protected function load(int $id): void
  {
    $data = $this->redis->get($this->key($id));
    $this->data = $data ? json_decode($data, true) : null;
  }

  /**
   * Get the model's data
   * @return mixed
   */
  public function get()
  {
    return $this->data;
  }

  /**
   * Get the model's data in JSON
   * @return false|string
   */
  public function json()
  {
    return json_encode($this->data);
  }

  /**
   * Get the model's ID
   * @return mixed
   */
  public function id()
  {
    return $this->data['meta']['id'];
  }

  /**
   * Add or modify a metadata value
   * @param string $with     Key
   * @param mixed $withValue Value
   * @return $this
   */
  public function withMeta(string $with, mixed $withValue)
  {
    $this->data['meta'][$with] = $withValue;

    return $this;
  }

  /**
   * Save the model
   * @return \Predis\Response\Status
   */
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
    $id = $id ?? $this->id();
    return 'php-redis-queue:jobs:' . $id;
  }

  protected function createId(): int
  {
    return $this->redis->incr("php-redis-queue:meta:$this->modelIdentifier");
  }

  protected function getDatetime(): string
  {
    $now = new \DateTime('now', new \DateTimeZone('America/New_York'));
    return $now->format('Y-m-d\TH:i:s');
  }
}
