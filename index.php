<?php
// Require Composer's autoloader
require __DIR__ . '/vendor/autoload.php';

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
    $dotenv = Dotenv::createImmutable(__DIR__);
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

// Handle AJAX request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if it's an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    $phone = $_POST["phone"] ?? '';
    $amount = $_POST["amount"] ?? 0;

    // Basic input validation
    if (empty($phone) || empty($amount)) {
        $response = ['error' => 'Phone number and amount are required'];
        sendJsonResponse($response, 400);
    }

    // Validate phone number format
    if (!preg_match('/^254\d{9}$/', $phone)) {
        $response = ['error' => 'Please enter a valid M-Pesa number in format 2547XXXXXXXX'];
        sendJsonResponse($response, 400);
    }

    // Validate amount
    if (!is_numeric($amount) || $amount <= 0) {
        $response = ['error' => 'Please enter a valid amount'];
        sendJsonResponse($response, 400);
    }

    try {
        // Call STK Push
        $result = stkPush($phone, $amount);
        
        if (isset($result['error'])) {
            sendJsonResponse(['error' => $result['error']], 400);
        }
        
        // If we get here, the STK push was initiated successfully
        sendJsonResponse([
            'success' => true,
            'message' => 'Payment request sent successfully. Please check your phone to complete the payment.'
        ]);
        
    } catch (Exception $e) {
        sendJsonResponse([
            'error' => 'Failed to process payment: ' . $e->getMessage()
        ], 500);
    }
}

