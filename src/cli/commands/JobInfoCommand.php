<?php

namespace PhpRedisQueue\cli\commands;

use PhpRedisQueue\models\Job;
use PhpRedisQueue\JobManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JobInfoCommand extends Command
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
      ->addArgument('id', InputArgument::REQUIRED, 'The ID of the job');
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $id = $input->getArgument('id');
    $job = $this->jobManager->getJob($id);

    if (empty($job)) {
      $output->writeln(sprintf('Job #%s not found.', $id));
    } else {
      $output->writeln("");
      $output->writeln(sprintf('<options=bold>Datetime initialized:</> %s', $job['meta']['datetime']));
      $output->writeln(sprintf('<options=bold>Job name:</> %s', $job['meta']['jobName']));
      $output->writeln(sprintf('<options=bold>Status:</> %s', $job['meta']['status']));

      $output->writeln("");
      $output->writeln('Attached data:');

      print_r($job['job']);

      // $table = new Table($output);
      // $table->setHeaders(['Key', 'Value'])
      //   ->setRows([
      //     ...(array_map(fn ($key, $value) => [$key, is_string($value) ? $value : json_encode($value)], array_keys($job['job']), $job['job']))
      //   ]);
      // $table->render();
    }

    return Command::SUCCESS;
  }
}
