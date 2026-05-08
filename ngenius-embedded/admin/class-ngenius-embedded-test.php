<?php
/**
 * N-Genius Embedded — Pagina admin di test API
 *
 * Aggiunge una pagina nascosta in WooCommerce → N-Genius Test che permette
 * di verificare che le credenziali Hosted Session funzionino, senza fare
 * transazioni reali.
 *
 * Endpoint testato: POST /identity/auth/access-token
 */

if (!defined('ABSPATH')) {
    exit; // Sicurezza: blocca accesso diretto al file
}

class NgeniusEmbeddedTest
{
    /**
     * Slug della pagina admin (usato negli URL)
     */
    const PAGE_SLUG = 'ngenius-embedded-test';

    /**
     * Nome dell'azione per il form submit
     */
    const ACTION_TEST_TOKEN = 'ngenius_embedded_test_token';

    /**
     * Costruttore: registra gli hook WordPress
     */
    public function __construct()
    {
        // Registra la voce di menu nell'admin
        add_action('admin_menu', array($this, 'register_admin_menu'));

        // Gestisce la sottomissione del form di test
        add_action('admin_post_' . self::ACTION_TEST_TOKEN, array($this, 'handle_test_request'));
    }

    /**
     * Aggiunge la voce di menu sotto WooCommerce
     */
    public function register_admin_menu()
    {
        add_submenu_page(
            'woocommerce',                              // parent slug
            'N-Genius Embedded Test',                   // page title
            'N-Genius Test',                            // menu title
            'manage_woocommerce',                       // capability (solo admin)
            self::PAGE_SLUG,                            // menu slug
            array($this, 'render_test_page')            // callback
        );
    }

    /**
     * Renderizza la pagina di test
     */
    public function render_test_page()
    {
        // Recupera le settings del gateway
        $settings = get_option('woocommerce_ngenius_embedded_settings', array());

        $environment = $settings['environment'] ?? 'live';
        $api_url = $environment === 'uat'
            ? ($settings['uat_api_url'] ?? 'https://api-gateway.sandbox.ngenius-payments.com')
            : ($settings['live_api_url'] ?? 'https://api-gateway.ngenius-payments.com');

        $hosted_session_api_key = trim($settings['hosted_session_api_key'] ?? '');

        // Mostra eventuali risultati salvati in transient
        $result = get_transient('ngenius_embedded_test_result');
        if ($result !== false) {
            delete_transient('ngenius_embedded_test_result');
        }

        ?>
        <div class="wrap">
            <h1>N-Genius Embedded — API Test</h1>
            <p>This page lets you verify that your Hosted Session credentials work, <strong>without making real transactions</strong>.</p>

            <h2>Current configuration</h2>
            <table class="widefat" style="max-width: 800px;">
                <tr>
                    <th style="width: 220px;">Environment</th>
                    <td><code><?php echo esc_html($environment); ?></code></td>
                </tr>
                <tr>
                    <th>API URL</th>
                    <td><code><?php echo esc_html($api_url); ?></code></td>
                </tr>
                <tr>
                    <th>Hosted Session API Key</th>
                    <td>
                        <?php if (empty($hosted_session_api_key)): ?>
                            <span style="color: #d63638;">⚠️ Not configured</span> —
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=ngenius_embedded')); ?>">go to settings</a>
                        <?php else: ?>
                            <span style="color: #00a32a;">✅ Configured</span>
                            (<?php echo esc_html(strlen($hosted_session_api_key)); ?> characters)
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2>Test 1 — Access Token (Hosted Session)</h2>
            <p>Calls <code>POST <?php echo esc_html($api_url); ?>/identity/auth/access-token</code> using the Hosted Session API Key.</p>
            <p>Expected: HTTP 200 with a JSON body containing <code>access_token</code> and <code>expires_in</code>.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_TEST_TOKEN); ?>">
                <?php wp_nonce_field(self::ACTION_TEST_TOKEN); ?>
                <p>
                    <button type="submit"
                            class="button button-primary"
                            <?php echo empty($hosted_session_api_key) ? 'disabled' : ''; ?>>
                        Run Test: Generate Access Token
                    </button>
                </p>
            </form>

            <?php if ($result !== false): ?>
                <h2>Test result</h2>
                <?php $this->render_result($result); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Gestisce il submit del form di test
     */
    public function handle_test_request()
    {
        // Verifica nonce (sicurezza CSRF)
        check_admin_referer(self::ACTION_TEST_TOKEN);

        // Verifica capability
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions.');
        }

