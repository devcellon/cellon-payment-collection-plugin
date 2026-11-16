<?php
/**
 * Retires the old payment handlers without editing the Core plugin or the theme.
 *
 * Each guard answers one old entry point before the original handler runs (priority 0, then exit),
 * so the old flow and this plugin can never process the same checkout. All guards are OFF by default:
 * turn one on in Settings → Cellon Payments only after every page that used it shows the shortcode
 * instead (docs/INTEGRATION.md, "Migration").
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Migration;

use Cellon\PaymentCollection\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Legacy_Guard {

	/**
	 * guard id => [ type, target, label ]
	 *
	 * @return array<string,array{type:string, target:string, label:string}>
	 */
	public static function guards(): array {
		return [
			'stripe_intent'     => [ 'type' => 'ajax', 'target' => 'stripe_payment_element_frm', 'label' => 'admin-ajax stripe_payment_element_frm (Core plugin: Stripe intent)' ],
			'stripe_confirm'    => [ 'type' => 'ajax', 'target' => 'stripe_payment_confirm', 'label' => 'admin-ajax stripe_payment_confirm (Core plugin: Stripe confirm)' ],
			'paypal_confirm'    => [ 'type' => 'ajax', 'target' => 'paypal_checkout_api_payment', 'label' => 'admin-ajax paypal_checkout_api_payment (Core plugin: PayPal confirm with payment_status "paid" from the browser)' ],
			'solidgate_confirm' => [ 'type' => 'ajax', 'target' => 'solid_payment_sucess_frm', 'label' => 'admin-ajax solid_payment_sucess_frm (Core plugin: Solidgate confirm)' ],
			'cross_sale'        => [ 'type' => 'ajax', 'target' => 'cross_sale_payment', 'label' => 'admin-ajax cross_sale_payment (Core plugin: offer page)' ],
			'paypal_token_rest' => [ 'type' => 'rest', 'target' => '/paypal/v1/token', 'label' => 'REST /wp-json/paypal/v1/token (iqpro theme: hands a PayPal access token to the browser)' ],
		];
	}

	public function register(): void {
		$active = Config::legacy_guards();
		foreach ( self::guards() as $id => $guard ) {
			if ( ! in_array( $id, $active, true ) ) {
				continue;
			}
			if ( $guard['type'] === 'ajax' ) {
				add_action( 'wp_ajax_' . $guard['target'], [ $this, 'retired_ajax' ], 0 );
				add_action( 'wp_ajax_nopriv_' . $guard['target'], [ $this, 'retired_ajax' ], 0 );
			}
		}
		if ( in_array( 'paypal_token_rest', $active, true ) ) {
			add_filter( 'rest_endpoints', [ $this, 'remove_rest_routes' ], 99 );
		}
	}

	public function retired_ajax(): void {
		// Same keys the old handlers answer with, so old page code shows an error instead of hanging.
		wp_send_json(
			[
				'status'  => 'error',
				'msg'     => __( 'This payment form has been replaced. Please reload the page.', 'cellon-payment-collection' ),
				'message' => __( 'This payment form has been replaced. Please reload the page.', 'cellon-payment-collection' ),
			],
			410
		);
	}

	/**
	 * @param array<string,mixed> $endpoints
	 * @return array<string,mixed>
	 */
	public function remove_rest_routes( array $endpoints ): array {
		unset( $endpoints['/paypal/v1/token'] );
		return $endpoints;
	}
}
