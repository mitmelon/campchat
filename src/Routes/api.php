<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CampChat\Controllers\{
    UserController,
    MessageController,
    GroupController,
    BotController
};

$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode(['ok' => true, 'status' => 'Welcome to CampChat']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/health', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode(['ok' => true, 'status' => 'Server running']));
    return $response->withHeader('Content-Type', 'application/json');
});

// User routes
$app->group('/users', function ($group) {
    $controller = new UserController();
    $group->post('/create', [$controller, 'create']);
    $group->get('/{id}', [$controller, 'get']);
    $group->put('/{id}', [$controller, 'update']);
    $group->delete('/{id}', [$controller, 'delete']);
    $group->post('/login', [$controller, 'login']);
});

$app->group('/messages', function ($group) {
    $group->post('/send/{type:(?:text|photo|video|audio|document|animation|voice|location|contact)}', [MessageController::class, 'send']);
    $group->post('/edit/{messageId}', [MessageController::class, 'edit']);
    $group->post('/delete/{messageId}', [MessageController::class, 'delete']);
    $group->post('/forward', [MessageController::class, 'forward']);
    $group->post('/reaction', [MessageController::class, 'setReaction']);
    $group->get('/history/{recipientId}', [MessageController::class, 'getHistory']);
    $group->get('/group/{groupId}', [MessageController::class, 'getGroupMessages']);
    $group->post('/pin/{groupId}', [MessageController::class, 'pinMessage']);
    $group->post('/unpin/{groupId}', [MessageController::class, 'unpinMessage']);
    $group->get('/updates', [MessageController::class, 'getUpdates']);
});

$app->group('/group', function ($group) {
    $group->post('/create', [GroupController::class, 'create']);
    $group->post('/{groupId}/members', [GroupController::class, 'addMember']);
    $group->delete('/{groupId}/members', [GroupController::class, 'removeMember']);
    $group->delete('/{groupId}/quit', [GroupController::class, 'quitGroup']);
    $group->post('/{groupId}/admins', [GroupController::class, 'addAdmin']);
    $group->delete('/{groupId}/admins', [GroupController::class, 'removeAdmin']);
    $group->put('/{groupId}/permissions', [GroupController::class, 'updatePermissions']);
    $group->put('/{groupId}/details', [GroupController::class, 'updateDetails']);
    $group->delete('/{groupId}', [GroupController::class, 'delete']);
});

$app->group('/bots', function ($group) {
    $group->post('', [BotController::class, 'create']);
    $group->put('/{botId}/commands', [BotController::class, 'updateCommands']);
    $group->put('/{botId}/webhook', [BotController::class, 'setWebhook']);
});

// Catch-all route for undefined endpoints
$app->map(['GET', 'POST', 'PUT', 'DELETE'], '/{routes:.+}', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Route not found']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});
?>