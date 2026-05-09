<?php

use \Ngenius\NgeniusCommon\NgeniusHTTPCommon;
use \Ngenius\NgeniusCommon\NgeniusHTTPTransfer;
use Ngenius\NgeniusCommon\NgeniusOrderStatuses;

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    if (!defined('ABSPATH')) {
        exit;
    }
});

$f = dirname(__DIR__, 2);
require_once "$f/vendor/autoload.php";

class NgeniusEmbeddedGatewayHttpHostedSession extends NgeniusEmbeddedGatewayHttpAbstract
{
    protected function pre_process(array $data): string
    {
        return wp_json_encode($data);
    }

    public function place_request(NgeniusHttpTransfer $transferObject)
    {
        $this->orderStatus = NgeniusOrderStatuses::orderStatuses('N-Genius', 'ng');

        try {
            $rawResponse = NgeniusHTTPCommon::placeRequest($transferObject);
            $response = json_decode($rawResponse);

            if (!is_object($response)) {
                return new WP_Error('ngenius_error', 'Invalid response from gateway.');
            }

            wc_get_logger()->info(
                '[Tappa5 RAW]: ' . wp_json_encode($response),
                ['source' => 'ngenius-embedded-debug']
            );

            if (isset($response->errors) && is_array($response->errors)) {
                $msg = $response->errors[0]->message ?? 'Unknown error';
                return new WP_Error('ngenius_error', 'N-Genius: ' . $msg);
            }

            return $this->parse_response($response);

        } catch (Exception $e) {
            return new WP_Error('ngenius_error', $e->getMessage());
        }
    }

    /**
     * Parses hosted-session response.
     *
     * The response has FLAT structure (state, reference, _links at root level),
     * not nested in _embedded.payment[0].
     */
    protected function parse_response(stdClass $response): array
    {
        $state = isset($response->state) ? strtoupper((string) $response->state) : '';

        $data = [
            'state'             => $state,
            'reference'         => $response->orderReference ?? ($response->reference ?? ''),
            'payment_reference' => $response->reference ?? '',
            'order_reference'   => $response->orderReference ?? '',
            'action'            => 'SALE',
            'status'            => substr($this->orderStatus[0]['status'] ?? 'ng-pending', 3),
            'requires_3ds'      => false,
            'three_ds_url'      => '',
            'raw'               => $response,
            'raw_json'          => wp_json_encode($response),
        ];

        // Detect 3DS requirement
        if (in_array($state, [
            'AWAIT_3DS',
            'AWAITING_3DS',
            '3DS_BROWSER_AUTHENTICATION_REQUIRED',
            'STARTED',
        ], true)) {
            $data['requires_3ds'] = true;

            if (isset($response->_links->{'cnp:3ds2-authentication'}->href)) {
                $data['three_ds_url'] = $response->_links->{'cnp:3ds2-authentication'}->href;
            } elseif (isset($response->_links->{'cnp:3ds'}->href)) {
                $data['three_ds_url'] = $response->_links->{'cnp:3ds'}->href;
            }
        }

        return $data;
    }
}
