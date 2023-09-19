<?php

namespace PhpRedisQueue\cli\commands;

use PhpRedisQueue\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueuesListCommand extends Command
{
  public function __construct(protected QueueManager $queueManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName('queues:list')
      ->setDescription('List active queues');
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $activeQueues = $this->queueManager->getList();

    if (empty($activeQueues)) {
      $output->writeln('No queues found.');
    } else {
      $table = new Table($output);
      $table
        ->setHeaders(['Queue name', 'Active workers', 'Pending jobs'])
        ->setRows(array_map(fn ($row) => array_values($row), $this->queueManager->getList()));

      $table->render();
    }

    return Command::SUCCESS;
  }
}
