<?php
namespace CampChat\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CampChat\Models\User;
use CampChat\Models\Analytics;
use CampChat\Services\EncryptionService;
use MongoDB\BSON\ObjectId;
use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class UserController {
    private $userModel;
    private $analytics;
    private $encryptionService;
    private $logger;

    public function __construct() {
        $this->userModel = new User();
        $this->analytics = new Analytics();
        $this->encryptionService = new EncryptionService();
        $this->logger = new Logger('campchat-user-controller');
        try {
            $logFile = __DIR__ . '/../../logs/controller.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize user controller logger: {$e->getMessage()}");
        }
    }

    public function create(Request $request): Response {
        $response = new \Slim\Psr7\Response();
        try {
            $data = $request->getParsedBody();
            $this->logger->info("CreateUser Request Payload: " . json_encode($data));

            // Validate required fields
            if (!isset($data['phone'], $data['username'], $data['password'], $data['first_name'])) {
                $missing = [];
                if (!isset($data['phone'])) $missing[] = 'phone';
                if (!isset($data['username'])) $missing[] = 'username';
                if (!isset($data['password'])) $missing[] = 'password';
                if (!isset($data['first_name'])) $missing[] = 'first_name';
                throw new Exception("Missing required fields: " . implode(", ", $missing));
            }

            // Validate field formats
            if (strlen($data['first_name']) > 50) {
                throw new Exception("first_name must be 50 characters or less");
            }
            if (isset($data['last_name']) && strlen($data['last_name']) > 50) {
                throw new Exception("last_name must be 50 characters or less");
            }
            if (isset($data['country']) && strlen($data['country']) > 100) {
                throw new Exception("country must be 100 characters or less");
            }
            if (isset($data['dob'])) {
                $dob = \DateTime::createFromFormat('Y-m-d', $data['dob']);
                if (!$dob || $dob->format('Y-m-d') !== $data['dob']) {
                    throw new Exception("dob must be a valid date in YYYY-MM-DD format");
                }
                $age = $dob->diff(new \DateTime())->y;
                if ($age < 13) {
                    throw new Exception("User must be at least 13 years old");
                }
            }
            if (isset($data['gender']) && !in_array($data['gender'], ['male', 'female', 'other'])) {
                throw new Exception("gender must be one of: male, female, other");
            }

            $passwordHash = $this->encryptionService->hashPassword($data['password']);
            $token = $this->encryptionService->generateToken();

            $user = [
                'phone' => $data['phone'],
                'username' => $data['username'],
                'password_hash' => $passwordHash,
                'token' => $token,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'dob' => isset($data['dob']) ? new \DateTime($data['dob']) : null,
                'gender' => $data['gender'] ?? null,
                'country' => $data['country'] ?? null,
                'created_at' => new \DateTime()
            ];

            $userId = $this->userModel->create($user);
            $this->analytics->incrementUsers();

            // Queue welcome notification
            $this->userModel->queueNotification($userId, "Welcome to CampChat, {$data['first_name']}!");
            $this->logger->info("Queued welcome notification for user $userId");

            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => ['id' => (string) $userId, 'username' => $data['username'], 'token' => $token]
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (Exception $e) {
            $this->logger->error("CreateUser Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function get(Request $request, Response $response, array $args): Response {
        try {
            $userId = $args['id'];
            $user = $this->userModel->findById($userId);
            if (!$user) {
                throw new Exception('User not found');
            }

            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => [
                    'id' => (string) $user['_id'],
                    'phone' => $user['phone'],
                    'username' => $user['username'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'dob' => $user['dob'] ? $user['dob']->format('Y-m-d') : null,
                    'gender' => $user['gender'],
                    'country' => $user['country'],
                    'created_at' => $user['created_at']->format('c')
                ]
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("GetUser Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
    }

    public function update(Request $request, Response $response, array $args): Response {
        try {
            $userId = $args['id'];
            $data = $request->getParsedBody();
           
            $updates = [];
            if (isset($data['username'])) {
                $updates['username'] = $data['username'];
            }
            if (isset($data['password'])) {
                $updates['password_hash'] = $this->encryptionService->hashPassword($data['password']);
            }
            if (isset($data['first_name'])) {
                if (strlen($data['first_name']) > 50) {
                    throw new Exception("first_name must be 50 characters or less");
                }
                $updates['first_name'] = $data['first_name'];
            }
            if (isset($data['last_name'])) {
                if (strlen($data['last_name']) > 50) {
                    throw new Exception("last_name must be 50 characters or less");
                }
                $updates['last_name'] = $data['last_name'];
            }
            if (isset($data['dob'])) {
                $dob = \DateTime::createFromFormat('Y-m-d', $data['dob']);
                if (!$dob || $dob->format('Y-m-d') !== $data['dob']) {
                    throw new Exception("dob must be a valid date in YYYY-MM-DD format");
                }
                $age = $dob->diff(new \DateTime())->y;
                if ($age < 13) {
                    throw new Exception("User must be at least 13 years old");
                }
                $updates['dob'] = $dob;
            }
            if (isset($data['gender'])) {
                if (!in_array($data['gender'], ['male', 'female', 'other'])) {
                    throw new Exception("gender must be one of: male, female, other");
                }
                $updates['gender'] = $data['gender'];
            }
            if (isset($data['country'])) {
                if (strlen($data['country']) > 100) {
                    throw new Exception("country must be 100 characters or less");
                }
                $updates['country'] = $data['country'];
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            $this->userModel->update($userId, $updates);

            $response->getBody()->write(json_encode(['ok' => true, 'result' => 'User updated']));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("UpdateUser Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function delete(Request $request, Response $response, array $args): Response {
        try {
            $userId = $args['id'];
            $this->userModel->delete($userId);
            $response->getBody()->write(json_encode(['ok' => true, 'result' => 'User deleted']));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("DeleteUser Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function login(Request $request, Response $response): Response {
        try {
            $data = $request->getParsedBody();
            $this->logger->info("Login Request Payload: " . json_encode($data));

            if (!isset($data['phone'], $data['password'])) {
                throw new Exception('Missing required fields');
            }

            $user = $this->userModel->findByPhone($data['phone']);
            if (!$user || !$this->encryptionService->verifyPassword($data['password'], $user['password_hash'])) {
                throw new Exception('Invalid credentials');
            }

            $token = $this->encryptionService->generateToken();
            $this->userModel->update($user['_id'], ['token' => $token]);

            $response->getBody()->write(json_encode([
                'ok' => true,
                'result' => ['id' => (string) $user['_id'], 'username' => $user['username'], 'token' => $token]
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $this->logger->error("Login Error: {$e->getMessage()}");
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    }
}
?>