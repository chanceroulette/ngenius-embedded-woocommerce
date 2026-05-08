<?php

use Ngenius\NgeniusCommon\Formatter\ValueFormatter;

add_action('plugins_loaded', function() {
    if (!defined('ABSPATH')) {
        exit;
    }

    if (!class_exists('WooCommerce')) {
        return;
    }
});

/**
 * Class NgeniusEmbeddedGatewayRequestRefund
 */
class NgeniusEmbeddedGatewayRequestRefund
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * Constructor
     *
     * @param NgeniusEmbeddedGatewayConfig $config
     */
    public function __construct(NgeniusEmbeddedGatewayConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Builds ENV refund request
     *
     * @param object $order_item
     * @param float $amount
     *
     * @return array|null
     */
    public function build($order_item, $amount, $url)
    {
        $currencyCode = $order_item->currency;

        $amount = ValueFormatter::floatToIntRepresentation($currencyCode, $amount);

        return [
            'token'   => $this->config->get_token(),
            'request' => [
                'data'   => [
                    'amount'              => [
                        'currencyCode' => $currencyCode,
                        'value'        => $amount,
                    ],
                    'merchantDefinedData' => [
                        'pluginName'    => 'woocommerce',
                        'pluginVersion' => NGENIUS_EMBEDDED_VERSION
                    ]
                ],
                'method' => 'POST',
                'uri'    => $url,
            ],
        ];
    }
}
