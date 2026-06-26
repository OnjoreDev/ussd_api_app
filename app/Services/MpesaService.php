<?php

namespace App\Services;

class MpesaService
{
    /**
     * Get OAuth Access Token using cURL
     */
    public function getAccessToken(): string
    {
        $consumerKey = $_ENV['MPESA_CONSUMER_KEY'] ?? '';
        $consumerSecret = $_ENV['MPESA_CONSUMER_SECRET'] ?? '';

        $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($consumerKey . ':' . $consumerSecret)
        ]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($curl);
        curl_close($curl);

        $data = json_decode($response, true);
        return $data['access_token'] ?? '';
    }

    /**
     * Initiate STK Push using cURL
     */
    public function sendStkPush(string $phone, int $amount, string $reference): array
    {
        $token = $this->getAccessToken();
        $shortcode = $_ENV['MPESA_BUSINESS_SHORTCODE'] ?? '';
        $passkey = $_ENV['MPESA_PASSKEY'] ?? '';
        $callbackUrl = $_ENV['MPESA_CALLBACK_URL'] ?? '';

        $timestamp = date('YmdHis');
        $password = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            "BusinessShortCode" => $shortcode,
            "Password"          => $password,
            "Timestamp"         => $timestamp,
            "TransactionType"   => "CustomerPayBillOnline",
            "Amount"            => $amount,
            "PartyA"            => $phone,
            "PartyB"            => $shortcode,
            "PhoneNumber"       => $phone,
            "CallBackURL"       => $callbackUrl,
            "AccountReference"  => $reference,
            "TransactionDesc"   => "CBO Deposit"
        ];

        $curl = curl_init('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);
    }
}