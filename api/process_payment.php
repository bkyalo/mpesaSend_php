<?php
// Require Composer's autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load environment variables using Dotenv
use Dotenv\Dotenv;

// Set content type to JSON for AJAX responses
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
        'MPESA_SHORTCODE',
        'MPESA_CALLBACK_URL'
    ]);
    
} catch (Exception $e) {
    sendJsonResponse(['error' => 'Error loading environment variables: ' . $e->getMessage()], 500);
}

// Only handle POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendJsonResponse(['error' => 'Method not allowed'], 405);
}

// Get and validate input
$phone = $_POST["phone"] ?? '';
$amount = $_POST["amount"] ?? 0;

// Basic input validation
if (empty($phone) || empty($amount)) {
    sendJsonResponse(['error' => 'Phone number and amount are required'], 400);
}

// Validate phone number format
if (!preg_match('/^254\d{9}$/', $phone)) {
    sendJsonResponse(['error' => 'Please enter a valid M-Pesa number in format 2547XXXXXXXX'], 400);
}

// Validate amount
if (!is_numeric($amount) || $amount <= 0) {
    sendJsonResponse(['error' => 'Please enter a valid amount'], 400);
}

// Include the stkPush function
require_once __DIR__ . '/../includes/stk_push.php';

try {
    // Call STK Push
    $result = stkPush($phone, $amount);
    
    if (isset($result['error'])) {
        sendJsonResponse(['error' => $result['error']], 400);
    }
    
    // If we get here, the STK push was initiated successfully
    sendJsonResponse([
        'success' => true,
        'message' => 'Payment request sent successfully. Please check your phone to complete the payment.',
        'checkoutRequestID' => $result['checkoutRequestID'] ?? ''
    ]);
    
} catch (Exception $e) {
    sendJsonResponse([
        'error' => 'Failed to process payment: ' . $e->getMessage()
    ], 500);
}
