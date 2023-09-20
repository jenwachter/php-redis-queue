<?php

namespace PhpRedisQueue\cli\commands;

use PhpRedisQueue\models\Queue;
use PhpRedisQueue\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueFailedCommand extends BaseQueueCommand
{
  protected string $name = 'queue:failed';
  protected string $description = 'Get failed jobs in a given queue';
  protected string $getMethod = 'getFailedJobs';
}
