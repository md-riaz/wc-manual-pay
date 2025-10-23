<?php
/**
 * REST API endpoints
 *
 * @package WCManualPay
 */

defined('ABSPATH') || exit;

/**
 * REST API class
 */
class WCManualPay_REST_API {
    /**
     * Singleton instance
     *
     * @var WCManualPay_REST_API
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return WCManualPay_REST_API
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('wcmanualpay/v1', '/notify', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_notify'),
            'permission_callback' => array($this, 'verify_request'),
        ));
    }

    /**
     * Verify request using verify key
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function verify_request($request) {
        // Ensure payment gateways are initialized before attempting to access them.
        $payment_gateways = WC()->payment_gateways();

        if (!$payment_gateways || !is_callable(array($payment_gateways, 'payment_gateways'))) {
            return new WP_Error('gateway_controller_unavailable', __('Unable to load payment gateways.', 'wc-manual-pay'), array('status' => 500));
        }

        $gateway = $payment_gateways->payment_gateways()['wcmanualpay'] ?? null;

        if (!$gateway) {
            return new WP_Error('gateway_not_found', __('Payment gateway not configured.', 'wc-manual-pay'), array('status' => 500));
        }

        $verify_key = $gateway->get_option('verify_key');

        if (empty($verify_key)) {
            return new WP_Error('no_verify_key', __('Verify key not configured.', 'wc-manual-pay'), array('status' => 500));
        }

        // Check verify key in header or body
        $provided_key = $request->get_header('X-Verify-Key');
        
        if (empty($provided_key)) {
            $provided_key = $request->get_param('verify_key');
        }

        if (empty($provided_key)) {
            return new WP_Error('missing_verify_key', __('Verify key is required.', 'wc-manual-pay'), array('status' => 401));
        }

        if ($provided_key !== $verify_key) {
            WCManualPay_Database::log_audit('api_auth_failed', null, null, null, array(
                'ip' => WCManualPay_Database::get_client_ip(),
            ));
            return new WP_Error('invalid_verify_key', __('Invalid verify key.', 'wc-manual-pay'), array('status' => 401));
        }

        return true;
    }

    /**
     * Handle notify endpoint
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function handle_notify($request) {
        // Get parameters
        $provider = sanitize_text_field($request->get_param('provider'));
        $txn_id = sanitize_text_field($request->get_param('txn_id'));
        $amount = floatval($request->get_param('amount'));
        $currency = strtoupper(sanitize_text_field($request->get_param('currency')));
        $occurred_at = sanitize_text_field($request->get_param('occurred_at'));
        $status = sanitize_text_field($request->get_param('status'));

        // Validate required fields
        if (empty($provider)) {
            return new WP_Error('missing_provider', __('Provider is required.', 'wc-manual-pay'), array('status' => 400));
        }

        if (empty($txn_id)) {
            return new WP_Error('missing_txn_id', __('Transaction ID is required.', 'wc-manual-pay'), array('status' => 400));
        }

        if (empty($amount) || $amount <= 0) {
            return new WP_Error('invalid_amount', __('Valid amount is required.', 'wc-manual-pay'), array('status' => 400));
        }

        if (empty($currency)) {
            return new WP_Error('missing_currency', __('Currency is required.', 'wc-manual-pay'), array('status' => 400));
        }

        // Default status
        if (empty($status)) {
            $status = 'pending';
        }

        // Validate occurred_at
        if (empty($occurred_at)) {
            $occurred_at = current_time('mysql');
        } else {
            // Validate datetime format
            $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $occurred_at);
            if (!$datetime || $datetime->format('Y-m-d H:i:s') !== $occurred_at) {
                return new WP_Error('invalid_occurred_at', __('Invalid occurred_at format. Use Y-m-d H:i:s', 'wc-manual-pay'), array('status' => 400));
            }
        }

        // Idempotency check - check if transaction already exists
        $existing = WCManualPay_Database::get_transaction($provider, $txn_id);

        if ($existing) {
            // Transaction already exists - return existing data
            WCManualPay_Database::log_audit('api_duplicate_transaction', $existing->id, null, null, array(
                'provider' => $provider,
                'txn_id' => $txn_id,
            ));

            return new WP_REST_Response(array(
                'success' => true,
                'message' => __('Transaction already exists.', 'wc-manual-pay'),
                'transaction_id' => $existing->id,
                'status' => $existing->status,
            ), 200);
        }

        // Insert transaction
        $transaction_id = WCManualPay_Database::insert_transaction(array(
            'provider' => $provider,
            'txn_id' => $txn_id,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => $occurred_at,
            'status' => $status,
        ));

        if (!$transaction_id) {
            WCManualPay_Database::log_audit('api_insert_failed', null, null, null, array(
                'provider' => $provider,
                'txn_id' => $txn_id,
            ));
            return new WP_Error('insert_failed', __('Failed to insert transaction.', 'wc-manual-pay'), array('status' => 500));
        }

        WCManualPay_Database::log_audit('api_transaction_created', $transaction_id, null, null, array(
            'provider' => $provider,
            'txn_id' => $txn_id,
            'amount' => $amount,
            'currency' => $currency,
        ));

        // Try to match with pending orders
        $this->try_match_pending_orders($transaction_id);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Transaction created successfully.', 'wc-manual-pay'),
            'transaction_id' => $transaction_id,
        ), 201);
    }

    /**
     * Try to match transaction with pending orders
     *
     * @param int $transaction_id Transaction ID
     */
    private function try_match_pending_orders($transaction_id) {
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
            $gateway = new WCManualPay_Gateway();
            $validation = $this->validate_transaction_for_order($transaction, $order);

            if (true === $validation) {
                // Mark transaction as used
                WCManualPay_Database::mark_transaction_used($transaction_id, $order->get_id());

                // Set order transaction ID
                $order->set_transaction_id($transaction->txn_id);
                $order->add_order_note(
                    sprintf(
                        __('Payment verified via REST API. Provider: %s, Transaction ID: %s', 'wc-manual-pay'),
                        $transaction->provider,
                        $transaction->txn_id
                    )
                );

                // Complete payment
                $order->payment_complete($transaction->txn_id);

                // Log audit
                WCManualPay_Database::log_audit('api_payment_matched', $transaction_id, $order->get_id(), null);

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
     * @return bool|string True if valid, error message otherwise
     */
    private function validate_transaction_for_order($transaction, $order) {
        // Check if already used
        if ('used' === $transaction->status) {
            return __('Transaction already used.', 'wc-manual-pay');
        }

        // Check amount
        if (abs(floatval($transaction->amount) - floatval($order->get_total())) > 0.01) {
            return __('Amount mismatch.', 'wc-manual-pay');
        }

        // Check currency
        if (strtoupper($transaction->currency) !== strtoupper($order->get_currency())) {
            return __('Currency mismatch.', 'wc-manual-pay');
        }

        // Check 72-hour window
        $occurred_time = strtotime($transaction->occurred_at);
        $current_time = current_time('timestamp');
        $time_diff = $current_time - $occurred_time;
        $hours_72 = 72 * 60 * 60;

        if ($time_diff > $hours_72) {
            return __('Transaction expired.', 'wc-manual-pay');
        }

        return true;
    }
}
