<?php

namespace PhpRedisQueue\cli;

use Symfony\Component\Console\Application;

$app = new Application();

$app->add(new \PhpRedisQueue\cli\commands\QueueCommands\JobsCommand());
$app->add(new \PhpRedisQueue\cli\commands\QueueCommands\ListCommand());
$app->add(new \PhpRedisQueue\cli\commands\JobCommands\InfoCommand());
$app->add(new \PhpRedisQueue\cli\commands\JobCommands\RerunCommand());
$app->add(new \PhpRedisQueue\cli\commands\GroupCommands\InfoCommand());
$app->add(new \PhpRedisQueue\cli\commands\GroupCommands\JobsCommand());

$app->run();
