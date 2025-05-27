<?php
namespace CampChat\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CampChat\Models\Message;
use CampChat\Models\User;
use CampChat\Models\Group;
use CampChat\Models\Bot;
use CampChat\Services\EncryptionService;
use CampChat\Services\StorageService;
use CampChat\Services\WebhookService;
use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class MessageController {
    private $messageModel;
    private $userModel;
    private $groupModel;
    private $botModel;
    private $encryptionService;
    private $storageService;
    private $webhookService;
    private $logger;

    public function __construct() {
        $this->messageModel = new Message();
        $this->userModel = new User();
        $this->groupModel = new Group();
        $this->botModel = new Bot();
        $this->encryptionService = new EncryptionService();
        $this->storageService = new StorageService();
        $this->webhookService = new WebhookService();
        $this->logger = new Logger('campchat-message-controller');
        try {
            $logFile = __DIR__ . '/../../logs/message.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize message controller logger: {$e->getMessage()}");
        }
    }

    public function send(Request $request, string $type): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $files = $request->getUploadedFiles();
            $this->logger->info("SendMessage Request: type=$type, payload=" . json_encode($data));

            if (!isset($data['sender_id']) || (!isset($data['recipient_id']) && !isset($data['group_id']))) {
                throw new Exception("Missing required fields: sender_id and either recipient_id or group_id");
            }

            $sender = $this->userModel->findById($data['sender_id']);
            if (!$sender) {
                throw new Exception("Invalid sender");
            }

            $message = [
                'sender_id' => $data['sender_id'],
                'type' => $type,
                'created_at' => new \DateTime(),
                'reply_to_message_id' => $data['reply_to_message_id'] ?? null,
                'caption' => $data['caption'] ?? null,
                'entities' => $data['entities'] ?? null
            ];

            if (isset($data['group_id'])) {
                $group = $this->groupModel->findById($data['group_id']);
                if (!$group) {
                    throw new Exception("Group not found");
                }
                if (!in_array($data['sender_id'], $group['members'])) {
                    throw new Exception("Sender is not a group member");
                }
                if ($group['permissions']['locked'] && !in_array($data['sender_id'], $group['admins'])) {
                    throw new Exception("Group is locked, only admins can send messages");
                }
                if (!$group['permissions']['allow_member_messages'] && !in_array($data['sender_id'], $group['admins'])) {
                    throw new Exception("Only admins can send messages in this group");
                }
                $message['group_id'] = $data['group_id'];
            } else {
                $recipient = $this->userModel->findById($data['recipient_id']);
                if (!$recipient) {
                    throw new Exception("Invalid recipient");
                }
                $message['recipient_id'] = $data['recipient_id'];
            }

            if (isset($data['reply_to_message_id'])) {
                $replyMessage = $this->messageModel->findById($data['reply_to_message_id']);
                if (!$replyMessage) {
                    throw new Exception("Replied-to message not found");
                }
                if ($message['group_id'] && $replyMessage['group_id'] !== $message['group_id']) {
                    throw new Exception("Replied-to message is not in the same group");
                }
                if ($message['recipient_id'] && ($replyMessage['sender_id'] !== $data['sender_id'] && $replyMessage['recipient_id'] !== $data['sender_id'])) {
                    throw new Exception("Replied-to message is not in the same chat");
                }
            }

            switch ($type) {
                case 'text':
                    if (!isset($data['content'])) {
                        throw new Exception("Missing content for text message");
                    }
                    if ($message['group_id']) {
                        $message['content'] = $this->encryptionService->encryptGroupMessage($data['content'], $message['group_id']);
                    } else {
                        $message['content'] = $this->encryptionService->encryptMessage(
                            $data['content'],
                            $recipient['public_key'],
                            $sender['private_key']
                        );
                    }
                    break;
                case 'photo':
                case 'video':
                case 'audio':
                case 'document':
                case 'animation':
                case 'voice':
                    if (!isset($files['media'])) {
                        throw new Exception("No media file uploaded");
                    }
                    $media = $files['media'];
                    if ($media->getError() !== UPLOAD_ERR_OK) {
                        throw new Exception("File upload error");
                    }
                    $allowedTypes = [
                        'photo' => ['image/png', 'image/jpeg'],
                        'video' => ['video/mp4'],
                        'audio' => ['audio/mpeg', 'audio/ogg'],
                        'document' => ['application/pdf', 'text/plain'],
                        'animation' => ['image/gif'],
                        'voice' => ['audio/ogg']
                    ];
                    if (!in_array($media->getClientMediaType(), $allowedTypes[$type])) {
                        throw new Exception("Invalid media type for $type");
                    }
                    $filename = uniqid("{$type}_") . '.' . pathinfo($media->getClientFilename(), PATHINFO_EXTENSION);
                    $message['media_url'] = $this->storageService->uploadFile($media, $filename);
                    break;
                case 'location':
                    if (!isset($data['latitude'], $data['longitude'])) {
                        throw new Exception("Missing latitude or longitude");
                    }
                    $message['location'] = [
                        'latitude' => (float)$data['latitude'],
                        'longitude' => (float)$data['longitude']
                    ];
                    break;
                case 'contact':
                    if (!isset($data['phone_number'], $data['first_name'])) {
                        throw new Exception("Missing phone_number or first_name");
                    }
                    $message['contact'] = [
                        'phone_number' => $data['phone_number'],
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'] ?? null
                    ];
                    break;
                default:
                    throw new Exception("Unsupported message type: $type");
            }

            $messageId = $this->messageModel->create($message);
            $this->logger->info("Message $messageId sent: type=$type, from={$data['sender_id']}");

            // Process bot commands or webhook for group messages
            if ($message['group_id']) {
                $this->processBotEvent($message, $data['content'] ?? null, $group);
            }

            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => ['id' => (string)$messageId, 'media_url' => $message['media_url'] ?? null]
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (Exception $e) {
            $this->logger->error("SendMessage Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    private function processBotEvent(array $message, ?string $content, array $group): void {
        if (!isset($group['bots']) || empty($group['bots'])) {
            return;
        }

        $event = [
            'event' => 'message',
            'group_id' => $message['group_id'],
            'user_id' => $message['sender_id']
        ];

        foreach ($group['bots'] as $botId) {
            $bot = $this->botModel->findById($botId);
            if (!$bot) {
                continue;
            }

            // Try webhook first
            if (isset($bot['webhook_url']) && $bot['webhook_url']) {
                $this->webhookService->sendEvent($botId, $bot['webhook_url'], $event, $message, $group);
                continue;
            }

            // Fallback to command-based response for text messages starting with /
            if ($message['type'] === 'text' && $content && strpos($content, '/') === 0) {
                $command = trim($content);
                $commandName = '';
                $targetBotId = null;

                if (preg_match('/^\/(\w+)(?:@(\w+))?/', $command, $matches)) {
                    $commandName = $matches[1];
                    $botName = $matches[2] ?? null;

                    if (!$botName || $bot['name'] === $botName) {
                        $targetBotId = $botId;
                    }
                }

                if ($targetBotId && isset($bot['commands'][$commandName])) {
                    $botMessage = [
                        'group_id' => $message['group_id'],
                        'bot_id' => $targetBotId,
                        'type' => 'text',
                        'content' => $this->encryptionService->encryptGroupMessage($bot['commands'][$commandName], $message['group_id']),
                        'created_at' => new \DateTime(),
                        'reply_to_message_id' => (string)$message['_id']
                    ];

                    if (isset($bot['commands']['keyboard'])) {
                        $botMessage['keyboard'] = $bot['commands']['keyboard'];
                    }

                    $botMessageId = $this->messageModel->create($botMessage);
                    $this->logger->info("Bot $targetBotId responded with message $botMessageId in group {$message['group_id']}");
                }
            }
        }
    }

    public function edit(Request $request, string $messageId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $files = $request->getUploadedFiles();
            $this->logger->info("EditMessage Request: messageId=$messageId, payload=" . json_encode($data));

            $message = $this->messageModel->findById($messageId);
            if (!$message) {
                throw new Exception("Invalid message");
            }

            if (isset($message['bot_id'])) {
                throw new Exception("Bot messages cannot be edited");
            }

            $sender = $this->userModel->findById($message['sender_id']);
            if (!$sender) {
                throw new Exception("Invalid sender");
            }

            $updates = [];
            if (isset($data['content']) && $message['type'] === 'text') {
                if (isset($message['group_id'])) {
                    $updates['content'] = $this->encryptionService->encryptGroupMessage($data['content'], $message['group_id']);
                } else {
                    $recipient = $this->userModel->findById($message['recipient_id']);
                    if (!$recipient) {
                        throw new Exception("Invalid recipient");
                    }
                    $updates['content'] = $this->encryptionService->encryptMessage(
                        $data['content'],
                        $recipient['public_key'],
                        $sender['private_key']
                    );
                }
            }
            if (isset($data['caption'])) {
                $updates['caption'] = $data['caption'];
            }
            if (isset($files['media']) && in_array($message['type'], ['photo', 'video', 'audio', 'document', 'animation', 'voice'])) {
                $media = $files['media'];
                if ($media->getError() !== UPLOAD_ERR_OK) {
                    throw new Exception("File upload error");
                }
                $allowedTypes = [
                    'photo' => ['image/png', 'image/jpeg'],
                    'video' => ['video/mp4'],
                    'audio' => ['audio/mpeg', 'audio/ogg'],
                    'document' => ['application/pdf', 'text/plain'],
                    'animation' => ['image/gif'],
                    'voice' => ['audio/ogg']
                ];
                if (!in_array($media->getClientMediaType(), $allowedTypes[$message['type']])) {
                    throw new Exception("Invalid media type for {$message['type']}");
                }
                $filename = uniqid("{$message['type']}_") . '.' . pathinfo($media->getClientFilename(), PATHINFO_EXTENSION);
                $updates['media_url'] = $this->storageService->uploadFile($media, $filename);
            }

            if (empty($updates)) {
                throw new Exception("No valid updates provided");
            }

            $this->messageModel->update($messageId, $updates);
            $this->logger->info("Message $messageId edited");

            $response->getBody()->write(json_encode(['ok' => true, 'media_url' => $updates['media_url'] ?? null]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("EditMessage Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function delete(Request $request, string $messageId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $this->logger->info("DeleteMessage Request: messageId=$messageId");

            $message = $this->messageModel->findById($messageId);
            if (!$message) {
                throw new Exception("Message not found");
            }

            if (isset($message['bot_id'])) {
                throw new Exception("Bot messages cannot be deleted");
            }

            $this->messageModel->delete($messageId);
            $this->logger->info("Message $messageId deleted");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("DeleteMessage Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function forward(Request $request): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("ForwardMessage Request: payload=" . json_encode($data));

            if (!isset($data['message_id'], $data['from_user_id']) || (!isset($data['to_user_id']) && !isset($data['to_group_id']))) {
                throw new Exception("Missing required fields: message_id, from_user_id, and either to_user_id or to_group_id");
            }

            $message = $this->messageModel->findById($data['message_id']);
            if (!$message) {
                throw new Exception("Message not found");
            }

            if (isset($message['bot_id'])) {
                throw new Exception("Bot messages cannot be forwarded");
            }

            $fromUser = $this->userModel->findById($data['from_user_id']);
            if (!$fromUser) {
                throw new Exception("Invalid from_user_id");
            }

            $forwardedMessage = [
                'sender_id' => $data['from_user_id'],
                'type' => $message['type'],
                'content' => $message['content'] ?? null,
                'media_url' => $message['media_url'] ?? null,
                'location' => $message['location'] ?? null,
                'contact' => $message['contact'] ?? null,
                'caption' => $message['caption'] ?? null,
                'entities' => $message['entities'] ?? null,
                'forward_from' => $message['sender_id'],
                'created_at' => new \DateTime()
            ];

            if (isset($data['to_group_id'])) {
                $group = $this->groupModel->findById($data['to_group_id']);
                if (!$group) {
                    throw new Exception("Group not found");
                }
                if (!in_array($data['from_user_id'], $group['members'])) {
                    throw new Exception("Sender is not a group member");
                }
                $forwardedMessage['group_id'] = $data['to_group_id'];
                if ($forwardedMessage['content']) {
                    $forwardedMessage['content'] = $this->encryptionService->encryptGroupMessage(
                        $this->encryptionService->decryptMessage(
                            $message['content'],
                            $fromUser['public_key'],
                            $fromUser['private_key']
                        ),
                        $data['to_group_id']
                    );
                }
            } else {
                $toUser = $this->userModel->findById($data['to_user_id']);
                if (!$toUser) {
                    throw new Exception("Invalid to_user_id");
                }
                $forwardedMessage['recipient_id'] = $data['to_user_id'];
                if ($forwardedMessage['content']) {
                    $forwardedMessage['content'] = $this->encryptionService->encryptMessage(
                        $this->encryptionService->decryptMessage(
                            $message['content'],
                            $message['sender_id'] === $data['from_user_id'] ? $toUser['public_key'] : $fromUser['public_key'],
                            $message['sender_id'] === $data['from_user_id'] ? $fromUser['private_key'] : $toUser['private_key']
                        ),
                        $toUser['public_key'],
                        $fromUser['private_key']
                    );
                }
            }

            $newMessageId = $this->messageModel->create($forwardedMessage);
            $this->logger->info("Message $newMessageId forwarded from {$data['from_user_id']}");

            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => ['id' => (string)$newMessageId, 'media_url' => $forwardedMessage['media_url'] ?? null]
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (Exception $e) {
            $this->logger->error("ForwardMessage Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function setReaction(Request $request): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("SetReaction Request: payload=" . json_encode($data));

            if (!isset($data['message_id'], $data['user_id'], $data['reaction'])) {
                throw new Exception("Missing required fields: message_id, user_id, reaction");
            }

            $message = $this->messageModel->findById($data['message_id']);
            if (!$message) {
                throw new Exception("Message not found");
            }

            $user = $this->userModel->findById($data['user_id']);
            if (!$user) {
                throw new Exception("Invalid user");
            }

            if ($message['group_id'] && !in_array($data['user_id'], $this->groupModel->findById($message['group_id'])['members'])) {
                throw new Exception("User is not a group member");
            }

            $allowedReactions = ['👍', '👎', '❤️', '🔥', '🎉'];
            if (!in_array($data['reaction'], $allowedReactions)) {
                throw new Exception("Invalid reaction");
            }

            $this->messageModel->addReaction($data['message_id'], $data['user_id'], $data['reaction']);
            $this->logger->info("Reaction {$data['reaction']} set on message {$data['message_id']} by {$data['user_id']}");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("SetReaction Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function getHistory(Request $request, string $recipientId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $params = $request->getQueryParams();
            $senderId = $params['sender_id'] ?? null;
            if (!$senderId) {
                throw new Exception("sender_id query parameter is required");
            }

            $sender = $this->userModel->findById($senderId);
            $recipient = $this->userModel->findById($recipientId);
            if (!$sender || !$recipient) {
                throw new Exception("Invalid sender or recipient");
            }

            $limit = (int)($params['limit'] ?? 100);
            $skip = (int)($params['skip'] ?? 0);

            $messages = $this->messageModel->getHistory($senderId, $recipientId, $limit, $skip);
            $decryptedMessages = [];

            foreach ($messages as $message) {
                $decryptedContent = $message['content'] ? $this->encryptionService->decryptMessage(
                    $message['content'],
                    $message['sender_id'] === $senderId ? $recipient['public_key'] : $sender['public_key'],
                    $message['sender_id'] === $senderId ? $sender['private_key'] : $recipient['private_key']
                ) : null;

                $decryptedMessages[] = [
                    'id' => (string)$message['_id'],
                    'sender_id' => $message['sender_id'] ?? null,
                    'bot_id' => $message['bot_id'] ?? null,
                    'recipient_id' => $message['recipient_id'],
                    'type' => $message['type'],
                    'content' => $decryptedContent,
                    'media_url' => $message['media_url'] ?? null,
                    'location' => $message['location'] ?? null,
                    'contact' => $message['contact'] ?? null,
                    'caption' => $message['caption'] ?? null,
                    'entities' => $message['entities'] ?? null,
                    'forward_from' => $message['forward_from'] ?? null,
                    'reply_to_message_id' => $message['reply_to_message_id'] ?? null,
                    'keyboard' => $message['keyboard'] ?? null,
                    'reactions' => $message['reactions'] ?? [],
                    'created_at' => $message['created_at']->format('c')
                ];
            }

            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => $decryptedMessages
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("GetHistory Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function getGroupMessages(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $params = $request->getQueryParams();
            $userId = $params['user_id'] ?? null;
            if (!$userId) {
                throw new Exception("user_id query parameter is required");
            }

            $group = $this->groupModel->findById($groupId);
            if (!$group) {
                throw new Exception("Group not found");
            }

            if (!in_array($userId, $group['members'])) {
                throw new Exception("User is not a group member");
            }

            $limit = (int)($params['limit'] ?? 100);
            $skip = (int)($params['skip'] ?? 0);

            $messages = $this->messageModel->getGroupHistory($groupId, $limit, $skip);
            $decryptedMessages = [];
            foreach ($messages as $message) {
                $decryptedContent = $message['content'] ? $this->encryptionService->decryptGroupMessage($message['content'], $groupId) : null;
                $decryptedMessages[] = [
                    'id' => (string)$message['_id'],
                    'sender_id' => $message['sender_id'] ?? null,
                    'bot_id' => $message['bot_id'] ?? null,
                    'group_id' => $message['group_id'],
                    'type' => $message['type'],
                    'content' => $decryptedContent,
                    'media_url' => $message['media_url'] ?? null,
                    'location' => $message['location'] ?? null,
                    'contact' => $message['contact'] ?? null,
                    'caption' => $message['caption'] ?? null,
                    'entities' => $message['entities'] ?? null,
                    'forward_from' => $message['forward_from'] ?? null,
                    'reply_to_message_id' => $message['reply_to_message_id'] ?? null,
                    'keyboard' => $message['keyboard'] ?? null,
                    'reactions' => $message['reactions'] ?? [],
                    'created_at' => $message['created_at']->format('c')
                ];
            }

            $pinnedMessage = null;
            if ($group['pinned_message_id']) {
                $pinned = $this->messageModel->findById($group['pinned_message_id']);
                if ($pinned) {
                    $decryptedContent = $pinned['content'] ? $this->encryptionService->decryptGroupMessage($pinned['content'], $groupId) : null;
                    $pinnedMessage = [
                        'id' => (string)$pinned['_id'],
                        'sender_id' => $pinned['sender_id'] ?? null,
                        'bot_id' => $pinned['bot_id'] ?? null,
                        'group_id' => $pinned['group_id'],
                        'type' => $pinned['type'],
                        'content' => $decryptedContent,
                        'media_url' => $pinned['media_url'] ?? null,
                        'location' => $pinned['location'] ?? null,
                        'contact' => $pinned['contact'] ?? null,
                        'caption' => $pinned['caption'] ?? null,
                        'entities' => $pinned['entities'] ?? null,
                        'forward_from' => $pinned['forward_from'] ?? null,
                        'reply_to_message_id' => $pinned['reply_to_message_id'] ?? null,
                        'keyboard' => $pinned['keyboard'] ?? null,
                        'reactions' => $pinned['reactions'] ?? [],
                        'created_at' => $pinned['created_at']->format('c')
                    ];
                }
            }

            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => [
                    'messages' => $decryptedMessages,
                    'pinned_message' => $pinnedMessage
                ]
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("GetGroupMessages Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function pinMessage(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("PinMessage Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['message_id'], $data['admin_id'])) {
                throw new Exception("Missing required fields: message_id, admin_id");
            }

            $group = $this->groupModel->findById($groupId);
            if (!$group) {
                throw new Exception("Group not found");
            }

            $message = $this->messageModel->findById($data['message_id']);
            if (!$message || $message['group_id'] !== $groupId) {
                throw new Exception("Invalid or non-group message");
            }

            $this->groupModel->pinMessage($groupId, $data['message_id'], $data['admin_id']);
            $this->messageModel->queueGroupNotification($groupId, $data['message_id'], $data['admin_id']);
            $this->logger->info("Message {$data['message_id']} pinned in group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("PinMessage Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function unpinMessage(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("UnpinMessage Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['admin_id'])) {
                throw new Exception("Missing required field: admin_id");
            }

            $group = $this->groupModel->findById($groupId);
            if (!$group) {
                throw new Exception("Group not found");
            }

            $this->groupModel->unpinMessage($groupId, $data['admin_id']);
            $this->messageModel->queueGroupNotification($groupId, null, $data['admin_id']);
            $this->logger->info("Message unpinned in group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("UnpinMessage Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function getUpdates(Request $request): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $params = $request->getQueryParams();
            $offset = (int)($params['offset'] ?? 0);
            $limit = (int)($params['limit'] ?? 100);
            $timeout = (int)($params['timeout'] ?? 30);

            $updates = $this->messageModel->getUpdates($offset, $limit, $timeout);
            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => $updates
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("GetUpdates Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
}
?>