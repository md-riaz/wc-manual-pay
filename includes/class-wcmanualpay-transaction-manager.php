<?php
/**
 * Shared transaction management utilities.
 *
 * @package WCManualPay
 */

defined('ABSPATH') || exit;

/**
 * Coordinate transaction creation and auto-matching rules.
 */
class WCManualPay_Transaction_Manager {
    /**
     * Create a transaction and execute auto-matching if enabled.
     *
     * @param array $payload        Transaction payload.
     * @param array $actor_context  Context about the caller (actor, labels, etc).
     *
     * @return array|WP_Error
     */
    public static function create_transaction($payload, $actor_context = array()) {
        $context = self::normalize_context($actor_context);

        $defaults = array(
            'provider'   => '',
            'txn_id'     => '',
            'amount'     => 0,
            'currency'   => '',
            'occurred_at' => '',
            'status'     => 'NEW',
            'payer'      => '',
            'meta_json'  => null,
            'mask_payer' => null,
        );

        $payload = wp_parse_args($payload, $defaults);

        $provider = substr(sanitize_text_field(wp_unslash($payload['provider'])), 0, 32);

        if ('' === $provider) {
            return new WP_Error('missing_provider', __('Provider is required.', 'wc-manual-pay'), array('status' => 400));
        }

        $txn_id = substr(sanitize_text_field(wp_unslash($payload['txn_id'])), 0, 128);

        if ('' === $txn_id) {
            return new WP_Error('missing_txn_id', __('Transaction ID is required.', 'wc-manual-pay'), array('status' => 400));
        }

        $amount = floatval($payload['amount']);

        if ($amount <= 0) {
            return new WP_Error('invalid_amount', __('Valid amount is required.', 'wc-manual-pay'), array('status' => 400));
        }

        $currency = strtoupper(sanitize_text_field(wp_unslash($payload['currency'])));

        if ('' === $currency) {
            return new WP_Error('missing_currency', __('Currency is required.', 'wc-manual-pay'), array('status' => 400));
        }

        $status = strtoupper(sanitize_text_field(wp_unslash($payload['status'])));

        if ('INVALID' !== $status) {
            $status = 'NEW';
        }

        $occurred_at = sanitize_text_field(wp_unslash($payload['occurred_at']));

        if ('' === $occurred_at) {
            $occurred_at = current_time('mysql');
        } else {
            $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $occurred_at);

            if (!$datetime || $datetime->format('Y-m-d H:i:s') !== $occurred_at) {
                return new WP_Error('invalid_occurred_at', __('Invalid occurred_at format. Use Y-m-d H:i:s', 'wc-manual-pay'), array('status' => 400));
            }
        }

        $payer = sanitize_text_field(wp_unslash($payload['payer']));

        $meta_payload = $payload['meta_json'];

        if (is_array($meta_payload) && array_key_exists('verify_key', $meta_payload)) {
            unset($meta_payload['verify_key']);
        }

        $mask_payer = is_null($payload['mask_payer']) ? WCManualPay_Gateway::is_mask_payer_globally_enabled() : (bool) $payload['mask_payer'];

        $existing = WCManualPay_Database::get_transaction($provider, $txn_id);

        if ($existing) {
            WCManualPay_Database::log_audit(
                $context['action_prefix'] . '_DUPLICATE_TRANSACTION',
                'transaction',
                $existing->id,
                array(
                    'provider' => $provider,
                    'txn_id'   => $txn_id,
                ),
                $context['actor']
            );

            return array(
                'duplicate'      => true,
                'transaction_id' => (int) $existing->id,
                'transaction'    => $existing,
                'match_result'   => null,
            );
        }

        $transaction_id = WCManualPay_Database::insert_transaction(
            array(
                'provider'   => $provider,
                'txn_id'     => $txn_id,
                'amount'     => $amount,
                'currency'   => $currency,
                'occurred_at' => $occurred_at,
                'status'     => $status,
                'payer'      => $payer,
                'meta_json'  => $meta_payload,
                'mask_payer' => $mask_payer,
            )
        );

        if (!$transaction_id) {
            WCManualPay_Database::log_audit(
                $context['action_prefix'] . '_INSERT_FAILED',
                'transaction',
                null,
                array(
                    'provider' => $provider,
                    'txn_id'   => $txn_id,
                ),
                $context['actor']
            );

            return new WP_Error('insert_failed', __('Failed to insert transaction.', 'wc-manual-pay'), array('status' => 500));
        }

        WCManualPay_Database::log_audit(
            $context['action_prefix'] . '_TRANSACTION_CREATED',
            'transaction',
            $transaction_id,
            array(
                'provider' => $provider,
                'txn_id'   => $txn_id,
                'amount'   => $amount,
                'currency' => $currency,
            ),
            $context['actor']
        );

        $match_result = self::try_match_pending_orders($transaction_id, $context);

        $transaction = WCManualPay_Database::get_transaction_by_id($transaction_id);

