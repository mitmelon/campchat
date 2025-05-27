<?php
namespace CampChat\Services;

use ParagonIE\Halite\Password;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\HiddenString\HiddenString;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class EncryptionService {
    private $logger;

    public function __construct() {
        $this->logger = new Logger('campchat-encryption');
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/encryption.log', Logger::DEBUG));
    }

    public function hashPassword(string $password): string {
        try {
            $key = KeyFactory::generateEncryptionKey();
            $hash = Password::hash(new HiddenString($password), $key);
            return $hash;
        } catch (\Exception $e) {
            $this->logger->error("Password hashing failed: {$e->getMessage()}");
            throw $e;
        }
    }

    public function verifyPassword(string $password, string $hash): bool {
        try {
            $key = KeyFactory::generateEncryptionKey();
            $verified = Password::verify(new HiddenString($password), $hash, $key);
            return $verified;
        } catch (\Exception $e) {
            $this->logger->error("Password verification failed: {$e->getMessage()}");
            return false;
        }
    }

    public function generateToken(): string {
        try {
            $random = random_bytes(32);
            $token = bin2hex($random);
            return $token;
        } catch (\Exception $e) {
            $this->logger->error("Token generation failed: {$e->getMessage()}");
            throw $e;
        }
    }
}
?>