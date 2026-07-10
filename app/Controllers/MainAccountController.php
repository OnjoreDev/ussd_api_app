<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\Mpesa;
use App\Models\Wallet;
use App\Services\MpesaService;         
use App\Services\TransactionService;
use App\Services\SmsService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class MainAccountController extends Controller
{
    private TransactionService $transactionService;
    private Member $member;
    private SmsService $smsService;
    private MpesaService $mpesaService;
    private Mpesa $mpesaModel;         
    private Wallet $walletModel;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->transactionService = $container->get(TransactionService::class);
        $this->member = $container->get(Member::class);
        $this->smsService = $container->get(SmsService::class);
        $this->mpesaService = $container->get(MpesaService::class);
        $this->mpesaModel = $container->get(Mpesa::class);        
        $this->walletModel = $container->get(Wallet::class);
    }

    /**
     * Processes an STK Push initialization deposit into the Main Wallet (ID 1).
     * Replaces an atomic immediate credit with an asynchronous transaction log flow.
     */
     /**
     * Processes an STK Push initialization deposit into the Main Wallet (ID 1).
     */
     public function deposit(Request $request, Response $response): Response
    {
        $this->logger->info("DEBUG: Received Payload: " . json_encode($request->getParsedBody()));
        $data = $request->getParsedBody();
        $memberId = (int)($data['member_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0.0);
        $phone = (string)($data['phone'] ?? '');

        if ($memberId <= 0 || $amount <= 0 || empty($phone)) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid inputs'], 400);
        }

        $walletTypeId = 1; // Main

        // NEW: Prevent duplicate pushes by checking for pending transactions
        if ($this->mpesaModel->hasPendingTransaction($memberId, $walletTypeId)) {
            return $this->jsonResponse($response, [
                'status' => 'error', 
                'message' => 'A transaction is already pending. Please wait for the M-Pesa prompt.'
            ], 409);
        }

        try {
            $stkResult = $this->mpesaService->initiateStkPush($amount, $phone, [
                'member_id'      => $memberId,
                'wallet_type_id' => $walletTypeId,
                'account_ref'    => 'Main-' . $memberId
            ]);

            $this->logger->info("DEBUG: Safaricom API Response: " . json_encode($stkResult));

            if (isset($stkResult->CheckoutRequestID)) {
                $this->mpesaModel->createTransaction([
                    'member_id'           => $memberId,
                    'wallet_type_id'      => $walletTypeId,
                    'amount'              => $amount,
                    'phone_number'        => $phone,
                    'checkout_request_id' => $stkResult->CheckoutRequestID,
                    'merchant_request_id' => $stkResult->MerchantRequestID
                ]);

                return $this->jsonResponse($response, ['status' => 'success', 'data' => $stkResult]);
            }

            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Failed to initiate STK'], 400);
        } catch (Exception $e) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper to find Main Account (Type 1) balance from member's wallets
     */
    public function getMainWalletBalance(Request $request, Response $response): Response
    {
        // 1. Get the member_id from query parameters
        $queryParams = $request->getQueryParams();
        $memberId = isset($queryParams['member_id']) ? (int)$queryParams['member_id'] : null;

        if (!$memberId) {
            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'Missing required parameter: member_id'
            ], 400);
        }

        // 2. Define the Main Account wallet type ID (Type 1)
        $mainWalletTypeId = 1;

        try {
            // 3. Fetch the wallet from your model
            $wallet = $this->walletModel->getWalletByMemberAndType($memberId, $mainWalletTypeId);

            if (!$wallet) {
                return $this->jsonResponse($response, [
                    'status' => 'error',
                    'message' => 'Main wallet not found for this member.'
                ], 404);
            }

            // Safe fallback lookup string for phone targets if your wallet payload model isolates phone definitions
            $smsTargetPhone = $wallet["phone"] ?? ($memberLookup['phone'] ?? null);

            if ($smsTargetPhone) {
                 $this->smsService->sendSMS($smsTargetPhone, "Your main account balance is KES " . $wallet["balance"]);
            } else {
                 $this->logger->warning("Could not send balance notification SMS for Member ID {$memberId}: Phone target unresolved.");
            }

            // 4. Return the balance successfully
            return $this->jsonResponse($response, [
                'status' => 'success',
                'data' => [
                    'member_id' => $memberId,
                    'wallet_type_id' => $mainWalletTypeId,
                    'balance' => (float)$wallet['balance']
                ]
            ], 200);

        } catch (Exception $e) {
            // 5. Catch block using your exact error structure
            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'Server processing breakdown: ' . $e->getMessage()
            ], 500);
        }
    }
}