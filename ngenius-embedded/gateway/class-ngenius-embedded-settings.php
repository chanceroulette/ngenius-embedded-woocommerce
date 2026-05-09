<?php

add_action('plugins_loaded', function() {
    if (!defined('ABSPATH')) {
        exit;
    }

    if (!class_exists('WooCommerce')) {
        return;https://claude.ai/project/019e06a8-8670-7018-96f2-a5cebcfc5dcf
    }
});

/**
 * Settings for N-Genius Gateway.
 */

class NgeniusEmbeddedSettings extends WC_Settings_API
{
    public static $currencies = [
        'AED',
        'ALL',
        'AOA',
        'AUD',
        'BHD',
        'BWP',
        'CAD',
        'DKK',
        'EGP',
        'EUR',
        'GBP',
        'GHS',
        'GNF',
        'HKD',
        'INR',
        'JOD',
        'JPY',
        'KES',
        'KWD',
        'LKR',
        'MAD',
        'MWK',
        'MYR',
        'NAD',
        'NGN',
        'OMR',
        'PHP',
        'PKR',
        'QAR',
        'SAR',
        'SEK',
        'SGD',
        'THB',
        'TRY',
        'TZS',
        'UGX',
        'USD',
        'XAF',
        'XOF',
        'ZAR',
        'ZMW',
        'ZWL'
    ];

