<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Mpesa;
use App\Models\MpesaB2C;
use GuzzleHttp\Client;
use Monolog\Logger;

class MpesaService
{
    private Client $client;
    private Mpesa $mpesaModel;
    private Logger $logger;
    private MpesaB2C $b2c;


    public function __construct(Mpesa $mpesaModel, Logger $logger, MpesaB2C $b2c)
    {
        $this->mpesaModel = $mpesaModel;
        $this->b2c = $b2c;
        $this->client = new Client([
            'base_uri' => $_ENV['MPESA_BASE_URL'],
            'timeout'  => 10.0,
        ]);
        $this->logger = $logger;
    }

    private function getAccessToken(): string
    {
        $response = $this->client->get('/oauth/v1/generate?grant_type=client_credentials', [
            'auth' => [$_ENV['MPESA_CONSUMER_KEY'], $_ENV['MPESA_CONSUMER_SECRET']]
        ]);
        return json_decode($response->getBody()->getContents(), true)['access_token'];
    }

    /**
     * Initiates the M-Pesa STK Push process.
     */
    public function initiateStkPush(float $amount, string $phone, array $meta): array
    {
        $token = $this->getAccessToken();
        $timestamp = date('YmdHis');
        $password = base64_encode($_ENV['MPESA_BUSINESS_SHORTCODE'] . $_ENV['MPESA_PASSKEY'] . $timestamp);

        // Ensure the phone number starts with 254 (International format required by Safaricom)
        // If it starts with 0, replace with 254
        $formattedPhone = preg_replace('/^0/', '254', $phone);

        $payload = [
            'BusinessShortCode' => $_ENV['MPESA_BUSINESS_SHORTCODE'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (int)$amount,
            'PartyA'            => $formattedPhone,
            'PartyB'            => $_ENV['MPESA_BUSINESS_SHORTCODE'],
            'PhoneNumber'       => $formattedPhone,
            'CallBackURL'       => $_ENV['MPESA_CALLBACK_URL'],
            'AccountReference'  => $meta['account_ref'],
            'TransactionDesc'   => 'Deposit to ' . $meta['account_ref']
        ];

        // LOGGING: This will help us confirm the exact payload reaching Safaricom
        $this->logger->info("DEBUG: Sending M-Pesa Payload", ['payload' => $payload]);

        $response = $this->client->post('/mpesa/stkpush/v1/processrequest', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json'    => $payload
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        if (isset($result['CheckoutRequestID'])) {
            $this->mpesaModel->createTransaction([
                'member_id'           => $meta['member_id'],
                'wallet_type_id'      => $meta['wallet_type_id'],
                'amount'              => $amount,
                'phone_number'        => $formattedPhone,
                'checkout_request_id' => $result['CheckoutRequestID'],
                'merchant_request_id' => $result['MerchantRequestID'],
                'status'              => 'pending'
            ]);
        }

        return $result;
    }
    //function for bulk payments:


    /**
     * Initiates an M-Pesa B2C (Business to Consumer) payment.
     */
    public function disburse(float $amount, string $phone, string $remarks, string $reference, int $memberId): array
    {
        $token = $this->getAccessToken();

        // Format phone: ensure it is 254XXXXXXXXX
        $formattedPhone = preg_replace('/^0/', '254', $phone);
        $originatorId = bin2hex(random_bytes(16));

        // 1. Persist the transaction first as 'pending'
        $this->b2c->createB2CTransaction([
            'member_id'                  => $memberId,
            'amount'                     => $amount,
            'phone'                      => $formattedPhone,
            'originator_conversation_id' => $originatorId,
            'conversation_id'            => null // Will be updated after API call
        ]);

        $payload = [
            "InitiatorName"            => $_ENV['MPESA_B2C_INITIATOR_NAME'],
            "SecurityCredential"       => $_ENV['MPESA_B2C_SECURITY_CREDENTIAL'],
            "CommandID"                => "BusinessPayment",
            "Amount"                   => (int)$amount,
            "PartyA"                   => $_ENV['MPESA_SHORTCODE'],
            "PartyB"                   => $formattedPhone,
            "Remarks"                  => $remarks,
            "QueueTimeOutURL"          => $_ENV['MPESA_B2C_TIMEOUT_URL'],
            "ResultURL"                => $_ENV['MPESA_B2C_RESULT_URL'],
            "Occassion"                => $reference,
            "OriginatorConversationID" => $originatorId
        ];

        $this->logger->info("DEBUG: Sending B2C Disbursement", ['payload' => $payload]);

        try {
            $response = $this->client->post('/mpesa/b2c/v3/paymentrequest', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json'
                ],
                'json' => $payload
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            // 2. If successful, link the returned ConversationID to our record
            if (isset($result['ConversationID'])) {
                $this->b2c->updateConversationId($originatorId, $result['ConversationID']);
            }

            return $result;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Log the detailed error from Safaricom
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            $this->logger->error("B2C Disbursement Failed: " . $responseBody);

            return [
                'status'  => 'error',
                'message' => 'Disbursement request failed.',
                'details' => json_decode($responseBody, true) ?? $responseBody
            ];
        }
    }
    public function queryStkStatus(string $checkoutId): array
    {
        $token = $this->getAccessToken();
        $timestamp = date('YmdHis');
        $password = base64_encode($_ENV['MPESA_BUSINESS_SHORTCODE'] . $_ENV['MPESA_PASSKEY'] . $timestamp);

        $response = $this->client->post('/mpesa/stkpushquery/v1/query', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json' => [
                'BusinessShortCode' => $_ENV['MPESA_BUSINESS_SHORTCODE'],
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'CheckoutRequestID' => $checkoutId
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }
}
