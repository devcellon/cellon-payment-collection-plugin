<?php
/**
 * Solidgate payment form (card form, or its Google Pay / Apple Pay button only).
 *
 * Intent: the API returns merchantId, signature and paymentIntent; nothing account-specific is in the
 * site. Confirm: the browser sends Solidgate's success event (`payment_obj`), the same field the current
 * `solid_payment_sucess_frm` handler forwards. See docs/BACKEND-CONTRACTS.md: the API must read the
 * order status from Solidgate itself and not trust `payment_obj`.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Solidgate_Provider extends Abstract_Provider {

	public function slug(): string {
		return 'solidgate';
	}

	public function label(): string {
		return 'Solidgate';
	}

	/** Payment-page gtag('PaymentPage') on Solidgate submit, on these blogs (as the themes' solidgate.js). */
	const PAYMENT_PAGE_GTAG_BLOGS = [ 10, 20 ];

	protected function confirm_fields(): array {
		return [
			'payment_obj' => [ 'type' => 'object', 'required' => true ],
		];
	}

	public function api_routes(): array {
		return [
			'intent'  => '/payment/solidgate/create-payment-intent',
			'confirm' => '/payment/solidgate/first-sale/payments/confirm',
		];
	}

	/**
	 * The form's own email field (themes' solidgate.js: success event order.customer_email). Returned to
	 * the browser, which sends it to this plugin's …/checkouts/<token>/email (PUT /customer/update-customer-email).
	 */
	public function collected_email( array $input ): string {
		$obj = isset( $input['payment_obj'] ) && is_array( $input['payment_obj'] ) ? $input['payment_obj'] : [];
		return self::real_email( $obj['order']['customer_email'] ?? '' );
	}

	public function site_events( string $site, int $blog_id ): array {
		$events                = parent::site_events( $site, $blog_id );
		$events['first_sale']  = $site !== 'iqpro'; // iqpro's solidgate.js sends no analytics first_sale
		$events['submit_gtag'] = in_array( $blog_id, self::PAYMENT_PAGE_GTAG_BLOGS, true ) ? 'PaymentPage' : '';
		return $events;
	}

	/** The themes' solidgate.js form look; language and direction from the template's attributes. */
	public function browser_options( array $env ): array {
		$lang    = (array) ( $env['lang'] ?? [] );
		$rtl     = ! empty( $lang['rtl'] );
		$page_id = (int) ( $env['page_id'] ?? 0 );
		$ids     = (array) ( $env['ids'] ?? [] );
		$acf     = [ \Cellon\PaymentCollection\Site\Site_Profile::class, 'acf' ];

		$solid_input = [ 'input' => [ 'background' => '#FFFF', '::placeholder' => [ 'color' => '#888' ] ], '.label' => [ 'display' => 'none' ] ];
		$options     = [
			'googlePayButtonParams' => [ 'color' => 'Black', 'type' => 'plain', 'allowedAuthMethods' => [ 'PAN_ONLY', 'CRYPTOGRAM_3DS' ] ],
			'formParams'            => [
				'allowSubmit'           => true,
				'buttonType'            => 'default',
				'isCardHolderVisible'   => false,
				'hideCvvNumbers'        => true,
				'headerText'            => 'Enter your debit or credit card details (from merchant)',
				'titleText'             => 'Card info (from merchant)',
				'formTypeClass'         => 'default',
				'autoFocus'             => false,
				'cardNumberPlaceholder' => $acf( 'credit_card_placeholder', $page_id, 'Card Number' ),
				'submitButtonText'      => $acf( 'get_result_button__label', $page_id, 'GET MY IQ RESULT' ),
			],
			'styles'                => [
				'card_number'   => $solid_input + [ 'i' => [ 'top' => '1em !important' ] ],
				'card_cvv'      => $solid_input + [ '.tooltip-icon' => [ 'top' => '1em !important' ], '.tooltip' => [ 'top' => '1em !important' ] ],
				'expiry_date'   => $solid_input,
				'submit_button' => [ 'background-color' => '#f81', 'border-radius' => '0 15px', 'height' => 'auto', '.title' => [ 'color' => '#121212' ], '.title-icon' => [ 'display' => 'none' ] ],
				'email'         => [ 'order' => '-1' ] + $solid_input,
			],
		];
		if ( ! empty( $ids['wallets'] ) ) {
			$options['walletContainerId'] = (string) $ids['wallets'];
		}
		$locale = (string) ( $lang['solidgate_locale'] ?? '' );
		if ( $locale !== '' ) {
			$options['formParams'] += [ 'locale' => $locale, 'language' => strtolower( (string) strtok( $locale, '-' ) ) ];
		}
		if ( $rtl ) {
			$options['formParams'] += [ 'direction' => 'rtl' ];
			$options['styles']['form_body']   = [ 'direction' => 'rtl', 'text-align' => 'right' ];
			$options['styles']['input_group'] = [ 'direction' => 'rtl', 'text-align' => 'right' ];
			$options['styles']['header']      = [ 'direction' => 'rtl', 'text-align' => 'right' ];
			$options['styles']['card_cvv']    = $solid_input + [
				'.tooltip-icon' => [ 'top' => '1em !important', 'right' => 'auto !important', 'left' => '0.5em !important' ],
				'.tooltip'      => [ 'top' => '1em !important', 'right' => 'auto !important', 'left' => '0 !important', 'direction' => 'rtl !important' ],
			];
		}
		return $options;
	}
}
