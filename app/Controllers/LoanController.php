<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\LoanRequest;
use App\Models\Mpesa;
use App\Models\MpesaC2B;
use App\Services\SmsService;
use App\Services\TransactionService;
use App\Services\MpesaService; // Injected Mpesa Service
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LoanController extends Controller
{
    private Member $member;
    private LoanRequest $loanRequest;
    private SmsService $smsService;
    private TransactionService $transactionService;
    private MpesaService $mpesaService; // Declared property
    private Mpesa $mpesaModel;
    private MpesaC2B $c2b;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->loanRequest = $container->get(LoanRequest::class);
        $this->smsService = $container->get(SmsService::class);
        $this->transactionService = $container->get(TransactionService::class);
        $this->mpesaService = $container->get(MpesaService::class); // Injected via container
        $this->mpesaModel = $container->get(Mpesa::class);
        $this->c2b = $container->get(MpesaC2B::class);
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
            $this->smsService->sendSMS($phone, "An active loan request already exists");
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

    /**
     * Approves and disburses a loan to the member via M-Pesa B2C.
     */
    /**
     * Approves and disburses a loan.
     * 1. Validates the loan request.
     * 2. Records the M-Pesa B2C intent.
     * 3. Records a debit transaction in the ledger (as a negative balance).
     * 4. Triggers the M-Pesa API.
     */
    public function disburseLoan(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $loanId = (int) ($data['loan_id'] ?? 0);
        $adminId = (int) ($data['admin_id'] ?? 0);

        $loan = $this->loanRequest->findById($loanId);

        // Only allow starting from 'pending'
        if (!$loan || $loan['status'] !== 'pending') {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Loan not found or not pending.'], 400);
        }

        $member = $this->member->findById((int)$loan['member_id']);
        $reference = "LOAN_" . $loanId . "_" . time();
        $originatorId = bin2hex(random_bytes(16));

        $result = $this->mpesaService->disburse((float)$loan['amount'], $member['phone'], "Loan", $reference);

        if (isset($result['ConversationID'])) {
            // 1. Log the B2C transaction to your database
            $this->c2b->createB2CTransaction([
                'member_id' => $loan['member_id'],
                'amount'    => (float)$loan['amount'],
                'phone'     => $member['phone'],
                'reference' => $reference,
                'originator_conversation_id' => $originatorId,
                'conversation_id' => $result['ConversationID']
            ]);

            // 2. Update loan status to 'approved' (since 'processing' doesn't exist)
            // This signifies the loan is "in flight" via M-Pesa
            $this->loanRequest->updateStatus($loanId, 'approved', [
                'conversation_id' => $result['ConversationID'],
                'approved_by' => $adminId
            ]);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'Disbursement initiated',
                'conversation_id' => $result['ConversationID']
            ]);
        }

        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'M-Pesa API failure'], 500);
    }
}
