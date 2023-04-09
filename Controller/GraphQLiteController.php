<?php

namespace TheCodingMachine\GraphQLite\Bundle\Controller;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Upload\UploadMiddleware;
use JsonException;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFileFactory;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use TheCodingMachine\GraphQLite\Bundle\Context\SymfonyGraphQLContext;
use TheCodingMachine\GraphQLite\Http\HttpCodeDecider;
use function array_map;
use function class_exists;
use function json_decode;

/**
 * Listens to every single request and forward Graphql requests to Graphql Webonix standardServer.
 */
class GraphQLiteController
{
    /** @var int */
    private $debug;

    /**
     * @var HttpMessageFactoryInterface
     */
    private $httpMessageFactory;

    public function __construct(
        private readonly ServerConfig $serverConfig,
        HttpMessageFactoryInterface   $httpMessageFactory = null,
        ?int                          $debug = null
    ) {
        $this->httpMessageFactory = $httpMessageFactory ?: new PsrHttpFactory(
            new ServerRequestFactory(),
            new StreamFactory(),
            new UploadedFileFactory(),
            new ResponseFactory()
        );
        $this->debug = $debug ?? $serverConfig->getDebugFlag();
    }

    /**
     * @throws JsonException
     */
    public function handleRequest(Request $request): Response
    {
        $psr7Request = $this->httpMessageFactory->createRequest($request);

        if (empty($psr7Request->getParsedBody()) && strtoupper($request->getMethod()) === "POST") {
            $content = $psr7Request->getBody()->getContents();
            $parsedBody = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON received in POST body: '.json_last_error_msg());
            }

            $psr7Request = $psr7Request->withParsedBody($parsedBody);
        }

        // Let's parse the request and adapt it for file uploads.
        if (class_exists(UploadMiddleware::class)) {
            $uploadMiddleware = new UploadMiddleware();
            $psr7Request = $uploadMiddleware->processRequest($psr7Request);
        }

        return $this->handlePsr7Request($psr7Request, $request);
    }

    public function loadRoutes(): RouteCollection
    {
        $routes = new RouteCollection();

        // prepare a new route
        $path = '/graphql';
        $defaults = [
            '_controller' => self::class.'::handleRequest',
        ];
        $route = new Route($path, $defaults);

        // add the new route to the route collection
        $routeName = 'graphqliteRoute';
        $routes->add($routeName, $route);

        return $routes;
    }

    private function handlePsr7Request(ServerRequestInterface $request, Request $symfonyRequest): JsonResponse
    {
        // Let's put the request in the context.
        $serverConfig = clone $this->serverConfig;
        $serverConfig->setContext(new SymfonyGraphQLContext($symfonyRequest));

        $standardService = new StandardServer($serverConfig);
        $result = $standardService->executePsrRequest($request);

        $httpCodeDecider = new HttpCodeDecider();

        if ($result instanceof ExecutionResult) {
            return new JsonResponse($result->toArray($this->debug), $httpCodeDecider->decideHttpStatusCode($result));
        }

        if (is_array($result)) {
            $finalResult = array_map(
                static fn(ExecutionResult $executionResult): array => $executionResult->toArray($this->debug),
                $result
            );
            // Let's return the highest result.
            $statuses = array_map([$httpCodeDecider, 'decideHttpStatusCode'], $result);
            $status = empty($statuses) ? 500 : max($statuses);

            return new JsonResponse($finalResult, $status);
        }

        throw new RuntimeException('Only SyncPromiseAdapter is supported');
    }
}