function stkPush($phoneNumber, $amount) {
    try {
        // Load credentials from environment variables using Dotenv
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Basic " . $credentials,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Debug output
    echo "<pre>Access Token Response (HTTP $httpCode):\n";
    print_r($response);
    echo "\nError: " . ($error ? $error : 'None') . "\n</pre>";

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
    $stkPayload = array(
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
    );

    // Debug: Show the payload being sent
    echo "<pre>STK Push Payload:\n";
    print_r($stkPayload);
    echo "</pre>";

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
    curl_setopt($curl, CURLOPT_HEADER, false);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    
    // Debug: Show the raw response
    echo "<pre>STK Push Response (HTTP $httpCode):\n";
    print_r($response);
    echo "\nError: " . ($error ? $error : 'None') . "\n</pre>";
    
    curl_close($curl);

    // Process the response
    if ($httpCode !== 200) {
        throw new Exception("Failed to initiate STK push. HTTP Code: $httpCode. Response: $response");
    }

    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response from M-Pesa API: " . $response);
    }
    

    // Check for specific error codes
    if (isset($responseData['errorCode'])) {
        throw new Exception("{$responseData['errorMessage']} (Error Code: {$responseData['errorCode']})");
    }
    
    return $responseData;
    
    } catch (Exception $e) {
        // Display a user-friendly error message
        echo "<div class='alert alert-danger'>";
        echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
        echo "</div>";
        
        // Log the error for debugging
        error_log("M-Pesa Payment Error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M-Pesa Payment Gateway</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .payment-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            display: none; /* Hide all cards by default */
        }
        .payment-card.active {
            display: block; /* Show active card */
        }
        .header-bg {
            background: linear-gradient(135deg, #00B300 0%, #008000 100%);
            border-radius: 15px 15px 0 0;
        }
        .btn-mpesa {
            background-color: #00B300;
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .btn-mpesa:hover {
            background-color: #008000;
        }
        .btn-mpesa:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        .form-control:focus {
            border-color: #00B300;
            box-shadow: 0 0 0 0.25rem rgba(0, 179, 0, 0.25);
        }
        .loader {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #00B300;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .success-icon {
            color: #00B300;
            font-size: 4rem;
            margin: 20px 0;
        }
        .error-message {
            color: #dc3545;
            margin-top: 10px;
        }
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <!-- Payment Form Card -->
                <div id="paymentFormCard" class="payment-card active">
                    <div class="header-bg text-white text-center py-4">
                        <h3><i class="fas fa-mobile-alt me-2"></i> M-Pesa Payment</h3>
                        <p class="mb-0">Secure and instant mobile payments</p>
                    </div>
                    <div class="card-body p-4">
                        <form id="paymentForm" method="POST" class="needs-validation" novalidate>
                            <div class="mb-4">
                                <label for="phone" class="form-label fw-bold">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="text" 
                                           class="form-control form-control-lg" 
                                           id="phone" 
                                           name="phone" 
                                           placeholder="e.g. 2547XXXXXXXX" 
                                           pattern="^254\d{9}$"
                                           required>
                                </div>
                                <div class="form-text">Enter your M-Pesa number in format 2547XXXXXXXX</div>
                                <div class="invalid-feedback">
                                    Please enter a valid M-Pesa number (e.g., 254712345678)
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="amount" class="form-label fw-bold">Amount (KES)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-money-bill-wave"></i></span>
                                    <input type="number" 
                                           class="form-control form-control-lg" 
                                           id="amount" 
                                           name="amount" 
                                           min="1" 
                                           step="1" 
                                           placeholder="Enter amount" 
                                           required>
                                </div>
                                <div class="invalid-feedback">
                                    Please enter a valid amount
                                </div>
                            </div>

                            <div class="d-grid gap-2 mt-5">
                                <button type="submit" class="btn btn-lg btn-mpesa text-white" id="submitBtn">
                                    <i class="fas fa-paper-plane me-2"></i> Send Payment Request
                                </button>
                            </div>
                        </form>
                        <div id="errorMessage" class="error-message text-center mt-3"></div>
                    </div>
                    <div class="card-footer bg-transparent text-center py-3">
                        <small class="text-muted">
                            <i class="fas fa-lock me-1"></i> Secure payment powered by Safaricom M-Pesa
                        </small>
                    </div>
                </div>

                <!-- Loading Card -->
                <div id="loadingCard" class="payment-card text-center p-5">
                    <div class="loader"></div>
                    <h4 class="mt-4">Processing Payment</h4>
                    <p class="text-muted">Please wait while we process your payment request...</p>
                    <p class="text-muted"><small>Check your phone to complete the payment</small></p>
                </div>

                <!-- Success Card -->
                <div id="successCard" class="payment-card text-center p-5">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="mt-3">Payment Successful!</h3>
                    <p class="text-muted">Your payment has been processed successfully.</p>
                    <div class="d-grid gap-2 mt-4">
                        <button id="newPaymentBtn" class="btn btn-lg btn-mpesa text-white">
                            <i class="fas fa-redo me-2"></i> New Payment
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation and submission
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('paymentForm');
            const paymentFormCard = document.getElementById('paymentFormCard');
            const loadingCard = document.getElementById('loadingCard');
            const successCard = document.getElementById('successCard');
            const errorMessage = document.getElementById('errorMessage');
            const submitBtn = document.getElementById('submitBtn');
            const newPaymentBtn = document.getElementById('newPaymentBtn');
            let paymentTimeout;

            // Format phone number
            document.getElementById('phone').addEventListener('input', function (e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.startsWith('0')) {
                    value = '254' + value.substring(1);
                } else if (value.startsWith('7') || value.startsWith('1')) {
                    value = '254' + value;
                }
                e.target.value = value;
            });

            // Handle form submission
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                
                if (!form.checkValidity()) {
                    event.stopPropagation();
                    form.classList.add('was-validated');
                    return;
                }

                // Disable submit button and show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
                
                // Show loading card
                paymentFormCard.classList.remove('active');
                loadingCard.classList.add('active');
                errorMessage.textContent = '';

                // Prepare form data
                const formData = new FormData(form);

                // Send AJAX request
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    try {
                        // Try to parse JSON response
                        const responseData = JSON.parse(data);
                        
                        if (responseData.error) {
                            throw new Error(responseData.error);
                        }
                        
                        // Show success message
                        showSuccess();
                    } catch (e) {
                        // If not valid JSON or has error, show error
                        throw new Error('Invalid response from server');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Failed to process payment. ' + (error.message || 'Please try again.'));
                });

                // Set timeout for the payment (5 minutes)
                paymentTimeout = setTimeout(function() {
                    showError('Payment request timed out. Please try again.');
                }, 300000); // 5 minutes
            });

            // New payment button
            newPaymentBtn.addEventListener('click', function() {
                // Reset form and show payment form
                form.reset();
                form.classList.remove('was-validated');
                successCard.classList.remove('active');
                paymentFormCard.classList.add('active');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Send Payment Request';
            });

            // Show success screen
            function showSuccess() {
                clearTimeout(paymentTimeout);
                loadingCard.classList.remove('active');
                successCard.classList.add('active');
            }

            // Show error and return to payment form
            function showError(message) {
                clearTimeout(paymentTimeout);
                loadingCard.classList.remove('active');
                paymentFormCard.classList.add('active');
                errorMessage.textContent = message;
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Try Again';
            }
        });
    </script>
</body>
</html>
