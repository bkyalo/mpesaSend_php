<?php
// Capture STK push callback from Safaricom
$data = file_get_contents("php://input");
file_put_contents("mpesa_log.json", $data . PHP_EOL, FILE_APPEND);

// Send back a success response
header("Content-Type: application/json");
echo json_encode(["ResultCode" => 0, "ResultDesc" => "Confirmation received successfully"]);
?>
