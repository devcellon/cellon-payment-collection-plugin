<?php
/**
 * Everything the server needs to run one checkout, resolved on the server when the shortcode renders
 * and stored with the checkout session. The browser never supplies any of it.
 *
 * Input contract (array returned by the `cellon_payment_checkout_context` filter, or built from the
 * `quiz_result_data` session by Context_Resolver):
 *
 *   quiz_result_id                   int|string  required
 *   email                            string      required for first-sale (the address on the quiz result)
 *   currency                         string      ISO 4217, e.g. "USD"
 *   amount                           float       major units; needed by Stripe's deferred card form
 *   payment_mode                     string      as on the quiz result ("live", "sandbox" ...), informational
 *   extra                            array       user_on_iqbooster (default false), prc_id, pricing_discount,
 *                                                ab_testing_price_category: sent with every payment call;
 *                                                price_categories: the quiz result's price_list categories, the
 *                                                only ones a page's plan choice may switch to (see Payment_Orchestrator)
 *   payment_provider_details         array       fallback chain, as the API returns it
 *   direct_payment_provider_details  array       direct providers, as the API returns it
 *                                                (fixed_payment_provider_details accepted as alias)
 *   payment_gateways                 array       legacy list, used only when both lists above are absent
 *   page_url                         string      the checkout page (PayPal return / cancel default, and where
 *                                                the customer lands when the API's redirect_page has no page)
 *   display                          array       price labels for the pay row: first_sale_price_label,
 *                                                first_and_cross_sale_price_label, cross_sale_compulsory (from pricing_info)
 *
 * Redirect pages are not part of the context: they are the quiz-funnel-core plugin's page map
 * (cellonnexus()->quiz->get_redirect_url(), filter quiz_funnel_redirect_page_ids), as for every
 * other step of the funnel.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Checkout;

use Cellon\PaymentCollection\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Checkout_Context {

	const FLOWS = [ 'first-sale', 'cross-sale' ];

	/** An A/B price category as the API names them in pricing_info.price_list ("regular" ...). */
	const CATEGORY_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

	/** @var string */
	public $flow;

	/** @var int|string */
	public $quiz_result_id;

	/** @var string */
	public $email = '';

	/** @var string */
	public $currency = '';

	/** @var float|null */
	public $amount;

	/** @var string */
	public $payment_mode = '';

	/** @var array<string,mixed> */
	public $extra = [];

	/** @var Provider_Config */
	public $providers;

	/** @var string */
	public $page_url = '';

	/** @var array<string,mixed> Price labels from pricing_info (first_sale_price_label, cross_sale_compulsory ...). Display only. */
	public $display = [];

	/** @var array<string,mixed> Server-side parts of the site profile: site, fire_ab_event, success message. */
	public $site = [];

	/**
	 * @param array<string,mixed> $data
	 * @return self|\WP_Error
	 */
	public static function from_array( array $data, string $flow, Provider_Registry $registry ) {
		if ( ! in_array( $flow, self::FLOWS, true ) ) {
			return new \WP_Error( 'invalid_flow', 'Unknown checkout flow: ' . $flow );
		}

		$context       = new self();
		$context->flow = $flow;

		$id = $data['quiz_result_id'] ?? '';
		if ( ! is_scalar( $id ) || (string) $id === '' || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', (string) $id ) ) {
			return new \WP_Error( 'missing_quiz_result', 'quiz_result_id is missing or invalid' );
		}
		$context->quiz_result_id = is_numeric( $id ) && (string) (int) $id === (string) $id ? (int) $id : (string) $id;

		$email = isset( $data['email'] ) && is_string( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		if ( $flow === 'first-sale' && ! is_email( $email ) ) {
			return new \WP_Error( 'missing_email', 'email is missing or invalid' );
		}
		$context->email = $email;

		$context->currency     = isset( $data['currency'] ) && is_string( $data['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', $data['currency'] ) ) : '';
		$context->amount       = isset( $data['amount'] ) && is_numeric( $data['amount'] ) ? (float) $data['amount'] : null;
		$context->payment_mode = isset( $data['payment_mode'] ) && is_scalar( $data['payment_mode'] ) ? sanitize_text_field( (string) $data['payment_mode'] ) : '';

		$extra            = isset( $data['extra'] ) && is_array( $data['extra'] ) ? $data['extra'] : [];
		$context->extra   = [
			'user_on_iqbooster' => ! empty( $extra['user_on_iqbooster'] ),
			'prc_id'            => isset( $extra['prc_id'] ) && is_scalar( $extra['prc_id'] ) ? sanitize_text_field( (string) $extra['prc_id'] ) : '',
			'pricing_discount'  => $extra['pricing_discount'] ?? '',
		];
		if ( ! empty( $extra['ab_testing_price_category'] ) && is_scalar( $extra['ab_testing_price_category'] ) ) {
			$context->extra['ab_testing_price_category'] = sanitize_text_field( (string) $extra['ab_testing_price_category'] );
		}
		$categories = [];
		foreach ( isset( $extra['price_categories'] ) && is_array( $extra['price_categories'] ) ? $extra['price_categories'] : [] as $category ) {
			if ( is_string( $category ) && $category !== '' && preg_match( self::CATEGORY_PATTERN, $category ) ) {
				$categories[] = $category;
			}
		}
		$context->extra['price_categories'] = array_values( array_unique( $categories ) );

		$context->providers = Provider_Config::from_response( $data, $registry );

		$display = isset( $data['display'] ) && is_array( $data['display'] ) ? $data['display'] : [];
		foreach ( [ 'first_sale_price_label', 'first_and_cross_sale_price_label', 'subscription_price_label', 'subscription_day_label', 'cross_sale_price_label' ] as $key ) {
			if ( isset( $display[ $key ] ) && is_scalar( $display[ $key ] ) ) {
				$context->display[ $key ] = wp_strip_all_tags( (string) $display[ $key ] );
			}
		}
		$context->display['cross_sale_compulsory'] = ! empty( $display['cross_sale_compulsory'] );
		$context->display['has_split_payment']     = ! empty( $display['has_split_payment'] );

		$context->page_url = self::url( $data['page_url'] ?? '' );

		return $context;
	}

	/**
	 * URL for an API redirect_page tag from the quiz-funnel-core page map, with ?qid= exactly as the Core
	 * plugin's own payment handlers build it. A tag the map does not know reloads the checkout page, where
	 * the Core plugin's redirect controller sends the customer on to the page the API now asks for.
	 */
	public function redirect_url( string $tag ): string {
		$tag = strtoupper( $tag );
		$url = '';

		if ( $tag !== '' && function_exists( 'cellonnexus' ) ) {
			$core = cellonnexus();
			if ( isset( $core->quiz ) && is_object( $core->quiz ) && method_exists( $core->quiz, 'get_redirect_url' ) ) {
				$url = (string) $core->quiz->get_redirect_url( $tag );
			}
		}
		$url = $url !== '' ? add_query_arg( 'qid', rawurlencode( (string) $this->quiz_result_id ), $url ) : $this->page_url;
		return (string) apply_filters( 'cellon_payment_collection_redirect_url', $url, $tag, $this );
	}

	/** Same-site URLs only, so a context value cannot send the customer elsewhere. */
	private static function url( $value ): string {
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}
		$url  = esc_url_raw( $value, [ 'https', 'http' ] );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host === null ) {
			return $url !== '' ? home_url( $url ) : '';
		}
		return strtolower( (string) $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ? $url : '';
	}

	/**
	 * Fields every payment route takes, all from this server-side context.
	 * Confirm and cross-sale routes reject `email` (422 "email is not allowed"), so it is opt-in.
	 *
	 * @return array<string,mixed>
	 */
	public function payment_fields( bool $with_email ): array {
		$fields = [
			'quiz_result_id'    => $this->quiz_result_id,
			'user_on_iqbooster' => (bool) ( $this->extra['user_on_iqbooster'] ?? false ),
			'prc_id'            => $this->extra['prc_id'] ?? '',
			'pricing_discount'  => $this->extra['pricing_discount'] ?? '',
		];
		if ( ! empty( $this->extra['ab_testing_price_category'] ) ) {
			$fields['ab_testing_price_category'] = $this->extra['ab_testing_price_category'];
		}
		return $with_email ? [ 'email' => $this->email ] + $fields : $fields;
	}

	/**
	 * A plan the page may switch this checkout to: '' (the default price) or one of the quiz result's
	 * price_list categories. Anything else never reaches the API.
	 */
	public function price_category_allowed( string $category ): bool {
		return $category === '' || in_array( $category, (array) ( $this->extra['price_categories'] ?? [] ), true );
	}

	/** The plan every later payment call of this checkout names ('' = the default price, key not sent). */
	public function set_price_category( string $category ): void {
		if ( $category === '' ) {
			unset( $this->extra['ab_testing_price_category'] );
		} else {
			$this->extra['ab_testing_price_category'] = $category;
		}
	}

	/** @return array<string,mixed> Serializable form for the session store. */
	public function to_array(): array {
		return [
			'flow'           => $this->flow,
			'quiz_result_id' => $this->quiz_result_id,
			'email'          => $this->email,
			'currency'       => $this->currency,
			'amount'         => $this->amount,
			'payment_mode'   => $this->payment_mode,
			'extra'          => $this->extra,
			'chain'          => $this->providers->chain,
			'direct'         => $this->providers->direct,
			'source'         => $this->providers->source,
			'page_url'       => $this->page_url,
			'display'        => $this->display,
			'site'           => $this->site,
		];
	}

	/** @param array<string,mixed> $data Output of to_array(). */
	public static function restore( array $data ): self {
		$context                 = new self();
		$context->flow           = (string) $data['flow'];
		$context->quiz_result_id = $data['quiz_result_id'];
		$context->email          = (string) $data['email'];
		$context->currency       = (string) $data['currency'];
		$context->amount         = $data['amount'];
		$context->payment_mode   = (string) $data['payment_mode'];
		$context->extra          = (array) $data['extra'];
		$context->providers         = new Provider_Config();
		$context->providers->chain  = (array) $data['chain'];
		$context->providers->direct = (array) $data['direct'];
		$context->providers->source = (string) $data['source'];
		$context->page_url       = (string) $data['page_url'];
		$context->display        = (array) ( $data['display'] ?? [] );
		$context->site           = (array) ( $data['site'] ?? [] );
		return $context;
	}
}
