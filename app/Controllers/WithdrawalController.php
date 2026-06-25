<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\Wallet;
use App\Services\TransactionService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WithdrawalController extends Controller
{
    private Member $member;
    private Wallet $wallet;
    private TransactionService $transactionService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->wallet = $container->get(Wallet::class);
        $this->transactionService = $container->get(TransactionService::class);
    }

    public function withdraw(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $memberId = (int)($data['member_id'] ?? 0);
        $amount = (int)($data['amount'] ?? 0);
        // Identify which wallet: 1 = Main, 2 = Welfare
        $walletTypeId = (int)($data['wallet_type_id'] ?? 1); 

        // 1. Date Restriction (1st, 5th, 15th)
        $allowedDays = [1, 5, 15];
        if (!in_array((int)date('d'), $allowedDays)) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Withdrawals restricted to 1st, 5th, 15th.'], 403);
        }

        // 2. Validate Balance
        $wallet = $this->wallet->getWalletByMemberAndType($memberId, $walletTypeId);
        if (!$wallet || (int)$wallet['balance'] < $amount) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Insufficient funds'], 400);
        }

        // 3. Atomic Debit
        $receipt = 'WTH-' . strtoupper(bin2hex(random_bytes(4)));
        $success = $this->transactionService->execute(
            $memberId,
            $walletTypeId,
            $amount,
            'Debit',
            'Withdrawal from ' . ($walletTypeId === 2 ? 'Welfare' : 'Main'),
            $receipt
        );

        return $success ? $this->jsonResponse($response, ['status' => 'success']) 
                        : $this->jsonResponse($response, ['status' => 'error'], 500);
    }
}