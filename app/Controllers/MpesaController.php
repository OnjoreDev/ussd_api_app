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
            // 1. Capture the parsed JSON body fields safely
            $body = $request->getParsedBody();

            // 2. Perform quick parameter validation rules
            if (empty($body['phone_number']) || empty($body['amount']) || empty($body['member_id']) || empty($body['wallet_type_id'])) {
                return $this->jsonResponse($response, [
                    'status' => 'error',
                    'message' => 'Missing required fields: phone_number, amount, member_id, and wallet_type_id are mandatory.'
                ], 400);
            }

            $rawPhone    = (string) $body['phone_number'];
            $amount      = (float) $body['amount'];
            $memberId    = (int) $body['member_id'];
            $walletTypeId = (int) $body['wallet_type_id'];

            // 3. Bulletproof Phone Number Parsing Logic
            // Strip out all non-numeric characters (spaces, +, hyphens, brackets)
            $cleanPhone = preg_replace('/[^0-9]/', '', $rawPhone);

            // Convert 07XXXXXXXX or 01XXXXXXXX to 2547XXXXXXXX / 2541XXXXXXXX
            if (preg_match('/^0(7|1)\d{8}$/', $cleanPhone)) {
                $cleanPhone = '254' . substr($cleanPhone, 1);
            } 
            // Convert 7XXXXXXXX or 1XXXXXXXX (missing leading zero) to 2547XXXXXXXX / 2541XXXXXXXX
            elseif (preg_match('/^(7|1)\d{8}$/', $cleanPhone)) {
                $cleanPhone = '254' . $cleanPhone;
            }

            // Strict Validation Check: Ensure it is exactly 12 digits starting with 2547 or 2541
            if (!preg_match('/^254(7|1)\d{8}$/', $cleanPhone)) {
                return $this->jsonResponse($response, [
                    'status' => 'error',
                    'message' => 'Invalid Kenyan phone number format. Use 07XXXXXXXX, 254XXXXXXXXX, or +2547XXXXXXXX.'
                ], 400);
            }

            // 4. Dynamic setup of AccountReference matching your database schema identifier strings
            $accountReference = 'Mem' . $memberId;
            $transactionDesc  = 'WalletType' . $walletTypeId;

            $this->logger->info("Attempting STK Push initiation for Member ID: {$memberId}, Amount: KES {$amount}, Parsed Phone: {$cleanPhone}");

            // 5. Hit Safaricom Daraja API Gateway using your service handler
            $stkResult = $this->mpesaService->initiateStkPush(
                $cleanPhone, // Passing the perfectly parsed phone number format
                $amount, 
                $accountReference, 
                $transactionDesc
            );

            // 6. Safaricom Response Code 0 means the request reached the handset successfully
            if (isset($stkResult['ResponseCode']) && (string)$stkResult['ResponseCode'] === '0') {
                
                // Prepare storage mapping array matching your mpesa_transactions schema columns
                $dbPayload = [
                    'member_id'           => $memberId,
                    'wallet_type_id'      => $walletTypeId,
                    'amount'              => $amount,
                    'phone_number'        => $cleanPhone,
                    'checkout_request_id' => $stkResult['CheckoutRequestID'],
                    'merchant_request_id' => $stkResult['MerchantRequestID']
                ];

                // Persist the transaction into the database with a 'pending' state flag status
                $this->mpesaModel->createTransaction($dbPayload);

                return $this->jsonResponse($response, [
                    'status'  => 'success',
                    'message' => 'STK Push initiated successfully. Please enter your M-Pesa PIN on your phone.',
                    'data'    => [
                        'MerchantRequestID' => $stkResult['MerchantRequestID'],
                        'CheckoutRequestID' => $stkResult['CheckoutRequestID'],
                        'CustomerMessage'   => $stkResult['CustomerMessage']
                    ]
                ], 200);
            }

            // Handles scenarios where Safaricom accepts the request but flags something wrong downstream
            return $this->jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Safaricom gateway rejected initialization parameters.',
                'details' => $stkResult
            ], 400);

        } catch (Exception $e) {
            $this->logger->error('STK Push Controller Error: ' . $e->getMessage());

            return $this->jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Processing error: ' . $e->getMessage()
            ], 500);
        }
    }
}