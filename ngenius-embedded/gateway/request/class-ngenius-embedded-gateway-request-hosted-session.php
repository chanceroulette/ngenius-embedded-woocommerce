<?php

use Ngenius\NgeniusCommon\Formatter\ValueFormatter;

require_once 'class-ngenius-embedded-gateway-request-abstract.php';

add_action('plugins_loaded', function() {
    if (!defined('ABSPATH')) {
        exit;
    }

    if (!class_exists('WooCommerce')) {
        return;
    }
});

/**
 * NgeniusEmbeddedGatewayRequestHostedSession class.
 *
 * Builds the request body for /transactions/outlets/{outletRef}/payment/hosted-session/{sessionId}
 * which completes a payment initiated via Web SDK.
 */
class NgeniusEmbeddedGatewayRequestHostedSession extends NgeniusEmbeddedGatewayRequestAbstract
{
    /**
     * Builds hosted session payment completion request array
     *
     * @param object $order WC_Order
     * @param string $sessionId The session_id from window.NI.generateSessionId()
     * @param string $action PURCHASE | AUTH | SALE
     *
     * @return array
     */
    public function get_build_array($order, string $sessionId = '', string $action = 'PURCHASE')
    {
        $currencyCode = $order->get_currency();
        $amount       = ValueFormatter::floatToIntRepresentation($currencyCode, $order->get_total());
        $countryCode  = WC()->countries->get_country_calling_code($order->get_billing_country());

        $actionMap = [
            'authorize' => 'AUTH',
            'sale'      => 'SALE',
            'purchase'  => 'PURCHASE',
        ];
        $configAction = $this->config->get_payment_action();
        $action = $actionMap[$configAction] ?? 'PURCHASE';

        return [
            'data'   => [
                'action'                 => $action,
                'amount'                 => [
                    'currencyCode' => $currencyCode,
                    'value'        => $amount,
                ],
                'merchantOrderReference' => $order->get_id(),
                'emailAddress'           => $order->get_billing_email(),
                'billingAddress'         => [
                    'firstName'   => $order->get_billing_first_name(),
                    'lastName'    => $order->get_billing_last_name(),
                    'address1'    => $order->get_billing_address_1(),
                    'address2'    => $order->get_billing_address_2(),
                    'city'        => $order->get_billing_city(),
                    'stateCode'   => $order->get_billing_state(),
                    'postalCode'  => $order->get_billing_postcode(),
                    'countryCode' => $order->get_billing_country(),
                ],
                'phoneNumber'            => [
                    'countryCode' => substr($countryCode, 1),
                    'subscriber'  => $order->get_billing_phone(),
                ],
                'merchantDefinedData'    => [
                    'pluginName'    => 'woocommerce-embedded',
                    'pluginVersion' => defined('NGENIUS_EMBEDDED_VERSION') ? NGENIUS_EMBEDDED_VERSION : '1.0.0',
                ],
            ],
            'method' => 'POST',
            'uri'    => $this->config->get_hosted_session_url($sessionId),
        ];
    }
}