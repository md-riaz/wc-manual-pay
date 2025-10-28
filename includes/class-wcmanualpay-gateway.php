<?php
/**
 * Payment Gateway
 *
 * @package WCManualPay
 */

defined('ABSPATH') || exit;

/**
 * WCManualPay Gateway class
 */
class WCManualPay_Gateway extends WC_Payment_Gateway {
    const STRICT_AMOUNT_TOLERANCE = 0.01;
    const LENIENT_AMOUNT_TOLERANCE = 5.00;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'wcmanualpay';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = __('Manual Pay', 'wc-manual-pay');
        $this->method_description = __('Accept payments with manual transaction verification', 'wc-manual-pay');
        $this->supports = array(
            'products',
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->verify_key = $this->get_option('verify_key');
        $this->providers = $this->get_providers_array();

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_admin_override_button'));
        add_action('wp_ajax_wcmanualpay_admin_override', array($this, 'handle_admin_override'));
    }

    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'wc-manual-pay'),
                'type' => 'checkbox',
                'label' => __('Enable Manual Pay', 'wc-manual-pay'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'wc-manual-pay'),
                'type' => 'text',
                'description' => __('Payment method title that customers will see during checkout.', 'wc-manual-pay'),
                'default' => __('Manual Payment', 'wc-manual-pay'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'wc-manual-pay'),
                'type' => 'textarea',
                'description' => __('Payment method description that customers will see during checkout.', 'wc-manual-pay'),
                'default' => __('Pay using your transaction ID from the payment provider.', 'wc-manual-pay'),
                'desc_tip' => true,
            ),
            'verify_key' => array(
                'title' => __('Verify Key', 'wc-manual-pay'),
                'type' => 'text',
                'description' => __('Secret key used to verify REST API requests. Keep this secure!', 'wc-manual-pay'),
                'default' => '',
                'desc_tip' => true,
            ),
            'providers' => array(
                'title' => __('Payment Providers', 'wc-manual-pay'),
                'type' => 'textarea',
                'description' => __('Enter one provider per line. Example: bkash, nagad, rocket', 'wc-manual-pay'),
                'default' => "bkash\nnagad\nrocket",
                'desc_tip' => true,
            ),
            'auto_verify_mode' => array(
                'title' => __('Auto-verify mode', 'wc-manual-pay'),
                'type' => 'select',
                'description' => __('Control how transactions are automatically validated against orders.', 'wc-manual-pay'),
                'default' => 'strict',
                'options' => array(
                    'off' => __('Off', 'wc-manual-pay'),
                    'lenient' => __('Lenient', 'wc-manual-pay'),
                    'strict' => __('Strict', 'wc-manual-pay'),
                ),
                'desc_tip' => true,
            ),
            'time_window_hours' => array(
                'title' => __('Verification window (hours)', 'wc-manual-pay'),
                'type' => 'number',
                'description' => __('Maximum transaction age allowed for automatic verification. Set to 0 to disable the limit.', 'wc-manual-pay'),
                'default' => 72,
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => 0,
                    'step' => 1,
                ),
            ),
            'auto_complete_on_match' => array(
                'title' => __('Auto-complete on match', 'wc-manual-pay'),
                'type' => 'checkbox',
                'label' => __('Automatically complete the order when a transaction is matched.', 'wc-manual-pay'),
                'default' => 'yes',
            ),
            'ip_allowlist' => array(
                'title' => __('Webhook IP allowlist', 'wc-manual-pay'),
                'type' => 'textarea',
                'description' => __('Optional comma or newline separated list of IP addresses or wildcard ranges that may call the webhook.', 'wc-manual-pay'),
                'default' => '',
                'desc_tip' => true,
            ),
            'mask_payer' => array(
                'title' => __('Mask payer details', 'wc-manual-pay'),
                'type' => 'checkbox',
                'label' => __('Store payer identifiers in masked form.', 'wc-manual-pay'),
                'default' => 'yes',
            ),
        );
    }

    /**
     * Get providers as array
     *
     * @return array
     */
    private function get_providers_array() {
        $providers_text = $this->get_option('providers', "bkash\nnagad\nrocket");
        $providers = array_filter(array_map('trim', explode("\n", $providers_text)));
        return array_values($providers);
    }

    /**
     * Get auto verification mode from settings.
     *
     * @return string
     */
    public function get_auto_verify_mode() {
        return self::normalize_auto_verify_mode($this->get_option('auto_verify_mode', 'strict'));
    }

    /**
     * Get the verification window in hours.
     *
     * @return int
     */
    public function get_time_window_hours() {
        $value = $this->get_option('time_window_hours', 72);
        return max(0, absint($value));
    }

    /**
     * Determine if orders should auto-complete when matched.
     *
     * @return bool
     */
    public function is_auto_complete_enabled() {
        return 'yes' === $this->get_option('auto_complete_on_match', 'yes');
    }

    /**
     * Determine if payer masking is enabled.
     *
     * @return bool
     */
    public function should_mask_payer() {
        return 'yes' === $this->get_option('mask_payer', 'yes');
    }

    /**
     * Retrieve configured webhook IP allowlist.
     *
     * @return array
     */
    public function get_ip_allowlist() {
        return self::parse_allowlist($this->get_option('ip_allowlist', ''));
    }

    /**
     * Retrieve raw option value from stored settings.
     *
     * @param string $key     Option key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get_option_value($key, $default = '') {
        $settings = get_option('woocommerce_wcmanualpay_settings', array());

        if (!is_array($settings)) {
            $settings = array();
        }

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Normalise auto verify mode value.
     *
     * @param string $mode Raw mode.
     * @return string
     */
    public static function normalize_auto_verify_mode($mode) {
        $mode = strtolower((string) $mode);

        return in_array($mode, array('off', 'lenient', 'strict'), true) ? $mode : 'off';
    }

    /**
     * Retrieve global auto verify mode.
     *
     * @return string
     */
    public static function get_global_auto_verify_mode() {
        return self::normalize_auto_verify_mode(self::get_option_value('auto_verify_mode', 'strict'));
    }

    /**
     * Retrieve global time window configuration.
     *
     * @return int
     */
    public static function get_global_time_window_hours() {
        $value = self::get_option_value('time_window_hours', 72);
        return max(0, absint($value));
    }

    /**
     * Determine if automatic completion is globally enabled.
     *
     * @return bool
     */
    public static function is_auto_complete_globally_enabled() {
        return 'yes' === self::get_option_value('auto_complete_on_match', 'yes');
    }

    /**
     * Determine if payer masking is globally enabled.
     *
     * @return bool
     */
    public static function is_mask_payer_globally_enabled() {
        return 'yes' === self::get_option_value('mask_payer', 'yes');
    }

    /**
     * Retrieve globally configured providers list.
     *
     * @return array
     */
    public static function get_global_providers() {
        $providers_text = self::get_option_value('providers', "bkash\nnagad\nrocket");
        $providers = preg_split('/\r\n|\r|\n/', (string) $providers_text);

        if (false === $providers) {
            $providers = array();
        }

        $providers = array_filter(array_map('trim', $providers));

        return array_values(array_unique($providers));
    }

    /**
     * Retrieve global IP allowlist values.
     *
     * @return array
     */
    public static function get_global_ip_allowlist() {
        return self::parse_allowlist(self::get_option_value('ip_allowlist', ''));
    }

    /**
     * Parse allowlist string into array.
     *
     * @param string $raw Raw allowlist string.
     * @return array
     */
    protected static function parse_allowlist($raw) {
        if (!is_string($raw) || '' === trim($raw)) {
            return array();
        }

        $parts = preg_split('/[\r\n,]+/', $raw);
        $parts = array_map('trim', (array) $parts);
        $parts = array_filter($parts, function ($entry) {
            return '' !== $entry;
        });

        return array_values($parts);
    }

    /**
     * Payment fields
     */
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        ?>
        <fieldset id="wc-<?php echo esc_attr($this->id); ?>-form" class="wc-payment-form">
            <p class="form-row form-row-wide">
                <label for="wcmanualpay_provider">
                    <?php esc_html_e('Payment Provider', 'wc-manual-pay'); ?>
                    <span class="required">*</span>
                </label>
                <select id="wcmanualpay_provider" name="wcmanualpay_provider" class="input-text" required>
                    <option value=""><?php esc_html_e('Select Provider', 'wc-manual-pay'); ?></option>
                    <?php foreach ($this->providers as $provider) : ?>
                        <option value="<?php echo esc_attr($provider); ?>">
                            <?php echo esc_html(ucfirst($provider)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-row form-row-wide">
                <label for="wcmanualpay_txn_id">
                    <?php esc_html_e('Transaction ID', 'wc-manual-pay'); ?>
                    <span class="required">*</span>
                </label>
                <input id="wcmanualpay_txn_id" name="wcmanualpay_txn_id" type="text" class="input-text" required autocomplete="off" />
            </p>
        </fieldset>
        <?php
    }

    /**
     * Validate payment fields
     *
     * @return bool
     */
    public function validate_fields() {
        $provider = isset($_POST['wcmanualpay_provider']) ? sanitize_text_field(wp_unslash($_POST['wcmanualpay_provider'])) : '';
        $txn_id = isset($_POST['wcmanualpay_txn_id']) ? sanitize_text_field(wp_unslash($_POST['wcmanualpay_txn_id'])) : '';

        if (empty($provider)) {
            wc_add_notice(__('Payment provider is required.', 'wc-manual-pay'), 'error');
            return false;
        }

        if (!in_array($provider, $this->providers, true)) {
            wc_add_notice(__('Invalid payment provider.', 'wc-manual-pay'), 'error');
            return false;
        }

        if (empty($txn_id)) {
            wc_add_notice(__('Transaction ID is required.', 'wc-manual-pay'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Process payment
     *
     * @param int $order_id Order ID
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found.', 'wc-manual-pay'), 'error');
            return array('result' => 'failure');
        }

        $provider = isset($_POST['wcmanualpay_provider']) ? sanitize_text_field(wp_unslash($_POST['wcmanualpay_provider'])) : '';
        $txn_id = isset($_POST['wcmanualpay_txn_id']) ? sanitize_text_field(wp_unslash($_POST['wcmanualpay_txn_id'])) : '';

        // Save payment details to order
        $order->update_meta_data('_wcmanualpay_provider', $provider);
        $order->update_meta_data('_wcmanualpay_txn_id', $txn_id);
        $order->save();

        // Try to match transaction
        $transaction = WCManualPay_Database::get_transaction($provider, $txn_id);

        $mode = $this->get_auto_verify_mode();
        $time_window_hours = $this->get_time_window_hours();
        $auto_complete = $this->is_auto_complete_enabled();

        if ($transaction && 'off' !== $mode) {
            $validation = self::validate_transaction_rules($transaction, $order, $mode, $time_window_hours);

            if (true === $validation) {
                if ($auto_complete) {
                    $marked = WCManualPay_Database::mark_transaction_used($transaction->id, $order_id);

                    if (!$marked) {
                        $message = __('Unable to reserve the transaction. Please try again or contact support.', 'wc-manual-pay');
                        wc_add_notice($message, 'error');
                        WCManualPay_Database::log_audit('TRANSACTION_LOCK_FAILED', 'order', $order_id, array(
                            'transaction_id' => $transaction->id,
                        ));

                        return array('result' => 'failure');
                    }

                    $order->set_transaction_id($txn_id);
                    $order->add_order_note(
                        sprintf(
                            __('Payment verified via %1$s. Transaction ID: %2$s', 'wc-manual-pay'),
                            $provider,
                            $txn_id
                        )
                    );

                    $order->payment_complete($txn_id);

                    WCManualPay_Database::log_audit('PAYMENT_COMPLETED', 'order', $order_id, array(
                        'transaction_id' => $transaction->id,
                        'auto_completed' => true,
                    ));

                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order),
                    );
                }

                $linked = WCManualPay_Database::link_transaction_to_order($transaction->id, $order_id);

                if (!$linked) {
                    $message = __('Unable to reserve the transaction. Please try again or contact support.', 'wc-manual-pay');
                    wc_add_notice($message, 'error');
                    WCManualPay_Database::log_audit('TRANSACTION_LINK_FAILED', 'order', $order_id, array(
                        'transaction_id' => $transaction->id,
                    ));

                    return array('result' => 'failure');
                }

                $order->set_transaction_id($txn_id);
                $order->add_order_note(
                    sprintf(
                        __('Transaction %1$s matched automatically (provider: %2$s) and awaits manual completion.', 'wc-manual-pay'),
                        $txn_id,
                        $provider
                    )
                );
                $order->update_status('on-hold', __('Transaction matched and awaiting manual completion.', 'wc-manual-pay'));

                WCManualPay_Database::log_audit('TRANSACTION_MATCHED', 'order', $order_id, array(
                    'transaction_id' => $transaction->id,
                    'auto_completed' => false,
                ));

                if (WC()->cart) {
                    WC()->cart->empty_cart();
                }

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            }

            wc_add_notice($validation, 'error');
            $order->update_status('on-hold', $validation);
            WCManualPay_Database::log_audit('PAYMENT_VALIDATION_FAILED', 'order', $order_id, array(
                'transaction_id' => $transaction->id,
                'reason' => $validation,
            ));

            return array('result' => 'failure');
        }

        $pending_message = __('Awaiting manual payment verification.', 'wc-manual-pay');
        $audit_action = 'PAYMENT_PENDING';
        $audit_data = array(
            'provider' => $provider,
            'txn_id' => $txn_id,
        );

        if ($transaction && 'off' === $mode) {
            $pending_message = __('Auto verification disabled. Awaiting manual review.', 'wc-manual-pay');
            $order->add_order_note(
                sprintf(
                    __('Transaction %1$s from %2$s recorded but auto verification is disabled.', 'wc-manual-pay'),
                    $txn_id,
                    $provider
                )
            );
            $audit_action = 'TRANSACTION_PENDING_REVIEW';
            $audit_data = array(
                'transaction_id' => $transaction->id,
            );
        }

        $order->update_status('on-hold', $pending_message);
        WCManualPay_Database::log_audit($audit_action, 'order', $order_id, $audit_data);

        if (WC()->cart) {
            WC()->cart->empty_cart();
        }

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

    /**
     * Validate transaction against order with configurable rules.
     *
     * @param object    $transaction Transaction object.
     * @param WC_Order  $order       Order object.
     * @param string    $mode        Auto verification mode.
     * @param int       $time_window Time window in hours.
     * @return bool|string
     */
    public static function validate_transaction_rules($transaction, $order, $mode, $time_window) {
        if ('USED' === $transaction->status) {
            return __('Transaction already used for another order.', 'wc-manual-pay');
        }

        if (in_array($transaction->status, array('INVALID', 'REJECTED'), true)) {
            return __('Transaction is not eligible for use.', 'wc-manual-pay');
        }

        $transaction_amount = floatval($transaction->amount);
        $order_total = floatval($order->get_total());
        $difference = abs($transaction_amount - $order_total);
        $tolerance = self::STRICT_AMOUNT_TOLERANCE;

        if ('lenient' === $mode) {
            $tolerance = self::LENIENT_AMOUNT_TOLERANCE;
        }

        if ($difference > $tolerance) {
            $formatted_transaction = function_exists('wc_format_decimal') ? wc_format_decimal($transaction_amount, 2) : number_format($transaction_amount, 2, '.', '');
            $formatted_order = function_exists('wc_format_decimal') ? wc_format_decimal($order_total, 2) : number_format($order_total, 2, '.', '');

            if ('lenient' === $mode) {
                return sprintf(
                    __('Transaction amount (%1$s %2$s) differs from order total (%3$s %4$s) by more than %5$s.', 'wc-manual-pay'),
                    $formatted_transaction,
                    $transaction->currency,
                    $formatted_order,
                    $order->get_currency(),
                    $tolerance
                );
            }

            return sprintf(
                __('Transaction amount (%1$s %2$s) does not match order total (%3$s %4$s).', 'wc-manual-pay'),
                $formatted_transaction,
                $transaction->currency,
                $formatted_order,
                $order->get_currency()
            );
        }

        if (strtoupper($transaction->currency) !== strtoupper($order->get_currency())) {
            return sprintf(
                __('Transaction currency (%1$s) does not match order currency (%2$s).', 'wc-manual-pay'),
                $transaction->currency,
                $order->get_currency()
            );
        }

        $hours = max(0, absint($time_window));

        if ($hours > 0) {
            $occurred_time = strtotime($transaction->occurred_at);
            $current_time = current_time('timestamp');

            if ($occurred_time && $occurred_time < ($current_time - $hours * (defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600))) {
                return sprintf(
                    __('Transaction is older than %1$d hours (occurred at %2$s).', 'wc-manual-pay'),
                    $hours,
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $occurred_time)
                );
            }
        }

        return true;
    }

    /**
     * Display admin override button
     *
     * @param WC_Order $order Order object
     */
    public function display_admin_override_button($order) {
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        if ($order->is_paid()) {
            return;
        }

        $provider = $order->get_meta('_wcmanualpay_provider');
        $txn_id = $order->get_meta('_wcmanualpay_txn_id');

        if (empty($provider) || empty($txn_id)) {
            return;
        }

        ?>
        <div class="wcmanualpay-admin-override" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
            <h3><?php esc_html_e('Manual Payment Override', 'wc-manual-pay'); ?></h3>
            <p>
                <strong><?php esc_html_e('Provider:', 'wc-manual-pay'); ?></strong> <?php echo esc_html($provider); ?><br>
                <strong><?php esc_html_e('Transaction ID:', 'wc-manual-pay'); ?></strong> <?php echo esc_html($txn_id); ?>
            </p>
            <p>
                <label for="wcmanualpay_override_reason">
                    <?php esc_html_e('Reason for override', 'wc-manual-pay'); ?>
                </label>
                <textarea id="wcmanualpay_override_reason" class="widefat wcmanualpay-override-reason" rows="3" required></textarea>
            </p>
            <button type="button" class="button button-primary wcmanualpay-override-complete" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                <?php esc_html_e('Complete Payment (Override)', 'wc-manual-pay'); ?>
            </button>
            <p class="description">
                <?php esc_html_e('Use this to manually complete the payment without transaction matching.', 'wc-manual-pay'); ?>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('.wcmanualpay-override-complete').on('click', function(e) {
                e.preventDefault();

                if (!confirm('<?php esc_html_e('Are you sure you want to complete this payment without transaction matching?', 'wc-manual-pay'); ?>')) {
                    return;
                }

                var button = $(this);
                var orderId = button.data('order-id');
                var container = button.closest('.wcmanualpay-admin-override');
                var reasonField = container.find('.wcmanualpay-override-reason');
                var reason = $.trim(reasonField.val());
                var originalText = button.text();

                if (!reason) {
                    alert('<?php esc_html_e('Please provide a reason for the override.', 'wc-manual-pay'); ?>');
                    return;
                }

                button.prop('disabled', true).text('<?php esc_html_e('Processing...', 'wc-manual-pay'); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wcmanualpay_admin_override',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wcmanualpay_admin_override')); ?>',
                        reason: reason
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_html_e('An error occurred.', 'wc-manual-pay'); ?>');
                            button.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        alert('<?php esc_html_e('An error occurred.', 'wc-manual-pay'); ?>');
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle admin override
     */
    public function handle_admin_override() {
        check_ajax_referer('wcmanualpay_admin_override', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-manual-pay')));
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found.', 'wc-manual-pay')));
        }

        if ($order->get_payment_method() !== $this->id) {
            wp_send_json_error(array('message' => __('Invalid payment method.', 'wc-manual-pay')));
        }

        $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        if ('' === $reason) {
            wp_send_json_error(array('message' => __('A reason is required to override the payment.', 'wc-manual-pay')));
        }

        $provider = $order->get_meta('_wcmanualpay_provider');
        $txn_id = $order->get_meta('_wcmanualpay_txn_id');

        // Set transaction ID
        $order->set_transaction_id($txn_id);
        $order->add_order_note(
            sprintf(
                __('Payment manually completed by admin override. Provider: %1$s, Transaction ID: %2$s. Reason: %3$s', 'wc-manual-pay'),
                $provider,
                $txn_id,
                $reason
            )
        );

        // Complete payment
        $order->payment_complete($txn_id);

        // Log audit
        WCManualPay_Database::log_audit('ADMIN_OVERRIDE', 'order', $order_id, array(
            'provider' => $provider,
            'txn_id' => $txn_id,
            'reason' => $reason,
        ));

        wp_send_json_success(array('message' => __('Payment completed successfully.', 'wc-manual-pay')));
    }
}
