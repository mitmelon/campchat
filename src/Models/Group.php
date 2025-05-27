<?php
namespace CampChat\Models;

use CampChat\Config\Config;
use MongoDB\BSON\ObjectId;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Group {
    private $collection;
    private $redis;
    private $logger;

    public function __construct() {
        $this->collection = Config::getMongoDB()->selectDatabase('campchat')->selectCollection('groups');
        $this->logger = new Logger('campchat-group');
        try {
            $logFile = __DIR__ . '/../../logs/group.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize group logger: {$e->getMessage()}");
        }

        try {
            $this->redis = Config::getRedis();
            $this->logger->info("Redis initialized for groups");
        } catch (\Exception $e) {
            $this->logger->warning("Redis unavailable, caching disabled: {$e->getMessage()}");
            $this->redis = null;
        }
    }

    public function create(array $group): string {
        $group['created_at'] = new \DateTime();
        $group['members'] = [$group['creator_id']];
        $group['admins'] = [$group['creator_id']];
        $group['permissions'] = [
            'locked' => false,
            'allow_member_messages' => true,
            'allow_member_invites' => false
        ];
        $group['description'] = $group['description'] ?? '';
        $group['icon_url'] = $group['icon_url'] ?? null;
        $result = $this->collection->insertOne($group);
        $groupId = (string)$result->getInsertedId();

        if ($this->redis) {
            try {
                $this->redis->set("group:$groupId", json_encode($group));
                $this->redis->expire("group:$groupId", 3600);
                $this->logger->info("Cached group $groupId");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to cache group $groupId: {$e->getMessage()}");
            }
        }

        return $groupId;
    }

    public function findById(string $id): ?array {
        if ($this->redis) {
            try {
                $cached = $this->redis->get("group:$id");
                if ($cached) {
                    $this->logger->info("Cache hit for group $id");
                    return json_decode($cached, true);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Redis cache error for group $id: {$e->getMessage()}");
            }
        }

        $group = $this->collection->findOne(['_id' => new ObjectId($id)]);
        if ($group && $this->redis) {
            try {
                $this->redis->set("group:$id", json_encode($group));
                $this->redis->expire("group:$id", 3600);
                $this->logger->info("Cached group $id after DB fetch");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to cache group $id: {$e->getMessage()}");
            }
        }

        return $group ? (array)$group : null;
    }

    public function addMember(string $groupId, string $userId): void {
        $this->collection->updateOne(
            ['_id' => new ObjectId($groupId)],
            ['$addToSet' => ['members' => $userId]]
        );
        $this->clearCache($groupId);
    }

    public function removeMember(string $groupId, string $userId, string $adminId): void {
        $group = $this->findById($groupId);
        if ($userId === $group['creator_id']) {
            throw new \Exception("Creator cannot be removed");
        }
        if (!in_array($adminId, $group['admins'])) {
            throw new \Exception("Only admins can remove members");
        }
        $this->collection->updateOne(
            ['_id' => new ObjectId($groupId)],
            ['$pull' => ['members' => $userId, 'admins' => $userId]]
        );
        $this->clearCache($groupId);
    }

    public function quitGroup(string $groupId, string $userId): void {
        $group = $this->findById($groupId);
        if ($userId === $group['creator_id'] && count($group['members']) > 1) {
            throw new \Exception("Creator must assign new creator before quitting");
        }
        $this->collection->updateOne(
            ['_id' => new ObjectId($groupId)],
            ['$pull' => ['members' => $userId, 'admins' => $userId]]
        );
        $this->clearCache($groupId);
    }

    public function addAdmin(string $groupId, string $userId, string $adminId): void {
        $group = $this->findById($groupId);
        if (!in_array($adminId, $group['admins'])) {
            throw new \Exception("Only admins can add admins");
        }
        if (!in_array($userId, $group['members'])) {
            throw new \Exception("User must be a member");
        }

        $this->collection->updateOne(
            ['_id' => new ObjectId($groupId)],
            ['$addToSet' => ['admins' => $userId]]
        );
        $this->clearCache($groupId);
    }

    public function removeAdmin(string $groupId, string $userId, string $adminId): void {
        $group = $this->findById($groupId);
        if ($userId === $group['creator_id']) {
            throw new \Exception("Creator admin cannot be removed");
        }
        if (!in_array($adminId, $group['admins'])) {
            throw new \Exception("Only admins can remove admins");
        }
        $this->collection->updateOne(
            ['_id' => new ObjectId($groupId)],
            ['$pull' => ['admins' => $userId]]
        );
        $this->clearCache($groupId);
    }

    public function updatePermissions(string $groupId, array $permissions, string $adminId): void {
        $group = $this->findById($groupId);
        if (!in_array($adminId, $group['admins'])) {
            throw new \Exception("Only admins can update permissions");
        }
        $this->collection->updateOne(
            ['_id' => new ObjectId($groupId)],
            ['$set' => ['permissions' => $permissions]]
        );
        $this->clearCache($groupId);
    }

    public function updateDetails(string $groupId, array $updates, string $adminId): void {
        $group = $this->findById($groupId);
        if (!in_array($adminId, $group['admins'])) {
            throw new \Exception("Only admins can update group details");
        }
        $this->collection->updateOne(
            ['_id' => new ObjectId($groupId)],
            ['$set' => $updates]
        );
        $this->clearCache($groupId);
    }

    public function delete(string $groupId, string $adminId): void {
        $group = $this->findById($groupId);
        if ($adminId !== $group['creator_id']) {
            throw new \Exception("Only creator can delete the group");
        }
        $this->collection->deleteOne(['_id' => new ObjectId($groupId)]);
        $this->clearCache($groupId);
    }

    private function clearCache(string $groupId): void {
        if ($this->redis) {
            try {
                $this->redis->del("group:$groupId");
                $this->logger->info("Cleared cache for group $groupId");
            } catch (\Exception $e) {
                $this->logger->warning("Failed to clear cache for group $groupId: {$e->getMessage()}");
            }
        }
    }
}
?>