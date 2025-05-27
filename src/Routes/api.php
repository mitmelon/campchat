<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CampChat\Controllers\UserController;

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
    $group->post('', [$controller, 'create']);
    $group->get('/{id}', [$controller, 'get']);
    $group->put('/{id}', [$controller, 'update']);
    $group->delete('/{id}', [$controller, 'delete']);
    $group->post('/login', [$controller, 'login']);
});

// Catch-all route for undefined endpoints
$app->map(['GET', 'POST', 'PUT', 'DELETE'], '/{routes:.+}', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Route not found']));
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});
?>