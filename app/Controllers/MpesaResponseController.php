<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LoanRequest;
use App\Models\Mpesa;
use App\Services\TransactionService;
use Psr\Container\ContainerInterface;
use App\Models\MpesaB2C;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MpesaResponseController extends Controller
{
    private Mpesa $mpesaModel;
    private TransactionService $transactionService;
    private MpesaB2C $b2c;
    private LoanRequest $loanRequest;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->mpesaModel = $container->get(Mpesa::class);
        $this->transactionService = $container->get(TransactionService::class);
        $this->b2c = $container->get(MpesaB2C::class);
        $this->loanRequest = $container->get(LoanRequest::class);
    }

    public function handleCallBack(Request $request, Response $response): Response
    {
        $rawPayload = (string)$request->getBody();
        $payload = json_decode($rawPayload, true);

        // 1. LOG EVERYTHING: If you don't see this in your logs, Safaricom is NOT hitting your server
        $this->logger->info("M-Pesa Callback Raw Data: " . $rawPayload);

        // 2. IP Whitelisting (Optional: Disable if using ngrok/proxy to test)
        // If testing on localhost/ngrok, REMOTE_ADDR might be the local proxy, not Safaricom.
        // Comment this out if you're getting "Unauthorized" in your logs.
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        // $safaricomIps = ['196.201.214.200', ...]; 
        // if (!in_array($clientIp, $safaricomIps)) { ... }

        $stk = $payload['Body']['stkCallback'] ?? [];
        if (empty($stk['CheckoutRequestID'])) {
            $this->logger->error("M-Pesa callback invalid structure.");
            return $this->jsonResponse($response, ['ResultCode' => 1, 'ResultDesc' => 'Invalid payload'], 400);
        }

        $checkoutId = $stk['CheckoutRequestID'];
        $resultCode = (int)($stk['ResultCode'] ?? 1);

        // 3. Process Result
        if ($resultCode === 0) {
            $metadata = $stk['CallbackMetadata']['Item'] ?? [];
            $receipt = $this->extractReceipt($metadata);

            $trans = $this->mpesaModel->findByCheckoutRequestId($checkoutId);

            if ($trans) {
                // Ensure we don't process the same transaction twice
                if ($trans['status'] === 'completed') {
                    return $this->jsonResponse($response, ['ResultCode' => 0, 'ResultDesc' => 'Already processed']);
                }

                $success = $this->transactionService->execute(
                    (int)$trans['member_id'],
                    (int)$trans['wallet_type_id'],
                    (float)$trans['amount'],
                    'Credit',
                    $receipt,
                    "M-Pesa STK Top-up: $receipt"
                );

                if ($success) {
                    $this->mpesaModel->updateTransactionStatus($checkoutId, 'completed', $receipt);
                    $this->logger->info("Payment SUCCESS for checkout: $checkoutId");
                } else {
                    $this->logger->error("CRITICAL: TransactionService failed for checkout: $checkoutId");
                }
            } else {
                $this->logger->error("CRITICAL: No transaction record found for ID: $checkoutId");
            }
        } else {
            $this->mpesaModel->updateTransactionStatus($checkoutId, 'failed');
            $this->logger->warning("Payment FAILED (Code $resultCode) for checkout: $checkoutId");
        }

        return $this->jsonResponse($response, ['ResultCode' => 0, 'ResultDesc' => 'Success']);
    }



    /**
     * Handles the B2C Result callback from Safaricom.
     * Updates the loan status and records the disbursement as a credit in the ledger.
     */
    public function handleB2CResult(Request $request, Response $response): Response
    {
        $payload = json_decode($request->getBody()->getContents(), true);
        $resultData = $payload['Result'] ?? null;

        if (!$resultData) return $response->withStatus(400);

        $conversationId = $resultData['ConversationID'];
        $originatorId = $resultData['OriginatorConversationID']; // Use this for safer lookups
        $resultCode = (int)$resultData['ResultCode'];

        // Use OriginatorID to find the record because it's guaranteed to exist from the start
        $transaction = $this->b2c->findByOriginatorId($originatorId);

        if (!$transaction) {
            $this->logger->error("B2C Callback: Transaction not found for OriginatorID: $originatorId");
            return $response->withStatus(200); // Acknowledge to stop retries
        }

        // Now update the record
        $status = ($resultCode === 0) ? 'success' : 'failed';
        $this->b2c->updateB2CTransaction($conversationId, $status, $resultData);

        // Update Loan Status
        $loan = $this->loanRequest->findByConversationId($conversationId);

        if ($resultCode === 0 && $loan) {
            // SUCCESS: Mark loan as cleared
            $this->loanRequest->updateStatus($loan['id'], 'cleared', [
                'transaction_id' => $resultData['ResultParameters']['ResultParameter'][1]['Value'] ?? 'N/A'
            ]);

            // RECORD CREDIT IN LEDGER:
            // This logs the disbursement as a Credit to the member's account.
            // Using the TransactionService ensures balance integrity and audit logging.
            $this->transactionService->execute(
                (int)$loan['member_id'],
                (int)$loan['wallet_type_id'],
                (float)$loan['amount'],
                'Credit',
                $resultData['TransactionID'] ?? 'B2C_' . $conversationId,
                "Loan Disbursement Credit: Ref " . $loan['id']
            );
        } elseif ($loan) {
            // FAILED: Mark loan as rejected
            $this->loanRequest->updateStatus($loan['id'], 'rejected', [
                'error' => $resultData['ResultDesc'] ?? 'Unknown Error'
            ]);
        }

        return $this->jsonResponse($response, ['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
    /**
     * Handles the B2C Timeout callback from Safaricom.
     * Triggered if M-Pesa cannot process the request within the expected timeframe.
     */
    public function handleB2CTimeout(Request $request, Response $response): Response
    {
        // 1. Decode payload
        $data = $request->getParsedBody();
        $this->logger->warning("B2C Timeout Callback Received", ['payload' => $data]);

        $result = $data['Result'] ?? [];
        $originatorId = $result['OriginatorConversationID'] ?? null;

        // 2. Validate OriginatorID and find the transaction in your DB
        if ($originatorId) {
            $transaction = $this->b2c->findByOriginatorId($originatorId);

            if ($transaction) {
                // 3. Update the transaction status to 'failed'
                // We use the ConversationID from the callback if present, 
                // otherwise, we use a placeholder to mark the record as failed.
                $conversationId = $result['ConversationID'] ?? 'TIMEOUT_NO_CONV';

                $this->b2c->updateB2CTransaction(
                    $conversationId,
                    'failed',
                    $result
                );

                // 4. Update the associated Loan record status to 'rejected' (or 'pending' for retry)
                // This assumes the conversation_id was linked during the initial disburse()
                $loan = $this->loanRequest->findByConversationId($conversationId);
                if ($loan) {
                    $this->loanRequest->updateStatus($loan['id'], 'rejected', [
                        'error' => 'B2C Disbursement Timed Out: ' . ($result['ResultDesc'] ?? 'No description')
                    ]);
                }

                // 5. Critical Alert for Security issues (ResultCode 8006)
                if (($result['ResultCode'] ?? 0) === 8006) {
                    $this->logger->critical("URGENT: B2C Security Credentials are locked. Manual intervention required on Daraja Portal.");
                }
            } else {
                $this->logger->error("B2C Timeout: No transaction found for OriginatorID: $originatorId");
            }
        }

        // 6. Always acknowledge receipt to Safaricom to prevent redundant retries
        $payload = [
            'ResultCode' => 0,
            'ResultDesc' => 'Service accepted successfully'
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function extractReceipt(array $items): string
    {
        foreach ($items as $item) {
            if (isset($item['Name']) && ($item['Name'] === 'MpesaReceiptNumber')) {
                return (string)$item['Value'];
            }
        }
        return 'N/A';
    }
}
