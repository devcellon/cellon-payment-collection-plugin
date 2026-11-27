<?php
/**
 * Registered provider handlers. A slug the API sends that has no handler here, or whose handler is
 * switched off, is never executed: it is dropped from the checkout and logged.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

use Cellon\PaymentCollection\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Provider_Registry {

	/**
	 * Providers seen in the sites' code whose API contract is not defined yet. They are reported in
	 * diagnostics instead of being run. See docs/BACKEND-CONTRACTS.md.
	 */
	const KNOWN_WITHOUT_CONTRACT = [
		'bluesnap' => 'BlueSnap: out of scope, skipped.',
		'tranzila' => 'Tranzila: out of scope, skipped.',
	];

	/** @var array<string,Provider> */
	private $providers = [];

	/** @var string[]|null Enabled slugs, read once. */
	private $enabled = null;

	public function __construct() {
		$built_in = [ new Stripe_Provider(), new Solidgate_Provider(), new Paypal_Provider(), new Bridgerpay_Provider(), new Evervault_Provider() ];

		/**
		 * Add or replace provider handlers. Each must implement Provider (extend Abstract_Provider) and
		 * ship a browser gateway file (Provider::script_url()) that registers the same slug.
		 *
		 * @param Provider[] $providers
		 */
		$providers = apply_filters( 'cellon_payment_collection_providers', $built_in );
		foreach ( (array) $providers as $provider ) {
			if ( $provider instanceof Provider ) {
				$this->providers[ strtolower( $provider->slug() ) ] = $provider;
			}
		}
	}

	public function get( string $slug ): ?Provider {
		$slug = strtolower( $slug );
		return $this->providers[ $slug ] ?? null;
	}

	/** Registered, and not switched off through the `cellon_payment_collection_enabled_providers` filter. */
	public function is_enabled( string $slug ): bool {
		$slug = strtolower( $slug );
		if ( $this->enabled === null ) {
			$this->enabled = Config::enabled_providers( array_keys( $this->providers ) );
		}
		$enabled = isset( $this->providers[ $slug ] ) && in_array( $slug, $this->enabled, true );
		return (bool) apply_filters( 'cellon_payment_collection_provider_enabled', $enabled, $slug );
	}

	/** @return array<string,Provider> */
	public function all(): array {
		return $this->providers;
	}

	/** @return array<string,Provider> Registered and enabled. */
	public function enabled(): array {
		$out = [];
		foreach ( $this->providers as $slug => $provider ) {
			if ( $this->is_enabled( $slug ) ) {
				$out[ $slug ] = $provider;
			}
		}
		return $out;
	}

	/**
	 * Today's per-provider API routes of every registered provider.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function api_routes(): array {
		$routes = [];
		foreach ( $this->providers as $slug => $provider ) {
			$routes[ $slug ] = $provider->api_routes();
		}
		return $routes;
	}
}
