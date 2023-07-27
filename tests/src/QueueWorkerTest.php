<?php

namespace PhpRedisQueue;

class QueueWorkerTest extends Base
{
  public function testWork__noCallback()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    // put something in the queue
    $client->push('queuename');

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen('php-redis-queue:client:queuename:processing'));

    // job is in the failed queue
    $this->assertEquals(1, $this->predis->lindex('php-redis-queue:client:queuename:failed', 0));

    // success queue is empty
    $this->assertEquals(0, $this->predis->llen('php-redis-queue:client:queuename:success'));

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
    $client->push('queuename');
    $client->push('queuename');

    // jobs are in the pending queue
    $this->assertEquals($this->getJobData(), $this->predis->lindex('php-redis-queue:client:queuename', 0));
    $this->assertEquals($this->getJobData(id: 2), $this->predis->lindex('php-redis-queue:client:queuename', 1));

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen('php-redis-queue:client:queuename:processing'));

    // jobs are in the success queue (newest added are first)
    $this->assertEquals(2, $this->predis->lindex('php-redis-queue:client:queuename:success', 0));
    $this->assertEquals(1, $this->predis->lindex('php-redis-queue:client:queuename:success', 1));

    // failed queue is empty
    $this->assertEquals(0, $this->predis->llen('php-redis-queue:client:queuename:failed'));

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
      ->with($this->getJobData(jobName: 'jobname', jobData: ['jobdata' => 'some data'], encode: false));

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
    $client->push('queuename', 'jobname', ['jobdata' => 'some data']);

    // job is in the pending queue
    $this->assertEquals(
      $this->getJobData(
        jobName: 'jobname',
        jobData: ['jobdata' => 'some data']
      ),
      $this->predis->lindex('php-redis-queue:client:queuename', 0)
    );

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen('php-redis-queue:client:queuename:processing'));

    // job is in the success queue
    $this->assertEquals(1, $this->predis->lindex('php-redis-queue:client:queuename:success', 0));

    // failed queue is empty
    $this->assertEmpty($this->predis->get('php-redis-queue:client:queuename:failed'));

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
    $client->push('queuename');

    // job is in the pending queue
    $this->assertEquals($this->getJobData(), $this->predis->lindex('php-redis-queue:client:queuename', 0));

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen('php-redis-queue:client:queuename:processing'));

    // job is in the failed queue
    $this->assertEquals(1, $this->predis->lindex('php-redis-queue:client:queuename:failed', 0));

    // failed queue is empty
    $this->assertEmpty($this->predis->get('php-redis-queue:client:queuename:success'));

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
   *  - callback arguments are what we expect
   *  - new, rerun job is added to the pending queue
   *  - rerun job is added to the success queue and is not in the
   *    failed or processing queue
   *  - job data is removed from the system (because it was successful)
   * @return void
   */
  public function testWork__reranJob()
  {
    $worker = new QueueWorker($this->predis, 'queuename', ['wait' => 0]);
    $client = new ClientMock($this->predis);

    // add original job to the system
    $originalJob = $this->getJobData(encode: false, status: 'failed', id: 15, context: 'failure reason');
    $this->predis->set('php-redis-queue:jobs:15', json_encode($originalJob));
    $this->predis->lpush('php-redis-queue:client:queuename:failed', 15);

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
    $newId = $client->rerun(15);

    // job is in the pending queue
    $this->assertEquals($this->getJobData(id: $newId, originalJobData: $originalJob), $this->predis->lindex('php-redis-queue:client:queuename', 0));

    // set the worker to work
    $worker->work(false);

    // processing queue is empty (job already processed)
    $this->assertEquals(0, $this->predis->llen('php-redis-queue:client:queuename:processing'));

    // job is in the success queue and its data is saved
    $this->assertEquals($newId, $this->predis->lindex('php-redis-queue:client:queuename:success', 0));
    $this->assertEquals($this->getJobData(
      id: $newId,
      originalJobData: $originalJob,
      status: 'success'
    ), $this->predis->get('php-redis-queue:jobs:16'));

    // old job is removed ands its job record is also gone
    $this->assertEmpty($this->predis->lindex('php-redis-queue:client:queuename:failed', 0));
    $this->assertEmpty($this->predis->lindex('php-redis-queue:client:jobs:15', 0));
  }

  public function testWork__trimProcessedLists()
  {
    $worker = new QueueWorker($this->predis, 'queuename', [
      'processedListsLimit' => 4,
      'wait' => 0
    ]);

    $client = new ClientMock($this->predis);

    // add callbackj
    $worker->addCallback('default', function () {});

    // put some stuff in the queue
    $client->push('queuename');
    $client->push('queuename');
    $client->push('queuename');
    $client->push('queuename');

    // job is in the pending queue
    $this->assertEquals(4, $this->predis->llen('php-redis-queue:client:queuename'));

    // set the worker to work
    $worker->work(false);

    // 4 jobs in the success queue
    $this->assertEquals(4, $this->predis->llen('php-redis-queue:client:queuename:success'));

    // all jobs have their data saved
    foreach (range(1, 4) as $id) {
      $this->assertEquals($this->getJobData(id: $id, status: 'success'), $this->predis->get("php-redis-queue:jobs:$id"));
    }

    // add another job
    $client->push('queuename');

    // work again
    $worker->work(false);

    // still only 4 in the success
    $this->assertEquals(4, $this->predis->llen('php-redis-queue:client:queuename:success'));

    // all jobs have their data saved
    foreach (range(2, 5) as $id) {
      $this->assertEquals($this->getJobData(id: $id, status: 'success'), $this->predis->get("php-redis-queue:jobs:$id"));
    }

    // first job has been booted from the queue
    $this->assertEmpty($this->predis->get('php-redis-queue:jobs:1'));
  }
}
