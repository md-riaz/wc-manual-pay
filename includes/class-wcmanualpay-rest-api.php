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
            WCManualPay_Database::log_audit('API_AUTH_FAILED', 'webhook', null, array(
                'ip' => WCManualPay_Database::get_client_ip(),
            ), 'system:webhook');
            return new WP_Error('invalid_verify_key', __('Invalid verify key.', 'wc-manual-pay'), array('status' => 401));
        }

        $allowlist = $gateway->get_ip_allowlist();

        if (!empty($allowlist)) {
            $remote_ip = WCManualPay_Database::get_client_ip();

            if ('' === $remote_ip || !$this->ip_in_allowlist($remote_ip, $allowlist)) {
                WCManualPay_Database::log_audit('API_IP_REJECTED', 'webhook', null, array(
                    'ip' => $remote_ip,
                ), 'system:webhook');

                return new WP_Error('ip_not_allowed', __('Webhook IP address is not allowlisted.', 'wc-manual-pay'), array('status' => 401));
            }
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
        $auto_verify_mode = WCManualPay_Gateway::get_global_auto_verify_mode();
        $time_window_hours = WCManualPay_Gateway::get_global_time_window_hours();
        $auto_complete = WCManualPay_Gateway::is_auto_complete_globally_enabled();
        $mask_payer = WCManualPay_Gateway::is_mask_payer_globally_enabled();

        // Get parameters
        $provider = sanitize_text_field($request->get_param('provider'));
        $txn_id = sanitize_text_field($request->get_param('txn_id'));
        $amount = floatval($request->get_param('amount'));
        $currency = strtoupper(sanitize_text_field($request->get_param('currency')));
        $occurred_at = sanitize_text_field($request->get_param('occurred_at'));
        $status = strtoupper(sanitize_text_field($request->get_param('status')));
        $payer = sanitize_text_field($request->get_param('payer'));

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

        // Default status handling (NEW by default, allow INVALID)
        if ('INVALID' !== $status) {
            $status = 'NEW';
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
            WCManualPay_Database::log_audit('API_DUPLICATE_TRANSACTION', 'transaction', $existing->id, array(
                'provider' => $provider,
                'txn_id' => $txn_id,
            ), 'system:webhook');

            return new WP_REST_Response(array(
                'success' => true,
                'message' => __('Transaction already exists.', 'wc-manual-pay'),
                'transaction_id' => $existing->id,
                'status' => $existing->status,
            ), 200);
        }

        $meta_payload = $request->get_json_params();

        if (empty($meta_payload)) {
            $meta_payload = $request->get_params();
        }

        if (is_array($meta_payload)) {
            unset($meta_payload['verify_key']);
        }

        // Insert transaction
        $transaction_id = WCManualPay_Database::insert_transaction(array(
            'provider' => $provider,
            'txn_id' => $txn_id,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => $occurred_at,
            'status' => $status,
            'payer' => $payer,
            'meta_json' => $meta_payload,
            'mask_payer' => $mask_payer,
        ));

        if (!$transaction_id) {
            WCManualPay_Database::log_audit('API_INSERT_FAILED', 'transaction', null, array(
                'provider' => $provider,
                'txn_id' => $txn_id,
            ), 'system:webhook');
            return new WP_Error('insert_failed', __('Failed to insert transaction.', 'wc-manual-pay'), array('status' => 500));
        }

        WCManualPay_Database::log_audit('API_TRANSACTION_CREATED', 'transaction', $transaction_id, array(
            'provider' => $provider,
            'txn_id' => $txn_id,
            'amount' => $amount,
            'currency' => $currency,
        ), 'system:webhook');

        // Try to match with pending orders
        $this->try_match_pending_orders($transaction_id, $auto_verify_mode, $time_window_hours, $auto_complete);

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
    private function try_match_pending_orders($transaction_id, $mode, $time_window_hours, $auto_complete) {
        if ('off' === $mode) {
            return;
        }

        $transaction = WCManualPay_Database::get_transaction_by_id($transaction_id);

        if (!$transaction) {
            return;
        }

        if (!in_array($transaction->status, array('NEW', 'MATCHED'), true)) {
            return;
        }

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
            $validation = WCManualPay_Gateway::validate_transaction_rules($transaction, $order, $mode, $time_window_hours);

            if (true !== $validation) {
                continue;
            }

            if ($auto_complete) {
                $marked = WCManualPay_Database::mark_transaction_used($transaction_id, $order->get_id());

                if (!$marked) {
                    WCManualPay_Database::log_audit('API_MARK_USED_FAILED', 'order', $order->get_id(), array(
                        'transaction_id' => $transaction_id,
                    ), 'system:webhook');
                    continue;
                }

                $order->set_transaction_id($transaction->txn_id);
                $order->add_order_note(
                    sprintf(
                        __('Payment verified via REST API. Provider: %1$s, Transaction ID: %2$s', 'wc-manual-pay'),
                        $transaction->provider,
                        $transaction->txn_id
                    )
                );
                $order->payment_complete($transaction->txn_id);

                WCManualPay_Database::log_audit('API_PAYMENT_MATCHED', 'order', $order->get_id(), array(
                    'transaction_id' => $transaction_id,
                    'auto_completed' => true,
                ), 'system:webhook');

                break;
            }

            $linked = WCManualPay_Database::link_transaction_to_order($transaction_id, $order->get_id(), 'MATCHED', 'system:webhook');

            if (!$linked) {
                WCManualPay_Database::log_audit('API_LINK_FAILED', 'order', $order->get_id(), array(
                    'transaction_id' => $transaction_id,
                ), 'system:webhook');
                continue;
            }

            $order->set_transaction_id($transaction->txn_id);
            $order->add_order_note(
                sprintf(
                    __('Transaction %1$s matched via webhook (provider: %2$s) and awaits manual completion.', 'wc-manual-pay'),
                    $transaction->txn_id,
                    $transaction->provider
                )
            );
            $order->update_status('on-hold', __('Transaction matched and awaiting manual completion.', 'wc-manual-pay'));

            WCManualPay_Database::log_audit('API_TRANSACTION_MATCHED', 'order', $order->get_id(), array(
                'transaction_id' => $transaction_id,
                'auto_completed' => false,
            ), 'system:webhook');

            break;
        }
    }

    /**
     * Determine if IP address is allowed by configured allowlist.
     *
     * @param string $ip        Remote IP address.
     * @param array  $allowlist Allowlist entries.
     * @return bool
     */
    private function ip_in_allowlist($ip, $allowlist) {
        if (empty($allowlist)) {
            return true;
        }

        foreach ($allowlist as $allowed) {
            if ('*' === $allowed) {
                return true;
            }

            if (false !== strpos($allowed, '*')) {
                $pattern = '/^' . str_replace('\*', '.*', preg_quote($allowed, '/')) . '$/i';

                if (preg_match($pattern, $ip)) {
                    return true;
                }
            } elseif ($ip === $allowed) {
                return true;
            }
        }

        return false;
    }
}
