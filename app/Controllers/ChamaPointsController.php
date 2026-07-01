<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TransactionService;
use App\Services\SmsService;
use App\Models\Wallet;
use App\Models\Member;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ChamaPointsController extends Controller
{
    private TransactionService $transactionService;
    private Wallet $walletModel;
    private Member $memberModel;
    private SmsService $smsService;

    private const POINT_TO_KES_RATE = 10;
    private const MIN_REDEMPTION_POINTS = 200; // Database defined minimum

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->transactionService = $container->get(TransactionService::class);
        $this->walletModel = $container->get(Wallet::class);
        $this->memberModel = $container->get(Member::class);
        $this->smsService = $container->get(SmsService::class);
    }


    // Remove array $args from the signature
    public function getBalanceAction(Request $request, Response $response): Response
    {
        // Fetch the route argument directly from the request attributes
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $memberId = (int)$route->getArgument('member_id');

        $balance = $this->walletModel->getChamaPointsBalance($memberId);

        return $this->jsonResponse($response, ['balance' => $balance]);
    }
    // Fixed signature: Now explicitly public and accepting all 3 required arguments
    public function redeemAction(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $memberId = (int)($data['member_id'] ?? 0);
        $pointsToRedeem = (int)($data['points'] ?? 0);

        if ($pointsToRedeem < self::MIN_REDEMPTION_POINTS) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Minimum ' . self::MIN_REDEMPTION_POINTS . ' points.'], 400);
        }

        $currentBalance = $this->walletModel->getChamaPointsBalance($memberId);
        if ($pointsToRedeem > $currentBalance) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Insufficient points.'], 400);
        }

        $success = $this->transactionService->execute(
            $memberId,
            3,
            $pointsToRedeem,
            'Debit', // Correct classification
            'RED-' . strtoupper(bin2hex(random_bytes(4))),
            'Chama Points Redemption'
        );

        if ($success) {
            $member = $this->memberModel->findById($memberId);
            if ($member && !empty($member['phone'])) {
                $kesValue = $pointsToRedeem * self::POINT_TO_KES_RATE;
                $msg = "Success: You redeemed {$pointsToRedeem} points for KES {$kesValue}.";
                $this->smsService->sendSMS($member['phone'], $msg);
            }
            return $this->jsonResponse($response, ['status' => 'success', 'message' => 'Redemption successful.']);
        }

        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Transaction failed.'], 500);
    }

    //add chama points
    // In ChamaPointsController.php

    public function addPoints(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $memberId = (int)($data['member_id'] ?? 0);
        $points = (int)($data['points'] ?? 0);

        // Only process for Chama wallet (ID 3)
        $success = $this->transactionService->execute(
            $memberId,
            3, // Guaranteed Chama Wallet ID
            $points,
            'Credit',
            'CHAMA-' . strtoupper(bin2hex(random_bytes(4))),
            "Agent added {$points} Chama points."
        );

        return $success
            ? $this->jsonResponse($response, ['status' => 'success'])
            : $this->jsonResponse($response, ['status' => 'error'], 500);
    }
}
