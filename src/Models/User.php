<?php
namespace CampChat\Models;

use CampChat\Config\Config;
use MongoDB\BSON\ObjectId;
use PhpAmqpLib\Message\AMQPMessage;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class User {
    private $collection;
    private $redis;
    private $logger;

    public function __construct() {
        $this->collection = Config::getMongoDB()->selectDatabase('campchat')->selectCollection('users');
        // Initialize logger first
        $this->logger = new Logger('campchat-user');
        try {
            $logFile = __DIR__ . '/../../logs/user.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize user logger: {$e->getMessage()}");
        }

        try {
            $this->redis = Config::getRedis();
            $this->logger->info("Redis initialized successfully");
        } catch (\Exception $e) {
            $this->logger->warning("Redis unavailable, caching disabled: {$e->getMessage()}");
            $this->redis = null;
        }
    }

    public function create(array $user): string {
        $result = $this->collection->insertOne($user);
        $userId = (string) $result->getInsertedId();
        
        // Cache user data
        if ($this->redis) {
            try {
                $this->redis->setex("user:$userId", 3600, json_encode($user));
                $this->logger->info("Cached user $userId in Redis");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to cache user $userId: {$e->getMessage()}");
            }
        }
        
        return $userId;
    }

    public function findById(string $id): ?array {
        $cacheKey = "user:$id";
        if ($this->redis) {
            try {
                $cached = $this->redis->get($cacheKey);
                if ($cached) {
                    $this->logger->info("Cache hit for user $id");
                    return json_decode($cached, true);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Redis cache error for user $id: {$e->getMessage()}");
            }
        }

        $user = $this->collection->findOne(['_id' => new ObjectId($id)]);
        if ($user) {
            if ($this->redis) {
                try {
                    $this->redis->setex($cacheKey, 3600, json_encode($user));
                    $this->logger->info("Cached user $id in Redis");
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to cache user $id: {$e->getMessage()}");
                }
            }
            return (array) $user;
        }
        return null;
    }

    public function findByPhone(string $phone): ?array {
        $user = $this->collection->findOne(['phone' => $phone]);
        if ($user) {
            $cacheKey = "user:{$user['_id']}";
            if ($this->redis) {
                try {
                    $this->redis->setex($cacheKey, 3600, json_encode($user));
                    $this->logger->info("Cached user by phone $phone in Redis");
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to cache user by phone $phone: {$e->getMessage()}");
                }
            }
            return (array) $user;
        }
        return null;
    }

    public function update(string $id, array $updates): void {
        $this->collection->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $updates]
        );
        if ($this->redis) {
            try {
                $this->redis->del("user:$id");
                $this->logger->info("Cleared cache for user $id");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to clear cache for user $id: {$e->getMessage()}");
            }
        }
    }

    public function delete(string $id): void {
        $this->collection->deleteOne(['_id' => new ObjectId($id)]);
        if ($this->redis) {
            try {
                $this->redis->del("user:$id");
                $this->logger->info("Cleared cache for user $id");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to clear cache for user $id: {$e->getMessage()}");
            }
        }
    }

    public function queueNotification(string $userId, string $message): void {
        $rabbitMQ = Config::getRabbitMQ();
        if (!$rabbitMQ) {
            $this->logger->debug("RabbitMQ unavailable, skipping notification for user $userId");
            return;
        }

        try {
            $channel = $rabbitMQ->channel();
            $channel->queue_declare('notifications', false, true, false, false);
            
            $msg = new AMQPMessage(json_encode([
                'user_id' => $userId,
                'message' => $message
            ]));
            $channel->basic_publish($msg, '', 'notifications');
            
            $this->logger->info("Queued notification for user $userId: $message");
            $channel->close();
            $rabbitMQ->close();
        } catch (\Exception $e) {
            $this->logger->warning("Failed to queue notification for user $userId: {$e->getMessage()}");
        }
    }
}
?>