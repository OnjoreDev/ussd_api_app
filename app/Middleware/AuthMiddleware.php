<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\Member;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    private Member $member;

    public function __construct(Member $member)
    {
        $this->member = $member;
    }

    public function process(Request $request, Handler $handler): Response
    {
        // 1. Get token from header
        $token = $request->getHeaderLine('Authorization');
        // If "Bearer token" format is used, strip "Bearer "
        $token = str_replace('Bearer ', '', $token);

        // 2. Validate token against the database
        // You should add a method in your Member model: findByToken(string $token)
        $user = $this->member->findByToken($token);

        if (!$user || !$user['is_verified']) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Unauthorized access']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // 3. Token is valid, proceed to the route
        return $handler->handle($request);
    }
}