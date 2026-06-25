<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\LoanRequest;
use App\Services\SmsService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LoanController extends Controller
{
    private Member $member;
    private LoanRequest $loanRequest;
    private SmsService $smsService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->loanRequest = $container->get(LoanRequest::class);
        $this->smsService = $container->get(SmsService::class);
    }

    public function requestLoan(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $amount = (int) ($data['amount'] ?? 0);
        $phone = $data['phone'] ?? '';

        if ($amount <= 0) {
            $this->logger->error("Loan Request Failed: Invalid amount", ['amount' => $amount, 'phone' => $phone]);
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid amount'], 400);
        }

        $member = $this->member->findByPhone($phone);
        if (!$member) {
            $this->logger->error("Loan Request Failed: Member not found", ['phone' => $phone]);
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        // Check for existing pending requests
        if ($this->loanRequest->hasPendingRequest((int)$member['id'])) {
            $this->logger->warning("Loan Request Denied: Active pending request exists", ['member_id' => $member['id']]);
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'You already have an active pending loan request.'], 409);
        }

        $success = $this->loanRequest->createPending((int)$member['id'], 4, $amount);

        if ($success) {
            // Send notification via SmsService
            $this->smsService->sendSMS($phone, "Your loan request of KES $amount has been received and is currently being processed by our admins.");
            
            $this->logger->info("Loan Request Created", ['member_id' => $member['id'], 'amount' => $amount]);
            return $this->jsonResponse($response, ['status' => 'success', 'message' => 'Loan request submitted successfully.']);
        }

        $this->logger->error("Loan Request Failed: Database insertion error", ['member_id' => $member['id']]);
        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'System connection error'], 500);
    }

    public function getLoanStatus(Request $request, Response $response, array $args): Response
    {
        $phone = $args['phone'] ?? '';
        $member = $this->member->findByPhone($phone);

        if (!$member) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        // Fetch latest loan request status
        $loan = $this->loanRequest->findLatestByMember((int)$member['id']);

        if (!$loan) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'No loan history found'], 404);
        }

        return $this->jsonResponse($response, [
            'status' => 'success',
            'data' => [
                'amount' => $loan['amount'],
                'status' => $loan['status'],
                'created_at' => $loan['created_at']
            ]
        ]);
    }
}