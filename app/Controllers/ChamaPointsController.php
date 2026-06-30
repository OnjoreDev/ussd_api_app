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
    private SmsService $smsService; // Added SmsService

    private const POINT_TO_KES_RATE = 10;
    private const MIN_REDEMPTION_POINTS = 10;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->transactionService = $container->get(TransactionService::class);
        $this->walletModel = $container->get(Wallet::class);
        $this->memberModel = $container->get(Member::class);
        $this->smsService = $container->get(SmsService::class); // Inject via Container
    }

    /**
     * Option 1: View Balance (Sends SMS to member)
     */
    public function getBalanceAction(Request $request, Response $response, array $args): Response
    {
        $memberId = (int)($args['member_id'] ?? 0);
        $balance = $this->walletModel->getChamaPointsBalance($memberId);
        $kesValue = $balance * self::POINT_TO_KES_RATE;
        
        // Fetch member phone to send SMS
        $member = $this->memberModel->findById($memberId);
        if ($member && !empty($member['phone'])) {
            $msg = "Your Chama balance is {$balance} points. Value: KES {$kesValue}.";
            $this->smsService->sendSMS($member['phone'], $msg);
        }
        
        return $this->jsonResponse($response, [
            'balance'   => $balance, 
            'kes_value' => $kesValue
        ]);
    }

    /**
     * Option 2: Redeem Points
     */
    public function redeemAction(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $memberId = (int)($data['member_id'] ?? 0);
        $pointsToRedeem = (int)($data['points'] ?? 0);

        // 1. Validate against minimum points
        if ($pointsToRedeem < self::MIN_REDEMPTION_POINTS) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Minimum 10 points.'], 400);
        }

        // 2. Prevent redemption if points are insufficient
        $currentBalance = $this->walletModel->getChamaPointsBalance($memberId);
        if ($pointsToRedeem > $currentBalance) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Insufficient points.'], 400);
        }

        // 3. Execute Deduction
        $success = $this->transactionService->execute(
            $memberId,
            3,
            -$pointsToRedeem,
            'RED-' . strtoupper(bin2hex(random_bytes(4))),
            'Chama Points Redemption'
        );

        if ($success) {
            $member = $this->memberModel->findById($memberId);
            if ($member && !empty($member['phone'])) {
                $msg = "Success: You redeemed {$pointsToRedeem} points for KES " . ($pointsToRedeem * self::POINT_TO_KES_RATE) . ".";
                $this->smsService->sendSMS($member['phone'], $msg);
            }
            return $this->jsonResponse($response, ['status' => 'success', 'message' => 'Redemption successful.']);
        }

        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Transaction failed'], 500);
    }
}