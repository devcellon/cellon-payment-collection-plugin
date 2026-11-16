<?php
/**
 * [cellon_payment_checkout flow="first-sale"]
 * [cellon_payment_checkout flow="cross-sale"]
 *
 * Renders the inner payment block of the themes' payment card, with their class names and ids
 * (.payment-methods, .express-checkout-wrapper with #gpay_btn and #paypal-button-container,
 * .line-group, #paymentResponse, .pprice with the price and the orange pay button), so the existing
 * theme CSS (style.css, css/newpayment_popup.css) styles it unchanged. The wrapper around it stays in the
 * template: the always-open payment dialog on Quiz Payment (.pmp / #IQpaymentModal) or the inline
 * #iq-report section on the checkout templates.
 *
 * Attributes (all optional)
 *   flow           first-sale | cross-sale                                   default first-sale
 *   direct         show | hide        direct providers (PayPal)              default show
 *   pay_label      Stripe / BLOX pay button                                  default ACF get_result_button__label
 *   pay_class      classes of the pay button                                 default "orange-btn big float-right"
 *   price          1 | 0              show the price line next to the pay button   default 1
 *   separator      text between wallets/PayPal and the card form             default "Or Pay with Card"
 *   express_layout row | stack        wallets + PayPal: side by side (one per row when narrow, two
 *                                     wallets get a row of their own) | every button a full-width row  default row
 *   class          extra classes on the wrapper
 *   switch_on      all | fixable | soft   which failures move to the next provider   default all
 *   last_gateway   fail | retry       failure on the last provider           default fail
 *   max_switches   gateway switches after a declined payment: 1 = one fallback (Stripe → Solidgate), then
 *                  the customer stays on it with the message                         default 1
 *                  (a gateway that fails to load is always skipped: that is not a decline)
 *   switch_delay   ms the failed provider stays visible before the next mounts     default 1500
 *   theme_ids      1 | 0              use the themes' element ids (#gpay_btn …)    default 1
 *   accept_label / skip_label / show_skip   cross-sale buttons
 *
 * Language attributes: the template decides them; the plugin has no language rules of its own
 *   locale            Stripe Elements locale ("ja", "zh-HK")                     default auto
 *   direction         ltr | rtl                                                   default ltr
 *   solidgate_locale  Solidgate form locale ("ar-SA")                            default Solidgate's own
 *   payment_methods   Stripe methods, comma separated ("card,alipay")            default card
 *   email_error       invalid-email message                                       default ACF email_collection_error_msg
 *   collect_email     auto | 1 | 0   ask for the customer's email on the page    default auto (site rule)
 *
 * Redirects after payment are the quiz-funnel-core page map; there is no URL attribute.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Frontend;

use Cellon\PaymentCollection\Checkout\Checkout_Context;
use Cellon\PaymentCollection\Checkout\Context_Resolver;
use Cellon\PaymentCollection\Checkout\Provider_Config;
use Cellon\PaymentCollection\Checkout\Session_Store;
use Cellon\PaymentCollection\Config;
use Cellon\PaymentCollection\Http\Rest_Controller;
use Cellon\PaymentCollection\Logger;
use Cellon\PaymentCollection\Providers\Provider_Registry;
use Cellon\PaymentCollection\Site\Message_Texts;
use Cellon\PaymentCollection\Site\Site_Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Shortcode {

	const TAG = 'cellon_payment_checkout';

	/** @var Context_Resolver */
	private $resolver;

	/** @var Session_Store */
	private $store;

	/** @var Assets */
	private $assets;

	/** @var Provider_Registry */
	private $providers;

	/** @var int Rendered first-sale checkouts on this page (only the first one gets the theme ids). */
	private $rendered = 0;

	public function __construct( Context_Resolver $resolver, Session_Store $store, Assets $assets, Provider_Registry $providers ) {
		$this->resolver  = $resolver;
		$this->store     = $store;
		$this->assets    = $assets;
		$this->providers = $providers;
	}

	/**
	 * Providers this checkout lists, by slug.
	 *
	 * @return array<string,\Cellon\PaymentCollection\Providers\Provider>
	 */
	private function checkout_providers( Checkout_Context $context ): array {
		$out = [];
		foreach ( $context->providers->slugs() as $slug ) {
			$provider = $this->providers->get( $slug );
			if ( $provider && $this->providers->is_enabled( $slug ) ) {
				$out[ $slug ] = $provider;
			}
		}
		return $out;
	}

	public function register(): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
		add_action( 'template_redirect', [ $this, 'maybe_nocache' ] );
	}

	public function maybe_nocache(): void {
		$post = get_queried_object();
		if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::TAG ) ) {
			self::nocache();
		}
	}

	/** Also callable from a theme template before output: cellon_payment_collection()->nocache(). */
	public static function nocache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'flow'         => 'first-sale',
				'direct'       => 'show',
				'pay_label'    => '',
				'pay_class'    => '',
				'price'        => '1',
				'separator'    => '',
				'express_layout' => 'row',
				'class'        => '',
				'switch_on'    => 'all',
				'last_gateway' => 'fail',
				'max_switches' => '1',
				'switch_delay' => '1500',
				'theme_ids'    => '1',
				'accept_label' => __( 'Yes, add it', 'cellon-payment-collection' ),
				'skip_label'   => __( 'No, thanks', 'cellon-payment-collection' ),
				'show_skip'    => '1',
				'locale'           => '',
				'direction'        => '',
				'solidgate_locale' => '',
				'payment_methods'  => '',
				'email_error'      => '',
				'collect_email'    => 'auto',
			],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);
		$flow = in_array( $atts['flow'], Checkout_Context::FLOWS, true ) ? $atts['flow'] : 'first-sale';

		self::nocache();

		if ( ! Config::is_configured() ) {
			return $this->problem( 'not_configured', 'The quiz-funnel-core plugin is not active, or its API secret is not saved.' );
		}

		$context = $this->resolver->resolve( $flow, $atts );
		if ( is_wp_error( $context ) ) {
			do_action( 'cellon_payment_collection_context_missing', $context, $flow, $atts );
			return $this->problem( $context->get_error_code(), $context->get_error_message() );
		}
		if ( $flow === 'first-sale' && ! $context->providers->chain && ! $context->providers->direct ) {
			return $this->problem( 'no_providers', 'The API listed no provider this plugin can run (source: ' . $context->providers->source . ', skipped: ' . wp_json_encode( $context->providers->skipped ) . ').' );
		}

		$profile       = Site_Profile::build( $context, $atts, $this->checkout_providers( $context ) );
		$context->site = [
			'site'            => $profile['site'],
			'fire_ab_event'   => (bool) $profile['fire_ab_event'],
			'collect_email'   => (bool) $profile['collect_email'],
			'success_message' => (string) $profile['labels']['success'],
		];

		$session = $this->store->create( $context );
		$this->assets->enqueue( self::messages() );

		return $flow === 'cross-sale'
			? $this->cross_sale_markup( $session->token, $atts, $profile )
			: $this->first_sale_markup( $session->token, $context, $atts, $profile );
	}

	/**
	 * @param array<string,string> $atts
	 * @param array<string,mixed>  $profile
	 */
	private function first_sale_markup( string $token, Checkout_Context $context, array $atts, array $profile ): string {
		$this->rendered++;
		$theme_ids = $atts['theme_ids'] === '1' && $this->rendered === 1;
		$uid       = 'cpc' . $this->rendered;
		// Theme ids: the wallet container (#gpay_btn), the first direct provider's button
		// (#paypal-button-container) and the notice line (#paymentResponse), so the theme CSS styles them
		$ids       = [
			'wallets'  => $theme_ids ? 'gpay_btn' : $uid . '-wallets',
			'direct'   => $theme_ids ? 'paypal-button-container' : $uid . '-direct',
			'response' => $theme_ids ? 'paymentResponse' : $uid . '-response',
			'email'    => $uid . '-email',
		];

		$chain  = $context->providers->chain;
		$direct = $atts['direct'] === 'hide' ? [] : $context->providers->direct;
		$max    = $atts['max_switches'] !== '' ? max( 0, (int) $atts['max_switches'] ) : max( 0, count( $chain ) - 1 );
		$stack  = $atts['express_layout'] === 'stack';

		// Each provider's browser options for this site, page and instance
		$env = (array) $profile['env'] + [
			'ids'            => $ids,
			'express_layout' => $stack ? 'stack' : 'row',
			'entries'        => array_merge( $chain, $direct ),
			'context'        => $context,
		];
		$options = [];
		foreach ( $this->checkout_providers( $context ) as $slug => $provider ) {
			$options[ $slug ] = $provider->browser_options( $env );
		}
		/**
		 * Final browser options per provider (keyed by slug).
		 *
		 * @param array            $options
		 * @param Checkout_Context $context
		 * @param array            $atts
		 */
		$options = (array) apply_filters( 'cellon_payment_collection_adapter_options', $options, $context, $atts );

		$labels = (array) $profile['labels'];
		if ( $atts['pay_label'] !== '' ) {
			$labels['pay'] = $atts['pay_label'];
		}
		if ( $atts['separator'] !== '' ) {
			$labels['separator'] = $atts['separator'];
		}

		$config = [
			'flow'         => 'first-sale',
			'rest'         => rest_url( Rest_Controller::NAMESPACE_V1 . '/checkouts/' . $token ),
			'chain'        => Provider_Config::for_browser( $chain ),
			'checkout'     => [
				'currency' => $context->currency !== '' ? $context->currency : null,
				'amount'   => $context->amount,
			],
			'options'      => $options ?: new \stdClass(),
			'policy'       => [
				'maxDeclineSwitches' => $max,
				'switchOn'           => in_array( $atts['switch_on'], [ 'soft', 'fixable', 'all' ], true ) ? $atts['switch_on'] : 'all',
				'lastGatewayDecline' => $atts['last_gateway'] === 'retry' ? 'retry' : 'fail',
				'switchDelayMs'      => max( 0, (int) $atts['switch_delay'] ),
			],
			'successUrl'   => $context->redirect_url( '' ),
			'quizResultId' => (string) $context->quiz_result_id, // analytics keys (first_sale_fronted_<id>), as the themes use it
			'site'         => [
				'site'         => $profile['site'],
				'blogId'       => $profile['blog_id'],
				'collectEmail' => (bool) $profile['collect_email'],
				// "Processing…" notice line while paying; the pay button shows the processing label either way
				'processingNotice'   => (bool) ( $profile['processing_notice'] ?? true ),
				'emailNonce'   => $profile['collect_email'] ? wp_create_nonce( 'email_info_frm_nonce' ) : '',
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'events'       => $profile['events'],
				'labels'       => $labels,
				'preloader'    => $profile['preloader'],
				'ids'          => $ids,
			],
			'resume'       => $this->resume(),
		];

		$messages  = self::messages();
		$pay_class = $atts['pay_class'] !== '' ? $atts['pay_class'] : (string) $profile['pay_class'];
		$price     = $atts['price'] === '1' ? (string) $labels['price'] : '';

		// Express row: wallets of the active card provider and the direct providers (PayPal)
		$direct_html = '';
		foreach ( array_values( $direct ) as $index => $entry ) {
			$direct_html .= $this->box( 'direct', (string) $index, $messages, $pay_class, $labels['pay'], '', Provider_Config::for_browser( [ $entry ] ), $index === 0 ? $ids['direct'] : '' );
		}
		// The plugin lays this row out itself (assets/css/checkout.css), whatever the theme's old
		// #gpay_btn / #paypal-button-container rules say
		$express = '<div class="express-checkout-wrapper cpc-express cpc-express--' . ( $stack ? 'stack' : 'row' ) . '">'
			. ( $chain ? '<div id="' . esc_attr( $ids['wallets'] ) . '" class="cpc-wallets"></div>' : '' )
			. $direct_html
			. '</div>';

		$email = '';
		if ( $profile['collect_email'] && $chain ) {
			$email = '<div class="cpc-email" data-cpc-email hidden>'
				. '<label class="cpc-email__label" for="' . esc_attr( $ids['email'] ) . '">' . esc_html( $labels['email'] ) . '</label>'
				. '<input type="email" id="' . esc_attr( $ids['email'] ) . '" class="cpc-email__input form-control" autocomplete="email" inputmode="email" placeholder="' . esc_attr( $labels['email'] ) . '">'
				. '</div>';
		}

		$chain_html = '';
		if ( $chain ) {
			// Hidden by checkout.js while the express row above shows nothing (no wallet, no PayPal)
			$chain_html = '<div class="line-group cpc-line"><span>' . esc_html( $labels['separator'] ) . '</span></div>'
				. $email
				. $this->box( 'chain', '', $messages, $pay_class, $labels['pay'], $price );
		}

		return '<div class="' . esc_attr( $this->classes( 'cpc-checkout payment-methods ' . $atts['class'] ) ) . '" data-cpc-checkout="' . esc_attr( (string) wp_json_encode( $config ) ) . '" data-flow="first-sale">'
			. '<div class="payment-methods-select"><div class="wpf_multi_form_controls wpf_input_content">'
			. $express
			. $chain_html
			. '</div></div>'
			. '<div class="payment-option mt-4"><div id="' . esc_attr( $ids['response'] ) . '" class="cpc-response" role="alert"></div></div>'
			. '</div>';
	}

	/**
	 * One provider box. The notice goes to the shared #paymentResponse; the box keeps its own states.
	 *
	 * @param array<string,string>                $messages
	 * @param array<int,array<string,mixed>>|null $details Direct box: its one provider.
	 */
	private function box( string $kind, string $index, array $messages, string $pay_class, string $pay_label, string $price, ?array $details = null, string $host_id = '' ): string {
		$data = $details !== null ? ' data-cpc-details="' . esc_attr( (string) wp_json_encode( $details ) ) . '" data-provider="' . esc_attr( (string) $details[0]['payment_provider_slug'] ) . '"' : '';
		$pay  = trim( 'cpc-pay cpc-pay--custom ' . $this->classes( $pay_class ) );

		return '<div class="cpc-box cpc-box--' . esc_attr( $kind ) . '" data-cpc-box="' . esc_attr( $kind ) . '" data-index="' . esc_attr( $index ) . '" data-status="idle"' . $data . '>'
			. '<div class="cpc-host"' . ( $host_id !== '' ? ' id="' . esc_attr( $host_id ) . '" style="position: relative;"' : '' ) . '></div>'
			. '<div class="pprice cpc-actions" hidden>'
			. ( $price !== '' ? '<span class="price">' . esc_html( $price ) . '</span>' : '' )
			. '<button type="button" class="' . esc_attr( $pay ) . '" hidden><span class="wpf_txt_normal cpc-pay__label">' . esc_html( $pay_label ) . '</span></button>'
			. '</div>'
			. '<div class="cpc-verify" hidden>'
			. '<button type="button" class="cpc-verify-again">' . esc_html( $messages['verify_again'] ) . '</button>'
			. '<button type="button" class="cpc-retry">' . esc_html( $messages['pay_again'] ) . '</button>'
			. '</div>'
			. '</div>';
	}

	/**
	 * Back from a provider's redirect (3DS page, Alipay): each provider recognises its own return
	 * (Provider::redirect_return()). The checkout that created the intent confirms it; a new page view gets
	 * a new token, so look the old one up by the provider reference.
	 *
	 * @return array<string,string>|null
	 */
	private function resume(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the old checkout's token and reference binding authorize it
		$query  = is_array( $_GET ) ? $_GET : [];
		$failed = null;
		foreach ( $this->providers->enabled() as $slug => $provider ) {
			$return = $provider->redirect_return( $query );
			if ( ! is_array( $return ) ) {
				continue;
			}
			$status    = (string) ( $return['status'] ?? '' );
			$reference = (string) ( $return['reference'] ?? '' );
			$token     = $reference !== '' ? $this->store->token_for_reference( $slug, $reference ) : null;
			if ( $token === null || ! $this->store->load( $token ) ) {
				if ( $status === 'failed' ) {
					$failed = [ 'status' => 'failed', 'provider' => $slug ];
				}
				continue;
			}
			return [
				'rest'      => rest_url( Rest_Controller::NAMESPACE_V1 . '/checkouts/' . $token ),
				'provider'  => $slug,
				'reference' => $reference,
				'status'    => $status,
			];
		}
		return $failed;
	}

	/**
	 * @param array<string,string> $atts
	 * @param array<string,mixed>  $profile
	 */
	private function cross_sale_markup( string $token, array $atts, array $profile ): string {
		$config = [
			'flow'         => 'cross-sale',
			'rest'         => rest_url( Rest_Controller::NAMESPACE_V1 . '/checkouts/' . $token ),
			'quizResultId' => '',
			'site'         => [ 'preloader' => $profile['preloader'], 'labels' => $profile['labels'], 'events' => $profile['events'] ],
		];
		$pay    = trim( 'cpc-accept ' . $this->classes( $atts['pay_class'] !== '' ? $atts['pay_class'] : 'orange-btn big' ) );

		return '<div class="' . esc_attr( $this->classes( 'cpc-checkout cpc-cross-sale ' . $atts['class'] ) ) . '" data-cpc-checkout="' . esc_attr( (string) wp_json_encode( $config ) ) . '" data-flow="cross-sale" data-status="idle">'
			. '<div class="cpc-notice" role="alert" hidden></div>'
			. '<button type="button" class="' . esc_attr( $pay ) . '">' . esc_html( $atts['accept_label'] ) . '</button>'
			. ( $atts['show_skip'] === '1' ? '<button type="button" class="cpc-skip">' . esc_html( $atts['skip_label'] ) . '</button>' : '' )
			. '</div>';
	}

	/** Customers see a neutral message; administrators also see the reason. */
	private function problem( string $code, string $detail ): string {
		Logger::log( 'render.problem', [ 'code' => $code, 'detail' => $detail ] );
		$html = '<div class="cpc-checkout cpc-checkout--unavailable" data-status="failed"><p class="cpc-unavailable">' . esc_html( self::messages()['unavailable'] ) . '</p>';
		if ( current_user_can( 'manage_options' ) ) {
			$html .= '<p class="cpc-admin-note"><strong>Cellon Payment Collection (' . esc_html( $code ) . '):</strong> ' . esc_html( $detail ) . '</p>';
		}
		return $html . '</div>';
	}

	private function classes( string $value ): string {
		return implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', $value ) ?: [] ) ) );
	}

	/** Customer messages in the page's language (Message_Texts; English when it has none). @return array<string,string> */
	public static function messages(): array {
		$translated = Message_Texts::for_locale();
		unset( $translated['separator'] );
		return (array) apply_filters(
			'cellon_payment_collection_messages',
			$translated + [
				'next_gateway' => __( 'Your payment could not be processed. Please enter your card details again to complete the payment.', 'cellon-payment-collection' ),
				'retry_same'   => __( 'Your payment was declined. Please check your card details or use another card.', 'cellon-payment-collection' ),
				'stop'         => __( 'Your payment was declined. Please contact your bank or use another card.', 'cellon-payment-collection' ),
				'verify'       => __( 'We are checking the status of your payment. Please do not pay again.', 'cellon-payment-collection' ),
				'unavailable'  => __( 'Payment is not available right now. Please try again in a few minutes.', 'cellon-payment-collection' ),
				'failed'       => __( 'Your payment could not be completed. Please try again later or contact support.', 'cellon-payment-collection' ),
				'verify_again' => __( 'Check again', 'cellon-payment-collection' ),
				'pay_again'    => __( 'It was not charged: pay again', 'cellon-payment-collection' ),
				'processing'   => __( 'Processing your payment… Please wait.', 'cellon-payment-collection' ),
				'busy'         => __( 'A payment is being processed. Please wait.', 'cellon-payment-collection' ),
				'expired'      => __( 'This checkout has expired. Please reload the page.', 'cellon-payment-collection' ),
			]
		);
	}
}
