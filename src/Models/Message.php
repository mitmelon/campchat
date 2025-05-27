<?php
namespace CampChat\Models;

use CampChat\Config\Config;
use MongoDB\BSON\ObjectId;
use PhpAmqpLib\Message\AMQPMessage;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Message {
    private $collection;
    private $redis;
    private $logger;

    public function __construct() {
        $this->collection = Config::getMongoDB()->selectDatabase('campchat')->selectCollection('messages');
        $this->logger = new Logger('campchat-message');
        try {
            $logFile = __DIR__ . '/../../logs/message.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize message logger: {$e->getMessage()}");
        }

        try {
            $this->redis = Config::getRedis();
            $this->logger->info("Redis initialized successfully");
        } catch (\Exception $e) {
            $this->logger->warning("Redis unavailable, caching disabled: {$e->getMessage()}");
            $this->redis = null;
        }
    }

    public function create(array $message): string {
        $result = $this->collection->insertOne($message);
        $messageId = (string)$result->getInsertedId();

        if ($this->redis) {
            try {
                $cacheKey = isset($message['group_id']) 
                    ? "group_messages:{$message['group_id']}"
                    : "messages:{$message['sender_id']}:{$message['recipient_id']}";
                $this->redis->lPush($cacheKey, json_encode($message));
                $this->redis->lTrim($cacheKey, 0, 99);
                $this->redis->expire($cacheKey, 3600);
                $this->logger->info("Cached message $messageId in Redis");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to cache message $messageId: {$e->getMessage()}");
            }
        }

        if (isset($message['group_id'])) {
            $this->queueGroupNotification($message['group_id'], $messageId, $message['sender_id']);
        } else {
            $this->queueNotification($message['recipient_id'], $messageId, $message['sender_id']);
        }

        return $messageId;
    }

    public function findById(string $id): ?array {
        $message = $this->collection->findOne(['_id' => new ObjectId($id)]);
        return $message ? (array)$message : null;
    }

    public function update(string $id, array $updates): void {
        $this->collection->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $updates]
        );

        if ($this->redis) {
            try {
                $message = $this->findById($id);
                $cacheKey = isset($message['group_id'])
                    ? "group_messages:{$message['group_id']}"
                    : "messages:{$message['sender_id']}:{$message['recipient_id']}";
                $this->redis->del($cacheKey);
                $this->logger->info("Cleared cache for message $id");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to clear cache for message $id: {$e->getMessage()}");
            }
        }
    }

    public function delete(string $id): void {
        $message = $this->findById($id);
        $this->collection->deleteOne(['_id' => new ObjectId($id)]);

        if ($this->redis && $message) {
            try {
                $cacheKey = isset($message['group_id'])
                    ? "group_messages:{$message['group_id']}"
                    : "messages:{$message['sender_id']}:{$message['recipient_id']}";
                $this->redis->del($cacheKey);
                $this->logger->info("Cleared cache for message $id");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to clear cache for message $id: {$e->getMessage()}");
            }
        }
    }

    public function addReaction(string $messageId, string $userId, string $reaction): void {
        $this->collection->updateOne(
            ['_id' => new ObjectId($messageId)],
            ['$push' => ['reactions' => ['user_id' => $userId, 'reaction' => $reaction]]]
        );

        if ($this->redis) {
            try {
                $message = $this->findById($messageId);
                $cacheKey = isset($message['group_id'])
                    ? "group_messages:{$message['group_id']}"
                    : "messages:{$message['sender_id']}:{$message['recipient_id']}";
                $this->redis->del($cacheKey);
                $this->logger->info("Cleared cache for message $messageId after reaction");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to clear cache for message $messageId: {$e->getMessage()}");
            }
        }
    }

    public function getHistory(string $senderId, string $recipientId): array {
        $cacheKey = "messages:$senderId:$recipientId";
        if ($this->redis) {
            try {
                $cached = $this->redis->lRange($cacheKey, 0, -1);
                if ($cached) {
                    $this->logger->info("Cache hit for messages between $senderId and $recipientId");
                    return array_map('json_decode', $cached, array_fill(0, count($cached), true));
                }
            } catch (\Exception $e) {
                $this->logger->warning("Redis cache error for messages: {$e->getMessage()}");
            }
        }

        $messages = $this->collection->find([
            '$or' => [
                ['sender_id' => $senderId, 'recipient_id' => $recipientId],
                ['sender_id' => $recipientId, 'recipient_id' => $senderId]
            ]
        ])->toArray();

        if ($this->redis && $messages) {
            try {
                foreach ($messages as $message) {
                    $this->redis->lPush($cacheKey, json_encode($message));
                }
                $this->redis->lTrim($cacheKey, 0, 99);
                $this->redis->expire($cacheKey, 3600);
                $this->logger->info("Cached messages between $senderId and $recipientId");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to cache messages: {$e->getMessage()}");
            }
        }

        return $messages;
    }

    public function getGroupHistory(string $groupId): array {
        $cacheKey = "group_messages:$groupId";
        if ($this->redis) {
            try {
                $cached = $this->redis->lRange($cacheKey, 0, -1);
                if ($cached) {
                    $this->logger->info("Cache hit for group messages $groupId");
                    return array_map('json_decode', $cached, array_fill(0, count($cached), true));
                }
            } catch (\Exception $e) {
                $this->logger->warning("Redis cache error for group messages $groupId: {$e->getMessage()}");
            }
        }

        $messages = $this->collection->find(['group_id' => $groupId])->toArray();

        if ($this->redis && $messages) {
            try {
                foreach ($messages as $message) {
                    $this->redis->lPush($cacheKey, json_encode($message));
                }
                $this->redis->lTrim($cacheKey, 0, 99);
                $this->redis->expire($cacheKey, 3600);
                $this->logger->info("Cached group messages for $groupId");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to cache group messages for $groupId: {$e->getMessage()}");
            }
        }

        return $messages;
    }

    public function getUpdates(int $offset, int $limit, int $timeout): array {
        $start = microtime(true);
        $updates = [];

        while ((microtime(true) - $start) < $timeout) {
            $newMessages = $this->collection->find([
                'created_at' => ['$gt' => new \DateTime("@$offset")]
            ], ['limit' => $limit])->toArray();

            if ($newMessages) {
                foreach ($newMessages as $message) {
                    $updates[] = [
                        'update_id' => (string)$message['_id'],
                        'message' => [
                            'id' => (string)$message['_id'],
                            'sender_id' => $message['sender_id'],
                            'recipient_id' => $message['recipient_id'] ?? null,
                            'group_id' => $message['group_id'] ?? null,
                            'type' => $message['type'],
                            'content' => $message['content'] ?? null,
                            'media_url' => $message['media_url'] ?? null,
                            'location' => $message['location'] ?? null,
                            'contact' => $message['contact'] ?? null,
                            'caption' => $message['caption'] ?? null,
                            'entities' => $message['entities'] ?? null,
                            'forwarded_from' => $message['forwarded_from'] ?? null,
                            'reactions' => $message['reactions'] ?? [],
                            'created_at' => $message['created_at']->format('c')
                        ]
                    ];
                }
                break;
            }
            usleep(500000);
        }

        return $updates;
    }

    public function queueNotification(string $recipientId, string $messageId, string $senderId): void {
        $rabbitMQ = Config::getRabbitMQ();
        if (!$rabbitMQ) {
            $this->logger->debug("RabbitMQ unavailable, skipping notification for message $messageId");
            return;
        }

        try {
            $channel = $rabbitMQ->channel();
            $channel->queue_declare('message_notifications', false, true, false, false);

            $msg = new AMQPMessage(json_encode([
                'recipient_id' => $recipientId,
                'message_id' => $messageId,
                'sender_id' => $senderId
            ]));
            $channel->basic_publish($msg, '', 'message_notifications');

            $this->logger->info("Queued notification for message $messageId to user $recipientId");
            $channel->close();
            $rabbitMQ->close();
        } catch (\Exception $e) {
            $this->logger->warning("Failed to queue notification for message $messageId: {$e->getMessage()}");
        }
    }

    public function queueGroupNotification(string $groupId, string $messageId, string $senderId): void {
        $rabbitMQ = Config::getRabbitMQ();
        if (!$rabbitMQ) {
            $this->logger->debug("RabbitMQ unavailable, skipping group notification for message $messageId");
            return;
        }

        try {
            $channel = $rabbitMQ->channel();
            $channel->queue_declare('group_notifications', false, true, false, false);

            $msg = new AMQPMessage(json_encode([
                'group_id' => $groupId,
                'message_id' => $messageId,
                'sender_id' => $senderId
            ]));
            $channel->basic_publish($msg, '', 'group_notifications');

            $this->logger->info("Queued group notification for message $messageId in group $groupId");
            $channel->close();
            $rabbitMQ->close();
        } catch (\Exception $e) {
            $this->logger->warning("Failed to queue group notification for message $messageId: {$e->getMessage()}");
        }
    }
}
?>