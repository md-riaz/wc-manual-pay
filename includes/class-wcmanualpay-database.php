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
     * Valid transaction statuses.
     *
     * @var array
     */
    private static $valid_statuses = array('NEW', 'MATCHED', 'USED', 'INVALID', 'REJECTED');
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
        $audit_table = $wpdb->prefix . 'wcmanualpay_audit';

        // Transactions table
        $transactions_sql = "CREATE TABLE IF NOT EXISTS {$transactions_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL,
            txn_id varchar(128) NOT NULL,
            amount decimal(18,6) NOT NULL DEFAULT 0,
            currency char(3) NOT NULL,
            payer varchar(191) NOT NULL DEFAULT '',
            occurred_at datetime NOT NULL,
            status enum('NEW','MATCHED','USED','INVALID','REJECTED') NOT NULL DEFAULT 'NEW',
            matched_order_id bigint(20) UNSIGNED DEFAULT NULL,
            meta_json longtext DEFAULT NULL,
            idem_key char(64) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_provider_txn (provider, txn_id),
            UNIQUE KEY uniq_idem_key (idem_key),
            KEY idx_status (status),
            KEY idx_order (matched_order_id),
            KEY idx_occurred_at (occurred_at)
        ) $charset_collate;";

        // Audit log table
        $audit_sql = "CREATE TABLE IF NOT EXISTS {$audit_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            actor varchar(191) NOT NULL,
            action varchar(64) NOT NULL,
            object_type varchar(32) NOT NULL,
            object_id bigint(20) UNSIGNED DEFAULT NULL,
            data_json longtext DEFAULT NULL,
            at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_object (object_type, object_id),
            KEY idx_action (action),
            KEY idx_at (at)
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
            'status' => 'NEW',
            'occurred_at' => current_time('mysql'),
            'payer' => '',
            'meta_json' => null,
            'matched_order_id' => null,
        );

        $data = wp_parse_args($data, $defaults);

        $mask_payer = array_key_exists('mask_payer', $data) ? (bool) $data['mask_payer'] : true;
        unset($data['mask_payer']);

        $provider = substr(sanitize_text_field($data['provider']), 0, 32);
        $txn_id = substr(sanitize_text_field($data['txn_id']), 0, 128);

        if ('' === $provider || '' === $txn_id) {
            return false;
        }

        $status = self::normalize_status($data['status']);

        if (!$status) {
            $status = 'NEW';
        }

        $amount = self::format_amount($data['amount']);
        $currency = substr(strtoupper(sanitize_text_field($data['currency'])), 0, 3);
        $payer = self::mask_payer($data['payer'], $mask_payer);
        $occurred_at = self::normalize_datetime($data['occurred_at']);
        $matched_order_id = isset($data['matched_order_id']) && '' !== $data['matched_order_id'] ? absint($data['matched_order_id']) : null;
        $meta_json = self::prepare_meta_json($data['meta_json']);
        $idem_key = hash('sha256', $provider . '|' . $txn_id);

        $insert_data = array(
            'provider' => $provider,
            'txn_id' => $txn_id,
            'amount' => $amount,
            'currency' => $currency,
            'payer' => $payer,
            'occurred_at' => $occurred_at,
            'status' => $status,
            'meta_json' => $meta_json,
            'idem_key' => $idem_key,
        );

        $insert_formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

        if (null !== $matched_order_id) {
            $insert_data['matched_order_id'] = $matched_order_id;
            $insert_formats[] = '%d';
        }

        $result = $wpdb->insert(
            $table,
            $insert_data,
            $insert_formats
        );

        if ($result) {
            self::log_audit('TRANSACTION_CREATED', 'transaction', $wpdb->insert_id, array(
                'provider' => $provider,
                'txn_id' => $txn_id,
                'status' => $status,
            ));

            return (int) $wpdb->insert_id;
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
        $id = absint($id);

        if (!$id) {
            return false;
        }

        $existing = self::get_transaction_by_id($id);

        if (!$existing || 'USED' === $existing->status) {
            return false;
        }

        $mask_payer = array_key_exists('mask_payer', $data) ? (bool) $data['mask_payer'] : true;
        unset($data['mask_payer']);

        $allowed_fields = array(
            'status' => '%s',
            'matched_order_id' => '%d',
            'meta_json' => '%s',
            'payer' => '%s',
        );

        $update_data = array();
        $update_formats = array();

        foreach ($data as $key => $value) {
            if (!array_key_exists($key, $allowed_fields)) {
                continue;
            }

            switch ($key) {
                case 'status':
                    $normalized = self::normalize_status($value);
                    if (!$normalized) {
                        continue 2;
                    }
                    $update_data[$key] = $normalized;
                    break;
                case 'matched_order_id':
                    $update_data[$key] = ('' === $value || null === $value) ? null : absint($value);
                    break;
                case 'meta_json':
                    $update_data[$key] = self::prepare_meta_json($value);
                    break;
                case 'payer':
                    $update_data[$key] = self::mask_payer($value, $mask_payer);
                    break;
            }

            if (array_key_exists($key, $update_data)) {
                $update_formats[] = $allowed_fields[$key];
            }
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $id),
            $update_formats,
            array('%d')
        );

        if (false !== $result) {
            self::log_audit('TRANSACTION_UPDATED', 'transaction', $id, $update_data);
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
        $transaction = self::get_transaction_by_id($transaction_id);

        if (!$transaction || 'USED' === $transaction->status) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            array(
                'status' => 'USED',
                'matched_order_id' => absint($order_id),
            ),
            array('id' => absint($transaction_id)),
            array('%s', '%d'),
            array('%d')
        );

        if (false !== $result) {
            self::log_audit('TRANSACTION_USED', 'transaction', $transaction_id, array(
                'order_id' => absint($order_id),
            ));
            return true;
        }

        return false;
    }

    /**
     * Link a transaction to an order and update status.
     *
     * @param int    $transaction_id Transaction ID.
     * @param int    $order_id       WooCommerce order ID.
     * @param string $status         Target status (default MATCHED).
     * @param string $actor          Optional actor override for audit log.
     * @return bool
     */
    public static function link_transaction_to_order($transaction_id, $order_id, $status = 'MATCHED', $actor = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_transactions';
        $transaction = self::get_transaction_by_id($transaction_id);
        $order_id = absint($order_id);

        if (!$transaction || !$order_id || 'USED' === $transaction->status) {
            return false;
        }

        $status = self::normalize_status($status);

        if (!in_array($status, array('MATCHED', 'USED', 'NEW'), true)) {
            $status = 'MATCHED';
        }

        $result = $wpdb->update(
            $table,
            array(
                'status' => $status,
                'matched_order_id' => $order_id,
            ),
            array('id' => absint($transaction_id)),
            array('%s', '%d'),
            array('%d')
        );

        if (false !== $result) {
            self::log_audit(
                'TRANSACTION_LINKED',
                'transaction',
                $transaction_id,
                array(
                    'order_id' => $order_id,
                    'status' => $status,
                ),
                $actor
            );
            return true;
        }

        return false;
    }

    /**
     * Unlink a transaction from an order with audit trail.
     *
     * @param int    $transaction_id Transaction ID.
     * @param string $target_status  Status to apply after unlink (MATCHED or NEW).
     * @param string $reason         Reason for unlinking.
     * @param string $actor          Optional actor override for audit log.
     * @return bool
     */
    public static function unlink_transaction_from_order($transaction_id, $target_status = 'MATCHED', $reason = '', $actor = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_transactions';
        $transaction = self::get_transaction_by_id($transaction_id);

        if (!$transaction || empty($transaction->matched_order_id)) {
            return false;
        }

        $normalized_status = self::normalize_status($target_status);

        if (!in_array($normalized_status, array('MATCHED', 'NEW'), true)) {
            $normalized_status = 'MATCHED';
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s, matched_order_id = NULL WHERE id = %d",
                $normalized_status,
                absint($transaction_id)
            )
        );

        if (false === $result) {
            return false;
        }

        $audit_data = array(
            'previous_status' => $transaction->status,
            'previous_order_id' => $transaction->matched_order_id,
            'target_status' => $normalized_status,
        );

        if ('' !== $reason) {
            $audit_data['reason'] = $reason;
        }

        self::log_audit(
            'TRANSACTION_UNLINKED',
            'transaction',
            $transaction_id,
            $audit_data,
            $actor
        );

        return true;
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
            'matched_order_id' => null,
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $where_values = array();

        if (!empty($args['status'])) {
            $status = self::normalize_status($args['status']);

            if ($status) {
                $where[] = 'status = %s';
                $where_values[] = $status;
            }
        }

        if (!empty($args['provider'])) {
            $where[] = 'provider = %s';
            $where_values[] = $args['provider'];
        }

        if (!empty($args['matched_order_id'])) {
            $where[] = 'matched_order_id = %d';
            $where_values[] = absint($args['matched_order_id']);
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
    public static function log_audit($action, $object_type = 'system', $object_id = null, $data = array(), $actor = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcmanualpay_audit';

        $action = strtoupper(sanitize_text_field($action));
        $object_type = sanitize_text_field($object_type);
        $actor = $actor ? sanitize_text_field($actor) : self::determine_actor();
        $object_id = is_null($object_id) || '' === $object_id ? null : absint($object_id);
        $data_json = empty($data) ? null : wp_json_encode($data);

        $result = $wpdb->insert(
            $table,
            array(
                'actor' => $actor,
                'action' => $action,
                'object_type' => $object_type,
                'object_id' => $object_id,
                'data_json' => $data_json,
                'at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
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

        $table = $wpdb->prefix . 'wcmanualpay_audit';

        $defaults = array(
            'object_type' => null,
            'object_id' => null,
            'action' => null,
            'limit' => 50,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $where_values = array();

        if (!empty($args['object_type'])) {
            $where[] = 'object_type = %s';
            $where_values[] = sanitize_text_field($args['object_type']);
        }

        if (!empty($args['object_id'])) {
            $where[] = 'object_id = %d';
            $where_values[] = absint($args['object_id']);
        }

        if (!empty($args['action'])) {
            $where[] = 'action = %s';
            $where_values[] = strtoupper(sanitize_text_field($args['action']));
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY at DESC";
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

        $ip = wp_unslash($ip);

        if (false !== strpos($ip, ',')) {
            $parts = array_map('trim', explode(',', $ip));
            $ip = $parts[0];
        }

        return sanitize_text_field($ip);
    }

    /**
     * Normalise transaction status.
     *
     * @param string $status Raw status.
     * @return string|null
     */
    private static function normalize_status($status) {
        if (empty($status)) {
            return null;
        }

        $status = strtoupper(sanitize_text_field($status));

        return in_array($status, self::$valid_statuses, true) ? $status : null;
    }

    /**
     * Format decimal amount for storage.
     *
     * @param string|float $amount Amount value.
     * @return string
     */
    private static function format_amount($amount) {
        if (function_exists('wc_format_decimal')) {
            $formatted = wc_format_decimal($amount, 6);
        } else {
            $formatted = number_format((float) $amount, 6, '.', '');
        }

        if ('' === $formatted) {
            $formatted = '0.000000';
        }

        return $formatted;
    }

    /**
     * Mask payer information.
     *
     * @param string $payer Raw payer input.
     * @return string
     */
    private static function mask_payer($payer, $mask = true) {
        $payer = trim((string) $payer);

        if ('' === $payer) {
            return '';
        }

        if (!$mask) {
            if (function_exists('mb_substr')) {
                $payer = mb_substr($payer, 0, 191);
            } else {
                $payer = substr($payer, 0, 191);
            }

            return sanitize_text_field($payer);
        }

        if (function_exists('mb_substr')) {
            $payer = mb_substr($payer, 0, 191);
            $length = mb_strlen($payer);
            if ($length <= 4) {
                return sanitize_text_field(str_repeat('*', $length));
            }

            $suffix = mb_substr($payer, -4);
            return sanitize_text_field(str_repeat('*', $length - 4) . $suffix);
        }

        $payer = substr($payer, 0, 191);
        $length = strlen($payer);

        if ($length <= 4) {
            return sanitize_text_field(str_repeat('*', $length));
        }

        $suffix = substr($payer, -4);
        return sanitize_text_field(str_repeat('*', $length - 4) . $suffix);
    }

    /**
     * Normalise datetime string.
     *
     * @param mixed $value Datetime value.
     * @return string
     */
    private static function normalize_datetime($value) {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $value = (string) $value;

        if ('' === $value) {
            return current_time('mysql');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        $timestamp = strtotime($value);

        if (false === $timestamp) {
            return current_time('mysql');
        }

        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s', $timestamp, wp_timezone());
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Prepare meta payload for storage.
     *
     * @param mixed $meta Meta input.
     * @return string|null
     */
    private static function prepare_meta_json($meta) {
        if (empty($meta)) {
            return null;
        }

        if (is_string($meta)) {
            return $meta;
        }

        return wp_json_encode($meta);
    }

    /**
     * Determine actor string for audit log.
     *
     * @return string
     */
    private static function determine_actor() {
        $user_id = get_current_user_id();

        if ($user_id) {
            $user = get_userdata($user_id);

            if ($user && !empty($user->user_email)) {
                return $user->user_email;
            }

            if ($user && !empty($user->user_login)) {
                return 'user:' . $user->user_login;
            }

            return 'user:' . $user_id;
        }

        return 'system';
    }

    /**
     * Retrieve valid transaction statuses.
     *
     * @return array
     */
    public static function get_valid_statuses() {
        return self::$valid_statuses;
    }
}
