<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\LoanRequest;
use App\Models\Mpesa;
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

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->loanRequest = $container->get(LoanRequest::class);
        $this->smsService = $container->get(SmsService::class);
        $this->transactionService = $container->get(TransactionService::class);
        $this->mpesaService = $container->get(MpesaService::class); // Injected via container
        $this->mpesaModel = $container->get(Mpesa::class);
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
   /**
 * Handles the loan disbursement request from the admin dashboard.
 */
public function disburseLoan(Request $request, Response $response): Response
{
    $data = $request->getParsedBody();
    $loanId = (int) ($data['loan_id'] ?? 0);
    $adminId = (int) ($data['admin_id'] ?? 0);

    // 1. Retrieve and validate the loan request
    $loan = $this->loanRequest->findById($loanId);

    if (!$loan || $loan['status'] !== 'pending') {
        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Loan not found or not pending.'], 400);
    }

    // 2. Retrieve member details
    $member = $this->member->findById((int)$loan['member_id']);
    $reference = "LOAN_" . $loanId . "_" . time();

    // 3. Initiate Disbursement via MpesaService
    // We pass the member_id here so the service can persist the transaction record to the DB
    $result = $this->mpesaService->disburse(
        (float)$loan['amount'], 
        $member['phone'], 
        "Loan", 
        $reference, 
        (int)$loan['member_id']
    );

    // 4. Handle API Response
    // If successful, M-Pesa returns a ConversationID
    if (isset($result['ConversationID'])) {
        
        // Update local loan status to 'approved' to indicate the transaction is in-flight
        $this->loanRequest->updateStatus($loanId, 'approved', [
            'conversation_id' => $result['ConversationID'],
            'approved_by' => $adminId
        ]);

        return $this->jsonResponse($response, [
            'status' => 'success',
            'message' => 'Disbursement initiated successfully',
            'conversation_id' => $result['ConversationID']
        ]);
    }

    // 5. Handle Failures (e.g., Security Credential Error 8006)
    return $this->jsonResponse($response, [
        'status' => 'error', 
        'message' => 'M-Pesa API failure', 
        'details' => $result['details'] ?? 'Check logs for further information'
    ], 500);
}
}
