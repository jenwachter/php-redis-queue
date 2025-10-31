<?php

namespace PhpRedisQueue\cli\commands;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Command extends \Symfony\Component\Console\Command\Command
{
  public const SUCCESS = 0;
  public const FAILURE = 1;
  public const INVALID = 2;

  protected function configure()
  {
    $this->addConfigOption();
  }

  protected function addConfigOption()
  {
    $this->addOption(
      'connection',
      'c',
      InputOption::VALUE_REQUIRED,
      'Retrieve the connection to Predis\Client from a local file. The path to the file is relative to the current working directory. Example: config/prq.php',
    );
  }

  protected function getPredis($input, $output): \Predis\Client
  {
    // check for a connection file containing Predis
    $configFile = $input->getOption('connection');
    if ($configFile !== null) {

      $location = getcwd() . '/' . $configFile;

      if (file_exists($location)) {

        $included = include($location);
        if (!($included instanceof \Predis\Client)) {
          $output->writeln(sprintf('<error>Config file did not return a valid Predis\Client object.</error>'));
          exit(Command::FAILURE);
        }

        $output->writeln(sprintf('<info>Predis\Client loaded from %s.</info>', $configFile));

        return $included;

      } else {
        $output->writeln(sprintf('<error>Config file not found. Tried %s</error>', $location));
        exit(Command::FAILURE);
      }
    }

    // check for REDIS_URI environment variable
    if ($uri = getenv('REDIS_URI')) {
      $output->writeln(sprintf('<info>Connected to Redis at %s.</info>', $uri));
      return new \Predis\Client($uri);
    }

    // otherwise, use default scheme, replaced with environment vars, if found
    $scheme = 'tcp';
    $host = '127.0.0.1';
    $port = 6379;

    if ($customScheme = getenv('REDIS_SCHEME')) {
      $scheme = $customScheme;
    }

    if ($customHost = getenv('REDIS_HOST')) {
      $host = $customHost;
    }

    if ($customPort = getenv('REDIS_PORT')) {
      $port = $customPort;
    }

    $uri = "{$scheme}://{$host}:{$port}";

    $output->writeln(sprintf('<info>Connected to Redis at %s.</info>', $uri));
    return new \Predis\Client($uri);
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $redis = $this->getPredis($input, $output);
    $this->groupManager = new \PhpRedisQueue\managers\JobGroupManager($redis);
    $this->jobManager = new \PhpRedisQueue\managers\JobManager($redis);
    $this->queueManager = new \PhpRedisQueue\managers\QueueManager($redis);
  }
}
