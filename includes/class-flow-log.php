<?php
/**
 * Payment flow log for review, in its own text files (never the PHP error log / debug.log), one per quiz result:
 *   wp-content/cellon-payment-logs/<provider>-flow-quiz-<quiz result id>.txt (times in UTC)
 *
 * For each quiz result paid with a logged provider it records, in order, everything sent to and received
 * from the Cellon API until the cross sale:
 *
 *   before the payment    the API calls for that quiz result just before the provider was chosen (e.g. the
 *                         quiz-result load that gave the checkout its PayPal account). They are held back
 *                         (last 8, 2 hours) and written when the provider's first API call starts the flow.
 *   browser → WordPress   what the checkout page posted to this plugin's REST route (intent / confirm)
 *   WordPress → API       route, headers (Authorization removed), JSON body, HTTP status, JSON answer, time
 *   WordPress → browser   what this plugin answered the page (status, redirect, message)
 *   Offers page           every API call for the quiz result (quiz-result load, cross-sale confirm), the
 *                         page's admin-ajax post (Core plugin action cross_sale_payment) and its JSON answer.
 *                         The flow ends with that answer (cross sale bought or skipped).
 *
 * API calls are caught through WordPress's http_api_debug hook, so the Core plugin's calls are logged too.
 *
 * Off by default. Switch on in wp-config.php (comma list of provider slugs, or true for every provider):
 *   define( 'CELLON_PAYMENT_FLOW_LOG', 'paypal' );
 * or with a filter:
 *   add_filter( 'cellon_payment_collection_flow_log', fn() => [ 'paypal' ] );
 * Admin-ajax actions that end a flow: filter cellon_payment_collection_flow_log_ajax
 * ( [ action => POST field holding the quiz result id ], default [ 'cross_sale_payment' => 'quiz_data_id' ] ).
 *
 * The files hold customer emails and order ids, so keep it off in production. The folder gets a .htaccess
 * deny rule and an index.php; on nginx, deny /wp-content/cellon-payment-logs/ in the server config.
 * Writing never interrupts a payment: every failure here is swallowed.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Flow_Log {

	const DIR = 'cellon-payment-logs';

	/** Keys whose values never reach the file (headers and bodies, any depth, case-insensitive). `card`, `wallet`, `network_token`, `cryptogram`: encrypted card / wallet data (Evervault). */
	const REDACT = [ 'authorization', 'api_token', 'token', 'secret', 'client_secret', 'signature', 'paymentintent', 'cashier_token', 'credit_card_token', 'encrypted_cvv', 'payment_obj', 'card', 'wallet', 'network_token', 'cryptogram', 'nonce', '_wpnonce', 'security' ];

	/** API calls held per quiz result before its flow starts. */
	const PRE_LIMIT = 8;

	/** Largest held-back entry, in bytes of JSON (bigger answers are cut). */
	const PRE_MAX_BYTES = 65536;

	/** @var array{slug:string, checkout:string, qid:string}|null The REST step being served. */
	private static $current = null;

	/** @var array{title:string, data:array<string,mixed>}|null Browser step, written once the quiz id is known. */
	private static $pending = null;

	/** @var array{slug:string, qid:string, action:string, output:string}|null The admin-ajax step being served. */
	private static $ajax = null;

	/** @var array<string,float> Start time per API URL. */
	private static $started = [];

	public static function register(): void {
		if ( ! self::providers() ) {
			return;
		}
		add_filter( 'pre_http_request', [ self::class, 'start' ], 10, 3 );
		add_action( 'http_api_debug', [ self::class, 'http' ], 10, 5 );

		$actions = apply_filters( 'cellon_payment_collection_flow_log_ajax', [ 'cross_sale_payment' => 'quiz_data_id' ] );
		foreach ( is_array( $actions ) ? $actions : [] as $action => $qid_field ) {
			$action    = sanitize_key( (string) $action );
			$qid_field = (string) $qid_field;
			$callback  = static function () use ( $action, $qid_field ) {
				Flow_Log::ajax_start( $action, $qid_field );
			};
			add_action( 'wp_ajax_' . $action, $callback, 0 );
			add_action( 'wp_ajax_nopriv_' . $action, $callback, 0 );
		}
	}

	/** @return string[] Provider slugs to log; '*' = every provider. */
	public static function providers(): array {
		$value = defined( 'CELLON_PAYMENT_FLOW_LOG' ) ? constant( 'CELLON_PAYMENT_FLOW_LOG' ) : '';
		$list  = $value === true ? [ '*' ] : ( is_string( $value ) && $value !== '' ? explode( ',', $value ) : [] );
		$list  = apply_filters( 'cellon_payment_collection_flow_log', $list );
		$clean = [];
		foreach ( is_array( $list ) ? $list : [] as $slug ) {
			$slug = trim( (string) $slug );
			if ( $slug === '*' || sanitize_key( $slug ) !== '' ) {
				$clean[] = $slug === '*' ? '*' : sanitize_key( $slug );
			}
		}
		return array_values( array_unique( $clean ) );
	}

	public static function enabled( string $slug ): bool {
		$providers = self::providers();
		return $slug !== '' && ( in_array( '*', $providers, true ) || in_array( $slug, $providers, true ) );
	}

	/* ---------------------------------------------------------------- REST steps (this plugin) */

	/**
	 * A browser step reached the REST route.
	 *
	 * @param array<string,mixed> $input Browser JSON body.
	 */
	public static function begin( string $slug, string $operation, string $token, array $input ): void {
		if ( ! self::enabled( $slug ) ) {
			return;
		}
		self::$current = [ 'slug' => $slug, 'checkout' => substr( $token, 0, 8 ), 'qid' => '' ];
		self::$pending = [
			'title' => 'browser → WordPress: ' . $operation . ' (REST)',
			'data'  => [ 'body' => $input ],
		];
	}

	/**
	 * The quiz result of the REST step being served, known once its checkout session is loaded, so a step the
	 * server refuses before any API call (e.g. an invalid plan) still lands in that quiz result's file.
	 */
	public static function quiz( string $qid ): void {
		if ( self::$current !== null && $qid !== '' && self::$current['qid'] === '' ) {
			self::$current['qid'] = $qid;
		}
	}

	/**
	 * The REST step answers the browser.
	 *
	 * @param array{status:int, body:array<string,mixed>} $result
	 */
	public static function end( string $operation, array $result ): void {
		if ( self::$current === null ) {
			return;
		}
		self::flush_pending();
		self::write( self::$current['slug'], 'WordPress → browser: ' . $operation . ' answer', [
			'status' => $result['status'] ?? null,
			'body'   => $result['body'] ?? null,
		] );
		self::$current = null;
	}

	/* ---------------------------------------------------------------- admin-ajax steps (Offers page) */

	/**
	 * The Offers page posted its cross-sale action (buy or skip). This request ends the quiz result's flow.
	 */
	public static function ajax_start( string $action, string $qid_field ): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only logging of the posted fields
			$qid  = isset( $_POST[ $qid_field ] ) && is_scalar( $_POST[ $qid_field ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $qid_field ] ) ) : '';
			$slug = $qid !== '' ? get_transient( self::flow_key( $qid ) ) : false;
			if ( ! is_string( $slug ) || ! self::enabled( $slug ) ) {
				return;
			}
			delete_transient( self::flow_key( $qid ) );
			self::$ajax = [ 'slug' => $slug, 'qid' => $qid, 'action' => $action, 'output' => '' ];
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted = map_deep( wp_unslash( $_POST ), static function ( $item ) {
				return is_string( $item ) ? sanitize_text_field( $item ) : $item;
			} );
			self::write( $slug, 'browser → WordPress: admin-ajax ' . $action, [ 'body' => $posted ], $qid );
			// The handler answers with wp_send_json() and dies; the answer is read as it leaves the buffer.
			ob_start( [ self::class, 'ajax_output' ] );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Output buffer of the admin-ajax step: passes the output through unchanged and logs it once complete.
	 * Only writes the file here (no database calls inside an output handler).
	 *
	 * @param string $buffer
	 * @param int    $phase
	 */
	public static function ajax_output( $buffer, $phase = 0 ): string {
		$buffer = (string) $buffer;
		if ( self::$ajax === null ) {
			return $buffer;
		}
		self::$ajax['output'] .= $buffer;
		if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
			$ajax       = self::$ajax;
			self::$ajax = null;
			$answer     = json_decode( $ajax['output'], true );
			self::write( $ajax['slug'], 'WordPress → browser: admin-ajax ' . $ajax['action'] . ' answer', [
				'body' => is_array( $answer ) ? $answer : substr( $ajax['output'], 0, 2000 ),
			], $ajax['qid'] );
			self::write_marker( $ajax['slug'], 'END of ' . $ajax['slug'] . ' flow (Offers page answered)', $ajax['qid'] );
		}
		return $buffer;
	}

	/* ---------------------------------------------------------------- API calls */

	/**
	 * @param false|array|\WP_Error $pre
	 * @param array<string,mixed>   $args
	 * @return false|array|\WP_Error
	 */
	public static function start( $pre, $args, $url ) {
		if ( is_string( $url ) && self::is_api_url( $url ) ) {
			self::$started[ $url ] = microtime( true );
		}
		return $pre;
	}

	/**
	 * Every API call of this PHP request (this plugin's and the Core plugin's).
	 *
	 * @param array|\WP_Error     $response
	 * @param string              $context
	 * @param string              $class
	 * @param array<string,mixed> $args
	 * @param string              $url
	 */
	public static function http( $response, $context, $class, $args, $url ): void {
		try {
			if ( $context !== 'response' || ! is_string( $url ) || ! self::is_api_url( $url ) ) {
				return;
			}
			$entry = self::api_entry( $response, $args, $url );
			$path  = (string) $entry['path'];
			$body  = $entry['data']['body'];
			$qid   = is_array( $body ) && isset( $body['quiz_result_id'] ) && is_scalar( $body['quiz_result_id'] ) ? (string) $body['quiz_result_id'] : '';
			if ( $qid === '' && self::$current !== null ) {
				$qid = self::$current['qid'];
			}
			if ( $qid === '' && self::$ajax !== null ) {
				$qid = self::$ajax['qid'];
			}

			$first_sale = self::first_sale_slug( $path, is_array( $body ) ? $body : [] );
			$active     = $qid !== '' ? get_transient( self::flow_key( $qid ) ) : false;
			$active     = is_string( $active ) ? $active : '';

			if ( $first_sale !== '' && self::enabled( $first_sale ) ) {
				$slug = $first_sale;
				if ( $active === '' && $qid !== '' ) {
					// The provider's first API call starts the quiz result's flow
					set_transient( self::flow_key( $qid ), $slug, DAY_IN_SECONDS );
					self::write_marker( $slug, 'START of ' . $slug . ' flow', $qid );
					self::flush_pre( $slug, $qid );
				}
			} elseif ( self::$current !== null ) {
				$slug = self::$current['slug'];
			} elseif ( self::$ajax !== null && $qid === self::$ajax['qid'] ) {
				$slug = self::$ajax['slug'];
			} elseif ( $active !== '' && self::enabled( $active ) ) {
				$slug = $active;
			} else {
				if ( $qid !== '' && $first_sale === '' ) {
					self::hold_pre( $qid, $entry );
				}
				return;
			}

			if ( self::$current !== null && $qid !== '' ) {
				self::$current['qid'] = $qid;
			}
			self::flush_pending();
			self::write( $slug, $entry['title'], $entry['data'], $qid );
		} catch ( \Throwable $e ) {
			unset( $e ); // Never let the log break a payment.
		}
	}

	/**
	 * @param array|\WP_Error     $response
	 * @param array<string,mixed> $args
	 * @return array{path:string, title:string, data:array<string,mixed>}
	 */
	private static function api_entry( $response, array $args, string $url ): array {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$raw  = is_string( $args['body'] ?? null ) ? $args['body'] : '';
		$body = json_decode( $raw, true );
		$body = is_array( $body ) ? $body : ( is_array( $args['body'] ?? null ) ? $args['body'] : $raw );

		$ms = isset( self::$started[ $url ] ) ? (int) round( ( microtime( true ) - self::$started[ $url ] ) * 1000 ) : null;
		unset( self::$started[ $url ] );

		if ( is_wp_error( $response ) ) {
			$status = 'transport error: ' . $response->get_error_message();
			$answer = null;
		} else {
			$status = (int) wp_remote_retrieve_response_code( $response );
			$text   = (string) wp_remote_retrieve_body( $response );
			$answer = json_decode( $text, true );
			$answer = is_array( $answer ) ? $answer : substr( $text, 0, 2000 );
		}

		return [
			'path'  => $path,
			'title' => 'WordPress → API: ' . strtoupper( (string) ( $args['method'] ?? 'POST' ) ) . ' ' . $path,
			'data'  => [
				'url'      => $url,
				'headers'  => (array) ( $args['headers'] ?? [] ),
				'body'     => $body,
				'status'   => $status,
				'time_ms'  => $ms,
				'response' => $answer,
			],
		];
	}

	private static function is_api_url( string $url ): bool {
		$api_host = (string) wp_parse_url( Config::api()['url'], PHP_URL_HOST );
		$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
		return $api_host !== '' && strcasecmp( $api_host, $host ) === 0;
	}

	/**
	 * The provider of a first-sale payment call: the common API names it in the body, today's routes in the path.
	 *
	 * @param array<string,mixed> $body
	 */
	private static function first_sale_slug( string $path, array $body ): string {
		if ( strpos( $path, '/first-sale/' ) === false ) {
			return '';
		}
		if ( isset( $body['payment_provider_slug'] ) && is_string( $body['payment_provider_slug'] ) ) {
			return sanitize_key( $body['payment_provider_slug'] );
		}
		return preg_match( '#/payment/([a-z0-9_-]+)/first-sale/#', $path, $match ) ? $match[1] : '';
	}

	/* ---------------------------------------------------------------- calls before the flow starts */

	/**
	 * @param array{path:string, title:string, data:array<string,mixed>} $entry
	 */
	private static function hold_pre( string $qid, array $entry ): void {
		$data = self::redact( $entry['data'] );
		$json = (string) wp_json_encode( $data );
		if ( strlen( $json ) > self::PRE_MAX_BYTES ) {
			$data['response'] = '[answer cut: ' . strlen( $json ) . ' bytes] ' . substr( (string) wp_json_encode( $data['response'] ), 0, self::PRE_MAX_BYTES ) . '…';
		}
		$held   = get_transient( self::pre_key( $qid ) );
		$held   = is_array( $held ) ? $held : [];
		$held[] = [ 'time' => gmdate( 'Y-m-d H:i:s' ), 'title' => $entry['title'] . '  (before the payment)', 'data' => $data ];
		set_transient( self::pre_key( $qid ), array_slice( $held, -self::PRE_LIMIT ), 2 * HOUR_IN_SECONDS );
	}

	private static function flush_pre( string $slug, string $qid ): void {
		$held = get_transient( self::pre_key( $qid ) );
		delete_transient( self::pre_key( $qid ) );
		foreach ( is_array( $held ) ? $held : [] as $item ) {
			if ( is_array( $item ) && isset( $item['title'], $item['data'] ) ) {
				self::write( $slug, (string) $item['title'], (array) $item['data'], $qid, (string) ( $item['time'] ?? '' ) );
			}
		}
	}

	private static function flow_key( string $qid ): string {
		return 'cpc_flow_' . md5( $qid );
	}

	private static function pre_key( string $qid ): string {
		return 'cpc_flow_pre_' . md5( $qid );
	}

	/* ---------------------------------------------------------------- file */

	private static function flush_pending(): void {
		if ( self::$pending === null || self::$current === null ) {
			return;
		}
		$pending       = self::$pending;
		self::$pending = null;
		self::write( self::$current['slug'], $pending['title'], $pending['data'] );
	}

	private static function write_marker( string $slug, string $text, string $qid ): void {
		self::append( $slug, $qid, sprintf( "\n######## %s UTC | quiz %s | %s ########\n\n", gmdate( 'Y-m-d H:i:s' ), $qid !== '' ? $qid : '-', $text ) );
	}

	/**
	 * @param array<string,mixed> $data
	 * @param string              $time Original UTC time of a held-back entry ('' = now; it then has no checkout).
	 */
	private static function write( string $slug, string $title, array $data, string $qid = '', string $time = '' ): void {
		$qid      = $qid !== '' ? $qid : (string) ( self::$current['qid'] ?? '' );
		$checkout = $time === '' ? (string) ( self::$current['checkout'] ?? '' ) : '';
		self::append( $slug, $qid, sprintf(
			"==== %s UTC | quiz %s | checkout %s | %s ====\n%s\n\n",
			$time !== '' ? $time : gmdate( 'Y-m-d H:i:s' ),
			$qid !== '' ? $qid : '-',
			$checkout !== '' ? $checkout : '-',
			$title,
			(string) wp_json_encode( self::redact( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		) );
	}

	/** One file per quiz result (<provider>-flow-quiz-<id>.txt), so parallel checkouts never interleave. */
	private static function append( string $slug, string $qid, string $text ): void {
		try {
			$dir = self::dir();
			if ( $dir !== '' ) {
				$qid  = preg_replace( '/[^A-Za-z0-9_-]/', '', $qid );
				$name = sanitize_key( $slug ) . '-flow-' . ( $qid !== '' ? 'quiz-' . $qid : gmdate( 'Y-m-d' ) ) . '.txt';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $dir . '/' . $name, $text, FILE_APPEND | LOCK_EX );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/** The log folder, created with its web-access guards on first use; '' when it cannot be made. */
	private static function dir(): string {
		$dir = WP_CONTENT_DIR . '/' . self::DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function redact( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = is_string( $key ) && in_array( strtolower( $key ), self::REDACT, true ) ? '[redacted]' : self::redact( $item );
		}
		return $value;
	}
}
