<?php

namespace PhpRedisQueue;

use PhpRedisQueue\models\Job;
use PhpRedisQueue\models\JobGroup;
use PhpRedisQueue\models\Queue;

class ClientTest extends Base
{
  protected Queue $queue;

  protected $ttl = [
    'success' => 60 * 60 * 24,
    'failed' => 60 * 60 * 24 * 7,
  ];

  public function setUp(): void
  {
    parent::setUp();
    $this->queue = new Queue($this->predis, 'queuename');
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
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['callback'])
      ->getMock();

    $mock->expects($this->exactly(2))
      ->method('callback')
      ->with([])
      ->will($this->onConsecutiveCalls(
        $this->throwException(new \Exception('Job failed', 123)),
        true // rerun
      ));

    // add callback
    $worker->addCallback('default', [$mock, 'callback']);

    // push job to the queue
    $client->push('queuename');

    // set the worker to work
    $worker->work(false);

    $job = new Job($this->predis, 1);

    // verify it failed
    $this->assertEquals('failed', $job->get('status'));

    // ttl is set
    $this->assertLessThanOrEqual($this->ttl['failed'], $this->predis->ttl($job->key()));

    // rerun job
    $return = $client->rerun(1);

    // back in the pending queue
    $this->assertEquals([1], $this->predis->lrange($this->queue->pending, 0, -1));

    // set the worker to work
    $worker->work(false);

    // verify it succeeded second time
    $this->assertEquals('success', (new Job($this->predis, 1))->get('status'));

    // ttl is set
    $this->assertLessThanOrEqual($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:1'));
  }

  public function testRerun__missingJob(): void
  {
    $client = new ClientMock($this->predis);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Job #10 not found. Cannot rerun.');

    $client->rerun(10);
  }

  public function testRerun__jobPending(): void
  {
    $client = new ClientMock($this->predis);
    $client->push('queuename');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Job #1 has not run yet. Cannot rerun yet.');

    $client->rerun(1);
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

    $this->assertTrue($client->pull(3));

    // job removed from queue
    $this->assertEquals([1, 2, 4], $this->predis->lrange($this->queue->pending, 0, -1));
  }

  public function testRemove__jobNotInQueue(): void
  {
    $client = new ClientMock($this->predis);
    $this->assertFalse($client->pull(10));
  }

