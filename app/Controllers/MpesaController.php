<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Mpesa;
use App\Services\MpesaService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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
     * Endpoint: POST /api/v1/payment/initiate
     * Initiates the STK Push process.
     */
    public function initiateStk(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        // 1. Basic validation
        if (empty($data['amount']) || empty($data['phone']) || empty($data['member_id']) || empty($data['wallet_type_id'])) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Missing required fields'], 400);
        }

        try {
            // 2. Trigger the external M-Pesa API
            $result = $this->mpesaService->initiateStkPush(
                (float)$data['amount'],
                (string)$data['phone'],
                [
                    'member_id'      => (int)$data['member_id'],
                    'wallet_type_id' => (int)$data['wallet_type_id'],
                    'account_ref'    => 'DEP-' . $data['member_id']
                ]
            );

            $this->logger->info("DEBUG: Safaricom API Response: " . json_encode($result));

            // 3. Return the result to the caller (e.g., your USSD app)
            return $this->jsonResponse($response, [
                'status' => 'success', 
                'data'   => $result
            ]);
            
        } catch (\Exception $e) {
            // Log the error via your logger if available
            return $this->jsonResponse($response, [
                'status' => 'error', 
                'message' => 'Failed to initiate payment: ' . $e->getMessage()
            ], 500);
        }
    }
}