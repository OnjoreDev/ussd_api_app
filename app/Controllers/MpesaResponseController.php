<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\Mpesa;
use App\Services\TransactionService;
use Psr\Container\ContainerInterface;

class MpesaResponseController extends Controller
{
    private Mpesa $mpesaModel;
    private TransactionService $transactionService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->mpesaModel = $container->get(Mpesa::class);
        $this->transactionService = $container->get(TransactionService::class);
    }

    public function handleCallback(Request $request, Response $response): Response
    {
        // Read raw body directly to avoid middleware/parsing issues
        $rawPayload = $request->getBody()->getContents();
        $this->logger->info('Mpesa Callback Raw Payload: ' . $rawPayload);
        
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
                    
                    // FIXED: Properly passing 'Credit' as the 4th argument to match the signature
                    $ledgerSuccess = $this->transactionService->execute(
                        (int)$tx['member_id'],
                        (int)$tx['wallet_type_id'],
                        (int)$tx['amount'],
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
                
                // Specific descriptive logs for tracking Distinct Safaricom drop buckets
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