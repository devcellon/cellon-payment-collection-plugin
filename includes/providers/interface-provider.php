<?php
/**
 * Server-side payment provider handler.
 *
 * A handler owns everything the plugin must know about one provider:
 *   - which fields the browser may send for each step (allow-list) and how to build the canonical API
 *     body from the server-side checkout context plus those fields;
 *   - how to tie a confirm to the intent this checkout created;
 *   - its browser gateway file (assets/js/gateways/<slug>.js), the options that file gets for this site and
 *     page, and the analytics event names per site;
 *   - its per-gateway API routes (today's API), an email its own form collected, and how a redirect back
 *     from the provider (3DS, Alipay) is recognised.
 *
 * Nothing else in the plugin names a provider. Add a provider: one class here (extend Abstract_Provider),
 * registered in Provider_Registry or through the `cellon_payment_collection_providers` filter, plus one
 * browser gateway file that calls CellonPC.registerGateway() with the same slug.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

use Cellon\PaymentCollection\Checkout\Checkout_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Provider {

	/** API slug, lower case, as in `payment_provider_slug` (stripe, paypal, solidgate, bridgerpay, evervault). */
	public function slug(): string;

	public function label(): string;

	/**
	 * Where the provider goes when the API sends only one list: "chain" (automatic fallback) or
	 * "direct" (its own button, loaded independently, e.g. PayPal).
	 */
	public function default_placement(): string;

	/**
	 * Steps this provider runs: intent, confirm, and charge_token for server-side token charges.
	 *
	 * @return string[]
	 */
	public function operations(): array;

	/**
	 * Canonical intent body, or WP_Error when the browser input is invalid.
	 *
	 * @param array<string,mixed> $input Raw browser JSON body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_intent_body( Checkout_Context $context, array $input, ?int $account_id );

	/**
	 * Canonical confirm body. Must never forward a payment result the browser claims
	 * (payment_status, capture ids ...): the API decides whether the payment succeeded.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_confirm_body( Checkout_Context $context, array $input, ?int $account_id );

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_charge_token_body( Checkout_Context $context, array $input, ?int $account_id );

	/**
	 * Provider reference of an intent the API created (Stripe PaymentIntent id, PayPal order id ...),
	 * or null when the provider has none we can compare.
	 *
	 * @param array<string,mixed> $data Intent response data.
	 */
	public function intent_reference( array $data ): ?string;

	/**
	 * Reference the browser confirms or charges. Must match an intent_reference of this checkout.
	 *
	 * @param array<string,mixed> $input
	 */
	public function confirm_reference( array $input ): ?string;

	/**
	 * Intent response data the browser gateway needs (client secret, public key, order id ...).
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function browser_intent_data( array $data ): array;

	/**
	 * URL of the browser gateway file that registers this slug with CellonPC.registerGateway().
	 * Enqueued after assets/js/core.js and before assets/js/checkout.js.
	 */
	public function script_url(): string;

	/**
	 * Options the browser gateway gets (site look, labels, containers). Must hold nothing secret.
	 *
	 * @param array<string,mixed> $env Render environment, see Site_Profile::env() and Shortcode:
	 *                                 site, blog_id, page_id, lang{locale,rtl,solidgate_locale,methods,email_error},
	 *                                 collect_email, country, customer_ip, ids{wallets,direct,response,email},
	 *                                 express_layout (row|stack), entries (chain + direct of this checkout),
	 *                                 context (Checkout_Context).
	 * @return array<string,mixed>
	 */
	public function browser_options( array $env ): array;

	/**
	 * Analytics for this provider on a site, as the site's old theme scripts fired them:
	 *   success      dataLayer event after the API recorded the sale ('' = none)
	 *   failed       dataLayer event once per browser session after a failed payment ('' = none)
	 *   first_sale   analytics.track('first_sale', { amount }) after the sale
	 *   submit_gtag  gtag('event', <name>) when the customer submits the provider's form ('' = none)
	 *
	 * @return array{success:string, failed:string, first_sale:bool, submit_gtag:string}
	 */
	public function site_events( string $site, int $blog_id ): array;

	/**
	 * An email the provider's own form collected (from the confirm input), or ''. Only used when the
	 * checkout collects the customer's email (dummy-email quiz results).
	 *
	 * @param array<string,mixed> $input Confirm body from the browser.
	 */
	public function collected_email( array $input ): string;

	/**
	 * The customer is back from the provider (3DS / Alipay page) on this request: the reference to confirm
	 * and the provider's status, ['status' => 'failed'] when it reports a failure without a reference,
	 * or null when the request is not a return from this provider.
	 *
	 * @param array<string,mixed> $query $_GET.
	 * @return array{reference?:string, status:string}|null
	 */
	public function redirect_return( array $query ): ?array;

	/**
	 * Today's per-provider API routes, relative to the API base URL: intent, confirm, charge_token.
	 * Empty when the provider uses only the common first-sale API.
	 *
	 * @return array<string,string>
	 */
	public function api_routes(): array;
}
