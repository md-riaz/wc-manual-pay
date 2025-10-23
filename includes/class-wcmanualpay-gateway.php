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

        if ($transaction) {
            // Transaction found - validate it
            $validation = $this->validate_transaction($transaction, $order);

            if (true === $validation) {
                // Mark transaction as used
                $marked = WCManualPay_Database::mark_transaction_used($transaction->id, $order_id);

                if (!$marked) {
                    $message = __('Unable to reserve the transaction. Please try again or contact support.', 'wc-manual-pay');
                    wc_add_notice($message, 'error');
                    WCManualPay_Database::log_audit('TRANSACTION_LOCK_FAILED', 'order', $order_id, array(
                        'transaction_id' => $transaction->id,
                    ));

                    return array('result' => 'failure');
                }

                // Set order transaction ID
                $order->set_transaction_id($txn_id);
                $order->add_order_note(
                    sprintf(
                        __('Payment verified via %s. Transaction ID: %s', 'wc-manual-pay'),
                        $provider,
                        $txn_id
                    )
                );

                // Complete payment
                $order->payment_complete($txn_id);

                // Log audit
                WCManualPay_Database::log_audit('PAYMENT_COMPLETED', 'order', $order_id, array(
                    'transaction_id' => $transaction->id,
                ));

                // Reduce stock
                wc_reduce_stock_levels($order_id);

                // Empty cart
                WC()->cart->empty_cart();

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            } else {
                // Validation failed
                wc_add_notice($validation, 'error');
                $order->update_status('pending', $validation);
                WCManualPay_Database::log_audit('PAYMENT_VALIDATION_FAILED', 'order', $order_id, array(
                    'transaction_id' => $transaction->id,
                    'reason' => $validation,
                ));
                return array('result' => 'failure');
            }
        } else {
            // Transaction not found - set to pending
            $order->update_status('pending', __('Awaiting manual payment verification.', 'wc-manual-pay'));
            WCManualPay_Database::log_audit('PAYMENT_PENDING', 'order', $order_id, array(
                'provider' => $provider,
                'txn_id' => $txn_id,
            ));

            // Empty cart
            WC()->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
    }

    /**
     * Validate transaction against order
     *
     * @param object $transaction Transaction object
     * @param WC_Order $order Order object
     * @return bool|string True if valid, error message otherwise
     */
    private function validate_transaction($transaction, $order) {
        // Check if already used
        if ('USED' === $transaction->status) {
            return __('Transaction already used for another order.', 'wc-manual-pay');
        }

        if (in_array($transaction->status, array('INVALID', 'REJECTED'), true)) {
            return __('Transaction is not eligible for use.', 'wc-manual-pay');
        }

        // Check amount
        if (abs(floatval($transaction->amount) - floatval($order->get_total())) > 0.01) {
            return sprintf(
                __('Transaction amount (%s %s) does not match order total (%s %s).', 'wc-manual-pay'),
                $transaction->amount,
                $transaction->currency,
                $order->get_total(),
                $order->get_currency()
            );
        }

        // Check currency
        if (strtoupper($transaction->currency) !== strtoupper($order->get_currency())) {
            return sprintf(
                __('Transaction currency (%s) does not match order currency (%s).', 'wc-manual-pay'),
                $transaction->currency,
                $order->get_currency()
            );
        }

        // Check 72-hour window
        $occurred_time = strtotime($transaction->occurred_at);
        $current_time = current_time('timestamp');
        $time_diff = $current_time - $occurred_time;
        $hours_72 = 72 * 60 * 60;

        if ($time_diff > $hours_72) {
            return sprintf(
                __('Transaction is older than 72 hours (occurred at %s).', 'wc-manual-pay'),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $occurred_time)
            );
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
                
                button.prop('disabled', true).text('<?php esc_html_e('Processing...', 'wc-manual-pay'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wcmanualpay_admin_override',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wcmanualpay_admin_override')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_html_e('An error occurred.', 'wc-manual-pay'); ?>');
                            button.prop('disabled', false).text('<?php esc_html_e('Complete Payment (Override)', 'wc-manual-pay'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_html_e('An error occurred.', 'wc-manual-pay'); ?>');
                        button.prop('disabled', false).text('<?php esc_html_e('Complete Payment (Override)', 'wc-manual-pay'); ?>');
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

        $provider = $order->get_meta('_wcmanualpay_provider');
        $txn_id = $order->get_meta('_wcmanualpay_txn_id');

        // Set transaction ID
        $order->set_transaction_id($txn_id);
        $order->add_order_note(
            sprintf(
                __('Payment manually completed by admin override. Provider: %s, Transaction ID: %s', 'wc-manual-pay'),
                $provider,
                $txn_id
            )
        );

        // Complete payment
        $order->payment_complete($txn_id);

        // Log audit
        WCManualPay_Database::log_audit('ADMIN_OVERRIDE', 'order', $order_id, array(
            'provider' => $provider,
            'txn_id' => $txn_id,
        ));

        wp_send_json_success(array('message' => __('Payment completed successfully.', 'wc-manual-pay')));
    }
}
