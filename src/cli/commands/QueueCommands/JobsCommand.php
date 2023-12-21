<?php


namespace PhpRedisQueue\cli\commands\QueueCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\models\Queue;
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
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $queueName = $input->getArgument('queuename');
    $queue = $this->queueManager->getQueue($queueName);

    $jobs = $queue->getJobs('pending');

    if (empty($jobs)) {
      $output->writeln('No jobs found.');
    } else {
      $table = new Table($output);
      $table
        ->setHeaders(['ID', 'Datetime initialized', 'Job name'])
        ->setRows(array_map(fn ($job) => [$job->id, $job->datetime, $job->jobName], $jobs));

      $table->render();
    }

    return Command::SUCCESS;
  }
}
