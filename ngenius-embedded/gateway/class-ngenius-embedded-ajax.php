<?php
/**
 * N-Genius Embedded — AJAX endpoints per il flusso embedded checkout.
 *
 * Endpoint:
 *   ngenius_embedded_complete_payment
 *
 * Riceve dal frontend dopo il completamento 3DS (success o fail) e
 * marca l'ordine WC come pagato/fallito di conseguenza.
 */

if (!defined('ABSPATH')) {
    exit;
}

class NgeniusEmbeddedAjax
{
    const ACTION_COMPLETE = 'ngenius_embedded_complete_payment';
    const NONCE_ACTION    = 'ngenius_embedded_nonce';

    public function __construct()
    {
        add_action('wp_ajax_' . self::ACTION_COMPLETE, array($this, 'handle_complete_payment'));
        add_action('wp_ajax_nopriv_' . self::ACTION_COMPLETE, array($this, 'handle_complete_payment'));
    }

    /**
     * Finalize order after 3DS challenge completion (or failure).
     *
     * Expected POST:
     *   action      = ngenius_embedded_complete_payment
     *   nonce       = ngenius_embedded_nonce
     *   order_id    = WC order ID
     *   final_state = state from window.NI.handlePaymentResponse() (e.g. AUTHORISED, PURCHASED, FAILED)
     *   success     = '1' or '0'
     */
    public function handle_complete_payment()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(array('error' => 'Invalid nonce'), 403);
            return;
        }

        $order_id    = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $final_state = isset($_POST['final_state']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['final_state']))) : '';
        $success     = isset($_POST['success']) ? ($_POST['success'] === '1') : false;

        if (empty($order_id)) {
            wp_send_json_error(array('error' => 'Missing order_id'), 400);
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('error' => 'Order not found'), 404);
            return;
        }

        $logger = wc_get_logger();
        $logger->info(
            sprintf('[Tappa5 finalize] order #%d state=%s success=%s', $order_id, $final_state, $success ? 'yes' : 'no'),
            array('source' => 'ngenius-embedded-debug')
        );

        $SUCCESS_STATES = array('AUTHORISED', 'CAPTURED', 'PURCHASED');

        if ($success && in_array($final_state, $SUCCESS_STATES, true)) {
            // Mark WC order as paid
            $order->payment_complete($order->get_meta('_ngenius_payment_ref'));
            $order->add_order_note(sprintf('N-Genius: payment completed after 3DS (state: %s)', $final_state));

            wp_send_json_success(array(
                'redirect' => $order->get_checkout_order_received_url(),
                'state'    => $final_state,
            ));
            return;
        }

        // Failure: mark order failed
        $order->update_status('failed', sprintf('N-Genius: payment failed after 3DS (state: %s)', $final_state));

        wp_send_json_error(array(
            'error'    => 'Payment failed: ' . $final_state,
            'redirect' => wc_get_checkout_url(),
            'state'    => $final_state,
        ));
    }
}

new NgeniusEmbeddedAjax();
