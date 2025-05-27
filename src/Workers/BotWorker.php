<?php
namespace CampChat\Workers;

use CampChat\Config\Config;
use CampChat\Models\Message;
use CampChat\Models\Bot;
use CampChat\Models\Group;
use CampChat\Services\EncryptionService;
use CampChat\Services\WebhookService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class BotWorker {
    private $messageModel;
    private $botModel;
    private $groupModel;
    private $encryptionService;
    private $webhookService;
    private $logger;

    public function __construct() {
        $this->messageModel = new Message();
        $this->botModel = new Bot();
        $this->groupModel = new Group();
        $this->encryptionService = new EncryptionService();
        $this->webhookService = new WebhookService();
        $this->logger = new Logger('campchat-bot-worker');
        try {
            $logFile = __DIR__ . '/../../logs/bot_worker.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::INFO));
        } catch (\Exception $e) {
            error_log("Failed to initialize bot worker logger: {$e->getMessage()}");
        }
    }

    public function run() {
        $rabbitMQ = Config::getRabbitMQ();
        if (!$rabbitMQ) {
            $this->logger->error("RabbitMQ unavailable, bot worker cannot run");
            return;
        }

        try {
            $channel = $rabbitMQ->channel();
            $channel->queue_declare('bot_events', false, true, false, false);

            $channel->basic_consume('bot_events', '', false, true, false, false, function ($msg) {
                $this->processEvent(json_decode($msg->body, true));
            });

            $this->logger->info("Bot worker started, waiting for events");
            while ($channel->is_open()) {
                $channel->wait();
            }

            $channel->close();
            $rabbitMQ->close();
        } catch (\Exception $e) {
            $this->logger->error("Bot worker error: {$e->getMessage()}");
        }
    }

    private function processEvent(array $event) {
        $this->logger->info("Processing bot event: " . json_encode($event));

        if (!isset($event['group_id'], $event['event'], $event['user_id'])) {
            $this->logger->warning("Invalid event data");
            return;
        }

        $group = $this->groupModel->findById($event['group_id']);
        if (!$group || empty($group['bots'])) {
            return;
        }

        foreach ($group['bots'] as $botId) {
            $bot = $this->botModel->findById($botId);
            if (!$bot) {
                continue;
            }

            // Try webhook first
            if (isset($bot['webhook_url']) && $bot['webhook_url']) {
                $this->webhookService->sendEvent($botId, $bot['webhook_url'], $event, null, $group);
                continue;
            }

            // Fallback to command-based response
            $response = null;
            if ($event['event'] === 'member_joined' && isset($bot['commands']['welcome'])) {
                $response = $bot['commands']['welcome'];
            } elseif ($event['event'] === 'member_left' && isset($bot['commands']['goodbye'])) {
                $response = $bot['commands']['goodbye'];
            }

            if ($response) {
                $message = [
                    'group_id' => $event['group_id'],
                    'bot_id' => $botId,
                    'type' => 'text',
                    'content' => $this->encryptionService->encryptGroupMessage($response, $event['group_id']),
                    'created_at' => new \DateTime()
                ];

                $messageId = $this->messageModel->create($message);
                $this->logger->info("Bot $botId sent message $messageId for event {$event['event']} in group {$event['group_id']}");
            }
        }
    }
}
?>