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
     * Processes an STK Push initialization deposit into the Main Wallet
     */
   public function deposit(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        // 1. Validation
        if (empty($data['phone']) || empty($data['amount']) || empty($data['member_id'])) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Missing required inputs'], 400);
        }

        $phone = (string) $data['phone'];
        $amount = (float) $data['amount'];
        $memberId = (int) $data['member_id'];
        $mainWalletTypeId = 1;

        // 2. Prevent duplicate pending transactions
        if ($this->mpesaModel->hasPendingTransaction($memberId, $mainWalletTypeId)) {
            return $this->jsonResponse($response, [
                'status' => 'error', 
                'message' => 'You have a pending transaction. Please complete it on your phone.'
            ], 409);
        }

        try {
            // 3. Initiate STK Push
            $stkResult = $this->mpesaService->initiateStkPush($amount, $phone, [
                'member_id'      => $memberId,
                'wallet_type_id' => $mainWalletTypeId,
                'account_ref'    => 'Main-' . $memberId
            ]);

            // 4. Handle Daraja API Response
            if (isset($stkResult['CheckoutRequestID'])) {
                $this->logger->info("STK Push initiated for Member ID: $memberId", ['checkout_id' => $stkResult['CheckoutRequestID']]);
                
                // Return success immediately to the USSD user
                return $this->jsonResponse($response, [
                    'status' => 'success', 
                    'message' => 'STK Push initiated. Please check your phone for the prompt.'
                ], 200);
            }

            // 5. Handle Gateway Errors
            $this->logger->error("STK Push failed for Member ID: $memberId", ['response' => $stkResult]);
            return $this->jsonResponse($response, [
                'status' => 'error', 
                'message' => 'Could not initiate M-Pesa request. Please try again.'
            ], 500);

        } catch (\Exception $e) {
            $this->logger->error("Deposit controller error: " . $e->getMessage());
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Internal Server Error'], 500);
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
