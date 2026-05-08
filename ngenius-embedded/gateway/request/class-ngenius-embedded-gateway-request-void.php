<?php

add_action('plugins_loaded', function() {
    if (!defined('ABSPATH')) {
        exit;
    }

    if (!class_exists('WooCommerce')) {
        return;
    }
});

if (!class_exists('WooCommerce')) {
    return;
}

/**
 * Class NgeniusEmbeddedGatewayRequestVoid
 */
class NgeniusEmbeddedGatewayRequestVoid
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
     * Builds ENV void request
     *
     * @param object $order_item
     *
     * @return array|null
     */
    public function build($order_item)
    {
        return [
            'token'   => $this->config->get_token(),
            'request' => [
                'data'   => [],
                'method' => 'PUT',
                'uri'    => $this->config->get_order_void_url($order_item->reference, $order_item->payment_id),
            ],
        ];
    }
}
