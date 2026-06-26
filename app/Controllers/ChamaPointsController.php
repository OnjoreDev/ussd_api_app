<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TransactionService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ChamaPointsController extends Controller
{
    private TransactionService $transactionService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->transactionService = $container->get(TransactionService::class);
    }

    /**
     * Add points to a member's Chama account
     */
    // public function addPoints(Request $request, Response $response): Response
    // {
    //     $data = $request->getParsedBody();
    //     // 3 = Chama Points Wallet Type
    //     $success = $this->transactionService->execute(
    //         (int)$data['member_id'],
    //         3,
    //         (int)$data['amount'],
    //         'Credit',
    //         'Chama Loyalty Points Earned',
    //         'PTS-' . strtoupper(bin2hex(random_bytes(4)))
    //     );

    //     return $success ? $this->jsonResponse($response, ['status' => 'success']) 
    //                     : $this->jsonResponse($response, ['status' => 'error'], 500);
    // }
}