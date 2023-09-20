<?php

namespace PhpRedisQueue\cli\commands;

use PhpRedisQueue\models\Queue;
use PhpRedisQueue\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueuePendingCommand extends BaseQueueCommand
{
  protected string $name = 'queue:pending';
  protected string $description = 'Get pending jobs in a given queue';
  protected string $getMethod = 'getPendingJobs';
}
