<?php

namespace PhpRedisQueue\cli\commands\JobCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\models\Job;
use PhpRedisQueue\managers\JobManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InfoCommand extends Command
{
  public function __construct(protected JobManager $jobManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName('job:info')
      ->setDescription('Get information on a specific job')
      ->addArgument(
        'id',
        InputArgument::REQUIRED,
        'The ID of the job',
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $id = $input->getArgument('id');
    $job = $this->jobManager->getJob($id)->get();

    if (empty($job)) {
      $output->writeln(sprintf('Job #%s not found.', $id));
    } else {
      $output->writeln("");

      $table = new Table($output);
      $table
        ->setHeaderTitle(sprintf('Job #%s', $id))
        ->setHeaders($this->getJobTableHeaders())
        ->setRows(array_map([$this, 'getJobTableRow'], [$job]));
      $table->render();

      if (!empty($job['jobData'])) {
        $table = new Table($output);
        $table
          ->setHeaderTitle(sprintf('Data attached to job #%s', $id))
          ->setRows([
            [print_r($job['jobData'], true)]
          ]);

        $output->writeln("");
        $table->render();
      }

      if (!empty($job['runs'])) {
        $table = new Table($output);
        $table
          ->setHeaderTitle(sprintf('Runs of job #%s', $id))
          ->setHeaders($this->getJobTableHeaders())
          ->setRows(array_map([$this, 'getJobTableRow'], $job['runs']));

        $output->writeln("");
        $table->render();
      }
    }

    return Command::SUCCESS;
  }

  protected function getJobTableHeaders()
  {
    return [
      'Datetime initialized',
      'Job name',
      'Status',
      'Context',
    ];
  }

  protected function getJobTableRow($job)
  {
    $formatter = $this->getHelper('formatter');

    return [
      $job['datetime'],
      $job['jobName'],
      $job['status'],
      $job['status'] === 'failed' ?
        $formatter->truncate($job['context']['exception_message'], 50) :
        'n/a',
    ];
  }
}
