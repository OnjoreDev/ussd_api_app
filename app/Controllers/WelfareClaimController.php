<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\Wallet;
use App\Models\WelfareClaim;
use App\Models\Mpesa;                  // 1. Import Mpesa Model
use App\Services\MpesaService;         // 2. Import Mpesa Service
use App\Services\TransactionService;
use App\Services\SmsService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class WelfareClaimController extends Controller
{
    private Member $member;
    private Wallet $wallet;
    private TransactionService $transactionService;
    private SmsService $smsService;
    private WelfareClaim $welfareClaim;
    private MpesaService $mpesaService; // Added
    private Mpesa $mpesaModel;         // Added

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->wallet = $container->get(Wallet::class);
        $this->transactionService = $container->get(TransactionService::class);
        $this->smsService = $container->get(SmsService::class);
        $this->welfareClaim = $container->get(WelfareClaim::class);
        $this->mpesaService = $container->get(MpesaService::class); // Instantiated
        $this->mpesaModel = $container->get(Mpesa::class);         // Instantiated
    }

    /**
     * Processes an STK Push initialization deposit into the Welfare Wallet (ID 2).
     */
    /**
     * Processes an STK Push initialization deposit into the Welfare Wallet (ID 2).
     * This replaces the immediate database balance logic with an asynchronous M-Pesa flow.
     */
    public function deposit(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        // Ensure we capture all necessary payload arguments from the USSD / Utility API wrapper call
        if (empty($data['phone']) || empty($data['amount']) || empty($data['member_id'])) {
            return $this->jsonResponse($response, [
                'status' => 'error', 
                'message' => 'Missing parameter inputs. phone, amount, and member_id are required.'
            ], 400);
        }

        $phone = (string) $data['phone'];
        $amount = (int) $data['amount'];
        $memberId = (int) $data['member_id'];
        $walletTypeId = 2; // Hardcoded strictly to ID 2 for the Welfare account wallet structure

        try {
            $this->logger->info("Initiating Welfare Deposit STK Push via USSD API trigger for Member ID: {$memberId}, Amount: {$amount}");

            // Trigger the Safaricom Daraja Gateway push using all 4 required arguments
            $stkResult = $this->mpesaService->initiateStkPush(
                $phone, 
                $amount, 
                "Welfare Dep",          // AccountReference (Argument 3)
                "Welfare Contribution"  // TransactionDesc  (Argument 4)
            );

            // If Safaricom accepts the request, track it as 'pending' inside the database
            if (isset($stkResult['ResponseCode']) && $stkResult['ResponseCode'] === "0") {
                
                $dbPayload = [
                    'member_id'           => $memberId,
                    'wallet_type_id'      => $walletTypeId,
                    'amount'              => $amount,
                    'phone_number'        => $phone,
                    'checkout_request_id' => $stkResult['CheckoutRequestID'],
                    'merchant_request_id' => $stkResult['MerchantRequestID']
                ];

                // Persist the transaction into mpesa_transactions table with its native 'pending' flag state
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
            $this->logger->error('Welfare Controller Deposit Initialization Error: ' . $e->getMessage());
            
            return $this->jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Server processing breakdown: ' . $e->getMessage()
            ], 500);
        }
    }
    // Add to WelfareClaimController.php

    public function getClaims(Request $request, Response $response): Response
    {
        // Assuming member is identified via Auth/Bearer token or request params
        $user = $this->member->findByPhone($request->getQueryParams()['phone'] ?? '');

        if (!$user) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        $claims = $this->welfareClaim->findByMemberId((int) $user['id']);
        return $this->jsonResponse($response, ['status' => 'success', 'data' => $claims]);
    }

    public function createClaim(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $user = $this->member->findByPhone($data['phone'] ?? '');

        if (!$user) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        if ($this->welfareClaim->hasActiveClaim((int) $user['id'])) {
            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'You already have an active welfare claim.'
            ], 409);
        }

        $tracking = 'CLM-' . strtoupper(bin2hex(random_bytes(3)));

        // No longer passing relationship here
        $success = $this->welfareClaim->create((int) $user['id'], $data['claim_type'], $tracking);

        if ($success) {
            $this->smsService->sendSMS($data['phone'], "Your welfare claim ($tracking) has been submitted for review.");
            return $this->jsonResponse($response, ['status' => 'success', 'tracking' => $tracking]);
        }

        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'System error'], 500);
    }

    public function getStatus(Request $request, Response $response): Response
    {
        $phone = $request->getQueryParams()['phone'] ?? '';
        $user = $this->member->findByPhone($phone);

        if (!$user) {
            // Log the search failure
            $this->logger->warning("Status check failed: Member not found for phone $phone");
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        $claim = $this->welfareClaim->findLatestByMember((int) $user['id']);

        if (!$claim) {
            $this->logger->info("Status check: No claims found for Member ID: {$user['id']}");
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'No welfare claims found'], 404);
        }

        // Prepare status message
        $message = "Your last welfare claim ({$claim['tracking_number']}) status is: " . strtoupper($claim['status']) . ".";

        // Dispatch and Log
        if ($this->smsService->sendSMS($phone, $message)) {
            $this->logger->info("Status SMS sent successfully", ['phone' => $phone, 'tracking' => $claim['tracking_number']]);
        } else {
            $this->logger->error("Status SMS failed to send", ['phone' => $phone, 'tracking' => $claim['tracking_number']]);
        }

        return $this->jsonResponse($response, [
            'status' => 'success',
            'data' => [
                'tracking_number' => $claim['tracking_number'],
                'claim_type' => $claim['claim_type'],
                'status' => $claim['status'],
                'updated_at' => $claim['updated_at']
            ]
        ]);
    }
}
