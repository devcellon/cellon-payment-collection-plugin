<?php
/**
 * On/off switch for the shared checkout: an ACF options page, Settings → Payment Collection.
 *
 * The plugin's checkout runs only when BOTH are true:
 *   1. this plugin is active on the site (network-activated counts), and
 *   2. "Use the plugin checkout" is On on that page (ACF PRO or Secure Custom Fields).
 * Otherwise the theme keeps its own payment code: iq_use_cellon_payment_collection() is false, the
 * shortcode renders nothing and the legacy guards stay off. The switch is Off until someone saves it On.
 *
 * Multisite (wwiqtest, wwpersonality): ACF saves options pages to the current site's options table,
 * so every site of a network has its own switch. Nothing is network-wide.
 *
 * WPML (iqpro, wwpersonality): ACF and ACFML save options-page values per language (options_ja …).
 * This page is language-neutral instead: one saved value, whatever language the admin bar shows, and the
 * "Languages" field limits the checkout to the ticked languages (none ticked = every language).
 *
 * Code override (both directions), e.g. an emergency kill switch in a mu-plugin:
 *   add_filter( 'cellon_payment_collection_enabled', '__return_false' );
 * The filter gets ( bool $enabled, string $language ); $language is '*' for "every language".
 *
 * Read the switch after `init` only: reading it earlier would start ACF before WordPress loads translations.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	/** ACF "post_id" of the page: values are stored as options cellon_payment_collection_<field name>. */
	const POST_ID = 'cellon_payment_collection';

	const MENU_SLUG = 'cellon-payment-collection';

	const GROUP_KEY     = 'group_cellon_payment_collection';
	const KEY_STATUS    = 'field_cellon_pc_status';
	const KEY_ENABLED   = 'field_cellon_pc_enabled';
	const KEY_LANGUAGES = 'field_cellon_pc_languages';

	public static function register(): void {
		add_filter( 'acf/validate_post_id', [ self::class, 'language_neutral_post_id' ], 99, 2 );
		add_action( 'acf/init', [ self::class, 'register_page' ] );
		add_filter( 'acf/load_field/key=' . self::KEY_LANGUAGES, [ self::class, 'language_choices' ] );
		add_filter( 'acf/load_field/key=' . self::KEY_STATUS, [ self::class, 'status_message' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( CELLON_PC_FILE ), [ self::class, 'action_link' ] );
	}

	/** ACF with options pages (ACF PRO or Secure Custom Fields) is active. ACF free has no options pages. */
	public static function acf_active(): bool {
		return function_exists( 'acf_add_options_page' ) && function_exists( 'get_field' );
	}

	/**
	 * The checkout is on for this site and the given language ('' = the current WPML language).
	 */
	public static function enabled( string $language = '' ): bool {
		$on = self::switched_on();
		if ( $on ) {
			$allowed = self::languages();
			if ( $allowed ) {
				$language = $language !== '' ? $language : self::current_language();
				// Unknown language (no WPML language set, or "all" in wp-admin): on only if it is on everywhere.
				$on = ( $language === '' || $language === 'all' )
					? self::covers_every_language( $allowed )
					: in_array( $language, $allowed, true );
			}
		}
		return (bool) apply_filters( 'cellon_payment_collection_enabled', $on, $language );
	}

	/**
	 * The checkout is on for every language of this site: no language of it still renders the theme's old
	 * payment code. Code that is shared by all languages (old REST routes, legacy guards) asks this.
	 */
	public static function enabled_everywhere(): bool {
		$on = self::switched_on() && self::covers_every_language( self::languages() );
		return (bool) apply_filters( 'cellon_payment_collection_enabled', $on, '*' );
	}

	/** The "Use the plugin checkout" switch, before languages and the filter. */
	public static function switched_on(): bool {
		if ( ! self::acf_active() ) {
			return false;
		}
		// Raw value (no formatting), so it reads the same before and after the field group is registered.
		return (bool) (int) get_field( 'enabled', self::POST_ID, false );
	}

	/**
	 * Ticked languages; empty = every language (also when WPML is not active on this site).
	 *
	 * @return string[]
	 */
	public static function languages(): array {
		if ( ! self::acf_active() || ! self::active_languages() ) {
			return [];
		}
		$value = get_field( 'languages', self::POST_ID, false );
		return array_values( array_filter( array_map( 'strval', (array) $value ), 'strlen' ) );
	}

	/**
	 * WPML's active languages of this site, code => name. Empty without WPML.
	 *
	 * @return array<string,string>
	 */
	public static function active_languages(): array {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return [];
		}
		$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		$out       = [];
		foreach ( is_array( $languages ) ? $languages : [] as $code => $language ) {
			// With the code: two languages can share a name (iqpro: "en" and "aben" are both English).
			$name                  = (string) ( $language['native_name'] ?? '' );
			$out[ (string) $code ] = ( $name !== '' ? $name : (string) $code ) . ' (' . $code . ')';
		}
		return $out;
	}

	private static function current_language(): string {
		$language = apply_filters( 'wpml_current_language', null );
		return is_string( $language ) ? $language : '';
	}

	/** @param string[] $allowed */
	private static function covers_every_language( array $allowed ): bool {
		if ( ! $allowed ) {
			return true;
		}
		return ! array_diff( array_keys( self::active_languages() ), $allowed );
	}

	/**
	 * ACF appends the WPML language to options-page ids ("options_ja", and ACFML does it for custom ids
	 * such as this page's). Undo it for this page only, so it saves and reads one value for all languages.
	 *
	 * @param string|int $post_id  Id after ACF / ACFML.
	 * @param mixed      $original Id as the caller passed it.
	 * @return string|int
	 */
	public static function language_neutral_post_id( $post_id, $original = null ) {
		return $original === self::POST_ID ? self::POST_ID : $post_id;
	}

	public static function register_page(): void {
		if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_options_sub_page(
			[
				'page_title'      => 'Cellon Payment Collection',
				'menu_title'      => 'Payment Collection',
				'menu_slug'       => self::MENU_SLUG,
				'parent_slug'     => 'options-general.php',
				'capability'      => 'manage_options',
				'post_id'         => self::POST_ID,
				'autoload'        => true,
				'updated_message' => 'Payment settings saved.',
			]
		);

		$fields = [
			[
				'key'       => self::KEY_STATUS,
				'label'     => 'Status',
				'name'      => '',
				'type'      => 'message',
				'message'   => '',
				'new_lines' => '',
				'esc_html'  => 0,
			],
			[
				'key'           => self::KEY_ENABLED,
				'label'         => 'Use the plugin checkout',
				'name'          => 'enabled',
				'type'          => 'true_false',
				'instructions'  => 'On: the payment pages show this plugin\'s checkout. Off: the theme\'s own payment code runs, as before the plugin. This site only' . ( is_multisite() ? ' (every site of the network has its own switch)' : '' ) . '.',
				'default_value' => 0,
				'ui'            => 1,
				'ui_on_text'    => 'On',
				'ui_off_text'   => 'Off',
			],
		];

		if ( self::active_languages() ) {
			$fields[] = [
				'key'               => self::KEY_LANGUAGES,
				'label'             => 'Languages',
				'name'              => 'languages',
				'type'              => 'checkbox',
				'instructions'      => 'Only the ticked languages use the plugin checkout; the others keep the theme\'s payment code. None ticked = every language. One setting for all languages: the language in the admin bar does not matter on this page.',
				'choices'           => [],
				'layout'            => 'horizontal',
				'return_format'     => 'value',
				'conditional_logic' => [
					[
						[
							'field'    => self::KEY_ENABLED,
							'operator' => '==',
							'value'    => '1',
						],
					],
				],
			];
		}

		acf_add_local_field_group(
			[
				'key'                   => self::GROUP_KEY,
				'title'                 => 'Payment checkout',
				'fields'                => $fields,
				'location'              => [
					[
						[
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => self::MENU_SLUG,
						],
					],
				],
				'style'                 => 'default',
				'label_placement'       => 'left',
				'instruction_placement' => 'label',
				'active'                => true,
			]
		);
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	public static function language_choices( $field ) {
		$field['choices'] = self::active_languages();
		return $field;
	}

	/**
	 * What the site does right now, from the saved values.
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	public static function status_message( $field ) {
		$names = self::active_languages();
		$lines = [];

		if ( ! self::switched_on() ) {
			$lines[] = '<strong>Off</strong>: every payment page uses the theme\'s own payment code.';
		} else {
			$allowed = self::languages();
			if ( ! $allowed || self::covers_every_language( $allowed ) ) {
				$lines[] = '<strong>On</strong>' . ( $names ? ' for every language' : '' ) . ': the payment pages show the plugin checkout.';
			} else {
				$on  = array_map( static function ( $code ) use ( $names ) { return $names[ $code ] ?? $code; }, $allowed );
				$off = array_diff_key( $names, array_flip( $allowed ) );
				$lines[] = '<strong>On</strong> for ' . esc_html( implode( ', ', $on ) ) . '.';
				$lines[] = 'Theme payment code for ' . esc_html( implode( ', ', $off ) ) . '.';
			}
			if ( ! Config::is_configured() ) {
				$lines[] = '<strong>The API connection is missing</strong> (quiz-funnel-core settings: API URL and secret). The checkout shows "Payment is not available" until it is saved.';
			}
		}
		if ( has_filter( 'cellon_payment_collection_enabled' ) ) {
			$lines[] = 'Code on this site filters <code>cellon_payment_collection_enabled</code> and can override this page.';
		}

		if ( is_multisite() ) {
			$lines[] = 'Site: ' . esc_html( (string) get_bloginfo( 'name' ) ) . ' (' . esc_html( home_url( '/' ) ) . ', ID ' . (int) get_current_blog_id() . '). Other sites of the network are not changed.';
		}

		$field['message'] = '<p>' . implode( '</p><p>', $lines ) . '</p>';
		return $field;
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public static function action_link( $links ) {
		if ( self::acf_active() && current_user_can( 'manage_options' ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ) . '">Settings</a>' );
		}
		return $links;
	}
}
