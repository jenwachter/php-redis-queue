<?php

namespace PhpRedisQueue;

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
    $this->assertEquals('1', $this->predis->lindex('php-redis-queue:client:queuename', 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      status: 'pending'
    ), $this->getJobById(1));

    // custom job
    $id = $client->push('queuename', 'customjob');
    $this->assertEquals('2', $this->predis->lindex('php-redis-queue:client:queuename', 1));
    $this->assertEquals($this->getJobData(
      encode: false,
      status: 'pending',
      id: 2,
      jobName: 'customjob'
    ), $this->getJobById(2));
  }

  /**
   * Ensure new jobs are pushed to the back of the queue
   * @return void
   */
  public function testPush__newJobsGoToBack()
  {
    $client = new ClientMock($this->predis);

    $client->push('queuename', 'customjob', ['first job']);
    $client->push('queuename', 'customjob', ['second job']);

    $this->assertEquals('1', $this->predis->lindex('php-redis-queue:client:queuename', 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      jobName: 'customjob',
      jobData: ['first job'],
      status: 'pending'
    ), $this->getJobById(1));

    $this->assertEquals('2', $this->predis->lindex('php-redis-queue:client:queuename', 1));
    $this->assertEquals($this->getJobData(
      encode: false,
      id: 2,
      jobName: 'customjob',
      jobData: ['second job'],
      status: 'pending'
    ), $this->getJobById(2));
  }

  public function testPushToFront()
  {
    $client = new ClientMock($this->predis);

    $client->pushToFront('queuename', 'customjob', ['first job']);
    $client->pushToFront('queuename', 'customjob', ['second job']);

    $this->assertEquals('2', $this->predis->lindex('php-redis-queue:client:queuename', 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      id: 2,
      jobName: 'customjob',
      jobData: ['second job'],
      status: 'pending'
    ), $this->getJobById(2));

    $this->assertEquals('1', $this->predis->lindex('php-redis-queue:client:queuename', 1));
    $this->assertEquals($this->getJobData(
      encode: false,
      jobName: 'customjob',
      jobData: ['first job'],
      status: 'pending'
    ), $this->getJobById(1));
  }

  public function testRerun()
  {
    $client = new ClientMock($this->predis);

    // add original job to the system
    $originalJob = $this->getJobData(
      encode: false,
      status: 'failed',
      id: 15,
      context: 'failure reason'
    );

    $this->predis->set('php-redis-queue:jobs:15', json_encode($originalJob));
    $this->predis->lpush('php-redis-queue:client:queuename:failed', 15);

    // increment the ID to that of the failed job
    $this->predis->incrby('php-redis-queue:meta:id', 15);

    $client->rerun(15);

    // back in the pending queue
    $this->assertEquals('15', $this->predis->lindex('php-redis-queue:client:queuename', 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      status: 'pending',
      id: 15,
      runs: [$originalJob['meta']]
    ), $this->getJobById(15));

    // out of the failed list
    $this->assertEmpty($this->predis->lindex('php-redis-queue:client:queuename:failed', 0));
  }

  public function testRerun__missingJob(): void
  {
    $client = new ClientMock($this->predis);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Job #10 not found. Cannot rerun.');

    $client->rerun(10);
  }

  public function testRemove(): void
  {
    $client = new ClientMock($this->predis);

    // push some jobs to the queue
    $client->push('queuename', 'customjob');
    $client->push('queuename', 'customjob');
    $client->push('queuename', 'customjob');
    $client->push('queuename', 'customjob');

    $this->assertEquals([1, 2, 3, 4], $this->predis->lrange('php-redis-queue:client:queuename', 0, -1));

    $this->assertTrue($client->remove('queuename', 3));

    $this->assertEquals([1, 2, 4], $this->predis->lrange('php-redis-queue:client:queuename', 0, -1));
  }

  public function testRemove__jobNotInQueue(): void
  {
    $client = new ClientMock($this->predis);
    $this->assertFalse($client->remove('queuename', 10));
  }
}
