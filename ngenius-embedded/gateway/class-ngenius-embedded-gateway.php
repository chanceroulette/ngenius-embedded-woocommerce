<?php

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    if (!defined('ABSPATH')) {
        exit;
    }
});

use Ngenius\NgeniusCommon\Formatter\ValueFormatter;
use Ngenius\NgeniusCommon\NgeniusHTTPTransfer;
use Ngenius\NgeniusCommon\NgeniusOrderStatuses;


$f = dirname(__DIR__, 1);
require_once "$f/vendor/autoload.php";

require_once dirname(__FILE__) . '/class-ngenius-embedded-abstract.php';

/**
 * NgeniusEmbeddedGateway class.
 */
class NgeniusEmbeddedGateway extends NgeniusEmbeddedAbstract
{
    /**
     * Whether logging is enabled
     *
     * @var bool
     */
    public static bool $logEnabled = false;

    /**
     * Logger instance
     *
     * @var null|WC_Logger
     */
    public $logger = null;

    /**
     * Singleton instance
     *
     * @var NgeniusEmbeddedGateway
     */
    private static NgeniusEmbeddedGateway $instance;

    /**
     * Notice variable
     *
     * @var string
     */
    private string $message;

    /**
     * get_instance
     *
     * Returns a new instance of self, if it does not already exist.
     *
     * @access public
     * @static
     * @return NgeniusEmbeddedGateway
     */
    public function __construct()
    {
        parent::__construct();
        $this->logger = wc_get_logger();
        // Initialize form fields and settings
        $this->init_form_fields();
        $this->init_settings();
        // Load settings
        $this->title = $this->get_option('title', __('Credit / Debit Card', 'ngenius'));
        $this->description = $this->get_option('description', '');
        $this->enabled = $this->get_option('enabled', 'no');
    }

    public static function get_instance(): NgeniusEmbeddedGateway
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Logging method.
     *
     * @param string $message Log message.
     * @param string $level Optional. Default 'info'. Possible values:
     *                        emergency|alert|critical|error|warning|notice|info|debug.
     */
    public function log(string $message, string $level = 'debug')
    {
        if ('yes' === $this->get_option('debug', 'no')) {
            $this->logger->log($level, $message, array('source' => 'ngenius'));
        }
    }

    /**
     * Cron Job Hook
     */
    public function ngenius_cron_task()
    {
        if (!wp_next_scheduled('ngenius_embedded_cron_order_update')) {
            wp_schedule_event(time(), 'hourly', 'ngenius_embedded_cron_order_update');
        }
        add_action('ngenius_embedded_cron_order_update', array($this, 'cron_order_update'));
    }

