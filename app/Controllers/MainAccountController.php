<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Services\TransactionService;
use App\Services\SmsService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MainAccountController extends Controller
{
    private TransactionService $transactionService;
    private Member $member;
    private SmsService $smsService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->transactionService = $container->get(TransactionService::class);
        $this->member = $container->get(Member::class);
        $this->smsService = $container->get(SmsService::class);
    }

    public function deposit(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $memberId = (int)$data['member_id'];
        $amount = (int)$data['amount'];

        // 1 = Main Wallet Type
        $receipt = 'MAIN-' . strtoupper(bin2hex(random_bytes(4)));
        
        $success = $this->transactionService->execute(
            $memberId,
            1,
            $amount,
            'Credit',
            'General Main Account Deposit',
            $receipt
        );

        if ($success) {
            // Retrieve member details to get phone number
            $member = $this->member->findById($memberId);
            
            if ($member && !empty($member['phone'])) {
                // Fetch current balance using defined model method
                $balance = $this->getMainWalletBalance($memberId);
                
                $message = "Deposit Success: KES $amount credited to your Main Account. New Balance: KES $balance. Receipt: $receipt";
                $this->smsService->sendSMS($member['phone'], $message);
            }

            return $this->jsonResponse($response, ['status' => 'success']);
        }

        return $this->jsonResponse($response, ['status' => 'error'], 500);
    }

    /**
     * Helper to find Main Account (Type 1) balance from member's wallets
     */
    private function getMainWalletBalance(int $memberId): int
    {
        $wallets = $this->member->getWalletsByMemberId($memberId);
        foreach ($wallets as $wallet) {
            if ((int)$wallet['wallet_type_id'] === 1) {
                return (int)$wallet['balance'];
            }
        }
        return 0;
    }
}