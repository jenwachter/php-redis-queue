<?php

namespace PhpRedisQueue\cli\commands\QueueCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\managers\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Command
{
  protected function configure()
  {
    parent::configure();

    $this
      ->setName('queues:list')
      ->setDescription('List queues');
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    parent::execute($input, $output);

    $queues = $this->queueManager->getList();

    if (empty($queues)) {
      $output->writeln('No queues found.');
    } else {
      $table = new Table($output);
      $table
        ->setHeaders(['Queue name', 'Active workers', 'Pending jobs', 'Processed jobs'])
        ->setRows(array_map(fn ($row) => array_values($row), $queues));

      $table->render();
    }

    return Command::SUCCESS;
  }
}
