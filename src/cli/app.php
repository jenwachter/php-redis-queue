<?php

namespace PhpRedisQueue\cli;

use Symfony\Component\Console\Application;

$app = new Application();

$redis = new \Predis\Client();

$groupManager = new \PhpRedisQueue\managers\JobGroupManager($redis);
$jobManager = new \PhpRedisQueue\managers\JobManager($redis);
$queueManager = new \PhpRedisQueue\managers\QueueManager($redis);

// queue commands
$app->add(new \PhpRedisQueue\cli\commands\QueueCommands\JobsCommand($queueManager));
$app->add(new \PhpRedisQueue\cli\commands\QueueCommands\ListCommand($queueManager));

// job commands
$app->add(new \PhpRedisQueue\cli\commands\JobCommands\InfoCommand($jobManager));
$app->add(new \PhpRedisQueue\cli\commands\JobCommands\RerunCommand($jobManager, $queueManager));

// group commands
$app->add(new \PhpRedisQueue\cli\commands\GroupCommands\InfoCommand($groupManager));
$app->add(new \PhpRedisQueue\cli\commands\GroupCommands\JobsCommand($groupManager));

$app->run();
