<?php
namespace CampChat\Models;

use CampChat\Config\Config;
use MongoDB\BSON\ObjectId;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Bot {
    private $collection;
    private $redis;
    private $logger;

    public function __construct() {
        $this->collection = Config::getMongoDB()->selectDatabase('campchat')->selectCollection('bots');
        $this->logger = new Logger('campchat-bot');
        try {
            $logFile = __DIR__ . '/../../logs/bot.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize bot logger: {$e->getMessage()}");
        }

        try {
            $this->redis = Config::getRedis();
            $this->logger->info("Redis initialized for bots");
        } catch (\Exception $e) {
            $this->logger->warning("Redis unavailable, caching disabled: {$e->getMessage()}");
            $this->redis = null;
        }
    }

    public function create(string $name, string $creatorId, array $commands = [], ?string $webhookUrl = null): string {
        if ($webhookUrl && !$this->isValidWebhookUrl($webhookUrl)) {
            throw new \Exception("Invalid webhook URL");
        }

        $bot = [
            'name' => $name,
            'creator_id' => $creatorId,
            'commands' => $commands,
            'groups' => [],
            'webhook_url' => $webhookUrl,
            'created_at' => new \DateTime()
        ];
        $result = $this->collection->insertOne($bot);
        $botId = (string)$result->getInsertedId();

        if ($this->redis) {
            try {
                $this->redis->set("bot:$botId", json_encode($bot));
                $this->redis->expire("bot:$botId", 3600);
                $this->logger->info("Cached bot $botId");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to cache bot $botId: {$e->getMessage()}");
            }
        }

        return $botId;
    }

    public function findById(string $id): ?array {
        if ($this->redis) {
            try {
                $cached = $this->redis->get("bot:$id");
                if ($cached) {
                    $this->logger->info("Cache hit for bot $id");
                    return json_decode($cached, true);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Redis cache error for bot $id: {$e->getMessage()}");
            }
        }

        $bot = $this->collection->findOne(['_id' => new ObjectId($id)]);
        if ($bot && $this->redis) {
            try {
                $this->redis->set("bot:$id", json_encode($bot));
                $this->redis->expire("bot:$id", 3600);
                $this->logger->info("Cached bot $id after DB fetch");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to cache bot $id: {$e->getMessage()}");
            }
        }

        return $bot ? (array)$bot : null;
    }

    public function updateCommands(string $id, array $commands, string $creatorId): void {
        $bot = $this->findById($id);
        if (!$bot || $bot['creator_id'] !== $creatorId) {
            throw new \Exception("Invalid bot or unauthorized creator");
        }
        $this->collection->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => ['commands' => $commands]]
        );
        $this->clearCache($id);
    }

    public function setWebhook(string $id, ?string $webhookUrl, string $creatorId): void {
        $bot = $this->findById($id);
        if (!$bot || $bot['creator_id'] !== $creatorId) {
            throw new \Exception("Invalid bot or unauthorized creator");
        }
        if ($webhookUrl && !$this->isValidWebhookUrl($webhookUrl)) {
            throw new \Exception("Invalid webhook URL");
        }
        $this->collection->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => ['webhook_url' => $webhookUrl]]
        );
        $this->clearCache($id);
        $this->logger->info("Webhook set for bot $id: " . ($webhookUrl ?? 'null'));
    }

    public function addToGroup(string $botId, string $groupId): void {
        $this->collection->updateOne(
            ['_id' => new ObjectId($botId)],
            ['$addToSet' => ['groups' => $groupId]]
        );
        $this->clearCache($botId);
    }

    public function removeFromGroup(string $botId, string $groupId): void {
        $this->collection->updateOne(
            ['_id' => new ObjectId($botId)],
            ['$pull' => ['groups' => $groupId]]
        );
        $this->clearCache($botId);
    }

    private function isValidWebhookUrl(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https:\/\//', $url);
    }

    private function clearCache(string $botId): void {
        if ($this->redis) {
            try {
                $this->redis->del("bot:$botId");
                $this->logger->info("Cleared cache for bot $botId");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to clear cache for bot $botId: {$e->getMessage()}");
            }
        }
    }
}
?>