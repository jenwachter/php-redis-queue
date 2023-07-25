<?php

namespace PhpRedisQueue\traits;

trait UsesQueues
{
  /**
   * Get a job's data.
   * @param int|string $id
   * @return mixed
   */
  public function getJob(int|string $id)
  {
    $data = $this->redis->get($this->getJobKey($id));
    return $data ? json_decode($data, true) : false;
  }

  /**
   * Save a job's data.
   * @param array $data
   * @return \Predis\Response\Status
   */
  protected function saveJob(array $data)
  {
    return $this->redis->set($this->getJobKey($data['meta']['id']), json_encode($data));
  }

  /**
   * @param array $data      Job data
   * @param string $with     Meta key
   * @param mixed $withValue Meta value
   * @return array New job data
   */
  protected function saveJobWith(array $data, string $with, mixed $withValue): array
  {
    $data['meta'][$with] = $withValue;
    $what = $this->saveJob($data);

    return $data;
  }

  /**
   * Save a job without a piece of meta
   * @param array $data      Job data
   * @param string $without  Meta key to remove
   * @return array New job data
   */
  protected function saveJobWithout(array $data, string ...$without): array
  {
    foreach ($without as $key) {
      if (isset($data['meta'][$key])) {
        unset($data['meta'][$key]);
        $this->saveJob($data);
      }
    }

    return $data;
  }

  /**
   * Delete a job's data.
   * @param int|string $id
   * @return void
   */
  public function deleteJob(int|string $id)
  {
    $this->redis->del($this->getJobKey($id));
  }

  /**
   * @param int|string $id Job ID
   * @return string Key to store job info in
   */
  protected function getJobKey(int|string $id)
  {
    return 'php-redis-queue:jobs:' . $id;
  }
}
