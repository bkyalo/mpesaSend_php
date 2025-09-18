<?php
// This is a frontend file
// The API endpoint is at /api/process_payment.php
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
    </style>
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

                // Send AJAX request to the API endpoint
                fetch('/api/process_payment.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async response => {
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const error = new Error(data.error || 'Failed to process payment');
                        error.response = response;
                        throw error;
                    }
                    return data;
                })
                .then(data => {
                    showSuccess(data.message || 'Payment request sent successfully! Please check your phone to complete the payment.');
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
