<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $phone = $_POST["phone"];
    $amount = $_POST["amount"];

    // Call STK Push
    stkPush($phone, $amount);
}

// Load environment variables from .env file
function loadEnv() {
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue; // Skip comments
            
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
                putenv("$name=$value");
            }
        }
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

    // Step 1: Access token
    $credentials = base64_encode($consumerKey . ":" . $consumerSecret);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Basic " . $credentials));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $access_token = json_decode($response)->access_token;

    // Step 2: Password
    $timestamp = date("YmdHis");
    $password  = base64_encode($shortCode . $passkey . $timestamp);

    // Step 3: STK Push payload
    $stkPayload = array(
        "BusinessShortCode" => $shortCode,
        "Password"          => $password,
        "Timestamp"         => $timestamp,
        "TransactionType"   => "CustomerPayBillOnline", // or CustomerBuyGoodsOnline
        "Amount"            => (int)$amount,
        "PartyA"            => $phoneNumber,
        "PartyB"            => $shortCode,
        "PhoneNumber"       => $phoneNumber,
        "CallBackURL"       => $callbackURL,
        "AccountReference"  => "Invoice123",
        "TransactionDesc"   => "Test Payment"
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "Authorization: Bearer " . $access_token
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stkPayload));

    $response = curl_exec($ch);
    curl_close($ch);

    echo "<pre>";
    print_r(json_decode($response, true));
    echo "</pre>";
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
