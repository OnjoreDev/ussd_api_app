<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Mpesa;
use App\Services\TransactionService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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

    /**
     * Endpoint: POST /api/v1/payment-hook
     * Safaricom hits this URL after the STK push process.
     */
    public function handleCallback(Request $request, Response $response): Response
    {
        $payload = json_decode($request->getBody()->getContents(), true);
        
        // Ensure we have a valid callback structure
        if (!isset($payload['Body']['stkCallback'])) {
            return $response->withStatus(400);
        }

        $stkCallback = $payload['Body']['stkCallback'];
        $checkoutRequestId = $stkCallback['CheckoutRequestID'];
        $resultCode = (int)$stkCallback['ResultCode'];

        // Logic for successful payment
        if ($resultCode === 0) {
            $metadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
            $receipt  = $this->extractMetadata($metadata, 'MpesaReceiptNumber');
            $amount   = (float)$this->extractMetadata($metadata, 'Amount');

            // 1. Update status in mpesa_transactions table
            $this->mpesaModel->updateTransactionStatus($checkoutRequestId, 'completed', $receipt);

            // 2. Fetch original transaction details to get member_id and wallet_type_id
            $txn = $this->mpesaModel->findByCheckoutRequestId($checkoutRequestId);
            
            if ($txn) {
                // 3. Update the Ledger via TransactionService
                $this->transactionService->execute(
                    (int)$txn['member_id'],
                    (int)$txn['wallet_type_id'],
                    $amount,
                    'Credit',
                    $receipt,
                    'M-Pesa STK Deposit'
                );
            }
        } else {
            // CAPTURE THE ERROR
            $resultDesc = $stkCallback['ResultDesc'] ?? 'No description provided';
            
            // LOG IT TO YOUR APP LOGS
            $this->logger->error("DEBUG: M-Pesa Transaction Failed for {$checkoutRequestId}. Code: {$resultCode}. Desc: {$resultDesc}");

            // Log failure in mpesa_transactions table
            $this->mpesaModel->updateTransactionStatus($checkoutRequestId, 'failed');
        }

        // IMPORTANT: Always return 200 OK so Safaricom knows you received the data
        return $response->withStatus(200);
    }

    /**
     * Helper to extract data from Safaricom's nested Item array
     */
    private function extractMetadata(array $items, string $name): ?string
    {
        foreach ($items as $item) {
            if ($item['Name'] === $name) {
                return (string)$item['Value'];
            }
        }
        return null;
    }
}