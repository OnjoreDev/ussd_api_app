<?php

namespace App\Services;

use Monolog\Logger;

class SmsService
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function sendSMS(string $msisdn, string $message): bool
    {
        // Place your provided code here exactly as is
        // (Ensure $_ENV is accessible or passed via constructor)
        try {
            $partnerId = trim($_ENV['PARTNER_ID'] ?? '');
            $apiKey    = trim($_ENV['API_KEY'] ?? '');
            $senderId  = trim($_ENV['SENDER_ID'] ?? '');
            $baseUrl   = trim($_ENV['URL'] ?? 'https://isms.celcomafrica.com/api/services/sendsms/');

            if (empty($partnerId) || empty($apiKey) || empty($senderId)) {
                $this->logger->error("SMS Dispatch canceled: Missing environmental configurations.");
                return false;
            }

            $payload = [
                'partnerID' => $partnerId,
                'apikey'    => $apiKey,
                'shortcode' => $senderId,
                'mobile'    => trim($msisdn),
                'message'   => $message
            ];

            $jsonPayload = json_encode($payload);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, rtrim($baseUrl, '/'));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                $this->logger->error("SMS gateway network timeout for {$msisdn}");
                return false;
            }

            // ... (rest of your existing logic)
            return true;
        } catch (\Exception $e) {
            $this->logger->error("SMS execution exception: " . $e->getMessage());
            return false;
        }
    }
}