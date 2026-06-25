<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\LoanRequest;
use App\Services\SmsService;
use App\Services\TransactionService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LoanController extends Controller
{
    private Member $member;
    private LoanRequest $loanRequest;
    private SmsService $smsService;
    private TransactionService $transactionService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->loanRequest = $container->get(LoanRequest::class);
        $this->smsService = $container->get(SmsService::class);
        $this->transactionService = $container->get(TransactionService::class);
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

        if ($this->loanRequest->hasPendingRequest((int) $member['id'])) {
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
     * Admin approves and disburses the loan.
     */
    public function disburseLoan(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $loanId = (int) ($data['loan_id'] ?? 0);
        $adminId = (int) ($data['admin_id'] ?? 0);

        $loan = $this->loanRequest->findById($loanId);

        if (!$loan || $loan['status'] !== 'pending') {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid or already processed.'], 400);
        }

        // Atomic transaction: Logs to 'transactions' AND updates wallet balance
        $receipt = 'LOAN-' . strtoupper(bin2hex(random_bytes(4)));
        $success = $this->transactionService->execute(
            (int) $loan['member_id'],
            (int) $loan['wallet_type_id'],
            (int) $loan['amount'],
            'Credit',
            "Loan Disbursement: ID {$loanId}",
            $receipt
        );

        if ($success) {
            $this->loanRequest->updateStatus($loanId, 'approved', $adminId);

            $member = $this->member->findById((int) $loan['member_id']);
            $this->smsService->sendSMS($member['phone'], "Loan of KES {$loan['amount']} approved and credited.");

            return $this->jsonResponse($response, ['status' => 'success']);
        }

        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Disbursement failed'], 500);
    }

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