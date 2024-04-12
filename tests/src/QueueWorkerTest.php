<?php

namespace PhpRedisQueue;

use PhpRedisQueue\models\Queue;

class QueueWorkerTest extends Base
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
    $this->assertEquals(1, $this->predis->get($this->queue->processed));

    // job data is saved
    $this->assertEquals($this->getJobData(context: 'No callback set for `default` job in queuename queue.', status: 'failed'), $this->predis->get('php-redis-queue:jobs:1'));

    // ttl is set
    $this->assertLessThanOrEqual($this->ttl['failed'], $this->predis->ttl('php-redis-queue:jobs:1'));
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
    $this->assertEquals(2, $this->predis->get($this->queue->processed));

    // job data is saved
    $this->assertEquals($this->getJobData(status: 'success'), $this->predis->get('php-redis-queue:jobs:1'));
    $this->assertEquals($this->getJobData(id: 2, status: 'success'), $this->predis->get('php-redis-queue:jobs:2'));

    // ttls are set
    $this->assertLessThanOrEqual($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:1'));
    $this->assertLessThanOrEqual($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:2'));
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
    $this->assertEquals(1, $this->predis->get($this->queue->processed));

    // job data is saved
    $this->assertEquals($this->getJobData(
      jobName: 'jobname',
      jobData: ['jobdata' => 'some data'],
      status: 'success',
      context: 'something returned from callback'
    ), $this->predis->get('php-redis-queue:jobs:1'));

    // ttl is set
    $this->assertLessThanOrEqual($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:1'));
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
    $this->assertEquals(1, $this->predis->get($this->queue->processed));

    // job data is saved
    $this->assertEquals($this->getJobData(
      status: 'failed',
      context: [
        'exception_type' => 'Exception',
        'exception_code' => 123,
        'exception_message' => 'Job failed'
      ]
    ), $this->predis->get('php-redis-queue:jobs:1'));

    // ttl is set
    $this->assertLessThanOrEqual($this->ttl['failed'], $this->predis->ttl('php-redis-queue:jobs:1'));
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
    $this->assertEquals(4, $this->predis->get($this->queue->processed));

    // ttl is set
    $this->assertLessThanOrEqual($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:1'));
    $this->assertLessThanOrEqual($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:2'));
    $this->assertLessThanOrEqual($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:3'));
    $this->assertLessThanOrEqual($this->ttl['success'], $this->predis->ttl('php-redis-queue:jobs:4'));
  }
}
