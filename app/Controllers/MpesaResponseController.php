<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\Mpesa;
use Psr\Container\ContainerInterface;
use Exception;

class MpesaResponseController extends Controller
{
    private Mpesa $mpesaModel;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->mpesaModel = $container->get(Mpesa::class);
    }

    /**
     * POST /api/v1/payment-hook
     * Handles Safaricom STK Push Callback Async Responses securely
     */
    public function handleCallback(Request $request, Response $response): Response
    {
        try {
            // 1. Secure validation query token check
            $queryParams = $request->getQueryParams();
            $secureToken = $_ENV['MPESA_CALLBACK_TOKEN'] ?? getenv('MPESA_CALLBACK_TOKEN') ?? '';

            // If you are troubleshooting token mismatches locally, you can temporarily disable this block.
            if (!empty($secureToken) && (empty($queryParams['token']) || $queryParams['token'] !== $secureToken)) {
                $this->logger->warning('Unauthorized M-Pesa callback attempt blocked. Token mismatch. Expected: ' . $secureToken . ' Got: ' . ($queryParams['token'] ?? 'none'));
                return $this->jsonResponse($response, ['ResultCode' => 1, 'ResultDesc' => 'Unauthorized'], 401);
            }

            // 2. Capture and parse the raw JSON payload body
            $body = $request->getParsedBody();
            
            // Log the payload so you can audit the results inside your app logs
            $this->logger->info('Incoming M-Pesa Hook Raw Payload: ', (array) $body);

            $callbackData = $body['Body']['stkCallback'] ?? null;
            if (!$callbackData) {
                $this->logger->error('Invalid M-Pesa structural payload format. Missing stkCallback wrapper.');
                return $this->jsonResponse($response, ['ResultCode' => 1, 'ResultDesc' => 'Invalid Payload Structure'], 400);
            }

            $resultCode        = (int) $callbackData['ResultCode'];
            $resultDesc        = (string) $callbackData['ResultDesc'];
            $checkoutRequestID = (string) $callbackData['CheckoutRequestID'];

            // 3. Process conditionally based on the ResultCode (0 means Success)
            if ($resultCode === 0) {
                $metaData = $callbackData['CallbackMetadata']['Item'] ?? [];
                
                // Extracting MpesaReceiptNumber explicitly
                $mpesaReceiptNumber = $this->extractMetaValue($metaData, 'MpesaReceiptNumber');

                if (empty($mpesaReceiptNumber)) {
                    $this->logger->error("Failed to extract 'MpesaReceiptNumber' from metadata. Raw Items: " . json_encode($metaData));
                }

                // Update transaction status to 'completed' [Matches Database ENUM schema constraint]
                $this->mpesaModel->updateTransactionStatus(
                    $checkoutRequestID, 
                    'completed', 
                    $mpesaReceiptNumber
                );

                // TODO: Insert your wallet funding execution triggers right here!
                $this->logger->info("Payment SUCCESS processing completed. Receipt: {$mpesaReceiptNumber}, CheckoutID: {$checkoutRequestID}");

            } else {
                // Payment failed, cancelled by user, or expired
                // Update transaction status to 'failed' [Matches Database ENUM schema constraint]
                $this->mpesaModel->updateTransactionStatus(
                    $checkoutRequestID, 
                    'failed', 
                    null
                );

                $this->logger->warning("Payment FAILED processing recorded. CheckoutID: {$checkoutRequestID}. Reason: {$resultDesc} (Code: {$resultCode})");
            }

            // 4. Always explicitly acknowledge Safaricom Gateway with a 0 code so they stop retrying
            return $this->jsonResponse($response, [
                'ResultCode' => 0,
                'ResultDesc' => 'Success'
            ], 200);

        } catch (Exception $e) {
            $this->logger->error('STK Hook Callback Critical Processing Error: ' . $e->getMessage());

            return $this->jsonResponse($response, [
                'ResultCode' => 1,
                'ResultDesc' => 'Internal Server Processing Error'
            ], 500);
        }
    }

    /**
     * Helper to safely isolate and extract keys from Safaricom's nested metadata array
     */
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