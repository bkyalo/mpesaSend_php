<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $phone = $_POST["phone"];
    $amount = $_POST["amount"];

    // Call STK Push
    stkPush($phone, $amount);
}

function stkPush($phoneNumber, $amount) {
    // Your Daraja credentials
    $consumerKey    = "YOUR_CONSUMER_KEY";
    $consumerSecret = "YOUR_CONSUMER_SECRET";
    $shortCode      = "174379"; // Sandbox shortcode
    $passkey        = "YOUR_LNM_PASSKEY";
    $callbackURL    = "https://yourdomain.com/callback.php"; // Public callback

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
<html>
<head>
    <title>Pay with M-Pesa</title>
</head>
<body>
    <h2>Enter Phone Number to Pay</h2>
    <form method="POST">
        <label>Phone Number (2547XXXXXXXX):</label><br>
        <input type="text" name="phone" required><br><br>

        <label>Amount:</label><br>
        <input type="number" name="amount" required><br><br>

        <button type="submit">Pay Now</button>
    </form>
</body>
</html>
