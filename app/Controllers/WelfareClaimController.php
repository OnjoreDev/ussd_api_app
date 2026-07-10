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
     * Processes an STK Push initialization deposit into the Welfare Wallet (ID 2).
     * This replaces the immediate database balance logic with an asynchronous M-Pesa flow.
     */
   public function deposit(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data['phone']) || empty($data['amount']) || empty($data['member_id'])) {
            return $this->jsonResponse($response, [
                'status' => 'error', 
                'message' => 'Missing parameter inputs.'
            ], 400);
        }

        $phone = (string) $data['phone'];
        $amount = (float) $data['amount'];
        $memberId = (int) $data['member_id'];
        $walletTypeId = 2; // Welfare

        // NEW: Prevent duplicate pushes
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
                'account_ref'    => 'Welfare-' . $memberId
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