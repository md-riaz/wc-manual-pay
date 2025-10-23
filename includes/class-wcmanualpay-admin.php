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
        add_action('admin_post_wcmanualpay_link_transaction', array($this, 'handle_link_transaction'));
        add_action('admin_post_wcmanualpay_mark_transaction', array($this, 'handle_mark_transaction'));
        add_action('admin_post_wcmanualpay_reject_transaction', array($this, 'handle_reject_transaction'));
        add_action('admin_post_wcmanualpay_unlink_transaction', array($this, 'handle_unlink_transaction'));
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

            <?php $this->render_notice(); ?>

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
     * Output admin notice if present in query args.
     */
    private function render_notice() {
        $raw_message = isset($_GET['wcmanualpay_message']) ? wp_unslash($_GET['wcmanualpay_message']) : '';

        if ('' === $raw_message) {
            return;
        }

        $message = sanitize_text_field($raw_message);
        $type = isset($_GET['wcmanualpay_message_type']) ? sanitize_text_field(wp_unslash($_GET['wcmanualpay_message_type'])) : 'success';
        $type = ('error' === $type) ? 'error' : 'success';
        $class = 'notice notice-' . $type;

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
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
                        <th><?php esc_html_e('Actions', 'wc-manual-pay'); ?></th>
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
                                <td><?php $this->render_transaction_actions($txn); ?></td>
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
     * Render action controls for a transaction row.
     *
     * @param object $txn Transaction row.
     */
    private function render_transaction_actions($txn) {
        $transaction_id = absint($txn->id);
        $matched_order_id = $txn->matched_order_id ? absint($txn->matched_order_id) : '';
        $status = strtoupper($txn->status);
        $default_unlink_status = ('NEW' === $status) ? 'NEW' : 'MATCHED';
        ?>
        <div class="wcmanualpay-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wcmanualpay_link_transaction_' . $transaction_id); ?>
                <input type="hidden" name="action" value="wcmanualpay_link_transaction" />
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($transaction_id); ?>" />
                <label class="screen-reader-text" for="wcmanualpay-link-<?php echo esc_attr($transaction_id); ?>"><?php esc_html_e('Order ID', 'wc-manual-pay'); ?></label>
                <input type="number" id="wcmanualpay-link-<?php echo esc_attr($transaction_id); ?>" name="order_id" value="<?php echo esc_attr($matched_order_id); ?>" placeholder="<?php esc_attr_e('Order ID', 'wc-manual-pay'); ?>" min="1" <?php disabled('USED' === $status); ?> />
                <button type="submit" class="button" <?php disabled('USED' === $status); ?>><?php esc_html_e('Link', 'wc-manual-pay'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wcmanualpay_mark_transaction_' . $transaction_id); ?>
                <input type="hidden" name="action" value="wcmanualpay_mark_transaction" />
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($transaction_id); ?>" />
                <label class="screen-reader-text" for="wcmanualpay-mark-<?php echo esc_attr($transaction_id); ?>"><?php esc_html_e('Order ID', 'wc-manual-pay'); ?></label>
                <input type="number" id="wcmanualpay-mark-<?php echo esc_attr($transaction_id); ?>" name="order_id" value="<?php echo esc_attr($matched_order_id); ?>" placeholder="<?php esc_attr_e('Order ID', 'wc-manual-pay'); ?>" min="1" <?php disabled('USED' === $status); ?> />
                <button type="submit" class="button button-primary" <?php disabled('USED' === $status); ?>><?php esc_html_e('Mark Used', 'wc-manual-pay'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wcmanualpay_reject_transaction_' . $transaction_id); ?>
                <input type="hidden" name="action" value="wcmanualpay_reject_transaction" />
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($transaction_id); ?>" />
                <button type="submit" class="button" <?php disabled('USED' === $status); ?>><?php esc_html_e('Reject', 'wc-manual-pay'); ?></button>
            </form>
            <?php if ($matched_order_id) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcmanualpay-unlink-form">
                    <?php wp_nonce_field('wcmanualpay_unlink_transaction_' . $transaction_id); ?>
                    <input type="hidden" name="action" value="wcmanualpay_unlink_transaction" />
                    <input type="hidden" name="transaction_id" value="<?php echo esc_attr($transaction_id); ?>" />
                    <label class="screen-reader-text" for="wcmanualpay-unlink-status-<?php echo esc_attr($transaction_id); ?>"><?php esc_html_e('Target status', 'wc-manual-pay'); ?></label>
                    <select id="wcmanualpay-unlink-status-<?php echo esc_attr($transaction_id); ?>" name="target_status">
                        <option value="MATCHED" <?php selected('MATCHED', $default_unlink_status); ?>><?php esc_html_e('MATCHED', 'wc-manual-pay'); ?></option>
                        <option value="NEW" <?php selected('NEW', $default_unlink_status); ?>><?php esc_html_e('NEW', 'wc-manual-pay'); ?></option>
                    </select>
                    <label class="screen-reader-text" for="wcmanualpay-unlink-reason-<?php echo esc_attr($transaction_id); ?>"><?php esc_html_e('Reason', 'wc-manual-pay'); ?></label>
                    <input type="text" id="wcmanualpay-unlink-reason-<?php echo esc_attr($transaction_id); ?>" name="reason" placeholder="<?php esc_attr_e('Reason', 'wc-manual-pay'); ?>" required />
                    <button type="submit" class="button button-secondary"><?php esc_html_e('Unlink', 'wc-manual-pay'); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Build redirect URL for transactions page with optional notice.
     *
     * @param string $message Notice message.
     * @param string $type    Notice type (success|error).
     */
    private function redirect_with_message($message, $type = 'success') {
        $message = sanitize_text_field($message);
        $type = ('error' === $type) ? 'error' : 'success';

        $url = add_query_arg(
            array(
                'page' => 'wcmanualpay-transactions',
                'tab' => 'transactions',
                'wcmanualpay_message' => $message,
                'wcmanualpay_message_type' => $type,
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Handle manual linking of a transaction to an order.
     */
    public function handle_link_transaction() {
        $transaction_id = isset($_POST['transaction_id']) ? absint($_POST['transaction_id']) : 0;
        check_admin_referer('wcmanualpay_link_transaction_' . $transaction_id);

        if (!current_user_can('manage_woocommerce')) {
            $this->redirect_with_message(__('You do not have permission to perform this action.', 'wc-manual-pay'), 'error');
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$transaction_id || !$order_id) {
            $this->redirect_with_message(__('A valid transaction and order are required.', 'wc-manual-pay'), 'error');
        }

        $transaction = WCManualPay_Database::get_transaction_by_id($transaction_id);

        if (!$transaction) {
            $this->redirect_with_message(__('Transaction not found.', 'wc-manual-pay'), 'error');
        }

        if ('USED' === strtoupper($transaction->status)) {
            $this->redirect_with_message(__('Used transactions cannot be relinked.', 'wc-manual-pay'), 'error');
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            $this->redirect_with_message(__('Order not found.', 'wc-manual-pay'), 'error');
        }

        if (!WCManualPay_Database::link_transaction_to_order($transaction_id, $order_id)) {
            $this->redirect_with_message(__('Unable to link transaction to order.', 'wc-manual-pay'), 'error');
        }

        $order->add_order_note(
            sprintf(
                __('Transaction %1$s (%2$s) linked manually.', 'wc-manual-pay'),
                $transaction->txn_id,
                $transaction->provider
            )
        );

        $this->redirect_with_message(
            sprintf(__('Transaction #%1$d linked to order #%2$d.', 'wc-manual-pay'), $transaction_id, $order_id)
        );
    }

    /**
     * Handle marking a transaction as used and completing the order.
     */
    public function handle_mark_transaction() {
        $transaction_id = isset($_POST['transaction_id']) ? absint($_POST['transaction_id']) : 0;
        check_admin_referer('wcmanualpay_mark_transaction_' . $transaction_id);

        if (!current_user_can('manage_woocommerce')) {
            $this->redirect_with_message(__('You do not have permission to perform this action.', 'wc-manual-pay'), 'error');
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $transaction = WCManualPay_Database::get_transaction_by_id($transaction_id);

        if (!$transaction) {
            $this->redirect_with_message(__('Transaction not found.', 'wc-manual-pay'), 'error');
        }

        if (!$order_id && !empty($transaction->matched_order_id)) {
            $order_id = absint($transaction->matched_order_id);
        }

        if (!$order_id) {
            $this->redirect_with_message(__('An order ID is required to mark a transaction as used.', 'wc-manual-pay'), 'error');
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            $this->redirect_with_message(__('Order not found.', 'wc-manual-pay'), 'error');
        }

        if (!WCManualPay_Database::mark_transaction_used($transaction_id, $order_id)) {
            $this->redirect_with_message(__('Unable to mark transaction as used.', 'wc-manual-pay'), 'error');
        }

        $order->set_transaction_id($transaction->txn_id);
        $order->add_order_note(
            sprintf(
                __('Transaction %1$s confirmed manually.', 'wc-manual-pay'),
                $transaction->txn_id
            )
        );
        $order->payment_complete($transaction->txn_id);

        WCManualPay_Database::log_audit('ADMIN_TRANSACTION_USED', 'order', $order_id, array(
            'transaction_id' => $transaction_id,
        ));

        $this->redirect_with_message(
            sprintf(__('Transaction #%1$d marked as used for order #%2$d.', 'wc-manual-pay'), $transaction_id, $order_id)
        );
    }

    /**
     * Handle rejecting a transaction.
     */
    public function handle_reject_transaction() {
        $transaction_id = isset($_POST['transaction_id']) ? absint($_POST['transaction_id']) : 0;
        check_admin_referer('wcmanualpay_reject_transaction_' . $transaction_id);

        if (!current_user_can('manage_woocommerce')) {
            $this->redirect_with_message(__('You do not have permission to perform this action.', 'wc-manual-pay'), 'error');
        }

        $transaction = WCManualPay_Database::get_transaction_by_id($transaction_id);

        if (!$transaction) {
            $this->redirect_with_message(__('Transaction not found.', 'wc-manual-pay'), 'error');
        }

        if ('USED' === strtoupper($transaction->status)) {
            $this->redirect_with_message(__('Used transactions must be unlinked before they can be rejected.', 'wc-manual-pay'), 'error');
        }

        if (!WCManualPay_Database::update_transaction($transaction_id, array('status' => 'REJECTED', 'matched_order_id' => null))) {
            $this->redirect_with_message(__('Unable to reject transaction.', 'wc-manual-pay'), 'error');
        }

        $this->redirect_with_message(
            sprintf(__('Transaction #%d rejected.', 'wc-manual-pay'), $transaction_id)
        );
    }

    /**
     * Handle unlinking a transaction from an order.
     */
    public function handle_unlink_transaction() {
        $transaction_id = isset($_POST['transaction_id']) ? absint($_POST['transaction_id']) : 0;
        check_admin_referer('wcmanualpay_unlink_transaction_' . $transaction_id);

        if (!current_user_can('manage_woocommerce')) {
            $this->redirect_with_message(__('You do not have permission to perform this action.', 'wc-manual-pay'), 'error');
        }

        $transaction = WCManualPay_Database::get_transaction_by_id($transaction_id);

        if (!$transaction || empty($transaction->matched_order_id)) {
            $this->redirect_with_message(__('Transaction is not currently linked to an order.', 'wc-manual-pay'), 'error');
        }

        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

        if ('' === $reason) {
            $this->redirect_with_message(__('A reason is required to unlink a transaction.', 'wc-manual-pay'), 'error');
        }

        $target_status = isset($_POST['target_status']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['target_status']))) : 'MATCHED';

        if (!in_array($target_status, array('MATCHED', 'NEW'), true)) {
            $target_status = 'MATCHED';
        }

        $previous_order_id = absint($transaction->matched_order_id);
        $success = WCManualPay_Database::unlink_transaction_from_order($transaction_id, $target_status, $reason, null);

        if (!$success) {
            $this->redirect_with_message(__('Unable to unlink transaction.', 'wc-manual-pay'), 'error');
        }

        if ($previous_order_id) {
            $order = wc_get_order($previous_order_id);
            if ($order) {
                $order->add_order_note(
                    sprintf(
                        __('Transaction %1$s unlinked by admin. Reason: %2$s', 'wc-manual-pay'),
                        $transaction->txn_id,
                        $reason
                    )
                );
            }
        }

        $this->redirect_with_message(
            sprintf(__('Transaction #%1$d unlinked from order #%2$d.', 'wc-manual-pay'), $transaction_id, $previous_order_id)
        );
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
