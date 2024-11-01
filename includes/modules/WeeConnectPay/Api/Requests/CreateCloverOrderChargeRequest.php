<?php

namespace WeeConnectPay\Api\Requests;

use WeeConnectPay\Dependencies\GuzzleHttp\Exception\GuzzleException;
use WeeConnectPay\Dependencies\Psr\Http\Message\ResponseInterface;
use WeeConnectPay\Api\ApiClient;
use WeeConnectPay\Api\ApiEndpoints;
use WeeConnectPay\WordPress\Plugin\includes\WeeConnectPayUtilities;

class CreateCloverOrderChargeRequest extends ApiClient {

	/**
	 * POST request
	 */
	public function POST(string $cloverOrderUuid, string $tokenizedCard, string $ipAddress): ResponseInterface {
		return $this->client->post( ApiEndpoints::createOrderCharge($cloverOrderUuid), self::setOptions($tokenizedCard, $ipAddress));
	}

	/**
	 * @param string $tokenizedCard
	 * @param string $ipAddress
	 *
	 * @updated 3.4.0
	 * @return array
	 */
	private static function setOptions(string $tokenizedCard, string $ipAddress): array {
		$options['form_params'] = self::setRequestBody( $tokenizedCard, $ipAddress );

		return $options;
	}

	/**
	 * Set the request body
	 *
	 * @param string $tokenizedCard
	 * @param string $ipAddress
	 *
	 * @return array
	 */
	private static function setRequestBody(string $tokenizedCard, string $ipAddress): array {

		return [
			'tokenized_card' => $tokenizedCard,
			'ip_address' => $ipAddress,
            'integration_version' => WEECONNECT_VERSION
		];
	}
}
