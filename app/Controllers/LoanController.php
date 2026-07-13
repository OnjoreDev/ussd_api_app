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
