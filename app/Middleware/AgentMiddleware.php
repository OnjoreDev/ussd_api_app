<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use App\Models\Member;
use Slim\Psr7\Response as SlimResponse;

class AgentMiddleware
{
    private Member $memberModel;

    public function __construct(Member $memberModel)
    {
        $this->memberModel = $memberModel;
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        // Retrieve the member_id set by your AuthMiddleware during the authentication phase
        $memberId = (int)$request->getAttribute('member_id');

        // Check if the member has the 'agent' role in the database
        if (!$this->memberModel->hasRole($memberId, 'agent')) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Unauthorized: Agent access only.']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // Proceed if the member is authorized
        return $handler->handle($request);
    }
}