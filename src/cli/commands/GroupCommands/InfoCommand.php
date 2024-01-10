<?php

namespace PhpRedisQueue\cli\commands\GroupCommands;

use PhpRedisQueue\cli\commands\Command;
use PhpRedisQueue\managers\JobGroupManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InfoCommand extends Command
{
  public function __construct(protected JobGroupManager $groupManager)
  {
    parent::__construct();
  }

  protected function configure()
  {
    $this
      ->setName('group:info')
      ->setDescription('Get information on a specific group')
      ->addArgument(
        'gid',
        InputArgument::REQUIRED,
        'The ID of the group',
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $id = $input->getArgument('gid');
    $group = $this->groupManager->getJobGroup($id)->get();

    if (empty($group)) {
      $output->writeln(sprintf('Group #%s not found.', $id));
    } else {
      $output->writeln("");

      $table = new Table($output);
      $table
        ->setHeaderTitle(sprintf('Group #%s', $id))
        ->setHeaders($this->getJobTableHeaders())
        ->setRows(array_map([$this, 'getGroupTableRow'], [$group]));
      $table->render();
    }

    return Command::SUCCESS;
  }

  protected function getJobTableHeaders()
  {
    return [
      'Datetime initialized',
      'Total jobs',
      'Pending jobs',
      'Successful jobs',
      'Failed jobs',
    ];
  }

  protected function getGroupTableRow($group)
  {
    $formatter = $this->getHelper('formatter');

    return [
      $group['datetime'],
      $group['total'],
      count($group['pending']),
      count($group['success']),
      count($group['failed']),
    ];
  }
}
