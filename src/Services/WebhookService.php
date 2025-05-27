<?php
namespace CampChat\Services;

use CampChat\Models\Message;
use CampChat\Services\EncryptionService;
use GuzzleHttp\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Exception;

class WebhookService {
    private $messageModel;
    private $encryptionService;
    private $httpClient;
    private $logger;

    public function __construct() {
        $this->messageModel = new Message();
        $this->encryptionService = new EncryptionService();
        $this->httpClient = new Client([
            'timeout' => 5,
            'connect_timeout' => 2
        ]);
        $this->logger = new Logger('campchat-webhook');
        try {
            $logFile = __DIR__ . '/../../logs/webhook.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize webhook logger: {$e->getMessage()}");
        }
    }

    public function sendEvent(string $botId, string $webhookUrl, array $event, ?array $message = null, ?array $group = null): ?string {
        if (!$webhookUrl) {
            return null;
        }

        try {
            $payload = [
                'bot_id' => $botId,
                'event' => $event['event'] ?? 'message',
                'group_id' => $event['group_id'] ?? null,
                'user_id' => $event['user_id'] ?? null,
                'timestamp' => time()
            ];

            if ($message) {
                $payload['message'] = [
                    'id' => (string)$message['_id'],
                    'sender_id' => $message['sender_id'] ?? null,
                    'content' => $message['content'] ? $this->encryptionService->decryptGroupMessage($message['content'], $message['group_id']) : null,
                    'type' => $message['type'],
                    'created_at' => $message['created_at']->format('c')
                ];
            }

            if ($group) {
                $payload['group'] = [
                    'id' => $group['_id'],
                    'name' => $group['name']
                ];
            }

            $this->logger->info("Sending webhook to $webhookUrl for bot $botId: " . json_encode($payload));

            $response = $this->httpClient->post($webhookUrl, [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json']
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new Exception("Webhook returned non-200 status: {$response->getStatusCode()}");
            }

            $responseData = json_decode($response->getBody()->getContents(), true);
            if (!$responseData || !isset($responseData['ok']) || !$responseData['ok']) {
                throw new Exception("Invalid webhook response");
            }

            return $this->processWebhookResponse($botId, $event['group_id'], $responseData, $message['_id'] ?? null);
        } catch (Exception $e) {
            $this->logger->error("Webhook error for bot $botId: {$e->getMessage()}");
            return null;
        }
    }

    private function processWebhookResponse(string $botId, string $groupId, array $responseData, ?string $replyToMessageId): ?string {
        if (!isset($responseData['result']['type']) || !isset($responseData['result']['content'])) {
            $this->logger->warning("Invalid webhook response structure for bot $botId");
            return null;
        }

        $message = [
            'group_id' => $groupId,
            'bot_id' => $botId,
            'type' => $responseData['result']['type'],
            'created_at' => new \DateTime(),
            'reply_to_message_id' => $replyToMessageId
        ];

        switch ($responseData['result']['type']) {
            case 'text':
                $message['content'] = $this->encryptionService->encryptGroupMessage($responseData['result']['content'], $groupId);
                break;
            case 'photo':
            case 'video':
            case 'document':
                if (!isset($responseData['result']['media_url'])) {
                    $this->logger->warning("Missing media_url for {$responseData['result']['type']} response");
                    return null;
                }
                $message['media_url'] = $responseData['result']['media_url'];
                $message['caption'] = $responseData['result']['caption'] ?? null;
                break;
            default:
                $this->logger->warning("Unsupported webhook response type: {$responseData['result']['type']}");
                return null;
        }

        if (isset($responseData['result']['keyboard'])) {
            $message['keyboard'] = $responseData['result']['keyboard'];
        }

        $messageId = $this->messageModel->create($message);
        $this->logger->info("Bot $botId sent message $messageId via webhook response in group $groupId");

        return $messageId;
    }
}
?>