<?php


namespace PhpRedisQueue\cli\commands\QueueCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\managers\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JobsCommand extends Command
{
  public function __construct(protected QueueManager $queueManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName('queues:jobs')
      ->setDescription('List pending jobs associated with a given queue.')
      ->addArgument(
        'queuename',
        InputArgument::REQUIRED,
        'The name of the queue',
      )
      ->addArgument(
        'type',
        InputArgument::OPTIONAL,
        'The type of jobs to return: `pending`, `processed`, or `processing`',
        'pending'
      )
      ->addArgument(
        'status',
        InputArgument::OPTIONAL,
        'If requesting process jobs, optionally filter by status: `success` or `failed`',
        false,
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $queueName = $input->getArgument('queuename');
    $queueType = $input->getArgument('type');
    $status = $input->getArgument('status');

    // validation
    $queueType = in_array($queueType, ['pending', 'processing', 'processed']) ? $queueType : 'pending';
    $status = $status === false || in_array($status, ['failed', 'success']) ? $status : 'success';
    
    $queue = $this->queueManager->getQueue($queueName);

    $jobs = $queue->getJobs($queueType);

    // filter by status, if necessary
    if ($status) {
      $jobs = array_filter($jobs, fn ($job) => $job->status === $status);
    }

    if (empty($jobs)) {
      $output->writeln('No jobs found.');
    } else {
      $table = new Table($output);
      $table
        ->setHeaders(['ID', 'Datetime initialized', 'Job name', 'Job status'])
        ->setRows(array_map(fn ($job) => [$job->id, $job->datetime, $job->jobName, $job->status], $jobs));

      $table->render();
    }

    return Command::SUCCESS;
  }
}
