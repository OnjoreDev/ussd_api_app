<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LoanRequest;
use App\Models\Mpesa;
use App\Services\TransactionService;
use Psr\Container\ContainerInterface;
use App\Models\MpesaC2B;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MpesaResponseController extends Controller
{
    private Mpesa $mpesaModel;
    private TransactionService $transactionService;
    private MpesaC2B $c2b;
    private LoanRequest $loanRequest;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->mpesaModel = $container->get(Mpesa::class);
        $this->transactionService = $container->get(TransactionService::class);
        $this->c2b = $container->get(MpesaC2B::class);
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
     * Updates the transaction status based on the ResultCode.
     * * @param Request $request
     * @param Response $response
     * @return Response
     */
 public function handleB2CResult(Request $request, Response $response): Response
{
    $payload = json_decode($request->getBody()->getContents(), true);
    $resultData = $payload['Result'] ?? null;

    if (!$resultData) return $response->withStatus(400);

    $conversationId = $resultData['ConversationID'];
    $resultCode = $resultData['ResultCode']; // 0 means Success

    // 1. Find the loan by conversation_id
    $loan = $this->loanRequest->findByConversationId($conversationId);
    
    if ($resultCode == 0) {
        // SUCCESS: Mark loan as cleared or disbursed
        $this->loanRequest->updateStatus($loan['id'], 'cleared', [
            'transaction_id' => $resultData['TransactionID']
        ]);
        
        // Update your mpesa_b2c_transactions table
        $this->c2b->updateB2CTransaction($conversationId, 'success', $resultData);
    } else {
        // FAILED: Mark loan as rejected or pending
        $this->loanRequest->updateStatus($loan['id'], 'rejected', ['error' => $resultData['ResultDesc']]);
        $this->c2b->updateB2CTransaction($conversationId, 'failed', $resultData);
    }

    return $response->withStatus(200);
}
    /**
     * Handles the B2C Timeout callback from Safaricom.
     * Triggered if the request couldn't be queued by Safaricom.
     */
    public function handleB2CTimeout(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $this->logger->warning("B2C Timeout Callback Received", ['payload' => $data]);

        // Logic:
        // 1. This means the request never reached the processing stage.
        // 2. Identify the transaction using the request ID or conversation ID.
        // 3. Update status to 'failed' or 'timed_out'.
        // 4. Optionally, notify the admin to manually retry or investigate.

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
