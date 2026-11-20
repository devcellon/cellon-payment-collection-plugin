<?php
/**
 * Finds the checkout context for a shortcode, in this order:
 *
 *   1. `cellon_payment_checkout_context` filter (the documented hook). A theme returns an array in the
 *      Checkout_Context contract.
 *   2. The installed quiz-funnel-core / Cellon-Nexus-Core plugin: cellonnexus()->quiz->get_results( qid ),
 *      the same call the payment templates make today (POST /questions/results). The result is also stored
 *      in $_SESSION['quiz_result_data'], as the templates do. One call per request, shared by every shortcode.
 *   3. $_SESSION['quiz_result_data'] (filled by the Core plugin's redirect controller), when the Core
 *      plugin is not available or its call failed.
 *
 * qid is ?qid= from the URL, else the session's quiz_result_id. A session for another quiz result is never
 * used when the URL names one.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Checkout;

use Cellon\PaymentCollection\Logger;
use Cellon\PaymentCollection\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Context_Resolver {

	/** @var Provider_Registry */
	private $registry;

	/** @var array<string,array<string,mixed>|null> get_results() answers of this request, per qid. */
	private static $results = [];

	public function __construct( Provider_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * @param array<string,string> $atts Shortcode attributes.
	 * @return Checkout_Context|\WP_Error
	 */
	public function resolve( string $flow, array $atts ) {
		/**
		 * Checkout context for the shortcode. Return an array (see Checkout_Context) or null to use the
		 * Core plugin / session.
		 *
		 * @param array|null $context null.
		 * @param string     $flow    first-sale | cross-sale.
		 * @param array      $atts    Shortcode attributes.
		 */
		$data = apply_filters( 'cellon_payment_checkout_context', null, $flow, $atts );

		if ( ! is_array( $data ) ) {
			$result = $this->quiz_result();
			$data   = $result ? self::map_result( $result, $flow ) : null;
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'no_context', 'No quiz result: open the page with ?qid=, or hook cellon_payment_checkout_context.' );
		}

		$data += [
			'page_url'    => $this->current_url(),
		];

		/**
		 * Adjust the resolved context data before validation (any source).
		 *
		 * @param array  $data
		 * @param string $flow
		 * @param array  $atts
		 */
		$data = (array) apply_filters( 'cellon_payment_checkout_context_data', $data, $flow, $atts );

		return Checkout_Context::from_array( $data, $flow, $this->registry );
	}

	/**
	 * The quiz result: Core plugin first, then the session.
	 *
	 * @return array<string,mixed>|null
	 */
	private function quiz_result(): ?array {
		$session = ( session_status() === PHP_SESSION_ACTIVE && isset( $_SESSION['quiz_result_data'] ) && is_array( $_SESSION['quiz_result_data'] ) ) ? $_SESSION['quiz_result_data'] : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only
		$qid = isset( $_GET['qid'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['qid'] ) ) : '';
		if ( $qid === '' && $session ) {
			$qid = (string) ( $session['quiz_result_id'] ?? '' );
		}
		if ( $qid === '' ) {
			return null;
		}

		if ( apply_filters( 'cellon_payment_collection_use_core_results', true ) && function_exists( 'cellonnexus' ) ) {
			if ( ! array_key_exists( $qid, self::$results ) ) {
				self::$results[ $qid ] = null;
				$core = cellonnexus();
				if ( isset( $core->quiz ) && is_object( $core->quiz ) && method_exists( $core->quiz, 'get_results' ) ) {
					$response = $core->quiz->get_results( $qid );
					if ( is_array( $response ) && ! empty( $response['meta']['success'] ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
						self::$results[ $qid ] = $response['data'];
						if ( session_status() === PHP_SESSION_ACTIVE ) {
							$_SESSION['quiz_result_data'] = $response['data']; // same as the payment templates
						}
					} else {
						Logger::log( 'context.core_results_failed', [ 'qid' => $qid ] );
					}
				}
			}
			if ( self::$results[ $qid ] ) {
				return self::$results[ $qid ];
			}
		}

		if ( $session && (string) ( $session['quiz_result_id'] ?? '' ) === $qid ) {
			return $session;
		}
		return null;
	}

	/**
	 * Quiz result (POST /questions/results data) → context data. Same fields the templates read.
	 *
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>|null
	 */
	public static function map_result( array $result, string $flow ): ?array {
		$id = isset( $result['quiz_result_id'] ) ? (string) $result['quiz_result_id'] : '';
		if ( $id === '' ) {
			return null;
		}
		$pricing = isset( $result['pricing_info'] ) && is_array( $result['pricing_info'] ) ? $result['pricing_info'] : [];
		$tx      = isset( $pricing['transactions'] ) && is_array( $pricing['transactions'] ) ? $pricing['transactions'] : [];

		if ( $flow === 'cross-sale' ) {
			$amount = $tx['cross_sale']['amount'] ?? null;
		} else {
			$first  = $tx['first_sale_first_split'] ?? ( $tx['first_sale'] ?? [] );
			$amount = is_array( $first ) && isset( $first['amount'] ) ? $first['amount'] : ( $pricing['first_sale_price'] ?? null );
		}

		// A/B price plans (pricing_info.price_list): the categories a multi-plan page may let the buyer pick
		$categories = [];
		foreach ( isset( $pricing['price_list'] ) && is_array( $pricing['price_list'] ) ? $pricing['price_list'] : [] as $plan ) {
			if ( is_array( $plan ) && isset( $plan['ab_testing_price_category'] ) && is_string( $plan['ab_testing_price_category'] ) && $plan['ab_testing_price_category'] !== '' ) {
				$categories[] = $plan['ab_testing_price_category'];
			}
		}

		$session = session_status() === PHP_SESSION_ACTIVE;
		$data    = [
			'quiz_result_id' => $id,
			'email'          => (string) ( $result['email'] ?? '' ),
			'currency'       => $pricing['currency_code'] ?? '',
			'amount'         => $amount,
			'payment_mode'   => $result['payment_mode'] ?? '',
			'extra'          => [
				'user_on_iqbooster'         => false,
				'prc_id'                    => $session ? ( $_SESSION['prc_id'] ?? '' ) : '',
				// Same shape as the Core plugin's Request_Helper::pricing_discount()
				'pricing_discount'          => [ 'mdid' => $session ? ( $_SESSION['mdid'] ?? '' ) : '' ],
				'ab_testing_price_category' => $session ? ( $_SESSION['ab_testing_price_category'] ?? '' ) : '',
				'price_categories'          => $categories,
			],
			'display'        => $pricing,
		];

		// The provider lists may sit on the quiz result or inside pricing_info.
		foreach ( [ 'payment_provider_details', 'direct_payment_provider_details', 'fixed_payment_provider_details', 'payment_gateways' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
				$data[ $key ] = $result[ $key ];
			} elseif ( isset( $pricing[ $key ] ) && is_array( $pricing[ $key ] ) ) {
				$data[ $key ] = $pricing[ $key ];
			}
		}
		return $data;
	}

	private function current_url(): string {
		$id  = get_queried_object_id();
		$url = $id ? (string) get_permalink( $id ) : home_url( '/' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['qid'] ) ) {
			$url = add_query_arg( 'qid', rawurlencode( sanitize_text_field( wp_unslash( (string) $_GET['qid'] ) ) ), $url );
		}
		return $url;
	}
}
