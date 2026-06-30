<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\Mpesa;                  
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
    private MpesaService $mpesaService; // Added
    private Mpesa $mpesaModel;         // Added

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->transactionService = $container->get(TransactionService::class);
        $this->member = $container->get(Member::class);
        $this->smsService = $container->get(SmsService::class);
        $this->mpesaService = $container->get(MpesaService::class); // Instantiated
        $this->mpesaModel = $container->get(Mpesa::class);         // Instantiated
    }


    /**
     * Processes an STK Push initialization deposit into the Main Wallet (ID 1).
     * Replaces an atomic immediate credit with an asynchronous transaction log flow.
     */

    public function deposit(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $memberId = (int)($data['member_id'] ?? 0);
        $amount = (int)($data['amount'] ?? 0); // Convert to float for M-Pesa accuracy

        // 1. Basic Validation
        // Your existing phone parameter must be captured here from the USSD Utility call
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
            // Provide specific reference "Main Dep" and description "Main Wallet Fund"
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
                    'phone_number'        => $phone,
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
