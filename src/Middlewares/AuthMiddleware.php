<?php
namespace CampChat\Middlewares;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use CampChat\Models\User;
use Slim\Psr7\Response as Psr7Response;

class AuthMiddleware {
    public function __invoke(Request $request, RequestHandler $handler): Response {
        $token = $request->getHeaderLine('Authorization');
        $token = str_replace('Bearer ', '', $token);

        if (!$token) {
            $response = new Psr7Response();
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Token missing']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $userModel = new User();
        $user = $userModel->findByToken($token);
        if (!$user) {
            $response = new Psr7Response();
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Invalid token']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // Attach user_id to request for controllers
        $request = $request->withAttribute('user_id', (string)$user['_id']);
        return $handler->handle($request);
    }
}
?>