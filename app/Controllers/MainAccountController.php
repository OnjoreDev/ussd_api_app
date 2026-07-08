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
    public function deposit(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $memberId = (int)($data['member_id'] ?? 0);
        // CHANGED: Converted to float for M-Pesa and Ledger accuracy
        $amount = (float)($data['amount'] ?? 0.0); 

        // 1. Basic Validation
        if ($memberId <= 0 || $amount <= 0 || empty($data['phone'])) {
            return $this->jsonResponse($response, [
                'status' => 'error', 
                'message' => 'Invalid input parameters. Ensure member_id, amount, and phone are provided.'
            ], 400);
        }

        $phone = (string) $data['phone'];
        $walletTypeId = 1; // Explicitly 1 for Main Wallet

        // Ensure member exists
        $memberLookup = $this->member->findById($memberId);
        if (!$memberLookup) {
            return $this->jsonResponse($response, [
                'status' => 'error', 
                'message' => 'Member account not found.'
            ], 404);
        }

        try {
            $this->logger->info("Initiating Main Deposit STK Push via USSD API trigger for Member ID: {$memberId}, Amount: {$amount}");
            $this->logger->info("DEBUG: Sending STK to Phone: " . $phone);
            
            // 2. Trigger the Safaricom Daraja Gateway push prompt thread.
            $stkResult = $this->mpesaService->initiateStkPush(
                $phone, 
                $amount, 
                "Main Dep", 
                "Main Wallet Fund"
            );

            // 3. If Safaricom accepts the request structure, track it as 'pending' inside the database
            if (isset($stkResult['ResponseCode']) && $stkResult['ResponseCode'] === "0") {
                
                $dbPayload = [
                    'member_id'           => $memberId,
                    'wallet_type_id'      => $walletTypeId,
                    'amount'              => $amount,
                    'phone_number'        => $phone, // Correctly intercepted and converted to 'phone' by our updated Mpesa model
                    'checkout_request_id' => $stkResult['CheckoutRequestID'],
                    'merchant_request_id' => $stkResult['MerchantRequestID']
                ];

                // Persist the transaction into mpesa_transactions table with its native 'pending' state flag
                $this->mpesaModel->createTransaction($dbPayload);

                return $this->jsonResponse($response, [
                    'status'  => 'success',
                    'message' => 'STK Push initiated successfully. Please enter your M-Pesa PIN on your phone.',
                    'data'    => [
                        'MerchantRequestID' => $stkResult['MerchantRequestID'],
                        'CheckoutRequestID' => $stkResult['CheckoutRequestID'],
                        'CustomerMessage'   => $stkResult['CustomerMessage']
                    ]
                ], 200);
            }

            // Handles scenarios where the API request structural validation fails downstream on Safaricom's side
            return $this->jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Safaricom gateway rejected initialization parameters.',
                'details' => $stkResult
            ], 400);

        } catch (Exception $e) {
            $this->logger->error('Main Controller Deposit Initialization Error: ' . $e->getMessage());
            
            return $this->jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Server processing breakdown: ' . $e->getMessage()
            ], 500);
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