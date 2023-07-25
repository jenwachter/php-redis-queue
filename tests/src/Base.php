<?php

namespace PhpRedisQueue;

abstract class Base extends \PHPUnit\Framework\TestCase
{
  public function setUp(): void
  {
    parent::setUp();

    $this->predis = new \Predis\Client();
    $keys = $this->predis->keys('php-redis-queue:*');

    if ($keys) {
      $this->predis->del($keys);
    }
  }

  public function tearDown(): void
  {
    parent::tearDown();

    $keys = $this->predis->keys('php-redis-queue:*');

    if ($keys) {
      $this->predis->del($keys);
    }
  }

  protected function getJobData(
    $id = 1,
    $jobName = 'default',
    $jobData = [],
    $originalJobData = [],
    $context = null,
    $status = null,
    $encode = true
  )
  {
    $job = [
      'meta' => [
        'jobName' => $jobName,
        'queue' => 'queuename',
        'id' => $id,
        'datetime' => '2023-01-01T10:00:00',
        'original' => $originalJobData,
      ],
      'job' => $jobData,
    ];

    if ($status) {
      $job['meta']['status'] = $status;
    }

    if ($context) {
      $job['meta']['context'] = $context;
    }

    if ($encode) {
      return json_encode($job);
    }

    return $job;
  }
}
