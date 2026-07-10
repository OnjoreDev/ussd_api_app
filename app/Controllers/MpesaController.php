<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\MpesaService;
use App\Models\Mpesa;
use Psr\Container\ContainerInterface;
use Exception;

class MpesaController extends Controller
{
    private MpesaService $mpesaService;
    private Mpesa $mpesaModel;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->mpesaService = $container->get(MpesaService::class);
        $this->mpesaModel = $container->get(Mpesa::class);
    }


    /**
     * POST /api/v1/mpesa/stk-push
     * Initiates an STK Push and creates a pending database log transaction record
     */
    public function initiateStk(Request $request, Response $response): Response
    {
        try {
            $body = $request->getParsedBody();

            if (empty($body['phone_number']) || empty($body['amount']) || empty($body['member_id']) || empty($body['wallet_type_id'])) {
                return $this->jsonResponse($response, [
                    'status' => 'error',
                    'message' => 'Missing required fields.'
                ], 400);
            }

            $cleanPhone = $this->sanitizePhoneNumber($body['phone_number']);
            if (!$cleanPhone) {
                return $this->jsonResponse($response, [
                    'status' => 'error',
                    'message' => 'Invalid phone number format.'
                ], 400);
            }

            $this->logger->info("Initiating STK Push for Member: {$body['member_id']}, Phone: {$cleanPhone}");

            // Call the service
            $stkResult = $this->mpesaService->initiateStkPush(
                $cleanPhone,
                (float)$body['amount'],
                'Mem' . $body['member_id'],
                'WalletType' . $body['wallet_type_id']
            );

            // Check specifically for Safaricom gateway acceptance
            // 0 = Success, anything else indicates a failure at the gateway level
            if (isset($stkResult['ResponseCode']) && (string)$stkResult['ResponseCode'] === '0') {

                $this->mpesaModel->createTransaction([
                    'member_id'           => (int)$body['member_id'],
                    'wallet_type_id'      => (int)$body['wallet_type_id'],
                    'amount'              => (float)$body['amount'],
                    'phone_number'        => $cleanPhone,
                    'checkout_request_id' => $stkResult['CheckoutRequestID'],
                    'merchant_request_id' => $stkResult['MerchantRequestID']
                ]);

                return $this->jsonResponse($response, [
                    'status'  => 'success',
                    'message' => 'Payment request sent. Please check your phone for the M-Pesa prompt.',
                    'data'    => ['CheckoutRequestID' => $stkResult['CheckoutRequestID']]
                ], 200);
            }

            // Handle Rejections (e.g., User unreachable, invalid shortcode, etc.)
            $errorDesc = $stkResult['ResponseDescription'] ?? 'Gateway rejected the request.';
            $this->logger->error("STK Initiation rejected: " . $errorDesc);

            return $this->jsonResponse($response, [
                'status'  => 'error',
                'message' => 'M-Pesa payment initiation failed: ' . $errorDesc
            ], 400);
        } catch (Exception $e) {
            $this->logger->error('STK Push Controller Exception: ' . $e->getMessage());
            return $this->jsonResponse($response, [
                'status'  => 'error',
                'message' => 'An internal error occurred. Please try again later.'
            ], 500);
        }
    }

    /**
     * Helper to keep controller clean
     */
    private function sanitizePhoneNumber(string $rawPhone): ?string
    {
        $clean = preg_replace('/[^0-9]/', '', $rawPhone);
        if (preg_match('/^0(7|1)\d{8}$/', $clean)) return '254' . substr($clean, 1);
        if (preg_match('/^(7|1)\d{8}$/', $clean)) return '254' . $clean;
        if (preg_match('/^254(7|1)\d{8}$/', $clean)) return $clean;
        return null;
    }
}
