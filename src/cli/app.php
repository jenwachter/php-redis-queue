<?php

namespace PhpRedisQueueCli\cli;

use PhpRedisQueueCli\commands\QueuesCommand;
use Symfony\Component\Console\Application;

$application = new Application();

$application->run();
