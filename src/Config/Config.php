<?php
namespace CampChat\Config;

use MongoDB\Client;
use Predis\Client as RedisClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Config {
    private static $logger;

    private static function getLogger(): Logger {
        if (!self::$logger) {
            self::$logger = new Logger('campchat-config');
            self::$logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/config.log', Logger::DEBUG));
        }
        return self::$logger;
    }

    public static function getMongoDB(): Client {
        $uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';
        return new Client($uri);
    }

    public static function getRedis(): RedisClient {
        $host = getenv('REDIS_HOST') ?: 'localhost';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $parameters = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
        ];

        try {
            $client = new RedisClient($parameters);
            $client->connect();
            self::getLogger()->info("Connected to Redis on $host:$port");
            return $client;
        } catch (\Exception $e) {
            self::getLogger()->error("Failed to connect to Redis: {$e->getMessage()}");
            throw new \RuntimeException("Redis connection failed: {$e->getMessage()}");
        }
    }

    public static function getRabbitMQ(): ?AMQPStreamConnection {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = getenv('RABBITMQ_PORT') ?: 5672;
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $pass = getenv('RABBITMQ_PASS') ?: 'guest';
        $maxRetries = 3;
        $retryDelay = 2; // seconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $connection = new AMQPStreamConnection($host, $port, $user, $pass);
                self::getLogger()->info("Connected to RabbitMQ on $host:$port (attempt $attempt)");
                return $connection;
            } catch (\Exception $e) {
                self::getLogger()->warning("RabbitMQ connection attempt $attempt failed: {$e->getMessage()}");
                if ($attempt < $maxRetries) {
                    sleep($retryDelay);
                }
            }
        }

        self::getLogger()->error("Failed to connect to RabbitMQ after $maxRetries attempts");
        return null;
    }
}
?>