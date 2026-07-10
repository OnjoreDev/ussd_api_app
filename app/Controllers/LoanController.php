<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\LoanRequest;
use App\Services\SmsService;
use App\Services\TransactionService;
use App\Services\MpesaService; // Injected Mpesa Service
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class LoanController extends Controller
{
    private Member $member;
    private LoanRequest $loanRequest;
    private SmsService $smsService;
    private TransactionService $transactionService;
    private MpesaService $mpesaService; // Declared property

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->loanRequest = $container->get(LoanRequest::class);
        $this->smsService = $container->get(SmsService::class);
        $this->transactionService = $container->get(TransactionService::class);
        $this->mpesaService = $container->get(MpesaService::class); // Injected via container
    }

    /**
     * Member requests a loan.
     */
    public function requestLoan(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $phone = $data['phone'] ?? '';
        $amount = (int) ($data['amount'] ?? 0);

        $member = $this->member->findByPhone($phone);
        if (!$member) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        //if user already has a loan
        if ($this->loanRequest->hasPendingRequest((int) $member['id'])) {
            $this->smsService->sendSMS($phone,"An active loan request already exists");
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Active pending request exists.'], 409);
        }

        // 4 = Loan Wallet Type
        $success = $this->loanRequest->createPending((int) $member['id'], 4, $amount);

        if ($success) {
            $this->smsService->sendSMS($phone, "Your loan request of KES $amount has been received.");
            return $this->jsonResponse($response, ['status' => 'success', 'message' => 'Loan request submitted.']);
        }

        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'System error'], 500);
    }

    
    /**
     * POST /api/v1/loan/disburse
     */
    /**
     * Admin approves and disburses the loan directly via M-Pesa B2C.
     * POST /api/v1/loan/disburse
     */
    public function disburseLoan(Request $request, Response $response): Response
    {
        try {
            $body = $request->getParsedBody();
            $loanId = isset($body['loan_id']) ? (int)$body['loan_id'] : 0;
            $adminId = isset($body['admin_id']) ? (int)$body['admin_id'] : 0;

            if ($loanId <= 0) {
                return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid Loan ID parameter.'], 400);
            }

            // 1. Validation: Ensure the loan request exists and status is 'pending'
            $loan = $this->loanRequest->findById($loanId);
            if (!$loan || $loan['status'] !== 'pending') {
                return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid or already processed.'], 400);
            }

            $member = $this->member->findById((int)$loan['member_id']);
            if (!$member || empty($member['phone'])) {
                return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Associated member profile or phone record missing.'], 404);
            }

            // 2. Local State Management: Update internal ledgers first
            $receipt = 'LOAN-' . strtoupper(bin2hex(random_bytes(4)));
            
            $success = $this->transactionService->execute(
                (int) $loan['member_id'],
                (int) $loan['wallet_type_id'],
                (float) $loan['amount'],
                'Credit',
                $receipt,
                "Loan Disbursement: ID {$loanId}"
            );

            if (!$success) {
                return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Internal financial entry recording failed'], 500);
            }

            // FIXED: Set status from 'pending' to 'processing' to capture the intermediate gateway stage
            $this->loanRequest->updateStatus($loanId, 'processing', $adminId);

            // 3. Initiate External Mobile Disbursal (M-Pesa B2C Gateway)
            $mpesaResult = $this->mpesaService->initiateB2cPayout(
                $member['phone'],
                (float)$loan['amount'],
                (string)$loanId
            );

            // Handle intermediate synchronous handshake receipt check from Safaricom
            if (isset($mpesaResult['ResponseCode']) && (string)$mpesaResult['ResponseCode'] === '0') {
                return $this->jsonResponse($response, [
                    'status' => 'success',
                    'message' => 'Internal wallets balanced. M-Pesa payout dispatched for background processing.',
                    'conversation_id' => $mpesaResult['ConversationID'] ?? ''
                ], 202);
            }

            // FALLBACK: Gateway parameters validation failed, fallback to 'rejected'
            $this->loanRequest->updateStatus($loanId, 'rejected', $adminId);
            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'Safaricom gateway rejected initialization parameters.',
                'details' => $mpesaResult
            ], 400);

        } catch (Exception $e) {
            $this->logger->error('Loan Disbursal Pipeline Critical Failure: ' . $e->getMessage());
            
            // FIXED: Automatically marks the request as 'rejected' (valid enum options) on critical exception failures
            if (isset($loanId) && $loanId > 0) {
                $this->loanRequest->updateStatus($loanId, 'rejected', $adminId ?? 0);
            }

            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'Gateway/Processing Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch active loan application statuses.
     */
    public function getLoanStatus(Request $request, Response $response): Response
    {
        $phone = $request->getQueryParams()['phone'] ?? '';
        $member = $this->member->findByPhone($phone);

        if (!$member) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        $loan = $this->loanRequest->findLatestByMember((int) $member['id']);

        if (!$loan) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'No loan history found'], 404);
        }

        return $this->jsonResponse($response, [
            'status' => 'success',
            'data' => [
                'amount' => $loan['amount'],
                'status' => $loan['status'],
                'tracking_id' => $loan['id'],
                'created_at' => $loan['created_at']
            ]
        ]);
    }
}
