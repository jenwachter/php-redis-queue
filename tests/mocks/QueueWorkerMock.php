<?php

namespace PhpRedisQueue;

use PhpRedisQueue\QueueWorker;

class QueueWorkerMock extends QueueWorker
{
  protected function checkQueue()
  {
    return $this->redis->lmove($this->pending, $this->processing, 'LEFT', 'LEFT');
  }
}
