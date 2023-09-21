<?php

namespace PhpRedisQueue\cli\commands\ListCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueuesCommand extends Command
{
  public function __construct(protected QueueManager $queueManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName('list:queues')
      ->setDescription('List queues');
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $queues = $this->queueManager->getList();

    if (empty($queues)) {
      $output->writeln('No queues found.');
    } else {
      $table = new Table($output);
      $table
        ->setHeaders(['Queue name', 'Active workers', 'Pending jobs', 'Successful jobs', 'Failed jobs'])
        ->setRows(array_map(fn ($row) => array_values($row), $queues));

      $table->render();
    }

    return Command::SUCCESS;
  }
}
