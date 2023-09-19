<?php

namespace PhpRedisQueue\cli\commands;

use PhpRedisQueue\models\Queue;
use PhpRedisQueue\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BaseQueueCommand extends Command
{
  protected string $name;
  protected string $getMethod;
  protected string $description;

  public function __construct(protected QueueManager $queueManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName($this->name)
      ->setDescription($this->description)
      ->addArgument('queuename', InputArgument::REQUIRED, 'The name of the queue');
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $queueName = $input->getArgument('queuename');
    $queue = new Queue($queueName);

    $method = $this->getMethod;
    $jobs = $this->queueManager->$method($queue);

    if (empty($jobs)) {
      $output->writeln('No jobs found.');
    } else {
      $table = new Table($output);
      $table
        ->setHeaders(['ID', 'Datetime initialized', 'Job name'])
        ->setRows(array_map(fn ($job) => [$job->meta->id, $job->meta->datetime, $job->meta->jobName], $jobs));

      $table->render();
    }

    return Command::SUCCESS;
  }
}
