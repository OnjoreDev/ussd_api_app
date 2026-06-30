<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
            'verify'   => false, 
        ]);
    }

    public function initiateStkPush(string $phoneNumber, int|float $amount, string $accountReference, string $transactionDesc)
    {
        try {
            $token = $this->generateAccessToken();
            $timestamp = date('YmdHis');
            $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

            $bodyArray = [
                'BusinessShortCode' => $this->shortcode,
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'TransactionType'   => 'CustomerPayBillOnline',
                'Amount'            => (int)$amount,
                'PartyA'            => $phoneNumber,
                'PartyB'            => $this->shortcode,
                'PhoneNumber'       => $phoneNumber,
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
            $rawBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No Response Body';
            $this->logger->error('STK Push Failed. Reason: ' . $rawBody);
            throw new Exception('STK push initiation failed: ' . $rawBody);
        }
    }

    private function generateAccessToken(): string
    {
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
}