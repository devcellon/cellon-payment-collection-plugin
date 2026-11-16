<?php
/**
 * One Cellon API answer, normalized from the `{ meta: { success, message, status }, data }` envelope.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Api_Response {

	/** @var int HTTP status; 0 when the request never got an answer (timeout, DNS, TLS). */
	public $status;

	/** @var bool */
	public $success;

	/** @var string */
	public $message;

	/** @var mixed `data` of the envelope. */
	public $data;

	/** @var string|null `data.code` when the API sends one. */
	public $code;

	/** @var bool The request failed in transport or the answer could not be read. */
	public $transport_error;

	/**
	 * @param mixed $data
	 */
	public function __construct( int $status, bool $success, string $message, $data = null, ?string $code = null, bool $transport_error = false ) {
		$this->status          = $status;
		$this->success         = $success;
		$this->message         = $message;
		$this->data            = $data;
		$this->code            = $code;
		$this->transport_error = $transport_error;
	}

	public static function transport_failure( string $message ): self {
		return new self( 0, false, $message, null, 'transport_error', true );
	}

	public static function local_error( int $status, string $message, string $code ): self {
		return new self( $status, false, $message, null, $code, false );
	}

	public function is_success(): bool {
		return $this->success && $this->status >= 200 && $this->status < 300;
	}

	/**
	 * After a confirm or token charge: money may have moved and we do not know. Transport failure,
	 * timeout, 5xx or an unreadable body. The caller must check the payment status before any new attempt.
	 */
	public function is_outcome_unknown(): bool {
		return $this->transport_error || $this->status === 0 || $this->status >= 500;
	}

	/** @return array<string,mixed> */
	public function data_array(): array {
		return is_array( $this->data ) ? $this->data : [];
	}

	/**
	 * The envelope the browser gets: same shape as the API, so the browser core's failover policy
	 * (status codes, messages) works unchanged. Never carries request headers or the token.
	 *
	 * @param mixed $data Data to send instead of the API data (already filtered by the caller).
	 * @return array<string,mixed>
	 */
	public function to_browser( $data = null ): array {
		$out = [
			'meta' => [
				'success' => $this->is_success(),
				'message' => $this->message,
				'status'  => $this->status === 0 ? 502 : $this->status,
			],
			'data' => $data,
		];
		if ( $this->code !== null && ! $this->is_success() ) {
			$out['data'] = is_array( $data ) ? $data + [ 'code' => $this->code ] : [ 'code' => $this->code ];
		}
		return $out;
	}
}
