<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Worker;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize logger
$logger = new Logger('campchat-websocket');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/websocket.log', Logger::INFO));

// Create WebSocket worker
$ws_worker = new Worker("websocket://0.0.0.0:8012");
$ws_worker->count = 4;

// Handle WebSocket connections
$ws_worker->onConnect = function ($connection) use ($logger) {
    $logger->info("WebSocket connection established: ID {$connection->id}");
    $connection->send(json_encode(['ok' => true, 'message' => 'WebSocket connected']));
};

// Handle WebSocket messages
$ws_worker->onMessage = function ($connection, $data) use ($logger) {
    $logger->info("WebSocket message received: $data");
    // Placeholder for real-time messaging
    $connection->send(json_encode(['ok' => true, 'message' => "Echo: $data"]));
};

// Log worker start
$ws_worker->onWorkerStart = function () use ($logger) {
    $logger->info("WebSocket Worker started on websocket://0.0.0.0:8012");
};

// Run all workers
Worker::runAll();
?>