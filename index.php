<?php
// Debug: Check environment variables
function debugEnvVars() {
    $required = ['MPESA_CONSUMER_KEY', 'MPESA_CONSUMER_SECRET', 'MPESA_PASSKEY', 'MPESA_SHORTCODE'];
    $envVars = [];
    
    foreach ($required as $var) {
        $envVars[$var] = getenv($var) ? 'Set' : 'Not Set';
    }
    
    echo '<pre>';
    echo 'Environment Variables Status:\n';
    print_r($envVars);
    echo '\nCurrent Directory: ' . __DIR__ . '\n';
    echo 'File Exists: ' . (file_exists(__DIR__ . '/.env') ? 'Yes' : 'No') . '\n';
    echo '</pre>';
}

// Debug environment variables
debugEnvVars();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $phone = $_POST["phone"];
    $amount = $_POST["amount"];

    // Call STK Push
    stkPush($phone, $amount);
}

// Load environment variables from .env file
function loadEnv() {
    $envFile = __DIR__ . '/.env';
    
    // Debug: Check if .env file exists and is readable
    if (!file_exists($envFile)) {
        die("Error: .env file not found at: " . $envFile);
    }
    
    if (!is_readable($envFile)) {
        die("Error: .env file is not readable. Check permissions.");
    }
    
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        die("Error: Failed to read .env file");
    }
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // Parse the line into name and value
        if (strpos($line, '=') !== false) {
            list($name, $value) = array_map('trim', explode('=', $line, 2));
            
            // Remove optional quotes from the value
            $value = trim($value, '"\'');
            
            // Set the environment variable if it doesn't exist
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
                putenv("$name=$value");
            }
        }
    }
    
    // Debug: Check if required variables are set
    $requiredVars = ['MPESA_CONSUMER_KEY', 'MPESA_CONSUMER_SECRET', 'MPESA_PASSKEY', 'MPESA_SHORTCODE'];
    $missingVars = [];
    
    foreach ($requiredVars as $var) {
        if (empty(getenv($var))) {
            $missingVars[] = $var;
        }
    }
    
    if (!empty($missingVars)) {
        die("Error: The following required environment variables are missing or empty: " . 
            implode(', ', $missingVars) . 
            ". Please check your .env file.");
    }
}

// Call the function to load environment variables
loadEnv();

function stkPush($phoneNumber, $amount) {
    // Load credentials from environment variables
    $consumerKey    = getenv('MPESA_CONSUMER_KEY');
    $consumerSecret = getenv('MPESA_CONSUMER_SECRET');
    $shortCode      = getenv('MPESA_SHORTCODE');
    $passkey        = getenv('MPESA_PASSKEY');
    $callbackURL    = getenv('MPESA_CALLBACK_URL');

    // Debug: Check if environment variables are loaded
    if (empty($consumerKey) || empty($consumerSecret)) {
        die("Error: M-Pesa credentials not found. Please check your .env file.");
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
        die("Failed to get access token. HTTP Code: $httpCode");
    }

    $responseData = json_decode($response);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($responseData->access_token)) {
        die("Invalid response from M-Pesa API: " . $response);
    }

    $access_token = $responseData->access_token;

    // Step 2: Password
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
    curl_setopt($curl, CURLOPT_URL, $stkURL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token,
        'Cache-Control: no-cache'
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($stkPayload));
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
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
        die("Failed to initiate STK push. HTTP Code: $httpCode");
    }

    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die("Invalid JSON response from M-Pesa API: " . $response);
    }

    // Display the response in a more readable format
    echo "<h3>Payment Request Status</h3>";
    echo "<pre>";
    print_r($responseData);
    echo "</pre>";
    
    // Check for specific error codes
    if (isset($responseData['errorCode'])) {
        echo "<div class='alert alert-danger'>";
        echo "<strong>Error {$responseData['errorCode']}:</strong> {$responseData['errorMessage']}";
        echo "</div>";
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
        }
        .payment-card:hover {
            transform: translateY(-5px);
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
        .form-control:focus {
            border-color: #00B300;
            box-shadow: 0 0 0 0.25rem rgba(0, 179, 0, 0.25);
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card payment-card">
                    <div class="header-bg text-white text-center py-4">
                        <h3><i class="fas fa-mobile-alt me-2"></i> M-Pesa Payment</h3>
                        <p class="mb-0">Secure and instant mobile payments</p>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" class="needs-validation" novalidate>
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
                                <button type="submit" class="btn btn-lg btn-mpesa text-white">
                                    <i class="fas fa-paper-plane me-2"></i> Send Payment Request
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer bg-transparent text-center py-3">
                        <small class="text-muted">
                            <i class="fas fa-lock me-1"></i> Secure payment powered by Safaricom M-Pesa
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        (function () {
            'use strict'
            
            var forms = document.querySelectorAll('.needs-validation')
            
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        
                        form.classList.add('was-validated')
                    }, false)
                })
        })()

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
    </script>
</body>
</html>
