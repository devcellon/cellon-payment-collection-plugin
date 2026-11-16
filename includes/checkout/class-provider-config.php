<?php
/**
 * Turns the provider fields of the quiz result / price response into two lists:
 *
 *   chain  – automatic fallback sequence, from `payment_provider_details`
 *   direct – providers shown on their own and loaded independently, from
 *            `direct_payment_provider_details` (alias `fixed_payment_provider_details`)
 *
 * Rules
 *   - is_active === false entries are dropped.
 *   - Priority = numeric `order` ascending; ties keep the response order. Entries without an order go
 *     after the ordered ones, in response order. When no entry has an order, the response order is used.
 *   - A slug without a registered and enabled handler is skipped (never executed) and reported.
 *   - Only one list sent (older API): providers whose handler prefers "direct" (PayPal) move to direct.
 *   - Neither field sent: the legacy `payment_gateways` names are used the way the themes use them
 *     today (SolidGate if listed, else Stripe; PayPal as its own button). Legacy entries carry no
 *     account or public key: Stripe and Solidgate take theirs from the intent response, PayPal stays
 *     hidden because its client id must come from the API before the order exists.
 *   - "null" / "undefined" strings become null (older API).
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Checkout;

use Cellon\PaymentCollection\Logger;
use Cellon\PaymentCollection\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Provider_Config {

	/** @var array<int,array<string,mixed>> */
	public $chain = [];

	/** @var array<int,array<string,mixed>> */
	public $direct = [];

	/** @var array<int,array{slug:string, reason:string}> */
	public $skipped = [];

	/** @var string provider_details | legacy | none */
	public $source = 'none';

	/**
	 * @param array<string,mixed> $source Array holding payment_provider_details / direct_payment_provider_details /
	 *                                    fixed_payment_provider_details / payment_gateways.
	 */
	public static function from_response( array $source, Provider_Registry $registry ): self {
		$config = new self();

		$has_chain  = isset( $source['payment_provider_details'] ) && is_array( $source['payment_provider_details'] );
		$direct_raw = null;
		foreach ( [ 'direct_payment_provider_details', 'fixed_payment_provider_details' ] as $key ) {
			if ( isset( $source[ $key ] ) && is_array( $source[ $key ] ) ) {
				$direct_raw = $source[ $key ];
				break;
			}
		}

		if ( $has_chain || $direct_raw !== null ) {
			$config->source = 'provider_details';
			$chain          = $config->normalize( $has_chain ? $source['payment_provider_details'] : [], $registry );
			$direct         = $config->normalize( $direct_raw ?? [], $registry );

			if ( $direct_raw === null ) {
				// One list only: move providers that belong on their own button out of the fallback chain.
				$keep = [];
				foreach ( $chain as $entry ) {
					$provider = $registry->get( $entry['slug'] );
					if ( $provider && $provider->default_placement() === 'direct' ) {
						$direct[] = $entry;
					} else {
						$keep[] = $entry;
					}
				}
				$chain = $keep;
			}

			$config->chain  = $config->dedupe( $chain );
			$config->direct = $config->dedupe( $direct );
		} elseif ( isset( $source['payment_gateways'] ) && is_array( $source['payment_gateways'] ) && apply_filters( 'cellon_payment_collection_use_legacy_gateways', true ) ) {
			$config->source = 'legacy';
			$config->from_legacy( $source['payment_gateways'], $registry );
		}

		/**
		 * Last word on the lists, e.g. to add a site fallback provider behind the API's chain.
		 *
		 * @param Provider_Config $config
		 * @param array           $source Raw response fields.
		 */
		do_action( 'cellon_payment_collection_provider_config', $config, $source );

		if ( $config->skipped ) {
			Logger::log( 'providers.skipped', [ 'skipped' => $config->skipped ] );
		}
		return $config;
	}

	/**
	 * @param array<int,mixed> $details
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize( array $details, Provider_Registry $registry ): array {
		$ordered   = [];
		$unordered = [];

		foreach ( array_values( $details ) as $index => $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}
			if ( array_key_exists( 'is_active', $detail ) && ( $detail['is_active'] === false || $detail['is_active'] === 0 || $detail['is_active'] === '0' || $detail['is_active'] === 'false' ) ) {
				continue;
			}
			$slug = strtolower( (string) self::clean( $detail['payment_provider_slug'] ?? '' ) );
			if ( $slug === '' ) {
				continue;
			}
			if ( ! $registry->get( $slug ) ) {
				$this->skipped[] = [ 'slug' => $slug, 'reason' => 'no_handler' ];
				continue;
			}
			if ( ! $registry->is_enabled( $slug ) ) {
				$this->skipped[] = [ 'slug' => $slug, 'reason' => 'disabled' ];
				continue;
			}

			$account = self::clean( $detail['payment_provider_detail_id'] ?? null );
			$order   = self::clean( $detail['order'] ?? null );
			$mode    = self::clean( $detail['checkout_mode'] ?? null );
			$entry   = [
				'slug'          => $slug,
				'provider_id'   => self::clean( $detail['payment_provider_id'] ?? null ),
				'account_id'    => self::account_id( $account ),
				'public_key'    => self::clean( $detail['public_key'] ?? null ),
				'checkout_mode' => in_array( $mode, [ 'blox', 'checkout' ], true ) ? $mode : null,
				'_index'        => $index,
			];

			if ( $order !== null && is_numeric( $order ) ) {
				$entry['_order'] = (float) $order;
				$ordered[]       = $entry;
			} else {
				$unordered[] = $entry;
			}
		}

		usort(
			$ordered,
			static function ( $a, $b ) {
				return $a['_order'] <=> $b['_order'] ?: $a['_index'] <=> $b['_index'];
			}
		);

		return array_merge( $ordered, $unordered );
	}

	/**
	 * Legacy `payment_gateways`: [ { name: "SolidGate" }, { name: "PayPal" }, ... ] or plain names.
	 *
	 * @param array<int,mixed> $gateways
	 */
	private function from_legacy( array $gateways, Provider_Registry $registry ): void {
		$names = [];
		foreach ( $gateways as $gateway ) {
			$name = is_array( $gateway ) ? ( $gateway['slug'] ?? $gateway['name'] ?? '' ) : $gateway;
			$names[] = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
		}

		$card = in_array( 'solidgate', $names, true ) ? 'solidgate' : 'stripe';
		$pick = static function ( string $slug, int $index ) {
			return [ 'slug' => $slug, 'provider_id' => null, 'account_id' => null, 'public_key' => null, 'checkout_mode' => null, '_index' => $index ];
		};

		if ( $registry->is_enabled( $card ) ) {
			$this->chain[] = $pick( $card, 0 );
		} else {
			$this->skipped[] = [ 'slug' => $card, 'reason' => 'disabled' ];
		}
		if ( in_array( 'paypal', $names, true ) ) {
			// No client id in the legacy list: the browser adapter cannot load PayPal and hides the button.
			$this->skipped[] = [ 'slug' => 'paypal', 'reason' => 'legacy_without_public_key' ];
		}
		foreach ( $names as $name ) {
			if ( $name !== '' && ! $registry->get( $name ) ) {
				$this->skipped[] = [ 'slug' => $name, 'reason' => 'no_handler' ];
			}
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $entries
	 * @return array<int,array<string,mixed>>
	 */
	private function dedupe( array $entries ): array {
		$seen = [];
		$out  = [];
		foreach ( $entries as $entry ) {
			$key = $entry['slug'] . '#' . ( $entry['account_id'] ?? 'default' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			unset( $entry['_order'], $entry['_index'] );
			$out[] = $entry;
		}
		return $out;
	}

	/** Every provider slug in either list. @return string[] */
	public function slugs(): array {
		return array_values( array_unique( array_merge( array_column( $this->chain, 'slug' ), array_column( $this->direct, 'slug' ) ) ) );
	}

	/**
	 * Account ids the API configured per slug (null = the site's generic account).
	 *
	 * @return array<string,array<int,int|null>>
	 */
	public function accounts(): array {
		$out = [];
		foreach ( array_merge( $this->chain, $this->direct ) as $entry ) {
			$out[ $entry['slug'] ][] = $entry['account_id'];
		}
		return $out;
	}

	/**
	 * Entries in the API's own shape for the browser core's normalizeGateways(). `order` is the rank this
	 * class computed, so the browser keeps the same sequence.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_browser( array $entries ): array {
		$out = [];
		foreach ( array_values( $entries ) as $rank => $entry ) {
			$item = [
				'payment_provider_id'        => $entry['provider_id'] ?? $entry['slug'],
				'payment_provider_slug'      => $entry['slug'],
				'payment_provider_detail_id' => $entry['account_id'],
				'public_key'                 => $entry['public_key'],
				'order'                      => $rank + 1,
				'is_active'                  => true,
			];
			if ( ! empty( $entry['checkout_mode'] ) ) {
				$item['checkout_mode'] = $entry['checkout_mode'];
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * @param mixed $value
	 * @return string|null
	 */
	public static function clean( $value ): ?string {
		if ( $value === null || $value === '' || $value === 'null' || $value === 'undefined' || is_array( $value ) || is_object( $value ) ) {
			return null;
		}
		return is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
	}

	/** API rule: a positive integer, or null for the site's generic settings account. */
	public static function account_id( ?string $value ): ?int {
		if ( $value === null || ! ctype_digit( $value ) ) {
			return null;
		}
		$id = (int) $value;
		return $id > 0 ? $id : null;
	}
}
