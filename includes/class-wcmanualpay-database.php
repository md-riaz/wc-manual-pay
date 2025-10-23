<?php
/**
 * Database operations
 *
 * @package WCManualPay
 */

defined('ABSPATH') || exit;

/**
 * Database class
 */
class WCManualPay_Database {
    /**
     * Singleton instance
     *
     * @var WCManualPay_Database
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return WCManualPay_Database
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
        // Constructor logic if needed
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $transactions_table = $wpdb->prefix . 'wcmanualpay_transactions';
        $audit_table = $wpdb->prefix . 'wcmanualpay_audit_log';

        // Transactions table
        $transactions_sql = "CREATE TABLE IF NOT EXISTS {$transactions_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            provider varchar(100) NOT NULL,
            txn_id varchar(255) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(10) NOT NULL,
            occurred_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            order_id bigint(20) UNSIGNED DEFAULT NULL,
            used_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_txn (provider, txn_id),
            KEY status (status),
            KEY order_id (order_id),
            KEY occurred_at (occurred_at)
        ) $charset_collate;";

        // Audit log table
        $audit_sql = "CREATE TABLE IF NOT EXISTS {$audit_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            action varchar(100) NOT NULL,
            transaction_id bigint(20) UNSIGNED DEFAULT NULL,
            order_id bigint(20) UNSIGNED DEFAULT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            data longtext DEFAULT NULL,
            ip_address varchar(100) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY transaction_id (transaction_id),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($transactions_sql);
        dbDelta($audit_sql);

        update_option('wcmanualpay_db_version', WCMANUALPAY_VERSION);
    }

    /**
     * Insert transaction
     *
     * @param array $data Transaction data
     * @return int|false Transaction ID or false on failure
     */
    public static function insert_transaction($data) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_transactions';

        $defaults = array(
            'status' => 'pending',
            'occurred_at' => current_time('mysql'),
        );

        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert(
            $table,
            array(
                'provider' => sanitize_text_field($data['provider']),
                'txn_id' => sanitize_text_field($data['txn_id']),
                'amount' => floatval($data['amount']),
                'currency' => strtoupper(sanitize_text_field($data['currency'])),
                'occurred_at' => $data['occurred_at'],
                'status' => sanitize_text_field($data['status']),
            ),
            array('%s', '%s', '%f', '%s', '%s', '%s')
        );

        if ($result) {
            self::log_audit('transaction_created', $wpdb->insert_id, null, null, $data);
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get transaction by provider and txn_id
     *
     * @param string $provider Provider name
     * @param string $txn_id Transaction ID
     * @return object|null Transaction object or null
     */
    public static function get_transaction($provider, $txn_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_transactions';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE provider = %s AND txn_id = %s",
                $provider,
                $txn_id
            )
        );
    }

    /**
     * Get transaction by ID
     *
     * @param int $id Transaction ID
     * @return object|null Transaction object or null
     */
    public static function get_transaction_by_id($id) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_transactions';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $id
            )
        );
    }

    /**
     * Update transaction
     *
     * @param int $id Transaction ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public static function update_transaction($id, $data) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_transactions';

        $result = $wpdb->update(
            $table,
            $data,
            array('id' => $id),
            array_fill(0, count($data), '%s'),
            array('%d')
        );

        if (false !== $result) {
            self::log_audit('transaction_updated', $id, null, null, $data);
            return true;
        }

        return false;
    }

    /**
     * Mark transaction as used
     *
     * @param int $transaction_id Transaction ID
     * @param int $order_id Order ID
     * @return bool Success status
     */
    public static function mark_transaction_used($transaction_id, $order_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_transactions';

        $result = $wpdb->update(
            $table,
            array(
                'status' => 'used',
                'order_id' => $order_id,
                'used_at' => current_time('mysql'),
            ),
            array('id' => $transaction_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        if (false !== $result) {
            self::log_audit('transaction_used', $transaction_id, $order_id, get_current_user_id());
            return true;
        }

        return false;
    }

    /**
     * Get transactions with filters
     *
     * @param array $args Query arguments
     * @return array Transactions
     */
    public static function get_transactions($args = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_transactions';

        $defaults = array(
            'status' => null,
            'provider' => null,
            'order_id' => null,
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $where_values = array();

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $where_values[] = $args['status'];
        }

        if (!empty($args['provider'])) {
            $where[] = 'provider = %s';
            $where_values[] = $args['provider'];
        }

        if (!empty($args['order_id'])) {
            $where[] = 'order_id = %d';
            $where_values[] = $args['order_id'];
        }

        $where_sql = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);

        $sql = "SELECT * FROM {$table} WHERE {$where_sql}";

        if ($orderby) {
            $sql .= " ORDER BY {$orderby}";
        }

        $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $args['limit'], $args['offset']);

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Log audit entry
     *
     * @param string $action Action name
     * @param int|null $transaction_id Transaction ID
     * @param int|null $order_id Order ID
     * @param int|null $user_id User ID
     * @param array $data Additional data
     * @return int|false Audit log ID or false on failure
     */
    public static function log_audit($action, $transaction_id = null, $order_id = null, $user_id = null, $data = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_audit_log';

        if (null === $user_id) {
            $user_id = get_current_user_id();
        }

        $ip_address = self::get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '';

        $result = $wpdb->insert(
            $table,
            array(
                'action' => sanitize_text_field($action),
                'transaction_id' => $transaction_id,
                'order_id' => $order_id,
                'user_id' => $user_id ?: null,
                'data' => wp_json_encode($data),
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
            ),
            array('%s', '%d', '%d', '%d', '%s', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get audit logs
     *
     * @param array $args Query arguments
     * @return array Audit logs
     */
    public static function get_audit_logs($args = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_audit_log';

        $defaults = array(
            'transaction_id' => null,
            'order_id' => null,
            'action' => null,
            'limit' => 50,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $where_values = array();

        if (!empty($args['transaction_id'])) {
            $where[] = 'transaction_id = %d';
            $where_values[] = $args['transaction_id'];
        }

        if (!empty($args['order_id'])) {
            $where[] = 'order_id = %d';
            $where_values[] = $args['order_id'];
        }

        if (!empty($args['action'])) {
            $where[] = 'action = %s';
            $where_values[] = $args['action'];
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC";
        $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $args['limit'], $args['offset']);

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Get client IP address
     *
     * @return string IP address
     */
    public static function get_client_ip() {
        $ip = '';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return sanitize_text_field(wp_unslash($ip));
    }
}
