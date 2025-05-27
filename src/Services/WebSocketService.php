<?php
namespace CampChat\Services;

use CampChat\Models\User;
use CampChat\Models\Message;
use CampChat\Models\Group;
use Monolog\Logger;

class WebSocketService {
    private $connections = [];
    private $userModel;
    private $messageModel;
    private $groupModel;
    private $encryptionService;
    private $logger;

    public function __construct(Logger $logger) {
        $this->userModel = new User();
        $this->messageModel = new Message();
        $this->groupModel = new Group();
        $this->encryptionService = new EncryptionService();
        $this->logger = $logger;
    }

    public function onConnect($connection) {
        $connection->userId = null;
        $this->connections[$connection->id] = $connection;
        $this->logger->info("WebSocket connection established: {$connection->id}");
    }

    public function onMessage($connection, string $data) {
        $payload = json_decode($data, true);
        if (!$payload) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Invalid JSON']));
            return;
        }

        switch ($payload['action'] ?? '') {
            case 'authenticate':
                $this->authenticate($connection, $payload);
                break;
            case 'send_message':
                $this->sendMessage($connection, $payload);
                break;
            case 'send_group_message':
                $this->sendGroupMessage($connection, $payload);
                break;
            case 'edit_message':
                $this->editMessage($connection, $payload);
                break;
            case 'delete_message':
                $this->deleteMessage($connection, $payload);
                break;
            case 'set_reaction':
                $this->setReaction($connection, $payload);
                break;
            default:
                $connection->send(json_encode(['ok' => false, 'error' => 'Invalid action']));
        }
    }

    public function onClose($connection) {
        unset($this->connections[$connection->id]);
        $this->logger->info("WebSocket connection closed: {$connection->id}");
    }

    private function authenticate($connection, array $payload) {
        if (!isset($payload['token'])) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Token required']));
            return;
        }

        $user = $this->userModel->findByToken($payload['token']);
        if (!$user) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Invalid token']));
            return;
        }

        $connection->userId = (string)$user['_id'];
        $this->logger->info("User {$connection->userId} authenticated on connection {$connection->id}");
        $connection->send(json_encode(['ok' => true, 'result' => 'Authenticated']));
    }

    private function sendMessage($connection, array $payload) {
        if (!$connection->userId) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Not authenticated']));
            return;
        }

        if (!isset($payload['recipient_id'], $payload['type'])) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Missing recipient_id or type']));
            return;
        }

        $recipient = $this->userModel->findById($payload['recipient_id']);
        if (!$recipient) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Invalid recipient']));
            return;
        }

        $sender = $this->userModel->findById($connection->userId);
        $message = [
            'sender_id' => $connection->userId,
            'recipient_id' => $payload['recipient_id'],
            'type' => $payload['type'],
            'created_at' => new \DateTime(),
            'reply_to_message_id' => $payload['reply_to_message_id'] ?? null,
            'caption' => $payload['caption'] ?? null,
            'entities' => $payload['entities'] ?? null
        ];

        if ($payload['type'] === 'text' && isset($payload['content'])) {
            $message['content'] = $this->encryptionService->encryptMessage(
                $payload['content'],
                $recipient['public_key'],
                $sender['private_key']
            );
        } elseif (in_array($payload['type'], ['photo', 'video', 'audio', 'document', 'animation', 'voice']) && isset($payload['media_url'])) {
            $message['media_url'] = $payload['media_url'];
        } elseif ($payload['type'] === 'location' && isset($payload['latitude'], $payload['longitude'])) {
            $message['location'] = [
                'latitude' => (float)$payload['latitude'],
                'longitude' => (float)$payload['longitude']
            ];
        } elseif ($payload['type'] === 'contact' && isset($payload['phone_number'], $payload['first_name'])) {
            $message['contact'] = [
                'phone_number' => $payload['phone_number'],
                'first_name' => $payload['first_name'],
                'last_name' => $payload['last_name'] ?? null
            ];
        } else {
            $connection->send(json_encode(['ok' => false, 'error' => 'Invalid message data']));
            return;
        }

        $messageId = $this->messageModel->create($message);
        $this->broadcastMessage($message, $messageId);
        $connection->send(json_encode(['ok' => true, 'result' => ['id' => (string)$messageId, 'media_url' => $message['media_url'] ?? null]]));
        $this->logger->info("Message $messageId sent via WebSocket from {$connection->userId} to {$payload['recipient_id']}");
    }

    private function sendGroupMessage($connection, array $payload) {
        if (!$connection->userId) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Not authenticated']));
            return;
        }

        if (!isset($payload['group_id'], $payload['type'])) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Missing group_id or type']));
            return;
        }

        $group = $this->groupModel->findById($payload['group_id']);
        if (!$group) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Group not found']));
            return;
        }

        if (!in_array($connection->userId, $group['members'])) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Not a group member']));
            return;
        }

        $message = [
            'sender_id' => $connection->userId,
            'group_id' => $payload['group_id'],
            'type' => $payload['type'],
            'created_at' => new \DateTime(),
            'reply_to_message_id' => $payload['reply_to_message_id'] ?? null,
            'caption' => $payload['caption'] ?? null,
            'entities' => $payload['entities'] ?? null
        ];

        if ($payload['type'] === 'text' && isset($payload['content'])) {
            $message['content'] = $this->encryptionService->encryptGroupMessage(
                $payload['content'],
                $payload['group_id']
            );
        } elseif (in_array($payload['type'], ['photo', 'video', 'audio', 'document', 'animation', 'voice']) && isset($payload['media_url'])) {
            $message['media_url'] = $payload['media_url'];
        } elseif ($payload['type'] === 'location' && isset($payload['latitude'], $payload['longitude'])) {
            $message['location'] = [
                'latitude' => (float)$payload['latitude'],
                'longitude' => (float)$payload['longitude']
            ];
        } elseif ($payload['type'] === 'contact' && isset($payload['phone_number'], $payload['first_name'])) {
            $message['contact'] = [
                'phone_number' => $payload['phone_number'],
                'first_name' => $payload['first_name'],
                'last_name' => $payload['last_name'] ?? null
            ];
        } else {
            $connection->send(json_encode(['ok' => false, 'error' => 'Invalid message data']));
            return;
        }

        $messageId = $this->messageModel->create($message);
        $this->broadcastGroupMessage($message, $messageId);
        $connection->send(json_encode(['ok' => true, 'result' => ['id' => (string)$messageId, 'media_url' => $message['media_url'] ?? null]]));
        $this->logger->info("Group message $messageId sent via WebSocket from {$connection->userId} to group {$payload['group_id']}");
    }

    private function editMessage($connection, array $payload) {
        if (!$connection->userId) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Not authenticated']));
            return;
        }

        if (!isset($payload['message_id'])) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Missing message_id']));
            return;
        }

        $message = $this->messageModel->findById($payload['message_id']);
        if (!$message || $message['sender_id'] !== $connection->userId) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Invalid message or permission']));
            return;
        }

        $updates = [];
        if (isset($message['group_id'])) {
            $group = $this->groupModel->findById($message['group_id']);
            if (!$group) {
                $connection->send(json_encode(['ok' => false, 'error' => 'Group not found']));
                return;
            }
            if ($payload['type'] === 'text' && isset($payload['content'])) {
                $updates['content'] = $this->encryptionService->encryptGroupMessage(
                    $payload['content'],
                    $message['group_id']
                );
            }
        } else {
            $recipient = $this->userModel->findById($message['recipient_id']);
            $sender = $this->userModel->findById($connection->userId);
            if ($payload['type'] === 'text' && isset($payload['content'])) {
                $updates['content'] = $this->encryptionService->encryptMessage(
                    $payload['content'],
                    $recipient['public_key'],
                    $sender['private_key']
                );
            }
        }

        if (isset($payload['caption'])) {
            $updates['caption'] = $payload['caption'];
        }
        if (isset($payload['media_url']) && in_array($message['type'], ['photo', 'video', 'audio', 'document', 'animation', 'voice'])) {
            $updates['media_url'] = $payload['media_url'];
        }

        if (empty($updates)) {
            $connection->send(json_encode(['ok' => false, 'error' => 'No valid updates']));
            return;
        }

        $this->messageModel->update($payload['message_id'], $updates);
        $this->broadcastUpdate($message, $payload['message_id'], 'edit');
        $connection->send(json_encode(['ok' => true, 'media_url' => $updates['media_url'] ?? null]));
        $this->logger->info("Message {$payload['message_id']} edited via WebSocket");
    }

    private function deleteMessage($connection, array $payload) {
        if (!$connection->userId) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Not authenticated']));
            return;
        }

        if (!isset($payload['message_id'])) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Missing message_id']));
            return;
        }

        $message = $this->messageModel->findById($payload['message_id']);
        if (!$message || $message['sender_id'] !== $connection->userId) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Invalid message or permission']));
            return;
        }

        $this->messageModel->delete($payload['message_id']);
        $this->broadcastUpdate($message, $payload['message_id'], 'delete');
        $connection->send(json_encode(['ok' => true]));
        $this->logger->info("Message {$payload['message_id']} deleted via WebSocket");
    }

    private function setReaction($connection, array $payload) {
        if (!$connection->userId) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Not authenticated']));
            return;
        }

        if (!isset($payload['message_id'], $payload['reaction'])) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Missing message_id or reaction']));
            return;
        }

        $message = $this->messageModel->findById($payload['message_id']);
        if (!$message) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Message not found']));
            return;
        }

        $allowedReactions = ['👍', '👎', '❤️', '🔥', '🎉'];
        if (!in_array($payload['reaction'], $allowedReactions)) {
            $connection->send(json_encode(['ok' => false, 'error' => 'Invalid reaction']));
            return;
        }

        $this->messageModel->addReaction($payload['message_id'], $connection->userId, $payload['reaction']);
        $this->broadcastUpdate($message, $payload['message_id'], 'reaction', ['reaction' => $payload['reaction'], 'user_id' => $connection->userId]);
        $connection->send(json_encode(['ok' => true]));
        $this->logger->info("Reaction {$payload['reaction']} set on message {$payload['message_id']} via WebSocket");
    }

    private function broadcastMessage(array $message, string $messageId) {
        $recipientId = $message['recipient_id'];
        $payload = [
            'ok' => true,
            'result' => [
                'id' => $messageId,
                'sender_id' => $message['sender_id'],
                'recipient_id' => $recipientId,
                'type' => $message['type'],
                'content' => $message['content'] ?? null,
                'media_url' => $message['media_url'] ?? null,
                'location' => $message['location'] ?? null,
                'contact' => $message['contact'] ?? null,
                'caption' => $message['caption'] ?? null,
                'entities' => $message['entities'] ?? null,
                'forwarded_from' => $message['forwarded_from'] ?? null,
                'created_at' => $message['created_at']->format('c')
            ]
        ];

        foreach ($this->connections as $conn) {
            if ($conn->userId === $recipientId || $conn->userId === $message['sender_id']) {
                $conn->send(json_encode($payload));
            }
        }
    }

    private function broadcastGroupMessage(array $message, string $messageId) {
        $groupId = $message['group_id'];
        $group = $this->groupModel->findById($groupId);
        if (!$group) {
            return;
        }

        $payload = [
            'ok' => true,
            'result' => [
                'id' => $messageId,
                'sender_id' => $message['sender_id'],
                'group_id' => $groupId,
                'type' => $message['type'],
                'content' => $message['content'] ?? null,
                'media_url' => $message['media_url'] ?? null,
                'location' => $message['location'] ?? null,
                'contact' => $message['contact'] ?? null,
                'caption' => $message['caption'] ?? null,
                'entities' => $message['entities'] ?? null,
                'forwarded_from' => $message['forwarded_from'] ?? null,
                'created_at' => $message['created_at']->format('c')
            ]
        ];

        foreach ($this->connections as $conn) {
            if (in_array($conn->userId, $group['members'])) {
                $conn->send(json_encode($payload));
            }
        }
    }

    private function broadcastUpdate(array $message, string $messageId, string $action, array $extra = []) {
        $payload = [
            'ok' => true,
            'action' => $action,
            'result' => array_merge([
                'id' => $messageId,
                'sender_id' => $message['sender_id']
            ], $extra)
        ];

        if (isset($message['group_id'])) {
            $group = $this->groupModel->findById($message['group_id']);
            if ($group) {
                $payload['result']['group_id'] = $message['group_id'];
                foreach ($this->connections as $conn) {
                    if (in_array($conn->userId, $group['members'])) {
                        $conn->send(json_encode($payload));
                    }
                }
            }
        } else {
            $recipientId = $message['recipient_id'];
            $senderId = $message['sender_id'];
            $payload['result']['recipient_id'] = $recipientId;
            foreach ($this->connections as $conn) {
                if ($conn->userId === $recipientId || $conn->userId === $senderId) {
                    $conn->send(json_encode($payload));
                }
            }
        }
    }
}
?>