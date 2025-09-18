<?php
// Require Composer's autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load environment variables using Dotenv
use Dotenv\Dotenv;

// Set content type to JSON
header('Content-Type: application/json');

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Create Dotenv instance and load environment variables
try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
    
    // Ensure required environment variables are set
    $dotenv->required([
        'MPESA_CONSUMER_KEY',
        'MPESA_CONSUMER_SECRET',
        'MPESA_PASSKEY',
        'MPESA_SHORTCODE'
    ]);
    
} catch (Exception $e) {
    sendJsonResponse(['error' => 'Error loading environment variables: ' . $e->getMessage()], 500);
}

// Only handle GET requests
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendJsonResponse(['error' => 'Method not allowed'], 405);
}

// Get the checkout request ID
$checkoutRequestID = $_GET['checkoutRequestID'] ?? '';
if (empty($checkoutRequestID)) {
    sendJsonResponse(['error' => 'Checkout request ID is required'], 400);
}

// Get access token
function getAccessToken() {
    $consumerKey = $_ENV['MPESA_CONSUMER_KEY'];
    $consumerSecret = $_ENV['MPESA_CONSUMER_SECRET'];
    $credentials = base64_encode($consumerKey . ':' . $consumerSecret);
    
    $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($httpCode !== 200) {
        throw new Exception('Failed to get access token. HTTP Code: ' . $httpCode);
    }
    
    $data = json_decode($response);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data->access_token)) {
        throw new Exception('Invalid response when getting access token');
    }
    
    return $data->access_token;
}

// Check payment status
function checkPaymentStatus($checkoutRequestID) {
    $accessToken = getAccessToken();
    $shortCode = $_ENV['MPESA_SHORTCODE'];
    $passkey = $_ENV['MPESA_PASSKEY'];
    
    $timestamp = date('YmdHis');
    $password = base64_encode($shortCode . $passkey . $timestamp);
    
    $payload = [
        'BusinessShortCode' => $shortCode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $checkoutRequestID
    ];
    
    $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($httpCode !== 200) {
        throw new Exception('Failed to check payment status. HTTP Code: ' . $httpCode);
    }
    
    $data = json_decode($response);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid response when checking payment status');
    }
    
    return $data;
}

try {
    $result = checkPaymentStatus($checkoutRequestID);
    
    // Check the result code
    $resultCode = (int)($result->ResultCode ?? 1);
    $resultDesc = $result->ResultDesc ?? 'Unknown status';
    
    // Result code 0 means success
    if ($resultCode === 0) {
        sendJsonResponse([
            'status' => 'completed',
            'message' => 'Payment completed successfully',
            'result' => $result
        ]);
    } 
    // Result code 1032 means user cancelled the payment
    else if ($resultCode === 1032) {
        sendJsonResponse([
            'status' => 'cancelled',
            'message' => 'Payment was cancelled by user',
            'result' => $result
        ]);
    }
    // Result code 1037 means timeout
    else if ($resultCode === 1037) {
        sendJsonResponse([
            'status' => 'timeout',
            'message' => 'Payment request timed out',
            'result' => $result
        ]);
    }
    // Any other result code means the payment is still pending or failed
    else {
        sendJsonResponse([
            'status' => 'pending',
            'message' => $resultDesc,
            'result' => $result
        ]);
    }
    
} catch (Exception $e) {
    sendJsonResponse([
        'error' => 'Failed to check payment status: ' . $e->getMessage()
    ], 500);
}
