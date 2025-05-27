<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;

$app = AppFactory::create();

// Add error middleware
$app->addErrorMiddleware(true, true, true);

// Include routes
require __DIR__ . '/../src/Routes/api.php';

$app->run();
?>