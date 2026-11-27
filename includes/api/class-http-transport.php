<?php
/**
 * The only place this plugin sends HTTP to the Cellon API. Adds the server-only headers
 * (Bearer token, x-host, customer IP) and turns every answer into an Api_Response.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Api;

use Cellon\PaymentCollection\Config;
use Cellon\PaymentCollection\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Http_Transport {

	/**
	 * Wait for an answer for as long as the API takes (a payment the API runs through several processors). cURL waits
	 * without limit; another transport (no cURL) waits this many seconds, as WordPress has no "no limit" of its own.
	 */
	const NO_LIMIT_FALLBACK = 3600;

	/**
	 * @param string              $path    Route relative to the base URL (…/api/v1), e.g. "/payment/stripe/create-payment-intent".
	 * @param array<string,mixed> $body    JSON body.
	 * @param int|null            $timeout Seconds to wait for the answer: null = the configured API timeout, 0 = no limit.
	 */
	public function post( string $path, array $body, ?int $timeout = null ): Api_Response {
		return $this->send( 'POST', $path, $body, $timeout );
	}

	/**
	 * Same as post(), with the PUT verb (customer routes, e.g. "/customer/update-customer-email").
	 *
	 * @param array<string,mixed> $body JSON body.
	 */
	public function put( string $path, array $body ): Api_Response {
		return $this->send( 'PUT', $path, $body );
	}

	/**
	 * @param string              $method  POST or PUT.
	 * @param array<string,mixed> $body    JSON body.
	 * @param int|null            $timeout See post().
	 */
	private function send( string $method, string $path, array $body, ?int $timeout = null ): Api_Response {
		$api = Config::api();
		if ( $api['url'] === '' || $api['token'] === '' ) {
			return Api_Response::local_error( 500, __( 'Payments are not configured on this site.', 'cellon-payment-collection' ), 'not_configured' );
		}
		$timeout  = $timeout === null ? (int) $api['timeout'] : max( 0, $timeout );
		$no_limit = $timeout === 0;

		// A confirm can wait ~60 s on the API (BridgerPay, Vault); shared hosts often cap PHP at 30 s. Without a
		// limit on the answer, PHP has none either.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $no_limit ? 0 : $timeout + 10 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// WordPress raises any timeout to at least 1 s and cannot say "no limit"; cURL's 0 means no limit, so this
		// one request gets it on its cURL handle (the connection itself still has WordPress's connect timeout).
		$unlimited = static function ( $handle ) {
			curl_setopt( $handle, CURLOPT_TIMEOUT, 0 );
			curl_setopt( $handle, CURLOPT_TIMEOUT_MS, 0 );
		};
		if ( $no_limit ) {
			add_action( 'http_api_curl', $unlimited, PHP_INT_MAX );
		}

		$headers = [
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $api['token'],
			'x-host'        => Config::x_host(),
			// The API geo-locates the visitor for pricing: X-Forwarded-For per the BLOX integration doc,
			// ip_address for the API generation that reads that header instead.
			'x-forwarded-for' => Config::client_ip(),
			'ip_address'    => Config::client_ip(),
			'User-Agent'    => Config::user_agent() !== '' ? Config::user_agent() : 'cellon-payment-collection/' . CELLON_PC_VERSION,
		];

		$started = microtime( true );
		try {
			$response = wp_remote_request(
				$api['url'] . '/' . ltrim( $path, '/' ),
				[
					'method'      => $method,
					'timeout'     => $no_limit ? self::NO_LIMIT_FALLBACK : $timeout,
					'redirection' => 0,
					'headers'     => (array) apply_filters( 'cellon_payment_collection_api_headers', $headers, $path ),
					'body'        => wp_json_encode( (object) $body ),
				]
			);
		} finally {
			if ( $no_limit ) {
				remove_action( 'http_api_curl', $unlimited, PHP_INT_MAX );
			}
		}

		if ( is_wp_error( $response ) ) {
			Logger::error( 'api.transport', [ 'path' => $path, 'error' => $response->get_error_message() ] );
			return Api_Response::transport_failure( __( 'The payment service did not answer.', 'cellon-payment-collection' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		Logger::log( 'api.response', [ 'path' => $path, 'status' => $status, 'ms' => (int) ( ( microtime( true ) - $started ) * 1000 ) ] );

		if ( ! is_array( $json ) ) {
			// 2xx with an unreadable body after a confirm is still "unknown", never "paid".
			return new Api_Response( $status >= 200 && $status < 300 ? 502 : $status, false, __( 'Unreadable answer from the payment service.', 'cellon-payment-collection' ), null, 'invalid_response', true );
		}

		$meta    = isset( $json['meta'] ) && is_array( $json['meta'] ) ? $json['meta'] : [];
		$data    = $json['data'] ?? null;
		$success = $status >= 200 && $status < 300 && ( ! isset( $meta['success'] ) || $meta['success'] !== false );
		$message = isset( $meta['message'] ) ? (string) $meta['message'] : '';
		$code    = is_array( $data ) && isset( $data['code'] ) && is_scalar( $data['code'] ) ? (string) $data['code'] : null;

		return new Api_Response( $status, $success, $message, $data, $code );
	}
}
