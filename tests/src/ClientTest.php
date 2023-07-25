<?php

namespace PhpRedisQueue;

use PhpRedisQueue\Client;

class ClientTest extends Base
{
  /**
   * The correct metadata is added to each job
   * @return void
   */
  public function testPush()
  {
    $client = new ClientMock($this->predis);

    // default job
    $client->push('queuename');
    $this->assertEquals($this->getJobData(), $this->predis->lindex('php-redis-queue:client:queuename', 0));
    $this->assertEquals($this->getJobData(encode: false, status: 'pending'), $client->getJob(1));

    // custom job
    $id = $client->push('queuename', 'customjob');
    $this->assertEquals($this->getJobData(id: 2, jobName: 'customjob'), $this->predis->lindex('php-redis-queue:client:queuename', 1));
    $this->assertEquals($this->getJobData(encode: false, status: 'pending', id: 2, jobName: 'customjob'), $client->getJob(2));
  }

  public function testRerun()
  {
    $client = new ClientMock($this->predis);

    // add original job to the system
    $originalJob = $this->getJobData(encode: false, status: 'failed', id: 15, context: 'failure reason');
    $this->predis->set('php-redis-queue:jobs:15', json_encode($originalJob));
    $this->predis->lpush('php-redis-queue:client:queuename:failed', 15);

    // increment the ID to that of the failed job
    $this->predis->incrby('php-redis-queue:meta:id', 15);

    $newId = $client->rerun(15);

    $this->assertEquals($this->getJobData(id: $newId, originalJobData: $originalJob), $this->predis->lindex('php-redis-queue:client:queuename', 0));
    $this->assertEquals($this->getJobData(encode: false, status: 'pending', id: $newId, originalJobData: $originalJob), $client->getJob($newId));
  }

  public function testRerun__missingJob(): void
  {
    $client = new ClientMock($this->predis);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Job #10 not found. Cannot rerun.');

    $client->rerun(10);
  }
}
