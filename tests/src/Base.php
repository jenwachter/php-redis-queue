<?php

namespace PhpRedisQueue;

use PhpRedisQueue\models\Job;

abstract class Base extends \PHPUnit\Framework\TestCase
{
  /**
   * Predis
   * @var \Predis\Client
   */
  protected $predis;

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

  protected function getJobById($id)
  {
    $job = new Job(new \Predis\Client(),$id);
    return $job->get();
  }

  protected function getJobData(
    $id = 1,
    $jobName = 'default',
    $jobData = [],
    $runs = [],
    $context = null,
    $status = 'pending',
    $encode = true
  )
  {
    $data = [
      'id' => $id,
      'datetime' => '2023-01-01T10:00:00',
      'jobData' => $jobData,
      'queue' => 'queuename',
      'jobName' => $jobName,
      'group' => null,
      'status' => $status,
    ];

    if ($context) {
      $data['context'] = $context;
    }

    if ($runs) {
      $data['runs'] = $runs;
    }

    return $encode ? json_encode($data) : $data;
  }

  protected function getDatetime(): string
  {
    $now = new \DateTime('now', new \DateTimeZone('America/New_York'));
    return $now->format('Y-m-d\TH:i:s');
  }
}
