<?php
namespace CampChat\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CampChat\Models\Group;
use CampChat\Models\User;
use CampChat\Services\EncryptionService;
use CampChat\Services\StorageService;
use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class GroupController {
    private $groupModel;
    private $userModel;
    private $encryptionService;
    private $storageService;
    private $logger;

    public function __construct() {
        $this->groupModel = new Group();
        $this->userModel = new User();
        $this->encryptionService = new EncryptionService();
        $this->storageService = new StorageService();
        $this->logger = new Logger('campchat-group');
        try {
            $logFile = __DIR__ . '/../../logs/group.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize group controller logger: {$e->getMessage()}");
        }
    }

    public function create(Request $request): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("CreateGroup Request: payload=" . json_encode($data));

            if (!isset($data['creator_id'], $data['name'])) {
                throw new Exception("Missing required fields: creator_id, name");
            }

            $creator = $this->userModel->findById($data['creator_id']);
            if (!$creator) {
                throw new Exception("Invalid creator");
            }

            $group = [
                'creator_id' => $data['creator_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'icon_url' => null
            ];

            $groupId = $this->groupModel->create($group);
            $this->logger->info("Group $groupId created by user {$data['creator_id']}");

            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => ['id' => $groupId]
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (Exception $e) {
            $this->logger->error("CreateGroup Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function addMember(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("AddMember Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['user_id'], $data['admin_id'])) {
                throw new Exception("Missing required fields: user_id, admin_id");
            }

            $group = $this->groupModel->findById($groupId);
            if (!$group) {
                throw new Exception("Group not found");
            }

            if ($group['permissions']['locked']) {
                throw new Exception("Group is locked");
            }

            if (!in_array($data['admin_id'], $group['admins']) && !$group['permissions']['allow_member_invites']) {
                throw new Exception("Only admins or members with invite permission can add members");
            }

            $user = $this->userModel->findById($data['user_id']);
            if (!$user) {
                throw new Exception("Invalid user");
            }

            $this->groupModel->addMember($groupId, $data['user_id']);
            $this->logger->info("User {$data['user_id']} added to group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e) {
            $this->logger->error("AddMember Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function removeMember(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("RemoveMember Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['user_id'], $data['admin_id'])) {
                throw new Exception("Missing required fields: user_id, admin_id");
            }

            $this->groupModel->removeMember($groupId, $data['user_id'], $data['admin_id']);
            $this->logger->info("User {$data['user_id']} removed from group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e) {
            $this->logger->error("RemoveMember Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function quitGroup(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("QuitGroup Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['user_id'])) {
                throw new Exception("Missing required field: user_id");
            }

            $this->groupModel->quitGroup($groupId, $data['user_id']);
            $this->logger->info("User {$data['user_id']} quit group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e) {
            $this->logger->error("QuitGroup Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function addAdmin(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("AddAdmin Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['user_id'], $data['admin_id'])) {
                throw new Exception("Missing required fields: user_id, admin_id");
            }

            $this->groupModel->addAdmin($groupId, $data['user_id'], $data['admin_id']);
            $this->logger->info("User {$data['user_id']} made admin in group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e) {
            $this->logger->error("AddAdmin Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function removeAdmin(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("RemoveAdmin Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['user_id'], $data['admin_id'])) {
                throw new Exception("Missing required fields: user_id, admin_id");
            }

            $this->groupModel->removeAdmin($groupId, $data['user_id'], $data['admin_id']);
            $this->logger->info("Admin {$data['user_id']} removed from group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e) {
            $this->logger->error("RemoveAdmin Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function updatePermissions(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("UpdatePermissions Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['admin_id'], $data['permissions'])) {
                throw new Exception("Missing required fields: admin_id, permissions");
            }

            $this->groupModel->updatePermissions($groupId, $data['permissions'], $data['admin_id']);
            $this->logger->info("Permissions updated for group $groupId");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e) {
            $this->logger->error("UpdatePermissions Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function updateDetails(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $files = $request->getUploadedFiles();
            $this->logger->info("UpdateDetails Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['admin_id'])) {
                throw new Exception("Missing required field: admin_id");
            }

            $updates = [];
            if (isset($data['description'])) {
                $updates['description'] = $data['description'];
            }
            if (isset($files['icon'])) {
                $icon = $files['icon'];
                if ($icon->getError() !== UPLOAD_ERR_OK) {
                    throw new Exception("Icon upload error");
                }
                if (!in_array($icon->getClientMediaType(), ['image/png', 'image/jpeg'])) {
                    throw new Exception("Invalid icon type");
                }
                $filename = uniqid("group_icon_") . '.' . pathinfo($icon->getClientFilename(), PATHINFO_EXTENSION);
                $updates['icon_url'] = $this->storageService->uploadFile($icon, $filename);
            }

            if (empty($updates)) {
                throw new Exception("No valid updates provided");
            }

            $this->groupModel->updateDetails($groupId, $updates, $data['admin_id']);
            $this->logger->info("Details updated for group $groupId");

            $response->getBody()->write(json_encode(['ok' => true, 'icon_url' => $updates['icon_url'] ?? null]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e) {
            $this->logger->error("UpdateDetails Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function delete(Request $request, string $groupId): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("DeleteGroup Request: groupId=$groupId, payload=" . json_encode($data));

            if (!isset($data['admin_id'])) {
                throw new Exception("Missing required field: admin_id");
            }

            $this->groupModel->delete($groupId, $data['admin_id']);
            $this->logger->info("Group $groupId deleted");

            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e) {
            $this->logger->error("DeleteGroup Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
}
?>