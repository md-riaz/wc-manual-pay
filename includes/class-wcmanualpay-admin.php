<?php
/**
 * Admin functionality
 *
 * @package WCManualPay
 */

defined('ABSPATH') || exit;

/**
 * Admin class
 */
class WCManualPay_Admin {
    /**
     * Singleton instance
     *
     * @var WCManualPay_Admin
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return WCManualPay_Admin
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Manual Pay Transactions', 'wc-manual-pay'),
            __('Manual Pay', 'wc-manual-pay'),
            'manage_woocommerce',
            'wcmanualpay-transactions',
            array($this, 'render_transactions_page')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_wcmanualpay-transactions' !== $hook) {
            return;
        }

        wp_enqueue_style('wcmanualpay-admin', WCMANUALPAY_PLUGIN_URL . 'assets/css/admin.css', array(), WCMANUALPAY_VERSION);
    }

    /**
     * Render transactions page
     */
    public function render_transactions_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'transactions';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manual Pay Transactions', 'wc-manual-pay'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=wcmanualpay-transactions&tab=transactions" class="nav-tab <?php echo 'transactions' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Transactions', 'wc-manual-pay'); ?>
                </a>
                <a href="?page=wcmanualpay-transactions&tab=audit" class="nav-tab <?php echo 'audit' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Audit Log', 'wc-manual-pay'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                if ('transactions' === $current_tab) {
                    $this->render_transactions_tab();
                } elseif ('audit' === $current_tab) {
                    $this->render_audit_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render transactions tab
     */
    private function render_transactions_tab() {
        // Get filter parameters
        $status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
        $provider = isset($_GET['filter_provider']) ? sanitize_text_field(wp_unslash($_GET['filter_provider'])) : '';
        $page_num = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;

        // Build query args
        $args = array(
            'limit' => $per_page,
            'offset' => ($page_num - 1) * $per_page,
        );

        if (!empty($status)) {
            $args['status'] = $status;
        }

        if (!empty($provider)) {
            $args['provider'] = $provider;
        }

        $transactions = WCManualPay_Database::get_transactions($args);

        // Get all providers for filter
        global $wpdb;
        $providers_table = $wpdb->prefix . 'wcmanualpay_transactions';
        $providers = $wpdb->get_col("SELECT DISTINCT provider FROM {$providers_table} ORDER BY provider");

        $available_statuses = WCManualPay_Database::get_valid_statuses();

        ?>
        <div style="background: #fff; padding: 20px; margin-top: 20px; border: 1px solid #ccc;">
            <form method="get" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="wcmanualpay-transactions">
                <input type="hidden" name="tab" value="transactions">
                
                <label>
                    <?php esc_html_e('Status:', 'wc-manual-pay'); ?>
                    <select name="filter_status">
                        <option value=""><?php esc_html_e('All', 'wc-manual-pay'); ?></option>
                        <?php foreach ($available_statuses as $status_option) : ?>
                            <option value="<?php echo esc_attr($status_option); ?>" <?php selected(strtoupper($status), $status_option); ?>>
                                <?php echo esc_html(ucwords(strtolower($status_option))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="margin-left: 10px;">
                    <?php esc_html_e('Provider:', 'wc-manual-pay'); ?>
                    <select name="filter_provider">
                        <option value=""><?php esc_html_e('All', 'wc-manual-pay'); ?></option>
                        <?php foreach ($providers as $prov) : ?>
                            <option value="<?php echo esc_attr($prov); ?>" <?php selected($provider, $prov); ?>>
                                <?php echo esc_html(ucfirst($prov)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit" class="button"><?php esc_html_e('Filter', 'wc-manual-pay'); ?></button>
                <a href="?page=wcmanualpay-transactions&tab=transactions" class="button"><?php esc_html_e('Reset', 'wc-manual-pay'); ?></a>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Provider', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Transaction ID', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Amount', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Currency', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Payer', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Occurred At', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Status', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Matched Order', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Created At', 'wc-manual-pay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)) : ?>
                        <tr>
                            <td colspan="9"><?php esc_html_e('No transactions found.', 'wc-manual-pay'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($transactions as $txn) : ?>
                            <tr>
                                <td><?php echo esc_html($txn->id); ?></td>
                                <td><?php echo esc_html(ucfirst($txn->provider)); ?></td>
                                <td><?php echo esc_html($txn->txn_id); ?></td>
                                <td>
                                    <?php
                                    if (function_exists('wc_format_decimal')) {
                                        $amount_display = wc_format_decimal($txn->amount, 2);
                                    } else {
                                        $amount_display = number_format((float) $txn->amount, 2);
                                    }
                                    echo esc_html($amount_display);
                                    ?>
                                </td>
                                <td><?php echo esc_html($txn->currency); ?></td>
                                <td><?php echo esc_html($txn->payer); ?></td>
                                <td><?php echo esc_html($txn->occurred_at); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr(strtolower($txn->status)); ?>">
                                        <?php echo esc_html(ucwords(strtolower($txn->status))); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($txn->matched_order_id) : ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $txn->matched_order_id . '&action=edit')); ?>">
                                            #<?php echo esc_html($txn->matched_order_id); ?>
                                        </a>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($txn->created_at); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            // Simple pagination
            $base_url = add_query_arg(array(
                'page' => 'wcmanualpay-transactions',
                'tab' => 'transactions',
                'filter_status' => $status,
                'filter_provider' => $provider,
            ), admin_url('admin.php'));
            ?>
            <div class="tablenav" style="margin-top: 20px;">
                <?php if ($page_num > 1) : ?>
                    <a href="<?php echo esc_url(add_query_arg('paged', $page_num - 1, $base_url)); ?>" class="button">
                        <?php esc_html_e('Previous', 'wc-manual-pay'); ?>
                    </a>
                <?php endif; ?>
                
                <?php if (count($transactions) >= $per_page) : ?>
                    <a href="<?php echo esc_url(add_query_arg('paged', $page_num + 1, $base_url)); ?>" class="button">
                        <?php esc_html_e('Next', 'wc-manual-pay'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render audit tab
     */
    private function render_audit_tab() {
        $page_num = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;

        $logs = WCManualPay_Database::get_audit_logs(array(
            'limit' => $per_page,
            'offset' => ($page_num - 1) * $per_page,
        ));

        ?>
        <div style="background: #fff; padding: 20px; margin-top: 20px; border: 1px solid #ccc;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Timestamp', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Actor', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Action', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Object', 'wc-manual-pay'); ?></th>
                        <th><?php esc_html_e('Details', 'wc-manual-pay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e('No audit logs found.', 'wc-manual-pay'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html($log->id); ?></td>
                                <td><?php echo esc_html($log->at); ?></td>
                                <td><?php echo esc_html($log->actor); ?></td>
                                <td><?php echo esc_html($log->action); ?></td>
                                <td>
                                    <?php
                                    if ('order' === $log->object_type && $log->object_id) {
                                        printf('<a href="%s">#%s</a>', esc_url(admin_url('post.php?post=' . $log->object_id . '&action=edit')), esc_html($log->object_id));
                                    } elseif (!empty($log->object_type)) {
                                        $label = $log->object_type;
                                        if ($log->object_id) {
                                            $label .= ' #' . $log->object_id;
                                        }
                                        echo esc_html($label);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $data = array();

                                    if (!empty($log->data_json)) {
                                        $decoded = json_decode($log->data_json, true);
                                        if (is_array($decoded)) {
                                            $data = $decoded;
                                        }
                                    }

                                    if (!empty($data)) {
                                        echo '<code>' . esc_html(wp_json_encode($data)) . '</code>';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            // Simple pagination
            $base_url = add_query_arg(array(
                'page' => 'wcmanualpay-transactions',
                'tab' => 'audit',
            ), admin_url('admin.php'));
            ?>
            <div class="tablenav" style="margin-top: 20px;">
                <?php if ($page_num > 1) : ?>
                    <a href="<?php echo esc_url(add_query_arg('paged', $page_num - 1, $base_url)); ?>" class="button">
                        <?php esc_html_e('Previous', 'wc-manual-pay'); ?>
                    </a>
                <?php endif; ?>
                
                <?php if (count($logs) >= $per_page) : ?>
                    <a href="<?php echo esc_url(add_query_arg('paged', $page_num + 1, $base_url)); ?>" class="button">
                        <?php esc_html_e('Next', 'wc-manual-pay'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
