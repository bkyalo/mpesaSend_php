<?php

function stkPush($phoneNumber, $amount) {
    try {
        // Load credentials from environment variables
        $consumerKey    = $_ENV['MPESA_CONSUMER_KEY'] ?? '';
        $consumerSecret = $_ENV['MPESA_CONSUMER_SECRET'] ?? '';
        $shortCode      = $_ENV['MPESA_SHORTCODE'] ?? '';
        $passkey        = $_ENV['MPESA_PASSKEY'] ?? '';
        $callbackURL    = $_ENV['MPESA_CALLBACK_URL'] ?? '';
        
        // Validate required environment variables
        if (empty($consumerKey) || empty($consumerSecret) || empty($shortCode) || empty($passkey)) {
            return ['error' => 'One or more required M-Pesa credentials are missing. Please check your .env file.'];
        }

        // Step 1: Get Access Token
        $credentials = base64_encode($consumerKey . ":" . $consumerSecret);
        $tokenURL = "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";
        
        $ch = curl_init($tokenURL);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic " . $credentials,
                "Content-Type: application/json"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Failed to get access token. HTTP Code: $httpCode");
        }

        $responseData = json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($responseData->access_token)) {
            throw new Exception("Invalid response from M-Pesa API when getting access token");
        }

        $access_token = $responseData->access_token;

        // Step 2: Generate Password
        $timestamp = date("YmdHis");
        $password  = base64_encode($shortCode . $passkey . $timestamp);
        
        // Step 3: STK Push payload
        $stkPayload = [
            "BusinessShortCode" => $shortCode,
            "Password"          => $password,
            "Timestamp"         => $timestamp,
            "TransactionType"   => "CustomerPayBillOnline",
            "Amount"            => (int)$amount,
            "PartyA"            => $phoneNumber,
            "PartyB"            => $shortCode,
            "PhoneNumber"       => $phoneNumber,
            "CallBackURL"       => $callbackURL,
            "AccountReference"  => "Invoice" . time(),
            "TransactionDesc"   => "Payment for services"
        ];

        $stkURL = "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest";
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $stkURL,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
                'Cache-Control: no-cache'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($stkPayload),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        $curlErrorNumber = curl_errno($curl);
        curl_close($curl);

        if ($curlErrorNumber) {
            throw new Exception("cURL Error ($curlErrorNumber): $error");
        }

        if ($httpCode !== 200) {
            throw new Exception("STK Push failed. HTTP Code: $httpCode");
        }

        $responseData = json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from M-Pesa API");
        }

        // Check if the STK push was initiated successfully
        if (isset($responseData->errorCode) || !isset($responseData->ResponseCode) || $responseData->ResponseCode !== "0") {
            $errorMessage = $responseData->errorMessage ?? 'Unknown error';
            $errorCode = $responseData->errorCode ?? 'N/A';
            throw new Exception("M-Pesa Error ($errorCode): $errorMessage");
        }

        // Return success response with checkoutRequestID
        return [
            'success' => true,
            'merchantRequestID' => $responseData->MerchantRequestID ?? '',
            'checkoutRequestID' => $responseData->CheckoutRequestID ?? '',
            'responseCode' => $responseData->ResponseCode ?? '',
            'responseDescription' => $responseData->ResponseDescription ?? '',
            'customerMessage' => $responseData->CustomerMessage ?? ''
        ];

    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// This file should only contain the function definition, not be executed directly
return;
