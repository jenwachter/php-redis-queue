# PHP Redis Queue

A simple background server queue utilizing Redis and PHP.

* [Requirements](#requirements)
* [Installation](#installation)
* [How it works](#how-it-works)
* [Quick example](#quick-example)
* [Documentation](#documentation)
  * [Worker](#worker)
  * [Client](#client)
  * [CLI](#cli)

## Requirements

* PHP >= 8.0
* Redis >= 6.0

## Installation

To install the library, you will need to use [Composer](https://getcomposer.org/download/) in your project.

```bash
composer require jenwachter/php-redis-queue
```

## How it works

A _worker_ defines a queue, listens for jobs to get pushed into the queue, and performs work to complete each job in the queue. A single queue can handle multiple types of jobs, specified by different callbacks. For example, you can create a queue to handle file uploads that can both upload and delete files using `upload` and `delete` callbacks. Ideally, workers are run as a process on a server.

A _client_ pushes jobs into a queue, optionally with data needed by the worker to complete the job. For example, for a job that uploads files, passing the path of the file would be helpful.

When a client pushes a job into a queue, it waits in the queue until it reaches the top. Once it does, the worker:

1. Removes the job from the queue and adds it to a processing queue.
1. Determines if there is an available callback for this job. If there isn't, the job is considered failed.
1. Calls a _before_ callback for the job type, if defined.
1. Calls the main callback for the job type.
    1. If the callback does not throw an exception, it is considered successful. If the callback throws an exception, it is considered failed.
    1. The job is removed from the processing queue and added to either the failed or success list.
1. Calls an _after_ callback for the job type, if defined.
1. If the job is part of a [job group](#job-groups) and all jobs within the group have completed, the worker calls the `group_after` callback, if defined.
1. The queue moves on to the next job or waits until another is added.

## Quick example

In this quick example, we'll set up a worker that handles processing that needs to be done when uploading and deleting files. We'll then create a client to send jobs to the worker.

### Create a worker

If using the default settings of the `work()` method (blocking), only one worker (queue) can be run in a single file; however, a single worker can handle multiple types of jobs. For example, here we create the `files` worker that can handle processing that needs to be done when both a file is uploaded and deleted:

_files.php_
```php
$predis = new Predis\Client();
$worker = new PhpRedisQueue\QueueWorker($predis, 'files');

$worker->addCallback('upload', function (array $data) {
  // create various crop sizes
  // optimize
  // ...
});

$worker->addCallback('delete', function (array $data) {
  // delete all created crop sizes
  // ...
});

$worker->work();
```

### Run the worker

To run the worker, you can run it manually on a server:
```bash
$ php files.php
```
But once the script exits or the connection closes, the worker will also stop running. This is helpful for testing during development, but not so much in a production environment.

To ensure your worker is always running, run the worker as a process using a system such as [Supervisor](http://supervisord.org/).


### Create a client

Continuing with our files example, the following client code would be executed after a file is uploaded or deleted in the system.

```php
// trigger: user uploads a file...

$predis = new Predis\Client();
$client = new PhpRedisQueue\Client($predis);

// gather data required by worker upload callback
$data = [
  'path' => '/path/to/uploaded/file',
];

// push the job to the files queue
$client->push('files', 'upload', $data);

```


### Job groups

You can also group jobs into a Job Group, which enables you to use the `group_after` callback when all jobs in the group have completed. Jobs added to a job group can be assigned to any queue. Refer to the [Job Group documentation](#job-group) for more details.

_*client.php*_
```php
$predis = new Predis\Client();
$client = new PhpRedisQueue\Client($predis);

// create a job group
$group = $client->createJobGroup();

// add jobs to the group
$group->push('queuename', 'jobname');
$group->push('another-queuename', 'jobname');
$group->push('queuename', 'jobname');
$group->push('queuename', 'jobname');

// add jobs in the group to the queue
$group->queue();
```

_*worker.php*_
```php
$predis = new Predis\Client();
$worker = new PhpRedisQueue\QueueWorker($predis, 'queuename');

$worker->addCallback('jobname', fn (array $data) true);

$worker->addCallback('group_after', function ($group, $success) {
  // respond to group completion
});

$worker->work();
```

## Documentation

### Worker

#### Initialization

```php
$predis = new Predis\Client();
$worker = new PhpRedisQueue\QueueWorker($predis, 'queuename');
```
_Note: queuename cannot be an integer._

#### Configuration

Some parts of the worker can be customized by sending an array of options as the third argument.

```php
$predis = new Predis\Client();
$worker = new PhpRedisQueue\QueueWorker($predis, 'queuename', [
  'logger' => new Logger(),
]);
```

##### Available options:

* __default_socket_timeout__: timeout (in seconds) for the worker, if using the default blocking functionality. Default: -1 (no timeout)
* __logger__: a logger that implements `Psr\Log\LoggerInterface`. Default: null
* __wait__: number of seconds to wait in between job processing. Default: 1

### Methods

#### __`addCallback(string $name, callable $callable)`__

Attaches a callback to the worker. Available callbacks:

* `<jobName>`: Runs the job. Example: `upload`
* `<jobName>_before`: Runs before the job begins. Example: `upload_before`
* `<jobName>_after`: Runs after the job is complete. Example: `upload_after`
* `group_after`: Runs after a group of jobs have completed.

Returns: Null.

Arguments:

* `$name`: Name of a hook that corresponds to one of three stages of the job's processing. See above for the format.
* `$callable`: Function to attach to the given hook. Arguments are as follows:
  * `<jobName>(array $data)`
    * `$data`: Array of data passed to the job by the client
  * `<jobName>_before(array $data)`
    * `$data`: Array of data passed to the job by the client
  * `<jobName>_after(array $data, bool $success)`
    * `$data`: Array of data passed to the job by the client. Exception data from failed jobs is available in `$data['context']`
    * `$success`: Job status; success (`true`) or failure (`false`)
  * `group_after(JobGroup $group, bool $success)`
    * `$group`: Job group model
    * `$success`: Group status; all jobs in the group completed successfully (`true`) or one or more jobs in the group failed (`false`)

#### __`work(bool $block = true)`__

Instructs the worker to begin listening to the queue.

Returns: Null.

Arguments:

* `$block`: Should the work method be blocking?

### Client

#### Initialization

```php
$predis = new Predis\Client();
$client = new \PhpRedisQueue\Client($predis);
```

#### Configuration

Some parts of the worker can be customized by sending an array of options as the second argument.

```php
$predis = new Predis\Client();
$worker = new PhpRedisQueue\QueueWorker($predis, 'queuename', [
  'logger' => new Logger()
]);
```

##### Available options:

* __logger__: a logger that implements `Psr\Log\LoggerInterface`. Default: null

### Methods

#### __`push(string $queue, string $jobName = 'default', array $jobData = [])`__

Pushes a job to the end of a queue.

Returns: Integer. ID of job.

Arguments:

* `$queue`: Name of the queue.
* `$jobName`: Name of the job to handle the work.
* `$data`: Data to pass to the worker.

#### __`pushToFront(string $queue, string string $jobName = 'default', array $jobData = [])`__

Pushes a job to the front of a queue.

Returns: Integer. ID of job.

Arguments:

* `$queue`: Name of the queue.
* `$jobName`: Name of the job to handle the work.
* `$data`: Data to pass to the worker.

#### __`pull(int $id)`__

Pull a job from a queue.

Returns: Boolean. `true` if the job was successfully removed; otherwise, `false`.

Arguments:

* `$id`: ID of job to pull.

#### __`rerun(int $id)`__

Reruns a previously failed job. Throws an exception if the job was successful already or cannot be found.

Returns: Boolean. TRUE if the job was successfully readded to the queue.

Arguments:

* `$id`: ID of failed job.

#### __`createJobGroup(int total = null, $data = [])`__

Creates a job group, which allows you to link jobs together. Use the [`group_after`](#addcallbackstring-name-callable-callable) callback to perform work when all jobs in the group have completed.

Returns: PhpRedisQueue\models\JobGroup object

Arguments:

* `$total`: Total number of jobs.
* `$data`: array of data to store with the job group.


### Job Group

#### Initialization

A job group are created via the `Client::createJobGroup`, which then returns the JobGroup model.

```php
$predis = new Predis\Client();
$client = new \PhpRedisQueue\Client($predis);

$group = $group->createJobGroup($total = null, $data = []);
```

Returns: JobGroup model

Arguments:

* `$total`: Total number of jobs, if known at initialization.
* `$data`: Array of data to attach to the group, which can be of use in the `group_after` callback.


### JobGroup Model Methods

#### __`push(string $queue, string $jobName = 'default', array $jobData = [])`__

Pushes a job to the job group. Note: jobs cannot be added to a Job Group if it has already been queued.

Returns: Integer. ID of job.

Arguments:

* `$queue`: Name of the queue.
* `$jobName`: Name of the job to handle the work.
* `$data`: Data to pass to the worker.

#### __`setTotal(int total)`__

Tells the group how many jobs to expect. Enables the Job Group to automatically add the jobs to the queue once the total is reached. Alternatively, use [`JobGroup::queue()`](#queue) to manually queue.

Returns: Boolean. TRUE if the total was successfully set.

#### __`queue()`__

Add the group's jobs to the queue. Only use this method if the Job Group is unaware of the total number of jos to expect via initialization of [`JobGroup::setTotal()`](#settotalint-total).

Returns: Boolean

#### __`removeFromQueue()`__

Removes any remaining jobs in the group from their queues.

Returns: Boolean

#### __`getJobs()`__

Get an array of jobs associated with the group.

Returns: array of Job models.

#### __`getUserData()`__

Get the data assigned to the group on initialization.

Returns: array


### CLI

The command line interface allows you to interact with the queue. To access the tool, run:
```bash
./vendor/bin/prq
```

To list all available commands, run:
```bash
./vendor/bin/prq list
```

To get help with a command, run:
### Get help with a command
```bash
./vendor/bin/prq queues:list --help
```

### Connecting to Redis in the CLI

By default, the CLI connects to Redis at `tcp://127.0.0.1:6379`. If you need to customize this connection, you have a couple options:

#### Environment variables

You can configure the connection to Redis using environment variables:

* To replace parts of the default connection string, set any or all of the following: `REDIS_SCHEME`, `REDIS_HOST`, `REDIS_PORT`
* To override the entire connection string, set `REDIS_URI`

The CLI will automatically use these variables to create the connection string.

##### Examples
* Setting `REDIS_HOST=my-redis-host` will result in a connection string of `tcp://my-redis-host:6379`
* Setting `REDIS_URI=unix:/path/to/redis.sock` will result in a connection string of `unix:/path/to/redis.sock`

#### Connection file

To create a more complex connection, create a PHP file that returns an instance of `Predis\Client` and pass the file to the `prq` command via the `--connection` (or `-c`) option.

```php
<?php

// config/prq.php
return new \Predis\Client($parameters, $options);
```

Then pass the file's path to the `prq` command:
```bash
$ prq queues:list -c config/prq.php
```