        return array(
            'duplicate'      => false,
            'transaction_id' => $transaction_id,
            'transaction'    => $transaction,
            'match_result'   => $match_result,
        );
    }

    /**
     * Normalize actor context with defaults.
     *
     * @param array $actor_context Raw actor context.
     *
     * @return array
     */
    private static function normalize_context($actor_context) {
        $defaults = array(
            'actor'                  => 'system:webhook',
            'actor_label'            => __('REST API', 'wc-manual-pay'),
            'action_prefix'          => 'API',
            'auto_verify_mode'       => null,
            'time_window_hours'      => null,
            'auto_complete'          => null,
            'order_note_auto_complete' => __('Payment verified via %3$s. Provider: %1$s, Transaction ID: %2$s', 'wc-manual-pay'),
            'order_note_match'       => __('Transaction %1$s matched via %3$s (provider: %2$s) and awaits manual completion.', 'wc-manual-pay'),
            'order_status_note'      => __('Transaction matched and awaiting manual completion.', 'wc-manual-pay'),
        );

        $context = wp_parse_args($actor_context, $defaults);

        $context['action_prefix'] = strtoupper(sanitize_key($context['action_prefix']));
        $context['actor'] = sanitize_text_field($context['actor']);
        $context['actor_label'] = sanitize_text_field($context['actor_label']);

        return $context;
    }

    /**
     * Attempt to match a transaction with pending orders.
     *
     * @param int   $transaction_id Transaction ID.
     * @param array $context        Actor context.
     *
     * @return array{matched:bool,auto_completed:bool,order_id:int|null}
     */
    private static function try_match_pending_orders($transaction_id, $context) {
        $mode = $context['auto_verify_mode'];

        if (null === $mode || '' === $mode) {
            $mode = WCManualPay_Gateway::get_global_auto_verify_mode();
        }

        $time_window_hours = is_null($context['time_window_hours']) ? WCManualPay_Gateway::get_global_time_window_hours() : (int) $context['time_window_hours'];
        $auto_complete = is_null($context['auto_complete']) ? WCManualPay_Gateway::is_auto_complete_globally_enabled() : (bool) $context['auto_complete'];

        $result = array(
            'matched'        => false,
            'auto_completed' => false,
            'order_id'       => null,
        );

        if ('off' === $mode) {
            return $result;
        }

        $transaction = WCManualPay_Database::get_transaction_by_id($transaction_id);

        if (!$transaction) {
            return $result;
        }

        if (!in_array($transaction->status, array('NEW', 'MATCHED'), true)) {
            return $result;
        }

        $orders = wc_get_orders(
            array(
                'status'         => array('pending', 'on-hold'),
                'payment_method' => 'wcmanualpay',
                'limit'          => -1,
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'   => '_wcmanualpay_provider',
                        'value' => $transaction->provider,
                        'compare' => '=',
                    ),
                    array(
                        'key'   => '_wcmanualpay_txn_id',
                        'value' => $transaction->txn_id,
                        'compare' => '=',
                    ),
                ),
            )
        );

        foreach ($orders as $order) {
            $validation = WCManualPay_Gateway::validate_transaction_rules($transaction, $order, $mode, $time_window_hours);

            if (true !== $validation) {
                continue;
            }

            if ($auto_complete) {
                $marked = WCManualPay_Database::mark_transaction_used($transaction_id, $order->get_id());

                if (!$marked) {
                    WCManualPay_Database::log_audit(
                        $context['action_prefix'] . '_MARK_USED_FAILED',
                        'order',
                        $order->get_id(),
                        array(
                            'transaction_id' => $transaction_id,
                        ),
                        $context['actor']
                    );
                    continue;
                }

                $order->set_transaction_id($transaction->txn_id);
                $order->add_order_note(
                    vsprintf(
                        $context['order_note_auto_complete'],
                        array(
                            $transaction->provider,
                            $transaction->txn_id,
                            $context['actor_label'],
                        )
                    )
                );
                $order->payment_complete($transaction->txn_id);

                WCManualPay_Database::log_audit(
                    $context['action_prefix'] . '_PAYMENT_MATCHED',
                    'order',
                    $order->get_id(),
                    array(
                        'transaction_id' => $transaction_id,
                        'auto_completed' => true,
                    ),
                    $context['actor']
                );

                $result = array(
                    'matched'        => true,
                    'auto_completed' => true,
                    'order_id'       => $order->get_id(),
                );

                break;
            }

            $linked = WCManualPay_Database::link_transaction_to_order($transaction_id, $order->get_id(), 'MATCHED', $context['actor']);

            if (!$linked) {
                WCManualPay_Database::log_audit(
                    $context['action_prefix'] . '_LINK_FAILED',
                    'order',
                    $order->get_id(),
                    array(
                        'transaction_id' => $transaction_id,
                    ),
                    $context['actor']
                );
                continue;
            }

            $order->set_transaction_id($transaction->txn_id);
            $order->add_order_note(
                vsprintf(
                    $context['order_note_match'],
                    array(
                        $transaction->txn_id,
                        $transaction->provider,
                        $context['actor_label'],
                    )
                )
            );
            $order->update_status('on-hold', $context['order_status_note']);

            WCManualPay_Database::log_audit(
                $context['action_prefix'] . '_TRANSACTION_MATCHED',
                'order',
                $order->get_id(),
                array(
                    'transaction_id' => $transaction_id,
                    'auto_completed' => false,
                ),
                $context['actor']
            );

            $result = array(
                'matched'        => true,
                'auto_completed' => false,
                'order_id'       => $order->get_id(),
            );

            break;
        }

        return $result;
    }
}
