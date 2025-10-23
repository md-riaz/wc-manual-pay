<?php
/**
 * Example: Bulk Transaction Import Script
 * 
 * This script demonstrates how to import multiple transactions at once.
 * Useful for migrating historical transactions or batch processing.
 * 
 * Usage: php bulk-import.php
 * 
 * @package WCManualPay
 */

// Load WordPress
require_once(__DIR__ . '/../../../../wp-load.php');

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Check if user has permission (optional, but recommended)
if (!current_user_can('manage_woocommerce')) {
    // For CLI, we'll skip this check, but you might want to add your own authentication
}

/**
 * Sample transactions to import
 * 
 * In a real scenario, you might load this from a CSV file, database, or API
 */
$transactions = array(
    array(
        'provider' => 'bkash',
        'txn_id' => 'BKH2025001',
        'amount' => 500.00,
        'currency' => 'BDT',
        'occurred_at' => '2025-10-20 10:30:00',
    ),
    array(
        'provider' => 'nagad',
        'txn_id' => 'NGD2025001',
        'amount' => 750.50,
        'currency' => 'BDT',
        'occurred_at' => '2025-10-21 14:15:00',
    ),
    array(
        'provider' => 'rocket',
        'txn_id' => 'RKT2025001',
        'amount' => 1200.00,
        'currency' => 'BDT',
        'occurred_at' => '2025-10-22 09:45:00',
    ),
    // Add more transactions here...
);

echo "Starting bulk transaction import...\n";
echo "Total transactions to import: " . count($transactions) . "\n\n";

$success_count = 0;
$error_count = 0;
$duplicate_count = 0;

foreach ($transactions as $index => $txn) {
    $txn_num = $index + 1;
    echo "[$txn_num/" . count($transactions) . "] Processing transaction {$txn['txn_id']}... ";
    
    // Validate required fields
    if (empty($txn['provider']) || empty($txn['txn_id']) || empty($txn['amount']) || empty($txn['currency'])) {
        echo "ERROR: Missing required fields\n";
        $error_count++;
        continue;
    }
    
    // Check if transaction already exists
    $existing = WCManualPay_Database::get_transaction($txn['provider'], $txn['txn_id']);
    if ($existing) {
        echo "SKIPPED: Already exists (ID: {$existing->id})\n";
        $duplicate_count++;
        continue;
    }
    
    // Insert transaction
    $transaction_id = WCManualPay_Database::insert_transaction($txn);
    
    if ($transaction_id) {
        echo "SUCCESS: Created (ID: {$transaction_id})\n";
        $success_count++;
        
        // Optional: Try to match with existing orders
        // This will automatically complete orders if matching criteria are met
        try_match_with_orders($transaction_id);
    } else {
        echo "ERROR: Failed to insert\n";
        $error_count++;
    }
    
    // Optional: Add a small delay to avoid overwhelming the database
    usleep(100000); // 0.1 second delay
}

echo "\n";
echo "========================================\n";
echo "Import completed!\n";
echo "========================================\n";
echo "Successful imports: $success_count\n";
echo "Duplicates skipped: $duplicate_count\n";
echo "Errors: $error_count\n";
echo "========================================\n";

/**
 * Try to match transaction with existing orders
 * 
 * @param int $transaction_id Transaction ID
 */
function try_match_with_orders($transaction_id) {
    $transaction = WCManualPay_Database::get_transaction_by_id($transaction_id);
    
    if (!$transaction) {
        return;
    }
    
    // Get pending orders with matching provider and txn_id
    $orders = wc_get_orders(array(
        'status' => array('pending', 'on-hold'),
        'payment_method' => 'wcmanualpay',
        'limit' => -1,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_wcmanualpay_provider',
                'value' => $transaction->provider,
                'compare' => '=',
            ),
            array(
                'key' => '_wcmanualpay_txn_id',
                'value' => $transaction->txn_id,
                'compare' => '=',
            ),
        ),
    ));
    
    foreach ($orders as $order) {
        // Validate transaction against order
        if (validate_transaction_for_order($transaction, $order)) {
            // Mark transaction as used
            WCManualPay_Database::mark_transaction_used($transaction_id, $order->get_id());
            
            // Complete payment
            $order->set_transaction_id($transaction->txn_id);
            $order->add_order_note(
                sprintf(
                    'Payment matched via bulk import. Provider: %s, Transaction ID: %s',
                    $transaction->provider,
                    $transaction->txn_id
                )
            );
            $order->payment_complete($transaction->txn_id);
            
            echo "\n    -> Matched with Order #{$order->get_id()}\n";
            
            // Only match one order
            break;
        }
    }
}

/**
 * Validate transaction against order
 * 
 * @param object $transaction Transaction object
 * @param WC_Order $order Order object
 * @return bool
 */
function validate_transaction_for_order($transaction, $order) {
    // Check if already used
    if ('used' === $transaction->status) {
        return false;
    }
    
    // Check amount (within 0.01 tolerance)
    if (abs(floatval($transaction->amount) - floatval($order->get_total())) > 0.01) {
        return false;
    }
    
    // Check currency
    if (strtoupper($transaction->currency) !== strtoupper($order->get_currency())) {
        return false;
    }
    
    // Check 72-hour window
    $occurred_time = strtotime($transaction->occurred_at);
    $current_time = current_time('timestamp');
    $time_diff = $current_time - $occurred_time;
    $hours_72 = 72 * 60 * 60;
    
    if ($time_diff > $hours_72) {
        return false;
    }
    
    return true;
}
