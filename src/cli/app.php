<?php

namespace PhpRedisQueue\cli;

use config\helpers\CreateLogger;
use Symfony\Component\Console\Application;

$app = new Application();

$redis = new \Predis\Client();

$queueManager = new \PhpRedisQueue\QueueManager($redis);
$jobManager = new \PhpRedisQueue\JobManager($redis);

$app->add(new \PhpRedisQueue\cli\commands\QueuesListCommand($queueManager));
$app->add(new \PhpRedisQueue\cli\commands\QueueFailedCommand($queueManager));
$app->add(new \PhpRedisQueue\cli\commands\QueuePendingCommand($queueManager));
$app->add(new \PhpRedisQueue\cli\commands\QueueSuccessCommand($queueManager));
$app->add(new \PhpRedisQueue\cli\commands\JobInfoCommand($jobManager));

$app->run();
