<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\Wallet;
use App\Models\WelfareClaim;
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

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->wallet = $container->get(Wallet::class);
        $this->transactionService = $container->get(TransactionService::class);
        $this->smsService = $container->get(SmsService::class);
        $this->welfareClaim = $container->get(WelfareClaim::class);
    }

    /**
     * Processes an atomic deposit into the Welfare Wallet (ID 2).
     */
    // Inside WelfareClaimController.php

    public function deposit(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $phone = $data['phone'] ?? '';
        $amount = (int) ($data['amount'] ?? 0);

        // 1. Basic Validation
        if ($amount <= 0) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid amount'], 400);
        }

        $user = $this->member->findByPhone($phone);
        if (!$user) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        // 2. Generate unique reference
        $reference = 'DEP-WEL-' . strtoupper(bin2hex(random_bytes(4)));

        try {
            // 3. Execute via Service
            // Note: I've updated the call to match the logic where the service 
            // handles the calculation and balance updates internally.
            $this->transactionService->execute(
                (int) $user['id'],
                2, // Welfare Wallet Type ID
                $amount,
                $reference,
                'Welfare Deposit via USSD'
            );

            // 4. Send Success SMS
            $this->smsService->sendSMS($phone, "Success: KES {$amount} credited to your Welfare Wallet.");

            return $this->jsonResponse($response, ['status' => 'success', 'message' => 'Deposit successful']);
        } catch (Exception $e) {
            // Log the actual error for debugging
            $this->logger->error("Deposit failed: " . $e->getMessage());

            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'Deposit failed: ' . $e->getMessage()
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