    /**
     * Initialize module hooks
     */
     public function init_hooks()
    {
        add_action('init', array($this, 'ngenius_cron_task'));
        add_action('woocommerce_api_ngenius_embedded', array($this, 'update_ngenius_response'));
        add_action('upgrader_process_complete', array($this, 'clear_cron_on_update'), 10, 2); // New: Clear cron on update

        // 🆕 Tappa 4 — Carica JS/CSS embedded sul frontend (solo checkout)
        add_action('wp_enqueue_scripts', array($this, 'enqueue_embedded_assets'));

        if (is_admin()) {
            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                array($this, 'processAdminOptions')
            );
            add_action('add_meta_boxes', array($this, 'ngenius_online_meta_boxes'), 10, 2);
            add_action('woocommerce_order_action_ngenius_capture', array($this, 'ngenius_capture_order_action'), 10, 1);
            add_action('woocommerce_order_action_ngenius_void', array($this, 'ngenius_void_order_action'), 10, 1);
            add_action('woocommerce_order_actions', array($this, 'wc_add_order_meta_box_action'), 1, 2);
        }
        register_deactivation_hook(__FILE__, array($this, 'clear_cron_on_deactivation'));
    }

    /**
     * Clear cron event when plugin is updated
     *
     * @param object $upgrader_object Unused parameter from upgrader_process_complete hook
     * @param array $options
     */
    public function clear_cron_on_update($upgrader_object, array $options)
    {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            $updated_plugins = $options['plugins'] ?? array();
            $plugin_file = plugin_basename(__FILE__);

            if (in_array($plugin_file, $updated_plugins)) {
                $cron_hook = 'ngenius_cron_order_update';

                if (wp_next_scheduled($cron_hook)) {
                    wp_clear_scheduled_hook($cron_hook);
                }

                if (function_exists('as_unschedule_all_actions')) {
                    as_unschedule_all_actions($cron_hook);
                }

                $this->log('Cleared cron event on plugin update: ' . $cron_hook, 'info');
            }
        }
    }

    /**
     * Clear cron event when plugin is deactivated
     */
    public function clear_cron_on_deactivation()
    {
        $cron_hook = 'ngenius_embedded_cron_order_update';

        if (wp_next_scheduled($cron_hook)) {
            wp_clear_scheduled_hook($cron_hook);
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($cron_hook);
        }

        $this->log('Cleared cron event on plugin deactivation: ' . $cron_hook, 'info');
    }

    /**
     * Add order meta box actions
     *
     * @param array $actions
     * @param WC_Order $order
     * @return array
     */
    public function wc_add_order_meta_box_action($actions, $order)
    {
        $order_item = $this->fetch_order($order->get_id());

        if ($order_item && 'ng-authorised' === $order_item->status) {
            $actions['ngenius_capture'] = __('Capture N-Genius Online', 'ngenius');
            $actions['ngenius_void']    = __('Void N-Genius Online', 'ngenius');
        }

        return $actions;
    }

    public function ngenius_capture_order_action($order)
    {
        $ngenius_state = ['ngenius_capture' => true];
        $this->ngeniusAction($order, $ngenius_state);
    }

    public function ngenius_void_order_action($order)
    {
        $ngenius_state = ['ngenius_void' => true];
        $this->ngeniusAction($order, $ngenius_state);
    }

    /**
     * Handle actions on order page
     *
     * @param $order
     *
     * @return void
     */
    public function ngeniusAction($order, $ngenius_state): void
    {
        $this->message = '';
        WC_Admin_Notices::remove_all_notices();
        $orderID    = $order->get_id();
        $order_item = $this->fetch_order($orderID);

        if ($order_item) {
            $config      = new NgeniusEmbeddedGatewayConfig($this, $order);
            $token_class = new NgeniusEmbeddedGatewayRequestToken($config);

            $this->validate_complete($config, $token_class, $order, $order_item, $ngenius_state);
        } else {
            $this->message = 'Order #' . $orderID . ' not found.';
            WC_Admin_Notices::add_custom_notice('ngenius', $this->message);
        }
        add_filter('redirect_post_location', array($this, 'add_notice_query_var'), 99);
    }

    /**
     * N-Genius Meta Boxes
     */
    public function ngenius_online_meta_boxes($post_type, $post)
    {
        if ('shop_order' !== $post_type || !$post instanceof WP_Post) {
            return;
        }
        $order = wc_get_order($post->ID);

        // Check if the order object is valid
        if (!$order instanceof WC_Order) {
            $this->log("Invalid order object for post ID: {$post->ID}", 'error');

            return;
        }

        // Get the payment method used for the order
        $payment_method = $order->get_meta('_payment_method', true);

        // Check if the payment method matches the current gateway ID
        if ($this->id === $payment_method) {
            add_meta_box(
                'ngenius-payment-actions',
                __('N-Genius Embedded', 'ngenius'),
                array($this, 'ngenius_online_meta_box_payment'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    /**
     * Generate the N-Genius payment meta box and echos the HTML
     */
    public function ngenius_online_meta_box_payment($post)
    {
        $order_id = $post->ID;
        $order    = wc_get_order($order_id);
        if (!empty($order)) {
            $order_item = $this->fetch_order($order_id);
            if (is_null($order_item)) {
                $this->log("No order item found for order ID: " . $order_id, 'warning');

                return;
            }
            $currency_code = $order_item->currency . ' ';
            ValueFormatter::formatCurrencyDecimals(trim($currency_code), $order_item->amount);
            $html = '';
            try {
                $ngAuthorised            = "";
                $ngAuthorisedAmount      = $currency_code . $order_item->amount;
                $ngAuthorisedAmountLabel = __('Authorized:', 'ngenius');
                if ('ng-authorised' === $order_item->status) {
                    $ngAuthorised = "
                        <tr>
                            <td> $ngAuthorisedAmountLabel </td>
                            <td> $ngAuthorisedAmount </td>
                        </tr>
";
                }
                $refunded = 0;
                if ('ng-full-refunded' === $order_item->status || 'ng-part-refunded' === $order_item->status ||
                    'refunded' === $order_item->status) {
                    $refunds = $order->get_refunds();
                    foreach ($refunds as $refund) {
                        $refunded += (double)($refund->get_data()["amount"]);
                    }
                }

                $ngAuthorised2 = "";

                $orderStatuses  = NgeniusOrderStatuses::orderStatuses('N-Genius', 'ng');
                $ng_state       = __('Status:', 'ngenius');
                $ng_state_value = $order->get_status();

                $itemState = $order_item->state;
                foreach ($orderStatuses as $status) {
                    if ("wc-" . $ng_state_value === $status["status"]) {
                        $ng_state_value = $status["label"];
                    }
                }

                $ng_payment_id_label = __('Payment_ID:', 'ngenius');
                $ng_payment_id       = $order_item->payment_id;

                $ng_captured_label  = __('Captured:', 'ngenius');
                $ng_captured_amount = $currency_code . $order_item->amount;

                $ng_refunded_label  = __('Refunded:', 'ngenius');
                $ng_refunded_amount = $currency_code . $refunded;

                $html = "
                    <table>
                    <tr>
                        <td> $ng_state </td>
                        <td> $ng_state_value </td>
                    </tr>
                    <tr>
                        <td> $ng_payment_id_label </td>
                        <td> $ng_payment_id </td>
                    </tr>
                    $ngAuthorised
                 
                    <tr>
				        <td> $ng_refunded_label </td>
				        <td> $ng_refunded_amount </td>
				    </tr>
				    $ngAuthorised2

";
                // Don't display captured line on 'STARTED' and 'AUTHORISED' states
                if ($itemState != 'STARTED'
                    && $itemState != 'AUTHORISED'
                    && $itemState != 'REVERSED'
                ) {
                    $html .= "
                    <tr>
                        <td> $ng_captured_label </td>
				        <td> $ng_captured_amount </td>
                    </tr>
";
                }
                $html .= '
 </table>
';

                if ('ng-authorised' === $order_item->status) {
                    $html .= '
 <hr>
 <p style="color: gray;">Void and capture moved to order actions.</p>
 ';
                }

                echo wp_kses_post($html);
            } catch (Exception $e) {
                throw new InvalidArgumentException(wp_kses_post($e->getMessage()));
            }
        }
    }

    // WooCommerce DPO Group settings html

    public function ngenius_woocommerce_add_gateway_ngenius($methods)
    {
        $methods[] = $this->id;

        return $methods;
    }

    public function payment_fields()
    {
        $html = new stdClass();
        parent::payment_fields();
        if (isset($html->html)) {
            echo esc_html($html->html);
        }

        // Mount point inline per il SDK iframe (visibile quando radio selezionata)
        if ($this->get_option('embedded_mode') === 'yes') {
            echo '<div class="ngenius-embedded-inline-wrap">';
            echo '<div id="ngenius-embedded-card-mount-inline" class="ngenius-embedded-card-mount-inline"></div>';
            echo '<div id="ngenius-embedded-inline-status" class="ngenius-embedded-inline-status" style="display:none;"></div>';
            echo '</div>';
        }
    }

    /**
     * Add notice query variable
     *
     * @param string $location
     *
     * @return string
     */
    public function add_notice_query_var(string $location): string
    {
        remove_filter('redirect_post_location', array($this, 'add_notice_query_var'), 99);

        return add_query_arg(array('message' => false), $location);
    }

    /**
     * Processing order
     *
     * @param int $order_id
     *
     * @return array
     * @throws Exception
     * @global object $woocommerce
     */
    public function process_payment($order_id): array
    {
        global $woocommerce;
        $order = wc_get_order($order_id);

        // 🆕 Tappa 5: branch embedded Web SDK
        if (
            $this->get_option('embedded_mode') === 'yes'
            && !empty($_POST['_ngenius_embedded_session_id'])
        ) {
            return $this->process_embedded_payment($order);
        }

        // === Comportamento HPP originale (invariato) ===
        include_once dirname(__FILE__) . '/request/class-ngenius-embedded-gateway-request-authorize.php';
        include_once dirname(__FILE__) . '/request/class-ngenius-embedded-gateway-request-sale.php';
        include_once dirname(__FILE__) . '/request/class-ngenius-embedded-gateway-request-purchase.php';
        include_once dirname(__FILE__) . '/http/class-ngenius-embedded-gateway-http-authorize.php';
        include_once dirname(__FILE__) . '/http/class-ngenius-embedded-gateway-http-purchase.php';
        include_once dirname(__FILE__) . '/http/class-ngenius-embedded-gateway-http-sale.php';
        include_once dirname(__FILE__) . '/validator/class-ngenius-embedded-gateway-validator-response.php';

        $config       = new NgeniusEmbeddedGatewayConfig($this, $order);
        $token_class  = new NgeniusEmbeddedGatewayRequestToken($config);
        $data         = [];

        if ($config->is_complete()) {
            $token = $token_class->get_access_token();
            if ($token && !is_wp_error($token)) {
                $config->set_token($token);
                if ($config->get_payment_action() == "authorize") {
                    $request_class = new NgeniusEmbeddedGatewayRequestAuthorize($config);
                    $request_http  = new NgeniusEmbeddedGatewayHttpAuthorize();
                } elseif ($config->get_payment_action() == "sale") {
                    $request_class = new NgeniusEmbeddedGatewayRequestSale($config);
                    $request_http  = new NgeniusEmbeddedGatewayHttpSale();
                } elseif ($config->get_payment_action() == "purchase") {
                    $request_class = new NgeniusEmbeddedGatewayRequestPurchase($config);
                    $request_http  = new NgeniusEmbeddedGatewayHttpPurchase();
                }

                $validator = new NgeniusEmbeddedGatewayValidatorResponse();
                $tokenRequest = $request_class->build($order);

                $transferClass = new NgeniusHttpTransfer(
                    $tokenRequest['request']['uri'],
                    $config->get_http_version(),
                    $tokenRequest['request']['method'],
                    $tokenRequest['request']['data']
                );

                $transferClass->setPaymentHeaders($token);

                if (is_wp_error($tokenRequest['token'])) {
                    $this->checkoutErrorThrow('Invalid Server Config');
                }

                $response = $request_http->place_request($transferClass);
                $result = $validator->validate($response);

                if ($result) {
                    $this->save_data($order);
                    $woocommerce->cart->empty_cart();
                    $data = array(
                        'result'   => 'success',
                        'redirect' => $result,
                    );
                }
            } else {
                $errorMsg = $token->errors['error'][0];
                if ($errorMsg == '') {
                    $errorMsg = 'Invalid configuration';
                }
                $this->checkoutErrorThrow("Error! " . $errorMsg . ".");
            }
        } else {
            $this->checkoutErrorThrow("Error! Invalid configuration.");
        }

        return $data;
    }

    /**
     * 🆕 Tappa 5 — Process payment via Web SDK Hosted Session.
     *
     * Called from process_payment() when _ngenius_embedded_session_id is in POST.
     * Calls N-Genius API /payment/hosted-session/{sessionId} with order data.
     *
     * @param WC_Order $order
     * @return array { result, redirect } per WC standard
     * @throws Exception
     */
    protected function process_embedded_payment($order): array
    {
        global $woocommerce;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $session_id = sanitize_text_field(wp_unslash($_POST['_ngenius_embedded_session_id']));

        if (empty($session_id)) {
            $this->checkoutErrorThrow('Embedded payment error: missing session ID.');
        }

        $this->log('[Tappa 5] process_embedded_payment for order #' . $order->get_id() . ' with session ' . substr($session_id, 0, 12) . '...');
        // 🐞 Debug log forzato (Tappa 5)

        $logger = wc_get_logger();
        $logger->info(
            sprintf('[Tappa5] Order #%d session %s...', $order->get_id(), substr($session_id, 0, 12)),
            ['source' => 'ngenius-embedded-debug']
        );

        include_once dirname(__FILE__) . '/request/class-ngenius-embedded-gateway-request-hosted-session.php';
        include_once dirname(__FILE__) . '/http/class-ngenius-embedded-gateway-http-hosted-session.php';

        $config      = new NgeniusEmbeddedGatewayConfig($this, $order);
        $token_class = new NgeniusEmbeddedGatewayRequestToken($config);

        if (!$config->is_complete()) {
            $this->checkoutErrorThrow('Error! Invalid N-Genius configuration.');
        }

        $token = $token_class->get_access_token();
        if (!$token || is_wp_error($token)) {
            $errMsg = is_wp_error($token) ? $token->get_error_message() : 'Could not get access token';
            $this->log('[Tappa 5] Token error: ' . $errMsg, 'error');
            $this->checkoutErrorThrow('Payment failed: authentication error.');
        }
        $config->set_token($token);

        // Build request body (amount, billing, etc. from WC order)
        $request_class = new NgeniusEmbeddedGatewayRequestHostedSession($config);
        $built = ["token" => $config->get_token(), "request" => $request_class->get_build_array($order, $session_id)];

        $this->log('[Tappa 5] POST ' . $built['request']['uri']);

        $logger->info(
            sprintf('[Tappa5] POST %s | Body: %s',
                $built['request']['uri'],
                wp_json_encode($built['request']['data'])
            ),
            ['source' => 'ngenius-embedded-debug']
        );

        $transferClass = new NgeniusHttpTransfer(
            $built['request']['uri'],
            $config->get_http_version(),
            $built['request']['method'],
            $built['request']['data']
        );
        $transferClass->setPaymentHeaders($token);

        $request_http = new NgeniusEmbeddedGatewayHttpHostedSession();
        $response = $request_http->place_request($transferClass);

        if (is_wp_error($response)) {
            $errMsg = $response->get_error_message();
            $this->log('[Tappa 5] HTTP error: ' . $errMsg, 'error');
            $logger->error('[Tappa5] HTTP error: ' . $errMsg, ['source' => 'ngenius-embedded-debug']);

        $logger->info('[Tappa5] Full response: ' . wp_json_encode($response), ['source' => 'ngenius-embedded-debug']);
            $order->update_status('failed', 'N-Genius embedded error: ' . $errMsg);
            $this->checkoutErrorThrow($errMsg);
        }

        $this->log('[Tappa 5] N-Genius response state: ' . ($response['state'] ?? 'unknown'));

        // Save data for our custom table (used by webhook/refund logic)
        global $wp_session;
        $wp_session['ngenius'] = [
            'reference' => $response['reference'] ?? '',
            'action'    => $response['action'] ?? '',
            'state'     => $response['state'] ?? '',
            'status'    => $response['status'] ?? '',
        ];
        $this->save_data($order);

        $state = strtoupper($response['state'] ?? '');
        $orderRef = $response['order_reference'] ?? '';
        $paymentRef = $response['payment_reference'] ?? '';

        // Save to our DB row (for refund/webhook later)
        if ($paymentRef) {
            $this->updateData(
                ['state' => $state, 'reference' => $orderRef],
                ['order_id' => $order->get_id()]
            );
        }

        // 3DS challenge required → store payment ref + return raw response to frontend
        // Frontend will call window.NI.handlePaymentResponse() which handles 3DS modal
        if (!empty($response['requires_3ds'])) {
            $order->add_order_note('N-Genius: 3DS challenge required (state: ' . $state . ')');
            $order->update_meta_data('_ngenius_payment_ref', $paymentRef);
            $order->update_meta_data('_ngenius_order_ref', $orderRef);
            $order->save();

            // Encode raw response for handlePaymentResponse on frontend
            $rawJson = $response['raw_json'] ?? wp_json_encode($response['raw']);

            return [
                'result'                  => 'success',
                'redirect'                => '#ngenius_3ds',
                'ngenius_3ds_required'    => true,
                'ngenius_payment_response' => $rawJson,
                'ngenius_order_id'        => $order->get_id(),
            ];
        }

        // Direct success states (no 3DS needed)
        if (in_array($state, ['AUTHORISED', 'CAPTURED', 'PURCHASED'], true)) {
            // Update our custom DB row with capture/payment ref
            if (!empty($response['payment_reference'])) {
                $this->updateData(
                    [
                        'state'      => $state,
                        'capture_id' => $response['payment_reference'],
                    ],
                    ['order_id' => $order->get_id()]
                );
            }

            $order->payment_complete($response['payment_reference'] ?? '');
            $order->add_order_note(sprintf(
                'N-Genius embedded payment %s (ref: %s)',
                strtolower($state),
                $response['payment_reference'] ?? '-'
            ));
            $woocommerce->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        // FAILED / DECLINED / other
        $failMsg = 'Payment was declined (state: ' . $state . ').';
        $order->update_status('failed', 'N-Genius embedded: ' . $failMsg);
        $this->log('[Tappa 5] Payment failed: ' . $failMsg, 'error');
        $this->checkoutErrorThrow($failMsg);

        return [];
    }

    /**
     * @throws Exception
     */
    private function checkoutErrorThrow($message)
    {
        throw new Exception(wp_kses_post($message));
    }

    /**
     * Save data
     *
     * @param object $order
     *
     * @global object $wp_session
     * @global object $wpdb
     */
    public function save_data(object $order)
    {
        global $wpdb;
        global $wp_session;

        $order_id  = $order->get_id();

        // Check if wp_session exists and has ngenius data
        $ngenius_session_data = [];
        if (isset($wp_session) && is_array($wp_session) && isset($wp_session['ngenius'])) {
            $ngenius_session_data = $wp_session['ngenius'];
        } else {
            $this->log('Missing or incomplete wp_session data for order: ' . $order_id, 'warning');
        }

        $cache_key = 'ngenius_order_' . $ngenius_session_data['reference'] ?? $order_id;

        // Prepare the data to be saved
        $data = array_merge(
            $ngenius_session_data,
            array(
                'order_id' => $order_id,
                'currency' => $order->get_currency(),
                'amount'   => $order->get_total(),
            )
        );

        // Check if the data is already cached
        $cached_data = wp_cache_get($cache_key, 'ngenius');

        if ($cached_data === false) {
            // Data not cached, perform the database operation and cache the data
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->replace(NGENIUS_EMBEDDED_TABLE, $data);

            // Cache the data
            wp_cache_set($cache_key, $data, 'ngenius');
        } else {
            // Data is already cached, no need to perform the database operation
        }
    }

    /**
     * Update data
     *
     * @param array $data
     * @param array $where
     *
     * @global object $wpdb
     */
    public function updateData(array $data, array $where): void
    {
        global $wpdb;

        // Define a unique cache key based on the update data and conditions
        $cache_key = 'ngenius_' . md5(serialize($where));

        // Perform the database update
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(NGENIUS_EMBEDDED_TABLE, $data, $where);

        // If the update is successful, update the cache
        if ($updated !== false) {
            wp_cache_set($cache_key, $data, 'ngenius_cache');
        }
    }

    /**
     * Processes and saves options.
     * If there is an error thrown, will continue to save and validate fields, but will leave the error field out.
     *
     * @return bool was anything saved?
     */
    public function processAdminOptions(): bool
    {
        $saved = parent::process_admin_options();
        if ('yes' === $this->get_option('enabled', 'no')) {
            if (empty($this->get_option('outletRef'))) {
                $this->add_ngenius_settings_error(
                    'ngenius_error',
                    esc_attr('settings_updated'),
                    __('Invalid Reference ID', 'ngenius'),
                    'error'
                );
            }
            if (empty($this->get_option('apiKey'))) {
                $this->add_ngenius_settings_error(
                    'ngenius_error',
                    esc_attr('settings_updated'),
                    __('Invalid API Key', 'ngenius'),
                    'error'
                );
            }
            add_action('admin_notices', 'ngenius_embedded_print_errors');
        }
        if ('yes' !== $this->get_option('debug', 'no')) {
            $this->logger->clear('ngenius');
        }

        return $saved;
    }

    /**
     * Add settings error using WordPress native functionality
     *
     * @param string $setting Setting name
     * @param string $code Error code
     * @param string $message Error message
     * @param string $type Error type (error, warning, info, success)
     */
    public function add_ngenius_settings_error($setting, $code, $message, $type = 'error')
    {
        // Ensure we're in admin context and the function is available
        if (is_admin() && !function_exists('add_settings_error')) {
            require_once ABSPATH . 'wp-admin/includes/template.php';
        }

        // Check if function exists before calling it
        if (function_exists('add_settings_error')) {
            add_settings_error($setting, $code, $message, $type);
        } else {
            // Fallback to WooCommerce admin notices
            WC_Admin_Notices::add_custom_notice($setting, $message);
        }
    }

    /**
     * Catch response from N-Genius
     */
    public function update_ngenius_response()
    {
        $order_ref = filter_input(INPUT_GET, 'ref', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        include_once plugin_dir_path(__FILE__) . '/class-ngenius-embedded-gateway-payment.php';
        $payment = new NgeniusEmbeddedGatewayPayment();
        $payment->execute($order_ref);
        die;
    }

    /**
     * Cron Job Action
     */
    public function cron_order_update()
    {
        include_once plugin_dir_path(__FILE__) . '/class-ngenius-embedded-gateway-payment.php';
        $payment = new NgeniusEmbeddedGatewayPayment();

        $cronSuccess = $payment->order_update();
        if ($cronSuccess) {
            $this->log('Cron updated the orders: ' . $cronSuccess);
        }
    }

    /**
     * Can the order be refunded?
     *
     * @param WC_Order $order Order object.
     *
     * @return bool
     */
    public function can_refund_order($order): bool
    {
        $order_item = $this->fetch_order($order->get_id());
        if (in_array($order_item->status, array('ng-complete', 'ng-captured', 'ng-part-refunded'), true)) {
            return true;
        }

        return false;
    }

    /**
     * Fetch Order details.
     *
     * @param int $order_id
     * @return object|null
     */
    public function fetch_order(int $order_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ngenius_embedded';

        // Define a unique cache key
        $cache_key = 'ngenius_order_' . $order_id;

        // Try to get the order data from cache
        $order = wp_cache_get($cache_key, 'ngenius_orders');

        if ($order === false) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // Custom table query and constant table name are required for N-Genius data.
            $order = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE order_id = %d",
                    $order_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            wp_cache_set($cache_key, $order, 'ngenius_orders', 3600);
        }

        return $order;
    }

    public function validate_complete($config, $token_class, $order, $order_item, $ngenius_state)
    {
        if ($config->is_complete()) {
            $token = $token_class->get_access_token();
            if ($token) {
                $config->set_token($token);
                if ($ngenius_state['ngenius_capture']) {
                    $this->ngenius_capture($order, $config, $order_item);
                } elseif ($ngenius_state['ngenius_void']) {
                    $this->ngenius_void($order, $config, $order_item);
                }
                WC_Admin_Notices::add_custom_notice('ngenius', $this->message);
            }
        } else {
            $this->message = 'Payment gateway credential are not configured properly.';
            WC_Admin_Notices::add_custom_notice('ngenius', $this->message);
        }
    }

    /**
     * Process Capture
     *
     * @param WC_Order $order
     * @param NgeniusEmbeddedGatewayConfig $config
     * @param object $orderItem
     */
    public function ngenius_capture(WC_Order $order, NgeniusEmbeddedGatewayConfig $config, object $orderItem)
    {
        include_once dirname(__FILE__) . '/request/class-ngenius-embedded-gateway-request-capture.php';
        include_once dirname(__FILE__) . '/http/class-ngenius-embedded-gateway-http-capture.php';
        include_once dirname(__FILE__) . '/validator/class-ngenius-embedded-gateway-validator-capture.php';

        $requestClass = new NgeniusEmbeddedGatewayRequestCapture($config);
        $requestHttp  = new NgeniusEmbeddedGatewayHttpCapture();
        $validator    = new NgeniusEmbeddedGatewayValidatorCapture();

        $requestBuild = $requestClass->build($orderItem);

        $transferClass = new NgeniusHttpTransfer(
            $requestBuild['request']['uri'],
            $config->get_http_version(),
            $requestBuild['request']['method'],
            $requestBuild['request']['data']
        );

        $transferClass->setPaymentHeaders($requestBuild['token']);

        $response = $requestHttp->place_request($transferClass);
        $result   = $validator->validate($response);

        $currencyCode = $orderItem->currency;

        $capturedAmount = $result['captured_amt'];
        $totalCaptured  = $result['total_captured'];

        ValueFormatter::formatCurrencyDecimals($currencyCode, $capturedAmount);

        if (isset($result['status']) && $result['status'] === "failed") {
            $order_message = $result['message'];
            $order->add_order_note($order_message);
        } else {
            $data                 = [];
            $data['status']       = $result['orderStatus'];
            $data['state']        = $result['state'];
            $data['captured_amt'] = $totalCaptured;
            $data['capture_id']   = $result['transaction_id'];
            $this->updateData($data, array('nid' => $orderItem->nid));
            $order_message = 'Captured an amount ' . $currencyCode . $capturedAmount;
            $this->message = 'Success! ' . $order_message . ' of an order #' . $orderItem->order_id;
            $order_message .= ' | Transaction ID: ' . $result['transaction_id'];
            $order->payment_complete($result['transaction_id']);
            $order->update_status($result['orderStatus']);
            $order->add_order_note($order_message);
            $eMailer = new WC_Emails();
            $eMailer->customer_invoice($order);
        }
    }

/**
     * 🆕 Tappa 4 — Carica JS/CSS embedded checkout sul frontend.
     *
     * Si attiva solo se:
     *   - siamo sulla pagina checkout (non sulla "thank you")
     *   - embedded_mode è ON nelle settings
     *   - hosted_session_api_key e outletRef sono configurati
     *
     * Passa al JS le credenziali necessarie via window.NgeniusEmbeddedConfig.
     *
     * @return void
     */
    public function enqueue_embedded_assets()
    {
        // Esci se non siamo sul checkout
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }

        // Esci se embedded mode non è attivo
        if ($this->get_option('embedded_mode') !== 'yes') {
            return;
        }

        // Recupera credenziali
        $hosted_key  = trim($this->get_option('hosted_session_api_key'));
        $outlet_ref  = trim($this->get_option('outletRef'));
        $environment = $this->get_option('environment', 'live');

        // Esci silenziosamente se mancano credenziali (no errori al cliente)
        if (empty($hosted_key) || empty($outlet_ref)) {
            return;
        }

        // URL SDK in base all'ambiente
        $sdk_url = ($environment === 'uat')
            ? 'https://paypage.sandbox.ngenius-payments.com/hosted-sessions/sdk.js'
            : 'https://paypage.ngenius-payments.com/hosted-sessions/sdk.js';

        // URL base del plugin per asset
        $plugin_url = plugin_dir_url(dirname(__FILE__));
        $version    = defined('NGENIUS_EMBEDDED_VERSION') ? NGENIUS_EMBEDDED_VERSION : '1.0.0';

        // Carica CSS
        wp_enqueue_style(
            'ngenius-embedded-checkout',
            $plugin_url . 'assets/css/embedded-checkout.css',
            array(),
            $version
        );

        // Carica JS (in footer, no dipendenze)
        wp_enqueue_script(
            'ngenius-embedded-checkout',
            $plugin_url . 'assets/js/embedded-checkout.js',
            array(),
            $version,
            true
        );

        // Passa configurazione al JS via window.NgeniusEmbeddedConfig
        wp_localize_script(
            'ngenius-embedded-checkout',
            'NgeniusEmbeddedConfig',
            array(
                'ajaxUrl'             => admin_url('admin-ajax.php'),
                'nonce'               => wp_create_nonce('ngenius_embedded_nonce'),
                'sdkUrl'              => $sdk_url,
                'hostedSessionApiKey' => $hosted_key,
                'outletRef'           => $outlet_ref,
                'embeddedMode'        => true,
                'pluginUrl'           => plugin_dir_url(dirname(__FILE__)),
                'shopName'            => get_bloginfo('name'),
                'debug'               => ($this->get_option('debug') === 'yes'),
            )
        );
    }

    /**
     * Display custom payment method icon.
     *
     * @return string
     */

    /**
     * Override title to inject inline card brand logos next to gateway name.
     */
    public function get_title()
    {
        $title = parent::get_title();
        $plugin_url = plugin_dir_url(dirname(__FILE__));
        $logos_html = '<span class="ngenius-embedded-brand-logos" style="display:inline-flex;gap:6px;align-items:center;margin-left:10px;vertical-align:middle;">'
            . '<img src="' . esc_url($plugin_url . 'resources/cards/visa.svg') . '" alt="VISA" style="height:18px;" onerror="this.style.display=\'none\'" />'
            . '<img src="' . esc_url($plugin_url . 'resources/cards/mastercard.svg') . '" alt="Mastercard" style="height:18px;" onerror="this.style.display=\'none\'" />'
            . '</span>';
        return $title . $logos_html;
    }

    public function get_icon()
    {
        // No icon shown — embedded checkout focuses on the modal experience.
        return apply_filters('woocommerce_gateway_icon', '', $this->id);
    }
}
