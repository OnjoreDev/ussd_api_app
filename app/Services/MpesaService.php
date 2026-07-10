<?php

namespace App\Services;

use GuzzleHttp\Client;
use App\Models\Mpesa;
use Exception;

class MpesaService
{
    private Client $client;
    private Mpesa $mpesaModel;
    private array $config;

    public function __construct(Mpesa $mpesaModel)
    {
        $this->mpesaModel = $mpesaModel;
        
        // Updated: Added 'verify' => false to bypass SSL certificate verification issues
        $this->client = new Client([
            'base_uri' => $_ENV['MPESA_BASE_URL'],
            'verify'   => false 
        ]);

        $this->config = [
            'key'       => $_ENV['MPESA_CONSUMER_KEY'],
            'secret'    => $_ENV['MPESA_CONSUMER_SECRET'],
            'shortcode' => $_ENV['MPESA_BUSINESS_SHORTCODE'],
            'passkey'   => $_ENV['MPESA_PASSKEY'],
            'callback'  => $_ENV['MPESA_CALLBACK_URL']
        ];
    }

    public function getAccessToken(): string
    {
        $response = $this->client->get('/oauth/v1/generate?grant_type=client_credentials', [
            'auth' => [$this->config['key'], $this->config['secret']]
        ]);
        
        return json_decode($response->getBody()->getContents())->access_token;
    }

    public function initiateStkPush(float $amount, string $phone, array $metaData)
    {
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->config['shortcode'] . $this->config['passkey'] . $timestamp);

        $response = $this->client->post('/mpesa/stkpush/v1/processrequest', [
            'headers' => ['Authorization' => 'Bearer ' . $this->getAccessToken()],
            'json' => [
                "BusinessShortCode" => $this->config['shortcode'],
                "Password"          => $password,
                "Timestamp"         => $timestamp,
                "TransactionType"   => "CustomerPayBillOnline",
                "Amount"            => $amount,
                "PartyA"            => $phone,
                "PartyB"            => $this->config['shortcode'],
                "PhoneNumber"       => $phone,
                "CallBackURL"       => $this->config['callback'],
                "AccountReference"  => $metaData['account_ref'],
                "TransactionDesc"   => "Deposit"
            ]
        ]);

        $result = json_decode($response->getBody()->getContents());

        // Save to DB via Mpesa Model
        $this->mpesaModel->createTransaction([
            'member_id'           => $metaData['member_id'],
            'wallet_type_id'      => $metaData['wallet_type_id'],
            'amount'              => $amount,
            'phone_number'        => $phone,
            'checkout_request_id' => $result->CheckoutRequestID,
            'merchant_request_id' => $result->MerchantRequestID
        ]);

        return $result;
    }
}