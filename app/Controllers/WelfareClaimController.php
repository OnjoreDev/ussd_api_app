<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\WelfareClaim;
use App\Models\Member;
use App\Models\Wallet;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\SmsService;

class WelfareClaimController extends Controller
{
    private WelfareClaim $welfareClaim;
    private Member $member;
    private Wallet $wallet;
    private SmsService $smsService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->welfareClaim = $container->get(WelfareClaim::class);
        $this->member = $container->get(Member::class);
        $this->wallet = $container->get(Wallet::class);
    }

    /**
     * Fetch list of welfare claims for a member
     */
    public function getClaims(Request $request, Response $response): Response
    {
        $phone = $request->getQueryParams()['phone'] ?? '';
        $user = $this->member->findByPhone($phone);

        if (!$user) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        $claims = $this->welfareClaim->findByMemberId((int)$user['id']);
        return $this->jsonResponse($response, ['status' => 'success', 'claims' => $claims]);
    }

    /**
     * File a new claim
     */
    public function createClaim(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $phone = $data['phone'] ?? '';
        $claimType = $data['claim_type'] ?? '';

        $user = $this->member->findByPhone($phone);
        if (!$user) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        $isCreated = $this->welfareClaim->create((int)$user['id'], $claimType);

        if ($isCreated) {
            return $this->jsonResponse($response, ['status' => 'success']);
        }

        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Failed to file claim'], 500);
    }
    /**
     * Processes a deposit into the member's welfare wallet and notifies them via SMS.
     */
    public function deposit(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $phone = $data['phone'] ?? '';
        $amount = (float)($data['amount'] ?? 0);

        // 1. Verify the member exists
        $user = $this->member->findByPhone($phone);
        if (!$user) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        // 2. Perform the deposit via the Wallet model
        $success = $this->wallet->creditWelfare((int)$user['id'], $amount);

        if ($success) {
            // 3. Dispatch confirmation SMS
            $message = "Success: KES " . number_format($amount, 2) . " has been credited to your Welfare Wallet.";

            // Ensure you have a 'smsService' property in your controller
            $this->smsService->sendSMS($phone, $message);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'Deposit successful'
            ]);
        }

        // 4. Handle failure
        return $this->jsonResponse($response, [
            'status' => 'error',
            'message' => 'Deposit failed'
        ], 500);
    }
}
