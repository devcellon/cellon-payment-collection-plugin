<?php
/**
 * Wiring.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection;

use Cellon\PaymentCollection\Api\Common_Payment_Api;
use Cellon\PaymentCollection\Api\Current_Payment_Api;
use Cellon\PaymentCollection\Api\Customer_Api;
use Cellon\PaymentCollection\Api\Http_Transport;
use Cellon\PaymentCollection\Api\Payment_Api;
use Cellon\PaymentCollection\Checkout\Context_Resolver;
use Cellon\PaymentCollection\Checkout\Payment_Orchestrator;
use Cellon\PaymentCollection\Checkout\Session_Store;
use Cellon\PaymentCollection\Frontend\Assets;
use Cellon\PaymentCollection\Frontend\Shortcode;
use Cellon\PaymentCollection\Http\Rest_Controller;
use Cellon\PaymentCollection\Migration\Legacy_Guard;
use Cellon\PaymentCollection\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Provider_Registry */
	public $providers;

	/** @var Payment_Api */
	public $api;

	/** @var Payment_Orchestrator */
	public $orchestrator;

	/** @var Session_Store */
	public $sessions;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Flow_Log::register();

		$http               = new Http_Transport();
		$this->providers    = new Provider_Registry();
		$this->api          = $this->make_api( $http );
		$this->sessions     = new Session_Store();
		$this->orchestrator = new Payment_Orchestrator( $this->api, $this->providers, $this->sessions, new Customer_Api( $http ) );

		$assets = new Assets( $this->providers );
		$assets->register();
		( new Shortcode( new Context_Resolver( $this->providers ), $this->sessions, $assets, $this->providers ) )->register();
		// The routes stay registered while the switch is off: they only serve checkout tokens a rendered page
		// created, so a checkout opened before the switch went off can still finish.
		( new Rest_Controller( $this->orchestrator ) )->register();

		// Guards retire the old handlers for the whole site, so only when no language uses the old code.
		// On init: the switch is an ACF value, read after WordPress has loaded.
		add_action(
			'init',
			static function () {
				if ( Settings::enabled_everywhere() ) {
					( new Legacy_Guard() )->register();
				}
			}
		);

		if ( is_admin() ) {
			add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		}
	}

	/**
	 * The API adapter. A future API version plugs in here without touching shortcode, REST or providers:
	 *
	 *   add_filter( 'cellon_payment_collection_payment_api', fn( $api, $http ) => new My_V2_Payment_Api( $http ), 10, 2 );
	 */
	private function make_api( Http_Transport $http ): Payment_Api {
		$routes = $this->providers->api_routes();
		$api    = Config::api_adapter() === Config::API_COMMON ? new Common_Payment_Api( $http, $routes ) : new Current_Payment_Api( $http, $routes );

		$filtered = apply_filters( 'cellon_payment_collection_payment_api', $api, $http );
		return $filtered instanceof Payment_Api ? $filtered : $api;
	}

	/**
	 * The plugin's own setting is the on/off switch (ACF page, Settings → Payment Collection);
	 * the API URL, secret and redirect pages are the quiz-funnel-core plugin's.
	 */
	public function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Settings::acf_active() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( 'Cellon Payment Collection is switched on and off on an ACF options page. Activate Secure Custom Fields or Advanced Custom Fields PRO; until then the theme\'s own payment code runs.' ) . '</p></div>';
			return;
		}
		// Off = the theme's old code runs, which does not need this plugin's API connection.
		if ( Config::is_configured() || ! Settings::switched_on() ) {
			return;
		}
		$text = Config::core_active()
			? 'Cellon Payment Collection: save the API secret in the quiz-funnel-core settings. Payments are off until then.'
			: 'Cellon Payment Collection needs the quiz-funnel-core plugin (API URL, secret, redirect pages). Payments are off until it is active.';
		echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p></div>';
	}

	/** For theme templates that echo do_shortcode() late: call before any output. */
	public function nocache(): void {
		Shortcode::nocache();
	}

	/**
	 * Template helper: echo cellon_payment_collection()->render( [ 'flow' => 'first-sale' ] );
	 *
	 * @param array<string,string> $atts
	 */
	public function render( array $atts = [] ): string {
		$pairs = '';
		foreach ( $atts as $key => $value ) {
			$pairs .= ' ' . sanitize_key( (string) $key ) . '="' . esc_attr( (string) $value ) . '"';
		}
		return do_shortcode( '[' . Shortcode::TAG . $pairs . ']' );
	}
}
