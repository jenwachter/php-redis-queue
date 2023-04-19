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
   * Save/update the status of a job.
   * @param array $data
   * @param $status
   * @return \Predis\Response\Status
   */
  protected function saveJobStatus(array $data, $status)
  {
    $data['meta']['status'] = $status;
    return $this->saveJob($data);
  }

  /**
   * Save/update the status of a job.
   * @param array $data
   * @param $returnData
   * @return array|\Predis\Response\Status
   */
  protected function removeJobStatus(array $data, $returnData = false)
  {
    unset($data['meta']['status']);
    $result = $this->saveJob($data);

    return $returnData ? $data : $result;
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
