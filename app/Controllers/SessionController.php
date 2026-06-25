<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SessionModel;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SessionController extends Controller
{
    private SessionModel $session;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->session = $container->get(SessionModel::class);
    }

    public function getLevel(Request $request, Response $response): Response
    {
        // Use getAttribute to fetch the value from the route placeholder
        $sessionId = $request->getAttribute('phone');

        if (!$sessionId) {
            $response->getBody()->write(json_encode(['error' => 'Session ID/Phone missing']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $level = $this->session->getLevel($sessionId);

        $response->getBody()->write(json_encode(['level' => $level]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateState(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $this->session->setLevel($data['session_id'], $data['level']);

        $response->getBody()->write(json_encode(['status' => 'success']));
        return $response->withHeader('Content-Type', 'application/json');
    }


    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $this->session->createSession($data['session_id'], $data['msisdn'], $data['ussd_code']);

        $response->getBody()->write(json_encode(['status' => 'success']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateInput(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $this->session->saveInput($data['session_id'], $data['input']);

        $response->getBody()->write(json_encode(['status' => 'success']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getInputs(Request $request, Response $response): Response
    {
        $sessionId = $request->getAttribute('sessionId'); // Ensure route uses {sessionId}
        $inputs = $this->session->getAllInputs($sessionId);
        $response->getBody()->write(json_encode(['inputs' => $inputs]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