  public function testJobGroup__autoQueue__predefinedTotal()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['callback'])
      ->getMock();

    $mock->expects($this->exactly(3))
      ->method('callback')
      ->willReturn(true);

    $worker->addCallback('default', [$mock, 'callback']);

    $group = $client->createJobGroup(3);

    // make sure the change is present on the group object
    $this->assertEquals(3, $group->get('total'));

    // make sure it was saved
    $newGroup = (new JobGroup($this->predis, (int) $group->id()));
    $this->assertEquals(3, $newGroup->get('total'));

    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');

    // make sure the group auto queued
    $this->assertTrue($group->get('queued'));

    // can't queue it again
    $this->assertFalse($group->queue());

    $this->assertEquals([1, 2, 3], $this->predis->lrange('php-redis-queue:client:queuename:pending', 0, -1));

    // can't add more jobs
    $this->assertFalse($group->push('queuename'));

    // set the worker to work
    $worker->work(false);

    $this->assertEquals(0, $this->predis->llen($this->queue->processing));
    $this->assertEquals(3, $this->predis->llen($this->queue->processed));
    $this->assertEquals(['1', '2', '3'], $this->predis->lrange($this->queue->processed, 0, -1));

    // ttls are set
    $this->assertEquals($this->ttl['success'], $this->predis->ttl('php-redis-queue:groups:1'));
    $this->assertEquals($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:1'));
    $this->assertEquals($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:2'));
    $this->assertEquals($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:3'));
  }

  public function testJobGroup__autoQueue__setTotal()
  {
    $client = new ClientMock($this->predis);
    $group = $client->createJobGroup();
    $group->setTotal(3);

    // make sure the change is present on the group object
    $this->assertEquals(3, $group->get('total'));

    // make sure it was saved
    $newGroup = (new JobGroup($this->predis, (int) $group->id()));
    $this->assertEquals(3, $newGroup->get('total'));

    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');

    // make sure the group auto queued
    $this->assertTrue($group->get('queued'));

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
    $this->assertNull($group->get('total'));

    // make sure it was saved
    $newGroup = (new JobGroup($this->predis, (int) $group->id()));
    $this->assertNull($newGroup->get('total'));

    $jid = $group->push('queuename');
    $job = (new Job($this->predis, $jid));
    $this->assertEquals(1, $job->get('group'));

    $group->push('queuename');
    $job = (new Job($this->predis, $jid));
    $this->assertEquals(1, $job->get('group'));

    $group->push('queuename');
    $job = (new Job($this->predis, $jid));
    $this->assertEquals(1, $job->get('group'));

    $this->assertTrue($group->queue());

    // make sure the change is present on the group object
    $this->assertEquals(3, $group->get('total'));

    // make sure it was saved
    $newGroup = (new JobGroup($this->predis, (int) $group->id()));
    $this->assertEquals(3, $newGroup->get('total'));
  }

  public function testJobGroup__removeJobGroupFromQueue()
  {
    $client = new ClientMock($this->predis);
    $group = $client->createJobGroup(3);

    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');

    // in the queue
    $this->assertEquals([1, 2, 3], $this->predis->lrange($this->queue->pending, 0, -1));

    $client->removeJobGroupFromQueue($group->id());

    // removed from queue
    $this->assertEquals([], $this->predis->lrange($this->queue->pending, 0, -1));
  }

  public function testJobGroup__removeJobGroupFromQueue__groupDoesNotExist()
  {
    $client = new ClientMock($this->predis);
    $this->assertFalse($client->removeJobGroupFromQueue(10));
  }

  public function testReruntestJobGroup__rerunFailedJob()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    // create group
    $group = $client->createJobGroup(3);

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['callback', 'group_after'])
      ->getMock();

    $mock->expects($this->exactly(4))
      ->method('callback')
      ->with([])
      ->will($this->onConsecutiveCalls(
        true,
        $this->throwException(new \Exception('Job failed', 123)),
        true,
        true // rerun of job #2
      ));

    $mock->expects($this->exactly(2))
      ->method('group_after')
      ->willReturnCallback(fn (string $key, string $value) => match ($mock->numberOfInvocations()) {
        1 => [$group, false],
        2 => [$group, true],
      });

    // add callbacks
    $worker->addCallback('default', [$mock, 'callback']);
    $worker->addCallback('group_after', [$mock, 'group_after']);

    // push three jobs to the queue
    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');

    // set the worker to work
    $worker->work(false);

    // assert job 2 failed and others succeeded
    $this->assertEquals('success', (new Job($this->predis, 1))->get('status'));
    $this->assertEquals('failed', (new Job($this->predis, 2))->get('status'));
    $this->assertEquals('success', (new Job($this->predis, 3))->get('status'));

    // processing queue is empty (jobs already processed)
    $this->assertEquals(0, $this->predis->llen($this->queue->processing));
    $this->assertEquals(3, $this->predis->llen($this->queue->processed));
    $this->assertEquals(['1', '2', '3'], $this->predis->lrange($this->queue->processed, 0, -1));

    // get updated group
    $updatedGroup = (new JobGroup($this->predis, 1));

    $this->assertEmpty($updatedGroup->get('pending'));
    $this->assertEquals($updatedGroup->get('success'), [1, 3]);
    $this->assertEquals($updatedGroup->get('failed'), [2]);

    // ttls are set
    $this->assertLessThanOrEqual($this->ttl['failed'], $this->predis->ttl('php-redis-queue:jobs:1'));

    // rerun job #2
    $client->rerun(2);

    $updatedJob = (new Job($this->predis, 2));
    $this->assertEquals($updatedJob->get('status'), 'pending');

    $updatedGroup = (new JobGroup($this->predis, 1));
    $this->assertEquals($updatedGroup->get('pending'), [2]);
    $this->assertEquals($updatedGroup->get('success'), [1, 3]);
    $this->assertEmpty($updatedGroup->get('failed'));
    $this->assertFalse($updatedGroup->get('complete'));

    // set the worker to work
    $worker->work(false);

    // get updated group
    $updatedGroup = (new JobGroup($this->predis, 1));

    $this->assertEmpty($updatedGroup->get('pending'));
    $this->assertEquals($updatedGroup->get('success'), [1, 3, 2]);
    $this->assertEmpty($updatedGroup->get('failed'));

    $job = (new Job($this->predis, 2));
    $this->assertEquals('success', $job->get('status'));
    $this->assertEquals(1, count($job->get('runs')));

    $this->assertEquals(4, $this->predis->llen($this->queue->processed));
    $this->assertEquals(['1', '2', '3', '2'], $this->predis->lrange($this->queue->processed, 0, -1));
  }

  public function testReruntestJobGroup__rerunSuccessfulJob()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    // create group
    $group = $client->createJobGroup(3);

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['callback', 'group_after'])
      ->getMock();

    $mock->expects($this->exactly(4))
      ->method('callback')
      ->with([])
      ->will($this->onConsecutiveCalls(
        true,
        true,
        true,
        true // rerun of job #2
      ));

    $mock->expects($this->exactly(2))
      ->method('group_after')
      ->willReturnCallback(fn (string $key, string $value) => match ($mock->numberOfInvocations()) {
        1 => [$group, true],
        2 => [$group, true],
      });

    // add callbacks
    $worker->addCallback('default', [$mock, 'callback']);
    $worker->addCallback('group_after', [$mock, 'group_after']);

    // push three jobs to the queue
    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');

    // set the worker to work
    $worker->work(false);

    // assert all jobs succeeded
    $this->assertEquals('success', (new Job($this->predis, 1))->get('status'));
    $this->assertEquals('success', (new Job($this->predis, 2))->get('status'));
    $this->assertEquals('success', (new Job($this->predis, 3))->get('status'));

    // processing queue is empty (jobs already processed)
    $this->assertEquals(0, $this->predis->llen($this->queue->processing));
    $this->assertEquals(3, $this->predis->llen($this->queue->processed));
    $this->assertEquals(['1', '2', '3'], $this->predis->lrange($this->queue->processed, 0, -1));

    // get updated group
    $updatedGroup = (new JobGroup($this->predis, 1));

    $this->assertEmpty($updatedGroup->get('pending'));
    $this->assertEquals($updatedGroup->get('success'), [1, 2, 3]);
    $this->assertEmpty($updatedGroup->get('failed'));

    // ttls are set
    $this->assertLessThanOrEqual($this->ttl['failed'], $this->predis->ttl('php-redis-queue:jobs:1'));

    $client->rerun(2, false, true);

    $updatedJob = (new Job($this->predis, 2));
    $this->assertEquals($updatedJob->get('status'), 'pending');

    $updatedGroup = (new JobGroup($this->predis, 1));
    $this->assertEquals($updatedGroup->get('pending'), [2]);
    $this->assertEquals($updatedGroup->get('success'), [1, 3]);
    $this->assertEmpty($updatedGroup->get('failed'));
    $this->assertFalse($updatedGroup->get('complete'));

    // set the worker to work
    $worker->work(false);

    // get updated group
    $updatedGroup = (new JobGroup($this->predis, 1));

    $this->assertEmpty($updatedGroup->get('pending'));
    $this->assertEquals($updatedGroup->get('success'), [1, 3, 2]);
    $this->assertEmpty($updatedGroup->get('failed'));

    $job = (new Job($this->predis, 2));
    $this->assertEquals('success', $job->get('status'));
    $this->assertEquals(1, count($job->get('runs')));

    $this->assertEquals(4, $this->predis->llen($this->queue->processed));
    $this->assertEquals(['1', '2', '3', '2'], $this->predis->lrange($this->queue->processed, 0, -1));
  }
}
