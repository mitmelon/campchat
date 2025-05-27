<?php
namespace CampChat\Services;

use ParagonIE\Halite\Password;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\HiddenString\HiddenString;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use ParagonIE\Halite\Symmetric\Crypto as SymmetricCrypto;
use CampChat\Models\Group;
use CampChat\Models\Key;

class EncryptionService {
    private $logger;
    private $groupModel;
    private $keyModel;

    public function __construct() {
        $this->logger = new Logger('campchat-encryption');
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/encryption.log', Logger::DEBUG));

        $this->groupModel = new Group();
        $this->keyModel = new Key();
    }

    public function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    public function encryptMessage(string $content, string $recipientPublicKey, string $senderPrivateKey): string {
        return SymmetricCrypto::encrypt(
            new HiddenString($content),
            KeyFactory::loadEncryptionKey($senderPrivateKey),
            KeyFactory::loadEncryptionPublicKey($recipientPublicKey)
        );
    }

    public function decryptMessage(string $content, string $senderPublicKey, string $recipientPrivateKey): string {
        return SymmetricCrypto::decrypt(
            $content,
            KeyFactory::loadEncryptionPublicKey($senderPublicKey),
            KeyFactory::loadEncryptionKey($recipientPrivateKey)
        )->getString();
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

     public function encryptGroupMessage(string $content, string $groupId): string {
        $group = $this->groupModel->findById($groupId);
        if (!$group) {
            throw new \Exception("Group not found");
        }
        $keys = $this->keyModel->getKeys($group['creator_id']);
        if (!$keys) {
            throw new \Exception("Creator keys not found");
        }
        return SymmetricCrypto::encrypt(
            new HiddenString($content),
            KeyFactory::loadEncryptionKey($keys['private_key']),
            KeyFactory::loadEncryptionPublicKey($keys['public_key'])
        );
    }

    public function decryptGroupMessage(string $content, string $groupId): string {
        $group = $this->groupModel->findById($groupId);
        if (!$group) {
            throw new \Exception("Group not found");
        }
        $keys = $this->keyModel->getKeys($group['creator_id']);
        if (!$keys) {
            throw new \Exception("Creator keys not found");
        }
        return SymmetricCrypto::decrypt(
            $content,
            KeyFactory::loadEncryptionPublicKey($keys['public_key']),
            KeyFactory::loadEncryptionKey($keys['private_key'])
        )->getString();
    }
}
?>