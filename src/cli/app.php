<?php

namespace PhpRedisQueue\cli;

use config\helpers\CreateLogger;
use Symfony\Component\Console\Application;

$app = new Application();

$redis = new \Predis\Client();

$jobManager = new \PhpRedisQueue\JobManager($redis);
$queueManager = new \PhpRedisQueue\QueueManager($redis);

// list commands
$app->add(new \PhpRedisQueue\cli\commands\ListCommands\JobsCommand($queueManager));
$app->add(new \PhpRedisQueue\cli\commands\ListCommands\QueuesCommand($queueManager));

// job commands
$app->add(new \PhpRedisQueue\cli\commands\JobCommands\InfoCommand($jobManager));

$app->run();
