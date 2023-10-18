<?php


namespace PhpRedisQueue\cli\commands\ListCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\models\Queue;
use PhpRedisQueue\QueueManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JobsCommand extends Command
{
  protected array $validStatuses = [
    'pending',
    'processing',
    'success',
    'failed'
  ];

  public function __construct(protected QueueManager $queueManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName('list:jobs')
      ->setDescription('List jobs associated with a given queue. Lists pending jobs by default.')
      ->addArgument(
        'queuename',
        InputArgument::REQUIRED,
        'The name of the queue',
      )
      ->addArgument(
        'status',
        null,
        'Job status (' . implode(', ', $this->validStatuses) .')',
        'pending',
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $queueName = $input->getArgument('queuename');
    $queue = new Queue($queueName);

    $status = $input->getArgument('status');
    if (!$this->validateStatus($status, $output)) {
      return Command::FAILURE;
    }

    $methodName = 'get' . ucfirst($status) . 'Jobs';
    if ($status === 'success') {
      $methodName = 'getSuccessfulJobs';
    }
    $jobs = $this->queueManager->$methodName($queue);

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

  protected function validateStatus($status, $output)
  {
    if (!in_array($status, $this->validStatuses)) {
      $output->writeln(sprintf('<error>Error: `%s` is not a valid job status.</error>', $status));
      $output->writeln(sprintf('Please pass one of the following: %s', implode(', ', $this->validStatuses)));
      return false;
    }

    return true;
  }
}
