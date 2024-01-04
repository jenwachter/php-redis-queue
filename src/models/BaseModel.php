<?php

namespace PhpRedisQueue\models;

use PhpRedisQueue\traits\CanLog;

class BaseModel
{
  use CanLog;

  /**
   * The name of the iterator that stores the latest assigned ID for new models.
   * When a new model is created, this value is iterated to generate the new ID.
   * @var string
   */
  protected string $iterator;

  /**
   * Cache key group
   * @var string
   */
  protected string $keyGroup;

  /**
   * Model data
   * @var array|null
   */
  protected array|null $data;

  public function __construct(protected \Predis\Client $redis, ...$args)
  {
    if (isset($args[0]) && is_int($args[0])) {
      $this->data = $this->load($args[0]);
    } else {
      $this->data = $this->create($args);
      $this->save();
    }
  }

  /**
   * Create the model data
   * @param array $args
   * @return array
   */
  protected function create(array $args = []): array
  {
    return [
      'id' => $this->createId(),
      'datetime' => $this->getDatetime(),
    ];
  }

  /**
   * Load an existing model's data, using the given ID
   * @param int $id
   * @return array|null
   */
  protected function load(int $id): array|null
  {
    $data = $this->redis->get($this->key($id));
    return $data ? json_decode($data, true) : null;
  }

  /**
   * @param string|null $key
   * @return mixed
   */
  public function get(string|null $key = null): mixed
  {
    if ($key) {
      return $this->data[$key] ?? null;
    }

    return $this->data;
  }

  public function remove()
  {
    return $this->redis->del($this->key());
  }

  /**
   * Get the model's data in JSON
   * @return false|string
   */
  public function json()
  {
    return json_encode($this->get());
  }

  /**
   * Get the model's ID
   * @return mixed
   */
  public function id()
  {
    return $this->get('id');
  }

  /**
   * Add or modify a metadata value
   * @param string $with     Key
   * @param mixed $withValue Value
   * @return $this
   */
  public function withData(string $with, mixed $withValue)
  {
    $this->data[$with] = $withValue;

    return $this;
  }

  public function withoutMeta(string $without)
  {
    if (isset($this->data[$without])) {
      unset($this->data[$without]);
    }
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
   * Set the key to expire.
   * Default TTL is 24 hours
   * @param $ttl
   * @return mixed
   */
  public function expire($ttl = 86400)
  {
    return $this->redis->expire($this->key(), $ttl);
  }

  /**
   * Get the key this job is/will be stored at
   * @param int|null $id
   * @return string
   */
  public function key(int|null $id = null): string
  {
    $id = $id ?? $this->id();
    return "php-redis-queue:$this->keyGroup:$id";
  }

  protected function createId(): int
  {
    return $this->redis->incr("php-redis-queue:meta:$this->iterator");
  }

  protected function getDatetime(): string
  {
    $now = new \DateTime('now', new \DateTimeZone('America/New_York'));
    return $now->format('Y-m-d\TH:i:s');
  }
}
