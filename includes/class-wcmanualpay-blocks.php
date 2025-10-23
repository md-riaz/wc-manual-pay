<?php
/**
 * WooCommerce Blocks support
 *
 * @package WCManualPay
 */

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks integration class
 */
class WCManualPay_Blocks {
    /**
     * Singleton instance
     *
     * @var WCManualPay_Blocks
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return WCManualPay_Blocks
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
        add_action('woocommerce_blocks_payment_method_type_registration', array($this, 'register_payment_method_type'));
    }

    /**
     * Register payment method type
     *
     * @param object $payment_method_registry Payment method registry
     */
    public function register_payment_method_type($payment_method_registry) {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        require_once WCMANUALPAY_PLUGIN_DIR . 'includes/class-wcmanualpay-blocks-integration.php';
        
        $payment_method_registry->register(new WCManualPay_Blocks_Integration());
    }
}

/**
 * Blocks integration implementation
 */
class WCManualPay_Blocks_Integration extends AbstractPaymentMethodType {
    /**
     * Payment method name
     *
     * @var string
     */
    protected $name = 'wcmanualpay';

    /**
     * Initialize
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_wcmanualpay_settings', array());
    }

    /**
     * Check if payment method is active
     *
     * @return bool
     */
    public function is_active() {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    /**
     * Get payment method script handles
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        wp_register_script(
            'wcmanualpay-blocks',
            WCMANUALPAY_PLUGIN_URL . 'assets/js/blocks.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            WCMANUALPAY_VERSION,
            true
        );

        return array('wcmanualpay-blocks');
    }

    /**
     * Get payment method data
     *
     * @return array
     */
    public function get_payment_method_data() {
        $providers_text = $this->settings['providers'] ?? "bkash\nnagad\nrocket";
        $providers = array_filter(array_map('trim', explode("\n", $providers_text)));

        return array(
            'title' => $this->settings['title'] ?? __('Manual Payment', 'wc-manual-pay'),
            'description' => $this->settings['description'] ?? __('Pay using your transaction ID from the payment provider.', 'wc-manual-pay'),
            'providers' => array_values($providers),
        );
    }
}
