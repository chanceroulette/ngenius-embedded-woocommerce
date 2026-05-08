<?php

use \Ngenius\NgeniusCommon\NgeniusHTTPCommon;
use \Ngenius\NgeniusCommon\NgeniusHTTPTransfer;

$f = dirname(__DIR__, 2);
require_once "$f/vendor/autoload.php";

add_action('plugins_loaded', function() {
    if (!defined('ABSPATH')) {
        exit;
    }

    if (!class_exists('WooCommerce')) {
        return;
    }
});

/**
 * Class NgeniusEmbeddedGatewayRequestToken
 */
class NgeniusEmbeddedGatewayRequestToken
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
     * Builds access token request
     *
     * @return WP_Error|string|null
     */
    public function get_access_token()
    {
        require_once(dirname(__DIR__) . '/http/class-ngenius-embedded-gateway-http-fetch.php');

        $url = $this->config->get_token_request_url();
        $key = $this->config->get_api_key();

        $httpTokenTransfer = new NgeniusHTTPTransfer($url, $this->config->get_http_version(), "POST");
        $httpTokenTransfer->setTokenHeaders($key);

        $result = json_decode(NgeniusHTTPCommon::placeRequest($httpTokenTransfer));

        if (isset($result->access_token)) {
            return $result->access_token;
        } else {
            $error_message = $result->errors[0]->message;

            return new WP_Error('error', $error_message);
        }
    }
}