        // Recupera settings
        $settings = get_option('woocommerce_ngenius_embedded_settings', array());
        $environment = $settings['environment'] ?? 'live';
        $api_url = $environment === 'uat'
            ? ($settings['uat_api_url'] ?? 'https://api-gateway.sandbox.ngenius-payments.com')
            : ($settings['live_api_url'] ?? 'https://api-gateway.ngenius-payments.com');
        $api_key = trim($settings['hosted_session_api_key'] ?? '');

        if (empty($api_key)) {
            $result = array(
                'success' => false,
                'error'   => 'Hosted Session API Key is not configured.',
            );
        } else {
            $result = $this->call_access_token_endpoint($api_url, $api_key);
        }

        // Salva il risultato in transient (vive 60 sec) per mostrarlo dopo il redirect
        set_transient('ngenius_embedded_test_result', $result, 60);

        // Redirect alla pagina di test
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * Chiama l'endpoint /identity/auth/access-token di N-Genius
     *
     * @param string $api_url URL base dell'API (sandbox o live)
     * @param string $api_key Hosted Session API Key
     * @return array Result con keys: success, http_code, body, error, duration_ms
     */
    private function call_access_token_endpoint($api_url, $api_key)
    {
        $endpoint = rtrim($api_url, '/') . '/identity/auth/access-token';
        $start = microtime(true);

        $args = array(
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . $api_key,
                'Content-Type'  => 'application/vnd.ni-identity.v1+json',
                'Accept'        => 'application/vnd.ni-identity.v1+json',
            ),
            'body'    => '', // L'endpoint non vuole body
        );

        $response = wp_remote_post($endpoint, $args);
        $duration_ms = round((microtime(true) - $start) * 1000, 2);

        // Errore di rete (timeout, DNS, connessione rifiutata...)
        if (is_wp_error($response)) {
            return array(
                'success'     => false,
                'error'       => $response->get_error_message(),
                'endpoint'    => $endpoint,
                'duration_ms' => $duration_ms,
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        return array(
            'success'     => ($http_code >= 200 && $http_code < 300),
            'http_code'   => $http_code,
            'body'        => $body,
            'headers'     => is_object($headers) ? $headers->getAll() : array(),
            'endpoint'    => $endpoint,
            'duration_ms' => $duration_ms,
        );
    }

    /**
     * Renderizza il risultato del test in HTML
     */
    private function render_result($result)
    {
        ?>
        <div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?> inline">
            <p>
                <strong><?php echo $result['success'] ? '✅ SUCCESS' : '❌ FAILED'; ?></strong>
                <?php if (isset($result['http_code'])): ?>
                    — HTTP <?php echo esc_html($result['http_code']); ?>
                <?php endif; ?>
                <?php if (isset($result['duration_ms'])): ?>
                    — <?php echo esc_html($result['duration_ms']); ?> ms
                <?php endif; ?>
            </p>
        </div>

        <?php if (!empty($result['error'])): ?>
            <h3>Error</h3>
            <pre style="background: #fff3f3; padding: 12px; border-left: 4px solid #d63638; max-width: 800px; overflow: auto;"><?php echo esc_html($result['error']); ?></pre>
        <?php endif; ?>

        <?php if (!empty($result['endpoint'])): ?>
            <h3>Request</h3>
            <pre style="background: #f0f0f1; padding: 12px; max-width: 800px; overflow: auto;"><?php
                echo "POST " . esc_html($result['endpoint']) . "\n";
                echo "Authorization: Basic [REDACTED]\n";
                echo "Content-Type: application/vnd.ni-identity.v1+json\n";
            ?></pre>
        <?php endif; ?>

        <?php if (!empty($result['body'])): ?>
            <h3>Response body</h3>
            <pre style="background: #f0f0f1; padding: 12px; max-width: 800px; overflow: auto; max-height: 400px;"><?php
                $decoded = json_decode($result['body'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // È JSON valido: redatta access_token se presente, mostra il resto
                    if (isset($decoded['access_token'])) {
                        $token = $decoded['access_token'];
                        $decoded['access_token'] = substr($token, 0, 12) . '...[REDACTED]...' . substr($token, -8);
                    }
                    echo esc_html(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    // Non è JSON: mostra raw
                    echo esc_html($result['body']);
                }
            ?></pre>
        <?php endif; ?>
        <?php
    }
}

// Inizializza la classe
new NgeniusEmbeddedTest();