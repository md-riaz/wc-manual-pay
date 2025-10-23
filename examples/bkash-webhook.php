<?php
/**
 * Example: bKash Payment Gateway Webhook Handler
 * 
 * This example shows how to integrate bKash payment gateway with WCManualPay.
 * Place this file in your WordPress root or create a custom endpoint.
 * 
 * @package WCManualPay
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // If not in WordPress context, load WordPress
    require_once(__DIR__ . '/../../../wp-load.php');
}

/**
 * Handle bKash webhook/IPN notification
 */
function handle_bkash_webhook() {
    // Get webhook payload
    $raw_payload = file_get_contents('php://input');
    $payload = json_decode($raw_payload, true);
    
    // Log the webhook for debugging
    error_log('bKash Webhook Received: ' . $raw_payload);
    
    // Validate webhook signature (if bKash provides one)
    // This is critical for security!
    $is_valid = verify_bkash_signature($payload, $_SERVER['HTTP_X_BKASH_SIGNATURE'] ?? '');
    
    if (!$is_valid) {
        error_log('bKash Webhook: Invalid signature');
        http_response_code(401);
        echo json_encode(array('error' => 'Invalid signature'));
        exit;
    }
    
    // Extract transaction details
    $provider = 'bkash';
    $txn_id = $payload['trxID'] ?? '';
    $amount = $payload['amount'] ?? 0;
    $currency = $payload['currency'] ?? 'BDT';
    $status = $payload['transactionStatus'] ?? 'pending';
    $occurred_at = isset($payload['paymentExecuteTime']) 
        ? date('Y-m-d H:i:s', strtotime($payload['paymentExecuteTime']))
        : current_time('mysql');
    
    // Validate required fields
    if (empty($txn_id) || $amount <= 0) {
        error_log('bKash Webhook: Missing required fields');
        http_response_code(400);
        echo json_encode(array('error' => 'Missing required fields'));
        exit;
    }
    
    // Only process successful transactions
    if ($status !== 'Completed') {
        error_log('bKash Webhook: Transaction not completed - Status: ' . $status);
        http_response_code(200);
        echo json_encode(array('message' => 'Transaction not completed'));
        exit;
    }
    
    // Send to WCManualPay
    $gateway = WC()->payment_gateways->payment_gateways()['wcmanualpay'] ?? null;
    if (!$gateway) {
        error_log('bKash Webhook: WCManualPay gateway not found');
        http_response_code(500);
        echo json_encode(array('error' => 'Payment gateway not configured'));
        exit;
    }
    
    $verify_key = $gateway->get_option('verify_key');
    
    $response = wp_remote_post(rest_url('wcmanualpay/v1/notify'), array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-Verify-Key' => $verify_key,
        ),
        'body' => json_encode(array(
            'provider' => $provider,
            'txn_id' => $txn_id,
            'amount' => floatval($amount),
            'currency' => strtoupper($currency),
            'occurred_at' => $occurred_at,
            'status' => 'pending',
        )),
        'timeout' => 30,
    ));
    
    if (is_wp_error($response)) {
        error_log('bKash Webhook: WCManualPay API Error - ' . $response->get_error_message());
        http_response_code(500);
        echo json_encode(array('error' => 'Failed to process transaction'));
        exit;
    }
    
    $result = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($result['success']) {
        error_log('bKash Webhook: Transaction processed - ID: ' . $result['transaction_id']);
        http_response_code(200);
        echo json_encode(array(
            'success' => true,
            'transaction_id' => $result['transaction_id'],
        ));
    } else {
        error_log('bKash Webhook: Failed to create transaction');
        http_response_code(500);
        echo json_encode(array('error' => 'Failed to create transaction'));
    }
}

/**
 * Verify bKash webhook signature
 * 
 * @param array $payload Webhook payload
 * @param string $signature Signature from header
 * @return bool
 */
function verify_bkash_signature($payload, $signature) {
    // Replace with your actual bKash signature verification logic
    // This is just an example - consult bKash documentation for actual implementation
    
    $bkash_secret = get_option('bkash_webhook_secret');
    if (empty($bkash_secret)) {
        return false;
    }
    
    // Example signature verification (adjust based on bKash's requirements)
    $expected_signature = hash_hmac('sha256', json_encode($payload), $bkash_secret);
    
    return hash_equals($expected_signature, $signature);
}

// If this file is accessed directly as a webhook endpoint
if (basename($_SERVER['SCRIPT_FILENAME']) === 'bkash-webhook.php') {
    handle_bkash_webhook();
}
