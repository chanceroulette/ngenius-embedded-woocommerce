<?php
/**
 * N-Genius Embedded — AJAX endpoints per il flusso embedded checkout.
 *
 * Endpoints registrati (entrambi richiedono nonce):
 *   - ngenius_embedded_complete_payment  (browser invia sessionId al server)
 *
 * Per la Tappa 4, l'endpoint solo logga il sessionId e risponde OK.
 * La Tappa 5 aggiungerà la logica di completion reale verso N-Genius.
 */

if (!defined('ABSPATH')) {
    exit; // Sicurezza: blocca accesso diretto
}

class NgeniusEmbeddedAjax
{
    /**
     * Action name per WP-AJAX (deve corrispondere al JS frontend)
     */
    const ACTION_COMPLETE = 'ngenius_embedded_complete_payment';

    /**
     * Nonce action name (per check CSRF)
     */
    const NONCE_ACTION = 'ngenius_embedded_nonce';

    /**
     * Costruttore: registra gli hook WP-AJAX
     */
    public function __construct()
    {
        // Registra l'endpoint per utenti autenticati (logged-in)
        add_action('wp_ajax_' . self::ACTION_COMPLETE, array($this, 'handle_complete_payment'));

        // Registra l'endpoint per utenti NON autenticati (guest checkout)
        add_action('wp_ajax_nopriv_' . self::ACTION_COMPLETE, array($this, 'handle_complete_payment'));
    }

    /**
     * Handler dell'endpoint AJAX: riceve sessionId, logga, risponde.
     *
     * Atteso dal browser:
     *   POST admin-ajax.php
     *   action: ngenius_embedded_complete_payment
     *   nonce: <generato dal nostro JS>
     *   session_id: <valore di NI.generateSessionId()>
     *   order_id: <ID WooCommerce dell'ordine>
     *
     * Risponde:
     *   200 OK + JSON { success: true, ... }     se tutto bene
     *   400 + JSON { success: false, error: ... } se input mancante
     *   403 + JSON { success: false, error: ... } se nonce invalido
     */
    public function handle_complete_payment()
    {
        // 1. Verifica nonce (CSRF protection)
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(
                array('error' => 'Invalid nonce — refresh the page and try again.'),
                403
            );
            return;
        }

        // 2. Valida i parametri obbligatori
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (empty($session_id)) {
            wp_send_json_error(array('error' => 'Missing session_id.'), 400);
            return;
        }

        if (empty($order_id)) {
            wp_send_json_error(array('error' => 'Missing order_id.'), 400);
            return;
        }

        // 3. Recupera l'ordine WooCommerce
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('error' => 'Order not found.'), 404);
            return;
        }

        // 4. Log del sessionId (utile per debug)
        $this->log_event(sprintf(
            '[Tappa 4 stub] Received sessionId for order #%d (amount: %s %s, status: %s). SessionId: %s...',
            $order_id,
            $order->get_total(),
            $order->get_currency(),
            $order->get_status(),
            substr($session_id, 0, 12)
        ));

        // 5. Aggiungi una nota sull'ordine (visibile in WP admin → Orders)
        $order->add_order_note(sprintf(
            'N-Genius Embedded — Tappa 4 stub: received sessionId %s... (Tappa 5 will complete payment)',
            substr($session_id, 0, 12)
        ));

        // 6. Risposta di successo (Tappa 5 farà davvero il completion)
        wp_send_json_success(array(
            'message'    => 'Session received successfully. Payment completion will be implemented in Tappa 5.',
            'order_id'   => $order_id,
            'session_id' => substr($session_id, 0, 12) . '...',
            'next_step'  => 'tappa-5-payment-completion',
        ));
    }

    /**
     * Logga un evento, sia nel debug log di WC sia in error_log PHP.
     */
    private function log_event($message)
    {
        $prefix = '[NgeniusEmbedded][Ajax] ';

        // Log in error_log PHP (sempre)
        error_log($prefix . $message);

        // Log in WooCommerce logger se la classe esiste
        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->info($message, array('source' => 'ngenius-embedded'));
        }
    }
}

// Inizializza la classe
new NgeniusEmbeddedAjax();