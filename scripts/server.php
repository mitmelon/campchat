<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Worker;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Slim\Factory\AppFactory;
use CampChat\Config\Config;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize logger
$logger = new Logger('campchat');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/server.log', Logger::DEBUG));

// Initialize Slim app
$app = AppFactory::create();
$app->setBasePath('/v1');

// Add JSON body parsing middleware
$app->addBodyParsingMiddleware();

// Add error middleware
$app->addErrorMiddleware(true, true, true);

// Include routes
require __DIR__ . '/../src/Routes/api.php';

// Create Workerman HTTP worker
$http_worker = new Worker("http://0.0.0.0:8011");
$http_worker->count = 2; // Reduced for Windows testing

// Handle HTTP requests with Slim
$http_worker->onMessage = function ($connection, WorkermanRequest $workermanRequest) use ($app, $logger) {
    try {
        // Log raw Workerman request
        $logger->debug("Raw Workerman Request: {$workermanRequest->method()} {$workermanRequest->uri()}");
        $logger->debug("Raw Body: " . $workermanRequest->rawBody());

        // Create PSR-7 request
        $uri = $workermanRequest->uri();
        $request = \Slim\Psr7\Factory\ServerRequestFactory::createFromGlobals();
        $request = $request->withMethod($workermanRequest->method())
                          ->withUri(new \Slim\Psr7\Uri('http', 'localhost', 8011, $uri))
                          ->withHeader('Content-Type', 'application/json');

        // Manually parse JSON body
        $rawBody = $workermanRequest->rawBody();
        if ($rawBody) {
            $parsedBody = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request = $request->withParsedBody($parsedBody);
            } else {
                $logger->warning("Invalid JSON body: " . json_last_error_msg());
            }
        }

        // Log PSR-7 request details
        $logger->debug("PSR-7 Request: {$request->getMethod()} {$request->getUri()->getPath()}?{$request->getUri()->getQuery()}");
        $logger->debug("Parsed Body: " . json_encode($request->getParsedBody()));

        // Handle request with Slim
        $response = $app->handle($request);

        // Send response
        $connection->send(
            new \Workerman\Protocols\Http\Response(
                $response->getStatusCode(),
                $response->getHeaders(),
                (string) $response->getBody()
            )
        );
    } catch (Exception $e) {
        $logger->error("Request error: {$e->getMessage()}");
        $connection->send(
            new \Workerman\Protocols\Http\Response(
                500,
                ['Content-Type' => 'application/json'],
                json_encode(['ok' => false, 'error' => 'Internal server error'])
            )
        );
    }
};

// Log worker start
$http_worker->onWorkerStart = function () use ($logger) {
    $logger->info("HTTP Worker started on http://0.0.0.0:8011");
};

// Run all workers
Worker::runAll();
?>