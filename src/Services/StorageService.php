<?php
namespace CampChat\Services;

use CampChat\Config\Config;
use Aws\S3\S3Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Exception;

class StorageService {
    private $config;
    private $s3Client;
    private $logger;

    public function __construct() {
        $this->config = Config::getStorageConfig();
        $this->logger = new Logger('campchat-storage');
        try {
            $logFile = __DIR__ . '/../../logs/storage.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $this->logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
        } catch (\Exception $e) {
            error_log("Failed to initialize storage logger: {$e->getMessage()}");
        }

        if ($this->config['type'] === 'aws_s3') {
            try {
                $this->s3Client = new S3Client([
                    'version' => 'latest',
                    'region' => $this->config['aws_s3']['region'],
                    'credentials' => [
                        'key' => $this->config['aws_s3']['key'],
                        'secret' => $this->config['aws_s3']['secret']
                    ]
                ]);
                $this->logger->info("AWS S3 client initialized");
            } catch (\Exception $e) {
                $this->logger->error("Failed to initialize S3 client: {$e->getMessage()}");
                throw $e;
            }
        }
    }

    public function uploadFile($file, string $filename): string {
        try {
            if ($this->config['type'] === 'aws_s3') {
                $this->s3Client->putObject([
                    'Bucket' => $this->config['aws_s3']['bucket'],
                    'Key' => $filename,
                    'Body' => fopen($file->getStream()->getMetadata('uri'), 'r'),
                    'ACL' => 'public-read'
                ]);
                $url = $this->config['aws_s3']['endpoint'] . '/' . $this->config['aws_s3']['bucket'] . '/' . $filename;
                $this->logger->info("Uploaded file to S3: $url");
                return $url;
            } else {
                $path = $this->config['local']['path'] . '/' . $filename;
                if (!file_exists(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }
                $file->moveTo($path);
                $url = $this->config['local']['url_base'] . '/' . $filename;
                $this->logger->info("Uploaded file locally: $url");
                return $url;
            }
        } catch (\Exception $e) {
            $this->logger->error("File upload failed: {$e->getMessage()}");
            throw $e;
        }
    }

    public function getFileUrl(string $filename): string {
        if ($this->config['type'] === 'aws_s3') {
            return $this->config['aws_s3']['endpoint'] . '/' . $this->config['aws_s3']['bucket'] . '/' . $filename;
        }
        return $this->config['local']['url_base'] . '/' . $filename;
    }
}
?>