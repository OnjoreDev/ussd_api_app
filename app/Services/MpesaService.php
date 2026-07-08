<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;
use Exception;

class MpesaService 
{
    private Logger $logger;
    private Client $client;
    private string $consumerKey;
    private string $consumerSecret;
    private string $shortcode = '174379'; // Hardcoded for Sandbox stability
    private string $passkey;
    private string $callbackUrl;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        $mpesaBaseUrl = getenv('MPESA_BASE_URL') ?: ($_ENV['MPESA_BASE_URL'] ?? 'https://sandbox.safaricom.co.ke/');
        
        $this->consumerKey    = getenv('MPESA_CONSUMER_KEY') ?: ($_ENV['MPESA_CONSUMER_KEY'] ?? '');
        $this->consumerSecret = getenv('MPESA_CONSUMER_SECRET') ?: ($_ENV['MPESA_CONSUMER_SECRET'] ?? '');
        $this->passkey        = getenv('MPESA_PASSKEY') ?: ($_ENV['MPESA_PASSKEY'] ?? '');
        $this->callbackUrl    = getenv('MPESA_CALLBACK_URL') ?: ($_ENV['MPESA_CALLBACK_URL'] ?? '');

        $this->client = new Client([
            'base_uri' => rtrim($mpesaBaseUrl, '/') . '/',
            'timeout'  => 30.0,
            'connect_timeout' => 30.0,
            'verify'   => false, // Set to true in production with valid SSL certificates
        ]);
    }

    public function initiateStkPush(string $phoneNumber, int|float $amount, string $accountReference, string $transactionDesc): array
    {
        try {
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            $token = $this->generateAccessToken();
            $timestamp = date('YmdHis');
            $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

            $bodyArray = [
                'BusinessShortCode' => $this->shortcode,
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'TransactionType'   => 'CustomerPayBillOnline',
                'Amount'            => (int)$amount,
                'PartyA'            => $formattedPhone,
                'PartyB'            => $this->shortcode,
                'PhoneNumber'       => $formattedPhone,
                'CallBackURL'       => $this->callbackUrl,
                'AccountReference'  => $accountReference,
                'TransactionDesc'   => $transactionDesc
            ];

            $response = $this->client->request('POST', 'mpesa/stkpush/v1/processrequest', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $bodyArray,
            ]);

            return json_decode($response->getBody()->getContents(), true);

        } catch (RequestException $e) {
            // Safely casting stream to string preserves compatibility across handlers
            $rawBody = $e->hasResponse() ? (string)$e->getResponse()->getBody() : 'No Response Body';
            $this->logger->error('STK Push Failed. Reason: ' . $rawBody);
            throw new Exception('STK push initiation failed: ' . $rawBody);
        }
    }

    private function generateAccessToken(): string
    {
        // Optional TODO: Wrap this with a caching layer (e.g., Predis or local cache adapter)
        // using a key like 'mpesa_access_token' for 3300 seconds to minimize API round-trips.
        try {
            $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);

            $response = $this->client->request('GET', 'oauth/v1/generate?grant_type=client_credentials', [
                'headers' => [
                    'Authorization' => 'Basic ' . $credentials,
                    'Accept'        => 'application/json',
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['access_token'];
        } catch (Exception $e) {
            $this->logger->error('OAuth Failed: ' . $e->getMessage());
            throw new Exception('Authentication with Safaricom failed.');
        }
    }

    /**
     * Normalizes Kenyan phone numbers to the 2547XXXXXXXX or 2541XXXXXXXX format required by Safaricom.
     */
    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone); // Strip all non-digits

        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        if (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
            return '254' . $phone;
        }

        return $phone;
    }
}