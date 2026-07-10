<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\Mpesa;
use App\Models\LoanRequest; // Injected to handle loan request updates
use App\Services\TransactionService;
use Psr\Container\ContainerInterface;

class MpesaResponseController extends Controller
{
    private Mpesa $mpesaModel;
    private LoanRequest $loanModel;
    private TransactionService $transactionService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->mpesaModel = $container->get(Mpesa::class);
        $this->loanModel = $container->get(LoanRequest::class);
        $this->transactionService = $container->get(TransactionService::class);
    }

    /**
     * Existing STK Push Callback handling (C2B / Deposits)
     */
    public function handleCallback(Request $request, Response $response): Response
    {
        $rawPayload = $request->getBody()->getContents();
        $this->logger->info('Mpesa C2B Deposit Callback Raw Payload: ' . $rawPayload);
        
        $body = json_decode($rawPayload, true);

        if (!$body) {
            $this->logger->error('Mpesa Callback Error: Failed to decode JSON body. Payload: ' . $rawPayload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $callbackData = $body['Body']['stkCallback'] ?? null;

        if ($callbackData && isset($callbackData['ResultCode'])) {
            $resultCode = (int) $callbackData['ResultCode'];
            $resultDesc = (string) ($callbackData['ResultDesc'] ?? '');
            $checkoutRequestID = (string) ($callbackData['CheckoutRequestID'] ?? '');

            if ($resultCode === 0) {
                $tx = $this->mpesaModel->findByCheckoutRequestId($checkoutRequestID);

                if ($tx) {
                    $metadata = $callbackData['CallbackMetadata']['Item'] ?? [];
                    $receiptNumber = $this->extractMetaValue($metadata, 'MpesaReceiptNumber');
                    
                    $ledgerSuccess = $this->transactionService->execute(
                        (int)$tx['member_id'],
                        (int)$tx['wallet_type_id'],
                        (float)$tx['amount'], 
                        'Credit', 
                        $checkoutRequestID,
                        'M-Pesa Deposit: ' . $receiptNumber
                    );

                    if ($ledgerSuccess) {
                        $this->mpesaModel->updateTransactionStatus($checkoutRequestID, 'completed', $receiptNumber);
                        $this->logger->info("Transaction $checkoutRequestID successfully processed and posted to ledger.");
                    } else {
                        $this->logger->error("Transaction $checkoutRequestID payment succeeded but Ledger Write Failed.");
                    }
                } else {
                    $this->logger->warning("M-Pesa payment succeeded but CheckoutRequestID {$checkoutRequestID} not found in database.");
                }
            } else {
                $this->mpesaModel->updateTransactionStatus($checkoutRequestID, 'failed');
                
                if ($resultCode === 1037) {
                    $this->logger->warning("Transaction {$checkoutRequestID} FAILED: Handset Unreachable/Timeout (ResultCode 1037).");
                } elseif ($resultCode === 1032) {
                    $this->logger->warning("Transaction {$checkoutRequestID} FAILED: Cancelled explicitly by user (ResultCode 1032).");
                } else {
                    $this->logger->warning("Transaction {$checkoutRequestID} FAILED with Code {$resultCode}: {$resultDesc}");
                }
            }
        }

        return $this->jsonResponse($response, ['ResultCode' => 0, 'ResultDesc' => 'Success']);
    }

    /**
     * NEW: Handles B2C Disbursal Callbacks (ResultURL)
     */
    public function handleB2cCallback(Request $request, Response $response): Response
    {
        $rawPayload = $request->getBody()->getContents();
        $this->logger->info('Mpesa B2C Loan Disbursal Callback Raw Payload: ' . $rawPayload);
        
        $body = json_decode($rawPayload, true);

        if (!$body || !isset($body['Result'])) {
            $this->logger->error('Mpesa B2C Callback Error: Invalid structure. Payload: ' . $rawPayload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $resultData = $body['Result'];
        $resultCode = (int) ($resultData['ResultCode'] ?? -1);
        $resultDesc = (string) ($resultData['ResultDesc'] ?? '');
        
        $originatorConversationID = (string) ($resultData['OriginatorConversationID'] ?? '');
        $conversationID = (string) ($resultData['ConversationID'] ?? '');
        $transactionID = (string) ($resultData['TransactionID'] ?? ''); 

        // Extract Loan ID: Check direct JSON key first (for Postman testing), fallback to remarks regex
        $loanId = null;
        if (isset($body['loan_id'])) {
            $loanId = (int)$body['loan_id'];
        } else {
            $remarks = (string) ($resultData['Remarks'] ?? '');
            preg_match('/id\s*:?\s*(\d+)/i', $remarks, $matches);
            $loanId = isset($matches[1]) ? (int)$matches[1] : null;
        }

        if ($resultCode === 0) {
            $this->logger->info("B2C Payout Success for Conversation ID: {$conversationID}. Mpesa Ref: {$transactionID}");

            if ($loanId) {
                // Call updateWebhookStatus to smoothly change to 'approved' without resetting approved_by
                $this->loanModel->updateWebhookStatus($loanId, 'approved');
                $this->logger->info("Loan Request ID {$loanId} status updated to approved successfully.");
            } else {
                $this->logger->warning("B2C Payout succeeded but Loan ID could not be regex-parsed from remarks string");
            }
        } else {
            $this->logger->error("B2C Disbursal Failed with Code {$resultCode}: {$resultDesc}");
            
            if ($loanId) {
                // Call updateWebhookStatus to smoothly change to 'rejected' without resetting approved_by
                $this->loanModel->updateWebhookStatus($loanId, 'rejected');
                $this->logger->warning("Loan Request ID {$loanId} marked as rejected due to API processing parameters error.");
            }
        }

        return $this->jsonResponse($response, ['ResultCode' => 0, 'ResultDesc' => 'Acknowledgement Success']);
    }
    /**
     * NEW: Handles B2C Timeout Queue Callbacks (QueueTimeOutURL)
     */
    public function handleB2cQueueTimeout(Request $request, Response $response): Response
    {
        $rawPayload = $request->getBody()->getContents();
        $this->logger->critical('CRITICAL: Mpesa B2C Callback Timeout Queue Hit! Payload: ' . $rawPayload);
        
        return $this->jsonResponse($response, ['ResultCode' => 0, 'ResultDesc' => 'Queue Timeout Acknowledged']);
    }

    private function extractMetaValue(array $metaData, string $keyName): ?string
    {
        foreach ($metaData as $item) {
            if (isset($item['Name']) && trim((string)$item['Name']) === $keyName) {
                return isset($item['Value']) ? trim((string)$item['Value']) : null;
            }
        }
        return null;
    }
}