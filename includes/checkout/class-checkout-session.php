<?php
/**
 * Server-side state of one rendered checkout: its context and every payment attempt.
 *
 * Attempt statuses
 *   intent      intent / order created, nothing charged yet
 *   confirming  confirm or token charge running (money may move now)
 *   pending     the API says the provider has not finished (BridgerPay 409)
 *   unknown     confirm got no usable answer (timeout, 5xx): money may have moved
 *   rejected    the API answered the confirm with an error: probably not recorded, check before paying again
 *   charged     a card token charge was accepted (approved or 3DS); its confirm has not run yet
 *   failed      resolved as not paid
 *   succeeded   the API recorded the sale
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Checkout_Session {

	/** Statuses that block a new attempt until the payment status was checked. */
	const UNRESOLVED = [ 'confirming', 'pending', 'unknown', 'rejected', 'charged' ];

	/** @var string 64 hex characters */
	public $token;

	/** @var int */
	public $created;

	/** @var string Hash of the PHP session that rendered the checkout, '' when none was active. */
	public $binding = '';

	/** @var string x-host the page computed (WPML language included); reused by every API call of this checkout. */
	public $x_host = '';

	/** @var array<string,mixed> Checkout_Context::to_array() */
	public $context = [];

	/** @var string open | paid */
	public $state = 'open';

	/** @var array<int,array<string,mixed>> */
	public $attempts = [];

	/** @var array<string,array<int,int|null>> Accounts the API returned on intents, per slug. */
	public $accounts_seen = [];

	/** @var array<string,mixed> On success: gateway, redirect_tag, redirect_url. */
	public $result = [];

	/** @var int Intent calls made, capped against card testing. */
	public $intent_count = 0;

	public function context(): Checkout_Context {
		return Checkout_Context::restore( $this->context );
	}

	public function is_paid(): bool {
		return $this->state === 'paid';
	}

	/**
	 * Slugs this checkout may use: the ones the API listed for it, nothing else.
	 *
	 * @return string[]
	 */
	public function allowed_slugs(): array {
		$slugs = [];
		foreach ( array_merge( (array) $this->context['chain'], (array) $this->context['direct'] ) as $entry ) {
			$slugs[] = (string) $entry['slug'];
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * The account the browser names must be one the API configured for this slug, or one the API
	 * itself returned on an intent of this checkout (admin switched accounts between page load and pay).
	 */
	public function account_allowed( string $slug, ?int $account_id ): bool {
		$allowed = [];
		foreach ( array_merge( (array) $this->context['chain'], (array) $this->context['direct'] ) as $entry ) {
			if ( $entry['slug'] === $slug ) {
				$allowed[] = $entry['account_id'];
			}
		}
		$allowed = array_merge( $allowed, $this->accounts_seen[ $slug ] ?? [] );
		return in_array( $account_id, $allowed, true );
	}

	/**
	 * @return array<string,mixed> The new attempt.
	 */
	public function add_attempt( string $slug, ?int $account_id, ?string $reference ): array {
		$attempt          = [
			'id'         => count( $this->attempts ) + 1,
			'slug'       => $slug,
			'account_id' => $account_id,
			'reference'  => $reference,
			'status'     => 'intent',
			'created'    => time(),
			'updated'    => time(),
		];
		$this->attempts[] = $attempt;
		if ( ! in_array( $account_id, $this->accounts_seen[ $slug ] ?? [], true ) ) {
			$this->accounts_seen[ $slug ][] = $account_id;
		}
		return $attempt;
	}

	/**
	 * The attempt a confirm belongs to: same slug and, when the provider has references, same reference.
	 * Latest first.
	 */
	public function find_attempt( string $slug, ?string $reference ): ?int {
		for ( $i = count( $this->attempts ) - 1; $i >= 0; $i-- ) {
			$attempt = $this->attempts[ $i ];
			if ( $attempt['slug'] !== $slug ) {
				continue;
			}
			if ( $reference === null || $attempt['reference'] === null || hash_equals( (string) $attempt['reference'], $reference ) ) {
				return $i;
			}
		}
		return null;
	}

	/** Whether any intent of this slug carried a provider reference (then a confirm must name one of them). */
	public function has_references( string $slug ): bool {
		foreach ( $this->attempts as $attempt ) {
			if ( $attempt['slug'] === $slug && $attempt['reference'] !== null ) {
				return true;
			}
		}
		return false;
	}

	public function set_status( int $index, string $status ): void {
		if ( isset( $this->attempts[ $index ] ) ) {
			$this->attempts[ $index ]['status']  = $status;
			$this->attempts[ $index ]['updated'] = time();
		}
	}

	/** Index of the newest attempt whose outcome is not settled, or null. */
	public function unresolved_attempt(): ?int {
		for ( $i = count( $this->attempts ) - 1; $i >= 0; $i-- ) {
			if ( in_array( $this->attempts[ $i ]['status'], self::UNRESOLVED, true ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $result gateway, redirect_tag, redirect_url
	 */
	public function mark_paid( array $result ): void {
		$this->state  = 'paid';
		$this->result = $result;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		$session = new self();
		foreach ( get_object_vars( $session ) as $key => $default ) {
			if ( array_key_exists( $key, $data ) ) {
				$session->$key = $data[ $key ];
			}
		}
		return $session;
	}
}
