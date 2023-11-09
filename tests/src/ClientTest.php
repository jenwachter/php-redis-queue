<?php

namespace PhpRedisQueue;

use PhpRedisQueue\models\Job;
use PhpRedisQueue\models\JobGroup;
use PhpRedisQueue\models\Queue;

class ClientTest extends Base
{
  public function setUp(): void
  {
    parent::setUp();
    $this->queue = new Queue('queuename');
  }

  /**
   * The correct metadata is added to each job
   * @return void
   */
  public function testPush()
  {
    $client = new ClientMock($this->predis);

    // default job
    $id = $client->push('queuename');
    $this->assertEquals(1, $id);

    $this->assertEquals('1', $this->predis->lindex($this->queue->pending, 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      status: 'pending'
    ), $this->getJobById(1));

    // custom job
    $id = $client->push('queuename', 'customjob');
    $this->assertEquals(2, $id);

    $this->assertEquals('2', $this->predis->lindex($this->queue->pending, 1));
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

    $id = $client->push('queuename', 'customjob', ['first job']);
    $this->assertEquals(1, $id);

    $id = $client->push('queuename', 'customjob', ['second job']);
    $this->assertEquals(2, $id);

    $this->assertEquals('1', $this->predis->lindex($this->queue->pending, 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      jobName: 'customjob',
      jobData: ['first job'],
      status: 'pending'
    ), $this->getJobById(1));

    $this->assertEquals('2', $this->predis->lindex($this->queue->pending, 1));
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

    $id = $client->pushToFront('queuename', 'customjob', ['first job']);
    $this->assertEquals(1, $id);

    $id = $client->pushToFront('queuename', 'customjob', ['second job']);
    $this->assertEquals(2, $id);

    $this->assertEquals('2', $this->predis->lindex($this->queue->pending, 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      id: 2,
      jobName: 'customjob',
      jobData: ['second job'],
      status: 'pending'
    ), $this->getJobById(2));

    $this->assertEquals('1', $this->predis->lindex($this->queue->pending, 1));
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
    $this->predis->lpush($this->queue->failed, 15);

    // increment the ID to that of the failed job
    $this->predis->incrby('php-redis-queue:meta:id', 15);

    $client->rerun(15);

    // back in the pending queue
    $this->assertEquals('15', $this->predis->lindex($this->queue->pending, 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      status: 'pending',
      id: 15,
      runs: [$originalJob['meta']]
    ), $this->getJobById(15));

    // out of the failed list
    $this->assertEmpty($this->predis->lindex($this->queue->failed, 0));
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
    $id = $client->push('queuename', 'customjob');
    $this->assertEquals(1, $id);

    $id = $client->push('queuename', 'customjob');
    $this->assertEquals(2, $id);

    $id = $client->push('queuename', 'customjob');
    $this->assertEquals(3, $id);

    $id = $client->push('queuename', 'customjob');
    $this->assertEquals(4, $id);

    $this->assertEquals([1, 2, 3, 4], $this->predis->lrange($this->queue->pending, 0, -1));

    $this->assertTrue($client->remove('queuename', 3));

    $this->assertEquals([1, 2, 4], $this->predis->lrange($this->queue->pending, 0, -1));
  }

  public function testRemove__jobNotInQueue(): void
  {
    $client = new ClientMock($this->predis);
    $this->assertFalse($client->remove('queuename', 10));
  }

  public function testJobGroup__autoQueue()
  {
    $client = new ClientMock($this->predis);
    $group = $client->createJobGroup(3);

    // make sure the change is present on the group object
    $this->assertEquals(3, ($group->get())['meta']['total']);

    // make sure it was saved
    $newGroup = (new JobGroup($this->predis, (int) $group->id()))->get();
    $this->assertEquals(3, $newGroup['meta']['total']);

    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');

    // make sure the group auto queued
    $this->assertTrue(($group->get())['meta']['queued']);

    // can't queue it again
    $this->assertFalse($group->queue());

    // can't add more jobs
    $this->assertFalse($group->push('queuename'));
  }

  public function testJobGroup__manualQueue()
  {
    $client = new ClientMock($this->predis);
    $group = $client->createJobGroup();

    // make sure the change is present on the group object
    $this->assertNull(($group->get())['meta']['total']);

    // make sure it was saved
    $newGroup = (new JobGroup($this->predis, (int) $group->id()))->get();
    $this->assertNull($newGroup['meta']['total']);

    $jid = $group->push('queuename');
    $job = (new Job($this->predis, $jid))->get();
    $this->assertEquals(1, $job['meta']['group']);

    $group->push('queuename');
    $job = (new Job($this->predis, $jid))->get();
    $this->assertEquals(1, $job['meta']['group']);

    $group->push('queuename');
    $job = (new Job($this->predis, $jid))->get();
    $this->assertEquals(1, $job['meta']['group']);

    $this->assertTrue($group->queue());

    // make sure the change is present on the group object
    $this->assertEquals(3, ($group->get())['meta']['total']);

    // make sure it was saved
    $newGroup = (new JobGroup($this->predis, (int) $group->id()))->get();
    $this->assertEquals(3, $newGroup['meta']['total']);
  }
}
