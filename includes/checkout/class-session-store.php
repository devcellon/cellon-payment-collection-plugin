<?php
/**
 * Stores checkout sessions server-side (transients) and serializes payment steps per checkout with
 * an atomic lock (add_option fails when the row exists), so two tabs or a double click can never run
 * two confirms, or an intent next to a running confirm, for the same checkout.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Checkout;

use Cellon\PaymentCollection\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Session_Store {

	const PREFIX      = 'cpc_co_';
	const LOCK_PREFIX = 'cpc_lock_';

	/** A lock older than this is left over from a killed PHP process. Above the API timeout. */
	const LOCK_STALE = 120;

	public function ttl(): int {
		return (int) apply_filters( 'cellon_payment_collection_session_ttl', 2 * HOUR_IN_SECONDS );
	}

	public function create( Checkout_Context $context ): Checkout_Session {
		$session          = new Checkout_Session();
		$session->token   = bin2hex( random_bytes( 32 ) );
		$session->created = time();
		$session->binding = self::binding();
		$session->x_host  = Config::x_host();
		$session->context = $context->to_array();
		$this->save( $session );
		return $session;
	}

	public function load( string $token ): ?Checkout_Session {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}
		$data = get_transient( self::PREFIX . $token );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$session = Checkout_Session::from_array( $data );

		// Bound to the PHP session that rendered it, when one was active on both requests.
		$current = self::binding();
		if ( $session->binding !== '' && $current !== '' && ! hash_equals( $session->binding, $current ) ) {
			return null;
		}
		Config::use_x_host( $session->x_host );
		return $session;
	}

	/**
	 * Provider reference → checkout token, so a payment that left the page (Stripe redirect methods,
	 * 3DS redirect) can be confirmed on return by the checkout that created it.
	 */
	public function remember_reference( string $slug, string $reference, string $token ): void {
		set_transient( 'cpc_ref_' . md5( $slug . '|' . $reference ), $token, $this->ttl() );
	}

	public function token_for_reference( string $slug, string $reference ): ?string {
		$token = get_transient( 'cpc_ref_' . md5( $slug . '|' . $reference ) );
		return is_string( $token ) && preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : null;
	}

	public function save( Checkout_Session $session ): void {
		set_transient( self::PREFIX . $session->token, $session->to_array(), $this->ttl() );
	}

	/**
	 * @param float $wait_seconds 0 = fail at once when another step runs.
	 */
	/**
	 * Atomic: INSERT IGNORE on the unique option_name index, straight to the database (add_option() is
	 * not atomic: it checks the cache first and upserts). Bypasses the object cache on purpose.
	 *
	 * @param float $wait_seconds 0 = fail at once when another step runs.
	 */
	public function lock( string $token, float $wait_seconds = 0.0 ): bool {
		global $wpdb;
		$name     = self::LOCK_PREFIX . $token;
		$deadline = microtime( true ) + $wait_seconds;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
			if ( $inserted === 1 ) {
				return true;
			}
			$since = $this->locked_since( $token );
			if ( $since > 0 && time() - $since > self::LOCK_STALE ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $since ) );
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( 150000 );
		} while ( true );
	}

	/**
	 * Keeps a held lock from looking stale for $seconds more: a payment that waits on the API without a time limit
	 * (one-call payments) is still running, not a process that died. A lock counts as stale LOCK_STALE seconds after
	 * its timestamp, so the timestamp moves ahead.
	 */
	public function hold_lock( string $token, int $seconds ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->options, [ 'option_value' => (string) ( time() + max( 0, $seconds ) ) ], [ 'option_name' => self::LOCK_PREFIX . $token ] );
	}

	public function unlock( string $token ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, [ 'option_name' => self::LOCK_PREFIX . $token ] );
	}

	public function is_locked( string $token ): bool {
		$since = $this->locked_since( $token );
		return $since > 0 && time() - $since <= self::LOCK_STALE;
	}

	private function locked_since( string $token ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_PREFIX . $token ) );
	}

	private static function binding(): string {
		if ( session_status() !== PHP_SESSION_ACTIVE || session_id() === '' ) {
			return '';
		}
		return hash_hmac( 'sha256', session_id(), wp_salt( 'auth' ) );
	}
}
