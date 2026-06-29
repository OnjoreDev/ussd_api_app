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
    private string $shortcode;
    private string $passkey;
    private string $callbackUrl;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        $mpesaBaseUrl = getenv('MPESA_BASE_URL') ?: ($_ENV['MPESA_BASE_URL'] ?? null);

        if (!$mpesaBaseUrl) {
            throw new Exception("M-Pesa configuration error: MPESA_BASE_URL is not set in the environment variables.");
        }

        $this->client = new Client([
            'base_uri' => rtrim($mpesaBaseUrl, '/') . '/',
            'timeout'  => 15.0,
            'verify'   => false, 
        ]);

        $this->consumerKey    = getenv('MPESA_CONSUMER_KEY') ?: ($_ENV['MPESA_CONSUMER_KEY'] ?? '');
        $this->consumerSecret = getenv('MPESA_CONSUMER_SECRET') ?: ($_ENV['MPESA_CONSUMER_SECRET'] ?? '');
        $this->shortcode      = getenv('MPESA_BUSINESS_SHORTCODE') ?: ($_ENV['MPESA_BUSINESS_SHORTCODE'] ?? '');
        $this->passkey        = getenv('MPESA_PASSKEY') ?: ($_ENV['MPESA_PASSKEY'] ?? '');
        
        // FIX: Grab both values and ensure the token query parameter is appended safely
        $rawCallbackUrl       = getenv('MPESA_CALLBACK_URL') ?: ($_ENV['MPESA_CALLBACK_URL'] ?? '');
        $callbackToken        = getenv('MPESA_CALLBACK_TOKEN') ?: ($_ENV['MPESA_CALLBACK_TOKEN'] ?? '');

        if (!empty($callbackToken) && !str_contains($rawCallbackUrl, 'token=')) {
            // Append the query token seamlessly so Safaricom sends it right back to us
            $this->callbackUrl = rtrim($rawCallbackUrl, '/') . '?token=' . $callbackToken;
        } else {
            $this->callbackUrl = $rawCallbackUrl;
        }
    }

    /**
     * Generate OAuth Access Token from Safaricom using Guzzle Basic Auth
     * @return string
     * @throws Exception
     */
    public function generateAccessToken(): string
    {
        try {
            $response = $this->client->request('GET', 'oauth/v1/generate', [
                'query' => ['grant_type' => 'client_credentials'],
                'auth'  => [$this->consumerKey, $this->consumerSecret]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['access_token'] ?? '';

        } catch (GuzzleException $e) {
            $this->logger->error('Mpesa Auth Token Generation Failed via Guzzle', [
                'error' => $e->getMessage()
            ]);
            throw new Exception('Failed to generate Mpesa access token: ' . $e->getMessage());
        }
    }

    /**
     * Initiate STK Push (Lipa Na M-Pesa Online) using Guzzle String-Cast Elements
     * @param string $phoneNumber
     * @param float $amount
     * @param string $accountReference
     * @param string $transactionDesc
     * @return array
     * @throws Exception
     */
    public function initiateStkPush(string $phoneNumber, float $amount, string $accountReference, string $transactionDesc): array
    {
        $token = $this->generateAccessToken();
        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        // Accept the pre-cleaned digits from the controller directly
        $formattedPhone = preg_replace('/[^0-9]/', '', $phoneNumber); 

        // Clear out character lengths and whitespace rules for string metrics
        $cleanRef = substr(str_replace(' ', '', $accountReference), 0, 12);
        $cleanDesc = substr(str_replace(' ', '', $transactionDesc), 0, 20);

        if (empty($cleanRef)) { $cleanRef = 'WalletFund'; }
        if (empty($cleanDesc)) { $cleanDesc = 'Payment'; }

        // Formulate the array payload with strict explicit string casts for digits
        $bodyArray = [
            'BusinessShortCode' => (string) $this->shortcode,
            'Password'          => (string) $password,
            'Timestamp'         => (string) $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (string) (int) $amount,
            'PartyA'            => (string) $formattedPhone,
            'PartyB'            => (string) $this->shortcode,
            'PhoneNumber'       => (string) $formattedPhone,
            'CallBackURL'       => (string) $this->callbackUrl,
            'AccountReference'  => (string) $cleanRef,
            'TransactionDesc'   => (string) $cleanDesc,
        ];

        try {
            // Encode cleanly preventing unescaped backslashes on the callback endpoint string
            $jsonPayload = json_encode($bodyArray, JSON_UNESCAPED_SLASHES);

            $response = $this->client->request('POST', 'mpesa/stkpush/v1/processrequest', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body' => $jsonPayload,
            ]);

            return json_decode($response->getBody()->getContents(), true);

        } catch (RequestException $e) {
            // Unpack exact untruncated structural rejection payloads from Safaricom's sandbox
            if ($e->hasResponse() && $e->getResponse() !== null) {
                $rawResponseBody = $e->getResponse()->getBody()->getContents();
                $this->logger->error('SAFARICOM RAW UNTRUNCATED ERROR REASON: ' . $rawResponseBody);
            }

            $this->logger->error('Mpesa STK Push Request Failed via Guzzle handling', [
                'message' => $e->getMessage()
            ]);
            
            throw new Exception('STK push initiation failed: ' . $e->getMessage());

        } catch (GuzzleException $e) {
            $this->logger->error('Mpesa Network/Timeout Failure: ' . $e->getMessage());
            throw new Exception('Safaricom gateway connection timeout: ' . $e->getMessage());
        }
    }
}