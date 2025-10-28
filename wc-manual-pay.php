<?php
/**
 * Plugin Name: WCManualPay
 * Plugin URI: https://github.com/md-riaz/wc-manual-pay
 * Description: Manual payment gateway with transaction verification via REST API
 * Version: 1.0.0
 * Author: MD Riaz
 * Author URI: https://github.com/md-riaz
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-manual-pay
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 *
 * @package WCManualPay
 */

defined('ABSPATH') || exit;

define('WCMANUALPAY_VERSION', '1.0.0');
define('WCMANUALPAY_PLUGIN_FILE', __FILE__);
define('WCMANUALPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCMANUALPAY_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
final class WCManualPay {
    /**
     * Singleton instance
     *
     * @var WCManualPay
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return WCManualPay
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
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once WCMANUALPAY_PLUGIN_DIR . 'includes/class-wcmanualpay-database.php';
        require_once WCMANUALPAY_PLUGIN_DIR . 'includes/class-wcmanualpay-gateway.php';
        require_once WCMANUALPAY_PLUGIN_DIR . 'includes/class-wcmanualpay-transaction-manager.php';
        require_once WCMANUALPAY_PLUGIN_DIR . 'includes/class-wcmanualpay-rest-api.php';
        require_once WCMANUALPAY_PLUGIN_DIR . 'includes/class-wcmanualpay-admin.php';
        require_once WCMANUALPAY_PLUGIN_DIR . 'includes/class-wcmanualpay-blocks.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init'), 0);
        add_action('init', array($this, 'load_textdomain'));
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_compatibility'));
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Add payment gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
        
        // Initialize components
        WCManualPay_Database::instance();
        WCManualPay_REST_API::instance();
        WCManualPay_Admin::instance();
        WCManualPay_Blocks::instance();
    }

    /**
     * Add gateway to WooCommerce
     *
     * @param array $gateways
     * @return array
     */
    public function add_gateway($gateways) {
        $gateways[] = 'WCManualPay_Gateway';
        return $gateways;
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('wc-manual-pay', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Activation hook
     */
    public function activate() {
        WCManualPay_Database::create_tables();
        flush_rewrite_rules();
    }

    /**
     * Deactivation hook
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php esc_html_e('WCManualPay requires WooCommerce to be installed and active.', 'wc-manual-pay'); ?></p>
        </div>
        <?php
    }
}

/**
 * Initialize the plugin
 */
function wcmanualpay() {
    return WCManualPay::instance();
}

// Start the plugin
wcmanualpay();
