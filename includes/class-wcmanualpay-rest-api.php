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
        $payload = array(
            'provider'   => $request->get_param('provider'),
            'txn_id'     => $request->get_param('txn_id'),
            'amount'     => $request->get_param('amount'),
            'currency'   => $request->get_param('currency'),
            'occurred_at' => $request->get_param('occurred_at'),
            'status'     => $request->get_param('status'),
            'payer'      => $request->get_param('payer'),
            'meta_json'  => $this->extract_meta_payload($request),
            'mask_payer' => WCManualPay_Gateway::is_mask_payer_globally_enabled(),
        );

        $context = array(
            'actor'             => 'system:webhook',
            'actor_label'       => __('REST API', 'wc-manual-pay'),
            'action_prefix'     => 'API',
            'auto_verify_mode'  => WCManualPay_Gateway::get_global_auto_verify_mode(),
            'time_window_hours' => WCManualPay_Gateway::get_global_time_window_hours(),
            'auto_complete'     => WCManualPay_Gateway::is_auto_complete_globally_enabled(),
        );

        $result = WCManualPay_Transaction_Manager::create_transaction($payload, $context);

        if (is_wp_error($result)) {
            return $result;
        }

        if (!empty($result['duplicate'])) {
            return new WP_REST_Response(array(
                'success'        => true,
                'message'        => __('Transaction already exists.', 'wc-manual-pay'),
                'transaction_id' => $result['transaction_id'],
                'status'         => $result['transaction']->status,
            ), 200);
        }

        return new WP_REST_Response(array(
            'success'        => true,
            'message'        => __('Transaction created successfully.', 'wc-manual-pay'),
            'transaction_id' => $result['transaction_id'],
            'match_result'   => $result['match_result'],
        ), 201);
    }

    /**
     * Extract meta payload from the REST request.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return array|string|null
     */
    private function extract_meta_payload($request) {
        $meta_payload = $request->get_json_params();

        if (empty($meta_payload)) {
            $meta_payload = $request->get_params();
        }

        if (is_array($meta_payload) && array_key_exists('verify_key', $meta_payload)) {
            unset($meta_payload['verify_key']);
        }

        return $meta_payload;
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
