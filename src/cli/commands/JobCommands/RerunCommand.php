<?php

namespace PhpRedisQueue\cli\commands\JobCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\models\Job;
use PhpRedisQueue\JobManager;
use PhpRedisQueue\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RerunCommand extends Command
{
  public function __construct(protected JobManager $jobManager, protected QueueManager $queueManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName('job:rerun')
      ->setDescription('Rerun a failed job')
      ->addArgument(
        'id',
        InputArgument::REQUIRED,
        'The ID of the job',
      )
      ->addArgument(
        'now',
        InputArgument::OPTIONAL,
        'Pass TRUE to push the job to the front of the queue',
        false,
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $id = $input->getArgument('id');
    $runNow = $input->getArgument('now') !== false;

    $job = $this->jobManager->getJob($id);
    $jobQueue = $job['meta']['queue'];

    if (!in_array($jobQueue, $this->queueManager->getActiveQueues())) {
      $output->writeln(sprintf('<error>Error: Cannot rerun job #%s.</error>', $id));
      $output->writeln(sprintf('This job requires an active %s queue worker.', $jobQueue));
      return Command::FAILURE;
    }

    if ($this->jobManager->rerun($id, $runNow)) {
      $output->writeln(sprintf('<info>Successfully added job #%s to the %s of the %s queue.</info>', $id, ($runNow ? 'front' : 'back'), $jobQueue));
      return Command::SUCCESS;
    }

    return Command::FAILURE;
  }
}
