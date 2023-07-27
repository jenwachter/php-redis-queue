# PHP Redis Queue

A simple background server queue utilizing Redis and PHP.

* [Requirements](#requirements)
* [Installation](#installation)
* [How it works](#how-it-works)
* [Quick example](#quick-example)
* [Documentation](#documentation)

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
1. Calls a _before_ callback for the job type, if available.
1. Calls the main callback for the job type.
    1. If the callback does not throw an exception, it is considered successful. If the callback throws an exception, it is considered failed.
    1. The job is removed from the processing queue and added to either the failed or success list.
1. Calls an _after_ callback for the job type, if available.
1. Queue moves on to the next job or waits until another is added.

## Quick example

In this quick example, we'll setup a worker that handles processing that needs to be done when uploading and deleting files. We'll then create a client to send jobs to the worker.

### Create a worker

If using the default settings of the `work()` method (blocking), only one worker (queue) can be run in a single file; however a single worker can handle multiple types of jobs. For example, here we create the `files` worker that can handle processing that needs to be done when both a file is uploaded and deleted:

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

## Documentation

### Worker

#### Initialization

```php
$predis = new Predis\Client();
$worker = new PhpRedisQueue\QueueWorker($predis, 'queuename');
```

#### Configuration

Some parts of the worker can be customized by sending an array of options as the third argument.

```php
$predis = new Predis\Client();
$worker = new PhpRedisQueue\QueueWorker($predis, 'queuename', [
  'logger' => new Logger(),
]);
```

##### Available options:

* __default_socket_timeout__: timeout (in seconds) for worker, if using the defualt blocking functionality. Default: -1 (no timeout)
* __logger__: a logger that implements `Psr\Log\LoggerInterface`. Default: null
* __processedListsLimit__: limits the number of items in the failed and success lists. Pass -1 for no limit. Default: 5000
* __wait__: number of seconds to wait inbetween job processing. Default: 1

### Methods

#### __`addCallback(string $name, callable $callable)`__

Attaches a callback to a job. You can attach up to three callbacks per job. The format of the callback names are as follows:
* `<jobName>`: runs the job
* `<jobName>_before`: runs before the job begins
* `<jobName>_after`: runs after the job is complete

Returns: Null.

Arguments:

* `$name`: Name of a hook that cooresponds to one of three stages of the job's processing. See above for format.
* `$callable`: Function to attach to the given hook. Arguments are as follows:
  * `jobName(array $data)`
    * `$data`: Array of data passed to the job by the client
  * `jobName_before(array $data)`
    * `$data`: Array of data passed to the job by the client
  * `jobName_after(array $data, bool $success)`
    * `$data`: Array of data passed to the job by the client. Exception data from failed jobs is available in `$data['meta']['context']`
    * `$success`: Job status; success (`true`) or failure (`false`)

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

#### __`getJob(int $id)`__

Get the data attached to a job.

Note: job data expires when a job is trimmed from either the failed or success lists. You can set the length of these lists using the `processedListsLimit` configuration option on the Worker, which can be set per queue/worker.

Returns: Array.

Arguments:

* `$id`: Required. ID of job.

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

#### __`rerun(int $id)`__

Reruns a previously failed job.

Returns: Integer. ID of the job.

Arguments:

* `$id`: ID of failed job.

