<?php

namespace PhpRedisQueue;

use PhpRedisQueue\dashboard\mappers\JobMapper;
use PhpRedisQueue\dashboard\mappers\QueueMapper;
use Middlewares\TrailingSlash;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Slim\Routing\RouteCollectorProxy;

class Dashboard
{
  /**
   * @param \Predis\Client $redis
   * @param string $baseUrl
   * @param ?LoggerInterface $logger
   */
  public function __construct(protected \Predis\Client $redis, protected string $baseUrl = '/', protected ?LoggerInterface $logger = null)
  {
    $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/dashboard/views');
    $this->twig = new \Twig\Environment($loader);
  }

  public function watch($queues = [])
  {
    $this->queues = $queues;

    $app = AppFactory::create();

    // Add Error Handling Middleware
    $app->addErrorMiddleware(true, false, false);

    $app->group($this->baseUrl, function (RouteCollectorProxy $group) use ($queues) {

      $group->get('/', function (Request $request, Response $response, $args) {
        return $this->renderTemplate($response, 'screens/queues');
      });

      $group->get('/queues/{id}/', function (Request $request, Response $response, $args) {

        $queueName = 'php-redis-queue:client:' . $args['id'];

        $mapper = new QueueMapper($this->redis);

        return $this->renderTemplate($response,'screens/queue', [
          'queues' => [
            'Pending' => $mapper->get($queueName),
            // 'Processing' => $mapper->get($queueName .':processing'),
            'Success' => $mapper->get($queueName .':success'),
            'Failed' => $mapper->get($queueName .':failed'),
          ],
        ]);
      });

      $group->get('/jobs/{id}/', function (Request $request, Response $response, $args) {
        return $this->renderTemplate($response, 'screens/job', [
          'job' => (new JobMapper($this->redis))->get($args['id'])
        ]);

        // try {
        //   return $this->renderTemplate($response, 'screens/job', [
        //     'job' => (new JobMapper($this->redis))->get($args['id'])
        //   ]);
        // } catch (\Exception $e) {
        //   if ($e->getCode() === 404) {
        //     throw new HttpNotFoundException($request);
        //   }
        // }
      });

    });

    $app->add((new TrailingSlash(true))->redirect());

    $app->run();
  }

  protected function renderTemplate(Response $response, string $template, array $data = [])
  {
    // convert any nested objects to arrays (twig is weird about objects)
    $data = json_decode(json_encode($data), true);

    // add global vars
    $data['baseURL'] = $this->baseUrl;
    $data['nav'] = $this->queues;

    $template = $this->twig->load($template . '.twig');

    $response->getBody()->write($template->render($data));

    return $response;
  }
}
