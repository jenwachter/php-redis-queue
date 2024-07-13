<?php


namespace PhpRedisQueue\cli\commands\QueueCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\models\Queue;
use PhpRedisQueue\managers\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JobsProcessedCommand extends Command
{
  public function __construct(protected QueueManager $queueManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName('queues:jobs:processed')
      ->setDescription('List processed jobs associated with a given queue.')
      ->addArgument(
        'queuename',
        InputArgument::REQUIRED,
        'The name of the queue',
      )
      ->addOption(
        'status',
        's',
        InputArgument::OPTIONAL,
        'status of the jobs to list [all, success, failed] (default: all)',
        'all',
      );

  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $queueName = $input->getArgument('queuename');
    $filter = $input->getOption('status');

    if (!in_array($filter, ['all', 'success', 'failed'])) {
      $output->writeln('Invalid status filter.');
      return Command::INVALID;
    }

    if ($filter === 'all') {
      $filter = null;
    }

    $queue = $this->queueManager->getQueue($queueName);

    $jobs = $queue->getJobs('processed');

    if (empty($jobs)) {
      $output->writeln('No jobs found.');
    } else {
      if ($filter) {
        $jobs = array_filter($jobs, fn ($job) => $job->status === $filter);
      }
      $table = new Table($output);
      $table
        ->setHeaders(['ID', 'Datetime initialized', 'Job name', 'Status', 'Context'])
        ->setRows(array_map(fn ($job) => [
            $job->id,
            $job->datetime,
            $job->jobName,
            $job->status,
            $job->status === 'failed' ?
                substr($job['context']['exception_message'], 0, 50) : 'n/a'
        ],
        $jobs)
    );

      $table->render();
    }

    return Command::SUCCESS;
  }
}
