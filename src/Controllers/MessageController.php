<?php
namespace CampChat\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CampChat\Models\Message;
use CampChat\Models\User;
use CampChat\Services\EncryptionService;
use CampChat\Services\StorageService;
use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class MessageController {
    private $messageModel;
    private $userModel;
    private $encryptionService;
    private $storageService;
    private $logger;

    public function __construct() {
        $this->messageModel = new Message();
        $this->userModel = new User();
        $this->encryptionService = new EncryptionService();
        $this->storageService = new StorageService();
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

            if (!isset($data['sender_id'], $data['recipient_id'])) {
                throw new Exception("Missing required fields: sender_id, recipient_id");
            }

            $sender = $this->userModel->findById($data['sender_id']);
            $recipient = $this->userModel->findById($data['recipient_id']);
            if (!$sender || !$recipient) {
                throw new Exception("Invalid sender or recipient");
            }

            $message = [
                'sender_id' => $data['sender_id'],
                'recipient_id' => $data['recipient_id'],
                'type' => $type,
                'created_at' => new \DateTime(),
                'reply_to_message_id' => $data['reply_to_message_id'] ?? null,
                'caption' => $data['caption'] ?? null,
                'entities' => $data['entities'] ?? null
            ];

            switch ($type) {
                case 'text':
                    if (!isset($data['content'])) {
                        throw new Exception("Missing content for text message");
                    }
                    $message['content'] = $this->encryptionService->encryptMessage(
                        $data['content'],
                        $recipient['public_key'],
                        $sender['private_key']
                    );
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
            $this->logger->info("Message $messageId sent: type=$type, from={$data['sender_id']} to={$data['recipient_id']}");

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

    public function edit(Request $request, string $messageId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $files = $request->getUploadedFiles();
            $this->logger->info("EditMessage Request: messageId=$messageId, payload=" . json_encode($data));

            $message = $this->messageModel->findById($messageId);
            if (!$message) {
                throw new Exception("Message not found");
            }

            $sender = $this->userModel->findById($message['sender_id']);
            $recipient = $this->userModel->findById($message['recipient_id']);
            if (!$sender || !$recipient) {
                throw new Exception("Invalid sender or recipient");
            }

            $updates = [];
            if (isset($data['content']) && $message['type'] === 'text') {
                $updates['content'] = $this->encryptionService->encryptMessage(
                    $data['content'],
                    $recipient['public_key'],
                    $sender['private_key']
                );
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

            if (!isset($data['message_id'], $data['from_user_id'], $data['to_user_id'])) {
                throw new Exception("Missing required fields: message_id, from_user_id, to_user_id");
            }

            $message = $this->messageModel->findById($data['message_id']);
            if (!$message) {
                throw new Exception("Message not found");
            }

            $fromUser = $this->userModel->findById($data['from_user_id']);
            $toUser = $this->userModel->findById($data['to_user_id']);
            if (!$fromUser || !$toUser) {
                throw new Exception("Invalid user IDs");
            }

            $forwardedMessage = [
                'sender_id' => $data['from_user_id'],
                'recipient_id' => $data['to_user_id'],
                'type' => $message['type'],
                'content' => $message['content'] ?? null,
                'media_url' => $message['media_url'] ?? null,
                'location' => $message['location'] ?? null,
                'contact' => $message['contact'] ?? null,
                'caption' => $message['caption'] ?? null,
                'entities' => $message['entities'] ?? null,
                'forwarded_from' => $message['sender_id'],
                'created_at' => new \DateTime()
            ];

            $newMessageId = $this->messageModel->create($forwardedMessage);
            $this->logger->info("Message $newMessageId forwarded from {$data['from_user_id']} to {$data['to_user_id']}");

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
            $senderId = $request->getQueryParams()['sender_id'] ?? null;
            if (!$senderId) {
                throw new Exception("sender_id query parameter is required");
            }

            $messages = $this->messageModel->getHistory($senderId, $recipientId);
            $decryptedMessages = [];
            $sender = $this->userModel->findById($senderId);
            $recipient = $this->userModel->findById($recipientId);

            foreach ($messages as $message) {
                $decryptedContent = $message['content'] ? $this->encryptionService->decryptMessage(
                    $message['content'],
                    $message['sender_id'] === $senderId ? $recipient['public_key'] : $sender['public_key'],
                    $message['sender_id'] === $senderId ? $sender['private_key'] : $recipient['private_key']
                ) : null;

                $decryptedMessages[] = [
                    'id' => (string)$message['_id'],
                    'sender_id' => $message['sender_id'],
                    'recipient_id' => $message['recipient_id'],
                    'type' => $message['type'],
                    'content' => $decryptedContent,
                    'media_url' => $message['media_url'] ?? null,
                    'location' => $message['location'] ?? null,
                    'contact' => $message['contact'] ?? null,
                    'caption' => $message['caption'] ?? null,
                    'entities' => $message['entities'] ?? null,
                    'forwarded_from' => $message['forwarded_from'] ?? null,
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

    public function getUpdates(Request $request): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $params = $request->getQueryParams();
            $offset = $params['offset'] ?? 0;
            $limit = $params['limit'] ?? 100;
            $timeout = $params['timeout'] ?? 30;

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