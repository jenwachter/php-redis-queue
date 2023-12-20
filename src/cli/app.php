<?php

namespace PhpRedisQueue\cli;

use Symfony\Component\Console\Application;

$app = new Application();

$redis = new \Predis\Client();

$jobManager = new \PhpRedisQueue\managers\JobManager($redis);
$queueManager = new \PhpRedisQueue\managers\QueueManager($redis);

// queue commands
$app->add(new \PhpRedisQueue\cli\commands\QueueCommands\InfoCommand($queueManager));
$app->add(new \PhpRedisQueue\cli\commands\QueueCommands\ListCommand($queueManager));

// job commands
$app->add(new \PhpRedisQueue\cli\commands\JobCommands\InfoCommand($jobManager));
$app->add(new \PhpRedisQueue\cli\commands\JobCommands\RerunCommand($jobManager, $queueManager));

$app->run();
