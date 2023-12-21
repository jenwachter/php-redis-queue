<?php


namespace PhpRedisQueue\cli\commands\GroupCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\managers\JobGroupManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JobsCommand extends Command
{
  public function __construct(protected JobGroupManager $groupManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName('group:jobs')
      ->setDescription('List jobs associated with a given group.')
      ->addArgument(
        'gid',
        InputArgument::REQUIRED,
        'The ID of the group',
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $groupId = $input->getArgument('gid');
    $group = $this->groupManager->getJobGroup($groupId);

    $jobs = $group->getJobs();

    if (empty($jobs)) {
      $output->writeln('No jobs found.');
    } else {
      $table = new Table($output);
      $table
        ->setHeaders(['ID', 'Datetime initialized', 'Job name', 'Status'])
        ->setRows(array_map(fn ($job) => [$job->id, $job->datetime, $job->jobName, $job->status], $jobs));

      $table->render();
    }

    return Command::SUCCESS;
  }
}
