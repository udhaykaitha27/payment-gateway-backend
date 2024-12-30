<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables from the .env file
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');

// Configuration using .env variables
$apiKey = $_ENV['API_KEY'];
$merchantId = $_ENV['MERCHANT_ID'];
$paymentApiUrl = $_ENV['PAYMENT_API_URL'];
$orderStatusApiUrl = $_ENV['ORDER_STATUS_API_URL'];

// Twilio Credentials
$accountSid = $_ENV['TWILIO_ACCOUNT_SID'];
$authToken = $_ENV['TWILIO_AUTH_TOKEN'];
$whatsAppNumber = $_ENV['TWILIO_WHATSAPP_NUMBER'];

$paymentPageClientId = $_ENV['PAYMENT_PAGE_CLIENT_ID'];
$returnUrl = $_ENV['RETURN_URL'];

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['action'])) {
    http_response_code(400);
    echo json_encode(["error" => "Action parameter is required"]);
    exit;
}

$action = $input['action'];

function sendWhatsAppMessage($phone, $message)
{
    global $accountSid, $authToken, $whatsAppNumber;

    $url = "https://api.twilio.com/2010-04-01/Accounts/$accountSid/Messages.json";
    $data = [
        'From' => $whatsAppNumber,
        'To' => "whatsapp:+91$phone",
        'Body' => $message,
    ];

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_USERPWD => "$accountSid:$authToken",
    ];

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

if ($action === 'initiatePayment') {
    $amount = $input['amount'] ?? null;

    if (!$amount) {
        http_response_code(400);
        echo json_encode(["error" => "Amount is required"]);
        exit;
    }

    $order_id = uniqid("order_");
    $data = [
        "order_id" => $order_id,
        "amount" => $amount,
        "currency" => "INR",
        "action" => "paymentPage",
        "payment_page_client_id" => $paymentPageClientId,
        "return_url" => $returnUrl,
        "customer_id" => "test_customer_" . uniqid(),
        "customer_email" => "udhay@multipliersolutions.com",
        "customer_phone" => "6305465943",
        "description" => "Payment for Order #$order_id",
        "first_name" => "xyz",
        "last_name" => "kiran",
    ];

    $headers = [
        "Authorization: Basic " . base64_encode("$apiKey:"),
        "Content-Type: application/json",
        "x-merchantid: $merchantId",
    ];

    $ch = curl_init($paymentApiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error_message = curl_error($ch);
        http_response_code(500);
        echo json_encode(["error" => "cURL Error: $error_message"]);
    } elseif ($httpCode !== 200) {
        http_response_code($httpCode);
        echo json_encode([
            "error" => "Failed to initiate payment",
            "response" => json_decode($response, true),
        ]);
    } else {
        $responseData = json_decode($response, true);

        if (isset($responseData['payment_links']['web'])) {
            $paymentLink = $responseData['payment_links']['web'];
            $paymentExpiry = $responseData['payment_links']['expiry'];
            $whatsAppMessage = "Your payment link: $paymentLink\nExpiry: $paymentExpiry";

            sendWhatsAppMessage($data['customer_phone'], $whatsAppMessage);

            echo $response;
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Payment link not found in the response"]);
        }
    }
    curl_close($ch);
}  elseif ($action === 'getOrderStatus') {
    // Validate required parameters
    $order_id = $input['order_id'] ?? null;
    $customer_id = $input['customer_id'] ?? null;

    if (!$order_id || !$customer_id) {
        http_response_code(400);
        echo json_encode(["error" => "Order ID and Customer ID are required"]);
        exit;
    }

    // Prepare cURL for order status fetch
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $orderStatusApiUrl . "/" . $order_id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'version: 2023-06-30',
            "Content-Type: application/json",
            'x-merchantid: ' . $merchantId,
            "x-customerid: $customer_id",
            "Authorization: Basic " . base64_encode("$apiKey:"),
            'User-Agent: PostmanRuntime/7.29.2',
        ),
    ));

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if (curl_errno($curl)) {
        // Handle cURL errors
        $error_message = curl_error($curl);
        http_response_code(500);
        echo json_encode(["error" => "cURL Error: $error_message"]);
    } elseif ($httpCode !== 200) {
        // Handle non-200 responses
        http_response_code($httpCode);
        echo json_encode([
            "error" => "Failed to fetch order status",
            "response" => json_decode($response, true),
        ]);
    } else {
        // Successfully received a response
        $responseData = json_decode($response, true);

        // Check if 'payment_links' and 'web' exist in the response
        if (isset($responseData['payment_links'])) {
            // Prepare WhatsApp message
            $whatsAppMessage = "Your Payment status: Successful\nAmount Paid: " . $responseData['amount'] . "\nOrder ID: " . $responseData['order_id'] . "\nCustomer ID: " . $responseData['customer_id'] . "\nCustomer Email: " . $responseData['customer_email'];

            // Send WhatsApp message
            sendWhatsAppMessage($responseData['customer_phone'], $whatsAppMessage);

            // Return the full response to the client
            echo $response;
        } else {
            // Handle the case where 'payment_links' is not found in the response
            http_response_code(500);
            echo json_encode(["error" => "Payment link not found in the response"]);
        }
    }

    curl_close($curl);
}

