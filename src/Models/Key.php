<?php
namespace CampChat\Models;

use CampChat\Config\Config;
use MongoDB\BSON\ObjectId;
use ParagonIE\Halite\Symmetric\Crypto as SymmetricCrypto;
use ParagonIE\HiddenString\HiddenString;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Key {
    private $collection;
    private $logger;

    public function __construct() {
        $this->collection = Config::getMongoDB()->selectDatabase('campchat')->selectCollection('keys');
        $this->logger = new Logger('campchat-key');
        try {
            $logFile = __DIR__ . '/../../logs/key.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize key logger: {$e->getMessage()}");
        }
    }

    public function storeKeys(string $userId, string $publicKey, string $privateKey): void {
        $globalKey = Config::getGlobalEncryptionKey();
        $encryptedPublicKey = SymmetricCrypto::encrypt(
            new HiddenString($publicKey),
            KeyFactory::importEncryptionKey($globalKey)
        );
        $encryptedPrivateKey = SymmetricCrypto::encrypt(
            new HiddenString($privateKey),
            KeyFactory::importEncryptionKey($globalKey)
        );

        $this->collection->insertOne([
            'user_id' => $userId,
            'public_key' => $encryptedPublicKey,
            'private_key' => $encryptedPrivateKey,
            'created_at' => new \DateTime()
        ]);

        $this->logger->info("Stored encrypted keys for user $userId");
    }

    public function getKeys(string $userId): ?array {
        $keyDoc = $this->collection->findOne(['user_id' => $userId]);
        if (!$keyDoc) {
            return null;
        }

        $globalKey = Config::getGlobalEncryptionKey();
        $publicKey = SymmetricCrypto::decrypt(
            $keyDoc['public_key'],
            KeyFactory::importEncryptionKey($globalKey)
        )->getString();
        $privateKey = SymmetricCrypto::decrypt(
            $keyDoc['private_key'],
            KeyFactory::importEncryptionKey($globalKey)
        )->getString();

        return [
            'public_key' => $publicKey,
            'private_key' => $privateKey
        ];
    }
}
?>