    public function overrideFormFieldsVariable()
    {
        return array(
            'onboarding_link' => array(
            'title'       => '',
            'type'        => 'title',
            'description' => 'Don&rsquo;t have credentials yet? Click <a href="https://www.network.ae/en/merchant-solutions/ecommerce-payments/plugins?utm_source=WooCommerce&utm_medium=referral&utm_content=link&utm_term=partner-plugins&utm_campaign=woocommerce-partner" target="_blank" rel="noopener noreferrer" style="font-weight:600; text-decoration:underline;">here</a> to get started with Network International and gain access to N-Genius&trade; payments for WooCommerce.',
        ),
            'enabled'                       => array(
                'title'   => __('Enable/Disable', 'ngenius'),
                'label'   => __('Enable N-Genius Embedded', 'ngenius'),
                'type'    => 'checkbox',
                'default' => 'no',
            ),
            'title'                         => array(
                'title'       => __('Title', 'ngenius'),
                'type'        => 'text',
                'description' => __('The title which the user sees during checkout.', 'ngenius'),
                'default'     => __('Credit / Debit Card', 'ngenius'),
            ),
            'description'                   => array(
                'title'       => __('Description', 'ngenius'),
                'type'        => 'textarea',
                'css'         => 'width: 400px;height:60px;',
                'description' => __('The description which the user sees during checkout.', 'ngenius'),
                'default'     => __('', 'ngenius'),
            ),
            // ============================================================
            // EMBEDDED MODE SETTINGS (Tappa 2 — added for Web SDK integration)
            // ============================================================
            'embedded_mode_section'         => array(
                'title'       => __('Embedded Mode (Web SDK)', 'ngenius-embedded'),
                'type'        => 'title',
                'description' => __('Settings for the embedded checkout experience. Requires a Hosted Session Service Account configured in your N-Genius portal. When OFF, the plugin behaves like the standard Hosted Payment Page (redirect).', 'ngenius-embedded'),
            ),
            'embedded_mode'                 => array(
                'title'       => __('Enable Embedded Mode', 'ngenius-embedded'),
                'label'       => __('Use Web SDK / Hosted Session instead of redirect', 'ngenius-embedded'),
                'type'        => 'checkbox',
                'default'     => 'no',
                'description' => __('When enabled, customers complete payment on your site without being redirected. Requires Hosted Session API Key below.', 'ngenius-embedded'),
            ),
            'threeds_display_mode'          => array(
                'title'       => __('3DS Challenge Display', 'ngenius-embedded'),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'options'     => array(
                    'modal'    => __('Modal overlay (recommended)', 'ngenius-embedded'),
                    'inline'   => __('Inline iframe', 'ngenius-embedded'),
                    'redirect' => __('Full-page redirect (fallback)', 'ngenius-embedded'),
                ),
                'default'     => 'modal',
                'description' => __('How to display the bank 3D Secure authentication challenge. Modal is the most user-friendly. Used only when Embedded Mode is enabled.', 'ngenius-embedded'),
            ),
            // ============================================================
            // END EMBEDDED MODE SETTINGS
            // ============================================================
            'environment'                   => array(
                'title'   => __('Environment', 'ngenius'),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'options' => array(
                    'uat'  => __('Sandbox', 'ngenius'),
                    'live' => __('Live', 'ngenius'),
                ),
                'default' => 'uat',
            ),
            'uat_api_url'                   => array(
                'title'   => __('Sandbox API URL', 'ngenius'),
                'type'    => 'text',
                'default' => 'https://api-gateway.sandbox.ngenius-payments.com',
            ),
            'live_api_url'                  => array(
                'title'   => __('Live API URL', 'ngenius'),
                'type'    => 'text',
                'default' => 'https://api-gateway.ngenius-payments.com',
            ),
            'paymentAction'                 => array(
                'title'   => __('Payment Action', 'ngenius'),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'options' => array(
                    'authorize' => __('Authorize', 'ngenius'),
                    'sale'      => __('Sale', 'ngenius'),
                    'purchase'  => __('Purchase', 'ngenius'),
                ),
                'default' => 'sale',
            ),
            'orderStatus'                   => array(
                'title'   => __('Status of new order', 'ngenius'),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'options' => array(
                    'ngenius_pending' => __('N-Genius Pending', 'ngenius'),
                ),
                'default' => 'ngenius_pending',
            ),
            'default_complete_order_status' => array(
                'title'   => __("Set successful orders to 'Processing'", 'ngenius'),
                'type'    => 'checkbox',
                'default' => 'no',
            ),
            'outletRef'                     => array(
                'title' => __('Outlet Reference ID', 'ngenius'),
                'type'  => 'text',
            ),
            'outlet_override_currency'      => [
                'title'       => __('Outlet 2 Currencies (Optional)', 'ngenius'),
                'type'        => 'multiselect',
                'class'       => 'wc-enhanced-select',
                'options'     => self::$currencies,
                'label'       => __('Outlet 2 Currencies (Optional)', 'ngenius'),
                'description' => __(
                    'If these currencies are selected, Outlet 2 Reference ID will be used.',
                    'ngenius'
                ),
            ],
            'outlet_override_ref'           => array(
                'title' => __('Outlet 2 Reference ID (Optional)', 'ngenius'),
                'type'  => 'text',
            ),
            'apiKey'                        => array(
                'title' => __('API Key', 'ngenius'),
                'type'  => 'textarea',
                'css'   => 'width: 400px;height:50px;',
            ),
            // ============================================================
            // HOSTED SESSION CREDENTIALS (Tappa 2 — Web SDK Service Account)
            // ============================================================
            'hosted_session_section'        => array(
                'title'       => __('Hosted Session Credentials (Web SDK)', 'ngenius-embedded'),
                'type'        => 'title',
                'description' => __('Separate API Key for the Hosted Session Service Account. Generate this in N-Genius portal: Settings → Integrations → Service Accounts → type "Hosted Session Service Account". Required only when Embedded Mode is enabled.', 'ngenius-embedded'),
            ),
            'hosted_session_api_key'        => array(
                'title'       => __('Hosted Session API Key', 'ngenius-embedded'),
                'type'        => 'textarea',
                'css'         => 'width: 400px;height:50px;',
                'description' => __('API Key from the Hosted Session Service Account on N-Genius portal. Different from the standard API Key above.', 'ngenius-embedded'),
            ),
            // ============================================================
            // END HOSTED SESSION CREDENTIALS
            // ============================================================
            'curl_http_version'             => array(
                'title'   => __('HTTP Version', 'ngenius'),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'options' => array(
                    "CURL_HTTP_VERSION_NONE"              => __('None', 'ngenius'),
                    "CURL_HTTP_VERSION_1_0"               => __('1.0', 'ngenius'),
                    "CURL_HTTP_VERSION_1_1"               => __('1.1', 'ngenius'),
                    "CURL_HTTP_VERSION_2_0"               => __('2.0', 'ngenius'),
                    "CURL_HTTP_VERSION_2TLS"              => __('2 (TLS)', 'ngenius'),
                    "CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE" => __('2 (prior knowledge)', 'ngenius'),
                ),
                'default' => 'CURL_HTTP_VERSION_NONE',
            ),
            'customOrderFields'             => array(
                'title'       => __('Custom Order Meta', 'ngenius'),
                'type'        => 'text',
                'css'         => 'width: 400px;height: auto;',
                'desc_tip'    => true,
                'description' => __(
                    'Add order meta to the custom merchant fields using a meta key (e.g. _billing_first_name)',
                    'ngenius'
                ),
            ),
            'debug'                         => array(
                'title'       => __('Debug Log', 'ngenius'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'ngenius'),
                'description' => sprintf(
                /* translators: %s: log file path */
                    __('Log file will be %s', 'ngenius'),
                    '<code>' . WC_Log_Handler_File::get_log_file_path('ngenius') . '</code>'
                ),
                'default'     => 'no',
            ),
            'debugMode'                     => array(
                'title'       => __('Cron Debug Mode', 'ngenius'),
                'type'        => 'checkbox',
                'label'       => __('Enable cron debug mode', 'ngenius'),
                'description' => __(
                    'Activate/deactivate cron debug mode',
                    'ngenius'
                ),
                'default'     => 'no',
            )
        );
    }
}
