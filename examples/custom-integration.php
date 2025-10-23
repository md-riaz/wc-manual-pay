<?php
/**
 * Example: Custom REST API Integration
 * 
 * This example shows how to create custom endpoints or integrate with WCManualPay
 * from your own WordPress plugin or theme.
 * 
 * @package WCManualPay
 */

/**
 * Class: Custom Payment Integration
 */
class Custom_Payment_Integration {
    
    /**
     * Initialize the integration
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_custom_routes'));
        add_action('woocommerce_payment_complete', array(__CLASS__, 'on_payment_complete'));
    }
    
    /**
     * Register custom REST API routes
     */
    public static function register_custom_routes() {
        // Custom endpoint to create transaction with additional validation
        register_rest_route('custom/v1', '/payment-notify', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_payment_notify'),
            'permission_callback' => array(__CLASS__, 'validate_api_key'),
        ));
        
        // Custom endpoint to query transaction status
        register_rest_route('custom/v1', '/transaction-status/(?P<txn_id>[a-zA-Z0-9-_]+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_transaction_status'),
            'permission_callback' => array(__CLASS__, 'validate_api_key'),
        ));
    }
    
    /**
     * Validate API key for custom endpoints
     */
    public static function validate_api_key($request) {
        $api_key = $request->get_header('X-API-Key');
        $expected_key = get_option('custom_payment_api_key');
        
        if ($api_key === $expected_key) {
            return true;
        }
        
        return new WP_Error('invalid_api_key', 'Invalid API key', array('status' => 401));
    }
    
    /**
     * Handle payment notification with custom logic
     */
    public static function handle_payment_notify($request) {
        $provider = $request->get_param('provider');
        $txn_id = $request->get_param('txn_id');
        $amount = $request->get_param('amount');
        $currency = $request->get_param('currency');
        $customer_phone = $request->get_param('customer_phone'); // Custom field
        
        // Custom validation logic
        if (!self::validate_provider($provider)) {
            return new WP_Error('invalid_provider', 'Provider not supported', array('status' => 400));
        }
        
        if (!self::validate_amount($amount)) {
            return new WP_Error('invalid_amount', 'Amount out of range', array('status' => 400));
        }
        
        // Send to WCManualPay
        $gateway = WC()->payment_gateways->payment_gateways()['wcmanualpay'] ?? null;
        if (!$gateway) {
            return new WP_Error('gateway_not_found', 'Payment gateway not configured', array('status' => 500));
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
            )),
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        // Store custom field in post meta for later use
        if ($result['success'] && !empty($customer_phone)) {
            update_post_meta($result['transaction_id'], '_customer_phone', sanitize_text_field($customer_phone));
        }
        
        return new WP_REST_Response($result, $result['success'] ? 200 : 400);
    }
    
    /**
     * Get transaction status
     */
    public static function get_transaction_status($request) {
        $txn_id = $request->get_param('txn_id');
        
        global $wpdb;
        $table = $wpdb->prefix . 'wcmanualpay_transactions';
        
        $transaction = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE txn_id = %s", $txn_id)
        );
        
        if (!$transaction) {
            return new WP_Error('not_found', 'Transaction not found', array('status' => 404));
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'transaction' => array(
                'id' => $transaction->id,
                'provider' => $transaction->provider,
                'txn_id' => $transaction->txn_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status,
                'order_id' => $transaction->order_id,
                'occurred_at' => $transaction->occurred_at,
            ),
        ));
    }
    
    /**
     * Custom provider validation
     */
    private static function validate_provider($provider) {
        $allowed_providers = array('bkash', 'nagad', 'rocket', 'upay');
        return in_array($provider, $allowed_providers, true);
    }
    
    /**
     * Custom amount validation
     */
    private static function validate_amount($amount) {
        $amount = floatval($amount);
        // Example: Amount must be between 10 and 100000
        return $amount >= 10 && $amount <= 100000;
    }
    
    /**
     * Hook into payment complete to send custom notifications
     */
    public static function on_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_payment_method() !== 'wcmanualpay') {
            return;
        }
        
        // Get transaction details
        $provider = $order->get_meta('_wcmanualpay_provider');
        $txn_id = $order->get_meta('_wcmanualpay_txn_id');
        
        if (empty($provider) || empty($txn_id)) {
            return;
        }
        
        // Send custom notification (e.g., SMS, webhook to another system)
        self::send_custom_notification($order, $provider, $txn_id);
    }
    
    /**
     * Send custom notification
     */
    private static function send_custom_notification($order, $provider, $txn_id) {
        // Example: Send to external webhook
        $webhook_url = get_option('custom_payment_webhook_url');
        
        if (empty($webhook_url)) {
            return;
        }
        
        wp_remote_post($webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'order_id' => $order->get_id(),
                'order_total' => $order->get_total(),
                'provider' => $provider,
                'txn_id' => $txn_id,
                'customer_email' => $order->get_billing_email(),
                'customer_phone' => $order->get_billing_phone(),
            )),
        ));
        
        // Log the notification
        error_log("Custom notification sent for Order #{$order->get_id()}");
    }
}

// Initialize the integration
add_action('plugins_loaded', array('Custom_Payment_Integration', 'init'));

/**
 * Helper function: Get transaction by order ID
 */
function get_transaction_by_order_id($order_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wcmanualpay_transactions';
    
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d", $order_id)
    );
}

/**
 * Helper function: Get all transactions for a provider
 */
function get_provider_transactions($provider, $status = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'wcmanualpay_transactions';
    
    $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE provider = %s", $provider);
    
    if ($status) {
        $sql .= $wpdb->prepare(" AND status = %s", $status);
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    return $wpdb->get_results($sql);
}

/**
 * Helper function: Get transaction statistics
 */
function get_transaction_stats($provider = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'wcmanualpay_transactions';
    
    $where = '1=1';
    $params = array();
    
    if ($provider) {
        $where .= ' AND provider = %s';
        $params[] = $provider;
    }
    
    $sql = "SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as used_count,
                SUM(amount) as total_amount,
                SUM(CASE WHEN status = 'used' THEN amount ELSE 0 END) as used_amount
            FROM {$table}
            WHERE {$where}";
    
    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }
    
    return $wpdb->get_row($sql);
}
