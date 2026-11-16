<?php
/**
 * Scripts and styles, enqueued only by a rendered shortcode. Plain JavaScript files of this plugin, no
 * build step:
 *
 *   assets/js/core.js            window.CellonPC: gateway registry, REST client, SDK loader, failover policy
 *   assets/js/sequence.js        CellonPC.Sequence: one provider at a time, in-place fallback
 *   assets/js/gateways/<slug>.js one per provider (Provider::script_url()), registers itself with the core
 *   assets/js/checkout.js        page code: boxes, email field, express row, analytics, redirects
 *
 * WordPress loads them in that order through script dependencies. Provider SDKs (Stripe.js, Solidgate,
 * PayPal, BridgerPay) are loaded by the gateway files from the providers' own URLs when a gateway mounts.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Frontend;

use Cellon\PaymentCollection\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	const CORE     = 'assets/js/core.js';
	const SEQUENCE = 'assets/js/sequence.js';
	const CHECKOUT = 'assets/js/checkout.js';
	const STYLE    = 'assets/css/checkout.css';

	/** @var Provider_Registry */
	private $providers;

	public function __construct( Provider_Registry $providers ) {
		$this->providers = $providers;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
	}

	public function register_assets(): void {
		wp_register_script( 'cellon-pc-core', CELLON_PC_URL . self::CORE, [], self::version( self::CORE ), true );
		wp_register_script( 'cellon-pc-sequence', CELLON_PC_URL . self::SEQUENCE, [ 'cellon-pc-core' ], self::version( self::SEQUENCE ), true );

		$deps = [ 'cellon-pc-core', 'cellon-pc-sequence' ];
		foreach ( $this->providers->enabled() as $slug => $provider ) {
			$url = $provider->script_url();
			if ( $url === '' ) {
				continue;
			}
			$handle = 'cellon-pc-gateway-' . $slug;
			wp_register_script( $handle, $url, [ 'cellon-pc-core' ], self::version_for_url( $url ), true );
			$deps[] = $handle;
		}

		wp_register_script( 'cellon-pc-checkout', CELLON_PC_URL . self::CHECKOUT, $deps, self::version( self::CHECKOUT ), true );
		wp_register_style( 'cellon-pc-checkout', CELLON_PC_URL . self::STYLE, [], self::version( self::STYLE ) );
	}

	/**
	 * @param array<string,string> $messages
	 */
	public function enqueue( array $messages ): void {
		if ( ! wp_script_is( 'cellon-pc-checkout', 'registered' ) ) {
			$this->register_assets();
		}
		wp_enqueue_style( 'cellon-pc-checkout' );
		wp_enqueue_script( 'cellon-pc-checkout' );

		static $localized = false;
		if ( ! $localized ) {
			wp_localize_script( 'cellon-pc-checkout', 'cellonPaymentCollection', [ 'messages' => $messages ] );
			$localized = true;
		}
	}

	/** Plugin version plus mtime, so a changed file busts caches without a version bump. */
	private static function version( string $file ): string {
		$mtime = @filemtime( CELLON_PC_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return CELLON_PC_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	/** A gateway file of this plugin gets its mtime too; one shipped elsewhere the plugin version. */
	private static function version_for_url( string $url ): string {
		if ( strpos( $url, CELLON_PC_URL ) === 0 ) {
			return self::version( substr( $url, strlen( CELLON_PC_URL ) ) );
		}
		return CELLON_PC_VERSION;
	}
}
