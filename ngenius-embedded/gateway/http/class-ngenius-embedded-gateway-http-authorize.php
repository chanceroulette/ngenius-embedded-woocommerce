<?php

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    if (!defined('ABSPATH')) {
        exit;
    }
});

/**
 * NgeniusEmbeddedGatewayHttpAuthorize class.
 */
class NgeniusEmbeddedGatewayHttpAuthorize extends NgeniusEmbeddedGatewayHttpAbstract
{
    /**
     * Processing of API request body
     *
     * @param array $data
     *
     * @return string
     */
    protected function pre_process(array $data): string
    {
        return wp_json_encode($data);
    }
}
