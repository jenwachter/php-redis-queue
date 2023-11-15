<?php

namespace PhpRedisQueue;

use PhpRedisQueue\models\Job;
use PhpRedisQueue\models\JobGroup;
use PhpRedisQueue\models\Queue;

class QueueWorkerTest extends Base
{
  public function setUp(): void
  {
    parent::setUp();
    $this->queue = new Queue('queuename');
  }

  public function testWork__noCallback()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    // put something in the queue
    $id = $client->push('queuename');
    $this->assertEquals(1, $id);

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen($this->queue->processing));

    // job is in the failed queue
    $this->assertEquals(1, $this->predis->lindex($this->queue->failed, 0));

    // success queue is empty
    $this->assertEquals(0, $this->predis->llen($this->queue->success));

    // job data is saved
    $this->assertEquals($this->getJobData(context: 'No callback set for `default` job in queuename queue.', status: 'failed'), $this->predis->get('php-redis-queue:jobs:1'));
  }

  /**
   * This test ensures:
   *  - callbacks are triggered once per job
   *  - jobs end up in the success queue
   *  - job data is saved
   * @return void
   */
  public function testWork__callbacksFire()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['callback_before', 'callback', 'callback_after'])
      ->getMock();

    $mock->expects($this->exactly(2))
      ->method('callback_before');

    // callbacks should all fire twice (once per job)
    $mock->expects($this->exactly(2))
      ->method('callback');

    $mock->expects($this->exactly(2))
      ->method('callback_after');

    // add callbacks
    $worker->addCallback('default_before', [$mock, 'callback_before']);
    $worker->addCallback('default', [$mock, 'callback']);
    $worker->addCallback('default_after', [$mock, 'callback_after']);

    // put stuff in the queue
    $id = $client->push('queuename');
    $this->assertEquals(1, $id);

    $id = $client->push('queuename');
    $this->assertEquals(2, $id);

    // jobs are in the pending queue
    $this->assertEquals('1', $this->predis->lindex($this->queue->pending, 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      id: 1,
      status: 'pending'
    ), $this->getJobById(1));

    $this->assertEquals('2', $this->predis->lindex($this->queue->pending, 1));
    $this->assertEquals($this->getJobData(
      encode: false,
      id: 2,
      status: 'pending'
    ), $this->getJobById(2));

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen($this->queue->processing));

    // jobs are in the success queue (newest added are first)
    $this->assertEquals(2, $this->predis->lindex($this->queue->success, 0));
    $this->assertEquals(1, $this->predis->lindex($this->queue->success, 1));

    // failed queue is empty
    $this->assertEquals(0, $this->predis->llen($this->queue->failed));

    // job data is saved
    $this->assertEquals($this->getJobData(status: 'success'), $this->predis->get('php-redis-queue:jobs:1'));

    $this->assertEquals($this->getJobData(id: 2, status: 'success'), $this->predis->get('php-redis-queue:jobs:2'));
  }

  /**
   * This test ensures:
   *  - callback arguments are what we expect
   *  - custom callback names (jobname) work
   *  - new job is added to the pending queue
   *  - successful job is added to the success queue and is not in the
   *    failed or processing queue
   *  - job data is removed from the system (because it was successful)
   * @return void
   */
  public function testWork__successulJob()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['callback', 'callback_before', 'callback_after'])
      ->getMock();

    $mock->expects($this->exactly(1))
      ->method('callback_before')
      ->with($this->getJobData(
        jobName: 'jobname',
        jobData: ['jobdata' => 'some data'],
        status: 'processing',
        encode: false
      ));

    $mock->expects($this->exactly(1))
      ->method('callback')
      ->with(['jobdata' => 'some data'])
      ->willReturn('something returned from callback');

    $mock->expects($this->exactly(1))
      ->method('callback_after')
      ->with($this->getJobData(
        jobName: 'jobname',
        jobData: ['jobdata' => 'some data'],
        status: 'success',
        context: 'something returned from callback',
        encode: false
      ), true);

    // add callbacks
    $worker->addCallback('jobname_before', [$mock, 'callback_before']);
    $worker->addCallback('jobname', [$mock, 'callback']);
    $worker->addCallback('jobname_after', [$mock, 'callback_after']);

    // put something in the queue
    $id = $client->push('queuename', 'jobname', ['jobdata' => 'some data']);
    $this->assertEquals(1, $id);

    // job is in the pending queue
    $this->assertEquals('1', $this->predis->lindex($this->queue->pending, 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      jobName: 'jobname',
      jobData: ['jobdata' => 'some data'],
      status: 'pending'
    ), $this->getJobById(1));

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen($this->queue->processing));

    // job is in the success queue
    $this->assertEquals(1, $this->predis->lindex($this->queue->success, 0));

    // failed queue is empty
    $this->assertEmpty($this->predis->get($this->queue->failed));

    // job data is saved
    $this->assertEquals($this->getJobData(
      jobName: 'jobname',
      jobData: ['jobdata' => 'some data'],
      status: 'success',
      context: 'something returned from callback'
    ), $this->predis->get('php-redis-queue:jobs:1'));
  }

  /**
   * This test ensures:
   *  - callback arguments are what we expect
   *  - new job is added to the pending queue
   *  - failed job is added to the failed queue
   * @return void
   */
  public function testWork__failedJob()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['callback', 'callback_after'])
      ->getMock();

    $mock->expects($this->exactly(1))
      ->method('callback')
      ->with([])
      ->will($this->throwException(new \Exception('Job failed', 123)));

    $mock->expects($this->exactly(1))
      ->method('callback_after')
      ->with($this->getJobData(
        encode: false,
        status: 'failed',
        context: [
          'exception_type' => 'Exception',
          'exception_code' => 123,
          'exception_message' => 'Job failed'
        ]
      ), false);

    // add callbacks
    $worker->addCallback('default', [$mock, 'callback']);
    $worker->addCallback('default_after', [$mock, 'callback_after']);

    // put something in the queue
    $id = $client->push('queuename');
    $this->assertEquals(1, $id);

    // job is in the pending queue
    $this->assertEquals('1', $this->predis->lindex($this->queue->pending, 0));
    $this->assertEquals($this->getJobData(
      encode: false,
      status: 'pending'
    ), $this->getJobById(1));

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen($this->queue->processing));

    // job is in the failed queue
    $this->assertEquals(1, $this->predis->lindex($this->queue->failed, 0));

    // success queue is empty
    $this->assertEmpty($this->predis->get($this->queue->success));

    // job data is saved
    $this->assertEquals($this->getJobData(
      status: 'failed',
      context: [
        'exception_type' => 'Exception',
        'exception_code' => 123,
        'exception_message' => 'Job failed'
      ]
    ), $this->predis->get('php-redis-queue:jobs:1'));
  }

  /**
   * This test ensures:
   *  - jobs stuck in the processig queue (after a system failure)
   *    are added to the pending queue
   * @return void
   */
  public function testWork__jobStuckInProcessing()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    // add callback
    $worker->addCallback('default', function () {});

    // push some jobs into the pending queue
    $id = $client->push('queuename');
    $this->assertEquals(1, $id);

    $id = $client->push('queuename');
    $this->assertEquals(2, $id);

    // push some jobs into the processing queue
    $id = $client->push('queuename');
    $this->assertEquals(3, $id);
    $this->predis->rpush($this->queue->processing, $id); // push into processing
    $this->predis->lrem($this->queue->pending, -1, $id); // remove from pending

    $id = $client->push('queuename');
    $this->assertEquals(4, $id);
    $this->predis->rpush($this->queue->processing, $id); // push into processing
    $this->predis->lrem($this->queue->pending, -1, $id); // remove from pending

    // make sure jobs are in the right queues
    $this->assertEquals(['1', '2'], $this->predis->lrange($this->queue->pending, 0, -1));
    $this->assertEquals(['3', '4'], $this->predis->lrange($this->queue->processing, 0, -1));

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (jobs already processed)
    $this->assertEquals(0, $this->predis->llen($this->queue->processing));

    // jobs are in the success queue (reverse order in which they ran)
    $this->assertEquals(['2', '1', '4', '3'], $this->predis->lrange($this->queue->success, 0, -1));

    // failed queue is empty
    $this->assertEmpty($this->predis->get($this->queue->failed));
  }

  /**
   * This test ensures:
   *  - callback arguments are what we expect
   *  - new, rerun job is added to the pending queue
   *  - rerun job is added to the success queue and is not in the
   *    failed or processing queue
   *  - job data is removed from the system (because it was successful)
   * @return void
   */
  public function testWork__rerunJob()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    // add original job to the system
    $originalJob = $this->getJobData(encode: false, status: 'failed', id: 15, context: 'failure reason');
    $this->predis->set('php-redis-queue:jobs:15', json_encode($originalJob));
    $this->predis->lpush($this->queue->failed, 15);

    // increment the ID to that of the failed job
    $this->predis->incrby('php-redis-queue:meta:id', 15);


    // run the test

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['callback'])
      ->getMock();

    $mock->expects($this->exactly(1))
      ->method('callback');

    // add callback
    $worker->addCallback('default', [$mock, 'callback']);

    // rerun the job
    $client->rerun(15);

    // job is in the pending queue
    $this->assertEquals(15, $this->predis->lindex($this->queue->pending, 0));

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen($this->queue->processing));

    // job is in the success queue and its data is saved
    $this->assertEquals(15, $this->predis->lindex($this->queue->success, 0));
    $this->assertEquals($this->getJobData(
      id: 15,
      status: 'success',
      runs: [
        array_diff_key($originalJob, array_flip(['runs']))
      ]
    ), $this->predis->get('php-redis-queue:jobs:15'));

    // old job is removed ands its job record is also gone
    $this->assertEmpty($this->predis->lindex($this->queue->failed, 0));
    $this->assertEmpty($this->predis->lindex('php-redis-queue:client:jobs:15', 0));
  }

  public function testWork__trimSucessLists()
  {
    $worker = new QueueWorker($this->predis, 'queuename', [
      'successListLimit' => 4,
      'wait' => 0
    ]);

    $client = new ClientMock($this->predis);

    // add callback
    $worker->addCallback('default', function () {});

    // put some stuff in the queue
    $id = $client->push('queuename');
    $this->assertEquals(1, $id);

    $id = $client->push('queuename');
    $this->assertEquals(2, $id);

    $id = $client->push('queuename');
    $this->assertEquals(3, $id);

    $id = $client->push('queuename');
    $this->assertEquals(4, $id);

    // job is in the pending queue
    $this->assertEquals(4, $this->predis->llen($this->queue->pending));

    // set the worker to work
    $worker->work(false);

    // 4 jobs in the success queue
    $this->assertEquals(4, $this->predis->llen($this->queue->success));

    // all jobs have their data saved
    foreach (range(1, 4) as $id) {
      $this->assertEquals($this->getJobData(id: $id, status: 'success'), $this->predis->get("php-redis-queue:jobs:$id"));
    }

    // add another job
    $id = $client->push('queuename');
    $this->assertEquals(5, $id);

    // work again
    $worker->work(false);

    // still only 4 in the success
    $this->assertEquals(4, $this->predis->llen($this->queue->success));

    // all jobs have their data saved
    foreach (range(2, 5) as $id) {
      $this->assertEquals($this->getJobData(id: $id, status: 'success'), $this->predis->get("php-redis-queue:jobs:$id"));
    }

    // first job has been booted from the queue
    $this->assertEmpty($this->predis->get('php-redis-queue:jobs:1'));
  }

  public function testWork__trimFailedLists()
  {
    $worker = new QueueWorker($this->predis, 'queuename', [
      'failedListLimit' => 4,
      'wait' => 0
    ]);

    $client = new ClientMock($this->predis);

    // add callback
    $worker->addCallback('default', function () { throw new \Exception('Failed job'); });

    // put some stuff in the queue
    $id = $client->push('queuename');
    $this->assertEquals(1, $id);

    $id = $client->push('queuename');
    $this->assertEquals(2, $id);

    $id = $client->push('queuename');
    $this->assertEquals(3, $id);

    $id = $client->push('queuename');
    $this->assertEquals(4, $id);

    // job is in the pending queue
    $this->assertEquals(4, $this->predis->llen($this->queue->pending));

    // set the worker to work
    $worker->work(false);

    // 4 jobs in the failed queue
    $this->assertEquals(4, $this->predis->llen($this->queue->failed));

    // all jobs have their data saved
    foreach (range(1, 4) as $id) {
      $this->assertEquals($this->getJobData(
        id: $id,
        status: 'failed',
        context: [
          'exception_type' => 'Exception',
          'exception_code' => 0,
          'exception_message' => 'Failed job'
        ]
      ), $this->predis->get("php-redis-queue:jobs:$id"));
    }

    // add another job
    $id = $client->push('queuename');
    $this->assertEquals(5, $id);

    // work again
    $worker->work(false);

    // still only 4 in the failed
    $this->assertEquals(4, $this->predis->llen($this->queue->failed));

    // all jobs have their data saved
    foreach (range(2, 5) as $id) {
      $this->assertEquals($this->getJobData(
        id: $id,
        status: 'failed',
        context: [
          'exception_type' => 'Exception',
          'exception_code' => 0,
          'exception_message' => 'Failed job'
        ]
      ), $this->predis->get("php-redis-queue:jobs:$id"));
    }

    // first job has been booted from the queue
    $this->assertEmpty($this->predis->get('php-redis-queue:jobs:1'));
  }

  public function testsWork__jobGroup__autoQueue()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $worker->addCallback('default', function () {});

    $client = new ClientMock($this->predis);

    $group = $client->createJobGroup(3);
    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');

    $worker->work(false);

    // make sure all jobs were successful
    $newGroup = (new JobGroup($this->predis, (int) $group->id()));

    $this->assertEquals(3, $newGroup->get('total'));
    $this->assertEquals([1, 2, 3], $newGroup->get('success'));
    $this->assertEmpty($newGroup->get('failed'));
    $this->assertTrue($newGroup->get('complete'));
  }

  public function testsWork__jobGroup__manualQueue()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $worker->addCallback('default', function () {});

    $client = new ClientMock($this->predis);

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['group_after'])
      ->getMock();

    $mock->expects($this->exactly(1))
      ->method('group_after')
      ->with($this->isInstanceOf(JobGroup::class), true);

    // add callbacks
    $worker->addCallback('group_after', [$mock, 'group_after']);

    $group = $client->createJobGroup();
    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');
    $group->push('queuename');
    $group->queue();

    $worker->work(false);

    // all jobs were successful
    $newGroup = (new JobGroup($this->predis, (int) $group->id()));
    $this->assertEquals(4, $newGroup->get('total'));
    $this->assertEquals([1, 2, 3, 4], $newGroup->get('success'));
    $this->assertEmpty($newGroup->get('failed'));
    $this->assertTrue($newGroup->get('complete'));
  }

  public function testsWork__jobGroup__someFailures()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $worker->addCallback('default', function () {});

    $client = new ClientMock($this->predis);

    $mock = $this->getMockBuilder(\StdClass::class)
      ->disableOriginalConstructor()
      ->addMethods(['group_after'])
      ->getMock();

    $mock->expects($this->exactly(1))
      ->method('group_after')
      ->with($this->isInstanceOf(JobGroup::class), false);

    // add callbacks
    $worker->addCallback('group_after', [$mock, 'group_after']);

    $group = $client->createJobGroup();
    $group->push('queuename', 'unknownCallback');
    $group->push('queuename');
    $group->push('queuename', 'unknownCallback');
    $group->push('queuename');
    $group->queue();

    $worker->work(false);

    // all jobs were successful
    $newGroup = (new JobGroup($this->predis, (int) $group->id()));
    $this->assertEquals(4, $newGroup->get('total'));
    $this->assertEquals([2, 4], $newGroup->get('success'));
    $this->assertEquals([1, 3], $newGroup->get('failed'));
    $this->assertTrue($newGroup->get('complete'));
  }
}
