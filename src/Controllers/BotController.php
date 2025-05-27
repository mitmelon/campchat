<?php
namespace CampChat\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CampChat\Models\Bot;
use CampChat\Models\Group;
use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class BotController {
    private $botModel;
    private $groupModel;
    private $logger;

    public function __construct() {
        $this->botModel = new Bot();
        $this->groupModel = new Group();
        $this->logger = new Logger('campchat-bot-controller');
        try {
            $logFile = __DIR__ . '/../../logs/bot.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize bot controller logger: {$e->getMessage()}");
        }
    }

    public function create(Request $request): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("CreateBot Request: payload=" . json_encode($data));

            if (!isset($data['user_id'], $data['name'])) {
                throw new Exception("Missing required fields: user_id, name");
            }

            $commands = $data['commands'] ?? [];
            $webhookUrl = $data['webhook_url'] ?? null;
            $botId = $this->botModel->create($data['name'], $data['user_id'], $commands, $webhookUrl);
            $this->logger->info("Bot created with ID $botId");

            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => ['id' => $botId]
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (Exception $e) {
            $this->logger->error("CreateBot Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function updateCommands(Request $request, string $botId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("UpdateBotCommands Request: botId=$botId, payload=" . json_encode($data));

            if (!isset($data['user_id'], $data['commands'])) {
                throw new Exception("Missing required fields: user_id, commands");
            }

            $this->botModel->updateCommands($botId, $data['commands'], $data['user_id']);
            $this->logger->info("Commands updated for bot $botId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("UpdateBotCommands Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function setWebhook(Request $request, string $botId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("SetWebhook Request: botId=$botId, payload=" . json_encode($data));

            if (!isset($data['user_id'], $data['webhook_url'])) {
                throw new Exception("Missing required fields: user_id, webhook_url");
            }

            $this->botModel->setWebhook($botId, $data['webhook_url'] ?: null, $data['user_id']);
            $this->logger->info("Webhook set for bot $botId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("SetWebhook Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function addBotToGroup(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("AddBotToGroup Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['bot_id'], $data['admin_id'])) {
                throw new Exception("Missing required fields: bot_id, admin_id");
            }

            $this->groupModel->addBot($groupId, $data['bot_id'], $data['admin_id']);
            $this->botModel->addToGroup($data['bot_id'], $groupId);
            $this->logger->info("Bot {$data['bot_id']} added to group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("AddBotToGroup Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function removeBotFromGroup(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("RemoveBotFromGroup Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['bot_id'], $data['admin_id'])) {
                throw new Exception("Missing required fields: bot_id, admin_id");
            }

            $this->groupModel->removeBot($groupId, $data['bot_id'], $data['admin_id']);
            $this->botModel->removeFromGroup($data['bot_id'], $groupId);
            $this->logger->info("Bot {$data['bot_id']} removed from group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("RemoveBotFromGroup Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
}
?>