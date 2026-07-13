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
    private MpesaService $mpesaService;
    private Mpesa $mpesaModel;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->wallet = $container->get(Wallet::class);
        $this->transactionService = $container->get(TransactionService::class);
        $this->smsService = $container->get(SmsService::class);
        $this->welfareClaim = $container->get(WelfareClaim::class);
        $this->mpesaService = $container->get(MpesaService::class);
        $this->mpesaModel = $container->get(Mpesa::class);
    }

   
    /**
     * Processes an STK Push initialization deposit into the Welfare Wallet (ID: 2)
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
        $welfareWalletTypeId = 2; // Welfare Wallet ID as per your wallet_types table

        // 2. Prevent duplicate pending transactions for the same wallet
        if ($this->mpesaModel->hasPendingTransaction($memberId, $welfareWalletTypeId)) {
            return $this->jsonResponse($response, [
                'status' => 'error', 
                'message' => 'You already have a pending welfare deposit. Please complete the M-Pesa prompt.'
            ], 409);
        }

        try {
            // 3. Initiate STK Push via MpesaService
            $stkResult = $this->mpesaService->initiateStkPush($amount, $phone, [
                'member_id'      => $memberId,
                'wallet_type_id' => $welfareWalletTypeId,
                'account_ref'    => 'Welfare-' . $memberId
            ]);

            // 4. Handle Daraja API Response
            if (isset($stkResult['CheckoutRequestID'])) {
                $this->logger->info("Welfare STK Push initiated for Member ID: $memberId", ['checkout_id' => $stkResult['CheckoutRequestID']]);
                return $this->jsonResponse($response, [
                    'status' => 'success', 
                    'message' => 'Welfare deposit initiated',
                    'data' => $stkResult
                ], 200);
            }

            $this->logger->error("Welfare STK Push failed for Member ID: $memberId", ['response' => $stkResult]);
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Could not initiate M-Pesa request'], 500);

        } catch (\Exception $e) {
            $this->logger->error("Welfare deposit controller error: " . $e->getMessage());
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Internal Server Error'], 500);
        }
    }
    
    public function getClaims(Request $request, Response $response): Response
    {
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
            $this->logger->warning("Status check failed: Member not found for phone $phone");
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        $claim = $this->welfareClaim->findLatestByMember((int) $user['id']);

        if (!$claim) {
            $this->logger->info("Status check: No claims found for Member ID: {$user['id']}");
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'No welfare claims found'], 404);
        }

        $message = "Your last welfare claim ({$claim['tracking_number']}) status is: " . strtoupper($claim['status']) . ".";

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