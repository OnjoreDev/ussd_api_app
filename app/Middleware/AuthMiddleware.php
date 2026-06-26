<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        // 1. Get the Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        
        // 2. Extract the token from "Bearer <token>"
        $token = str_replace('Bearer ', '', $authHeader);

        // 3. Retrieve the expected secret from the environment
        $expectedToken = $_ENV['USSD_CLIENT_TOKEN'] ?? null;

        // 4. Validate: Compare the incoming token to the environment secret
        if (!$expectedToken || $token !== $expectedToken) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Unauthorized access']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // 5. Success: Proceed to the next middleware or controller
        return $handler->handle($request);
    }
}