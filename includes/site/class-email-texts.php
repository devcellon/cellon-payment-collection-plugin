<?php
/**
 * Texts of the email field the checkout shows when the quiz result has no real email, in every
 * language the three sites run in (and the common others).
 *
 * The language is the template's `locale` attribute; with `locale="auto"` or none, the page's WPML
 * language, else the WordPress site language (one blog per language on the multisite sites).
 * Messages from iqpro js/stripe-new.js are kept word for word; the rest were added for this plugin.
 *
 * Order for the error message: the template's `email_error` attribute, the page's ACF field
 * `email_collection_error_msg`, this table, English.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Texts {

	/** language => [ invalid-email message, field label / placeholder ] */
	const TEXTS = [
		'en'    => [ 'Please enter a valid email address before proceeding with payment.', 'Email address' ],
		'no'    => [ 'Vennligst skriv inn en gyldig e-postadresse før du fortsetter med betalingen.', 'E-postadresse' ],
		'de'    => [ 'Bitte geben Sie eine gültige E-Mail-Adresse ein, bevor Sie mit der Zahlung fortfahren.', 'E-Mail-Adresse' ],
		'fr'    => [ 'Veuillez saisir une adresse e-mail valide avant de procéder au paiement.', 'Adresse e-mail' ],
		'es'    => [ 'Introduzca una dirección de correo electrónico válida antes de continuar con el pago.', 'Correo electrónico' ],
		'pt'    => [ 'Insira um endereço de e-mail válido antes de prosseguir com o pagamento.', 'Endereço de e-mail' ],
		'it'    => [ 'Inserisci un indirizzo email valido prima di procedere con il pagamento.', 'Indirizzo email' ],
		'pl'    => [ 'Przed dokonaniem płatności wprowadź prawidłowy adres e-mail.', 'Adres e-mail' ],
		'nl'    => [ 'Voer een geldig e-mailadres in voordat u doorgaat met betalen.', 'E-mailadres' ],
		'id'    => [ 'Masukkan alamat email yang valid sebelum melanjutkan pembayaran.', 'Alamat email' ],
		'tr'    => [ 'Ödemeye devam etmeden önce geçerli bir e-posta adresi girin.', 'E-posta adresi' ],
		'ja'    => [ 'お支払いを進める前に有効なメールアドレスを入力してください', 'メールアドレス' ],
		'vi'    => [ 'Vui lòng nhập địa chỉ email hợp lệ trước khi tiến hành thanh toán.', 'Địa chỉ email' ],
		'ko'    => [ '결제를 진행하기 전에 유효한 이메일 주소를 입력해 주세요.', '이메일 주소' ],
		'sv'    => [ 'Ange en giltig e-postadress innan du fortsätter med betalningen.', 'E-postadress' ],
		'da'    => [ 'Indtast en gyldig e-mailadresse, før du fortsætter med betalingen.', 'E-mailadresse' ],
		'fi'    => [ 'Anna kelvollinen sähköpostiosoite ennen kuin jatkat maksuun.', 'Sähköpostiosoite' ],
		'ar'    => [ 'يرجى إدخال عنوان بريد إلكتروني صالح قبل متابعة الدفع.', 'البريد الإلكتروني' ],
		'he'    => [ 'יש להזין כתובת דוא״ל תקינה לפני המשך התשלום.', 'כתובת דוא״ל' ],
		'zh'    => [ '请输入有效的电子邮件地址后再继续付款。', '电子邮件地址' ],
		'zh-TW' => [ '請先輸入有效的電子郵件地址，再繼續付款。', '電子郵件地址' ],
		'ru'    => [ 'Пожалуйста, введите действительный адрес электронной почты, прежде чем продолжить оплату.', 'Адрес электронной почты' ],
		'uk'    => [ 'Будь ласка, введіть дійсну адресу електронної пошти, перш ніж продовжити оплату.', 'Адреса електронної пошти' ],
		'cs'    => [ 'Před pokračováním k platbě zadejte platnou e-mailovou adresu.', 'E-mailová adresa' ],
		'el'    => [ 'Εισαγάγετε μια έγκυρη διεύθυνση email πριν προχωρήσετε στην πληρωμή.', 'Διεύθυνση email' ],
		'hu'    => [ 'A fizetés folytatása előtt adjon meg egy érvényes e-mail-címet.', 'E-mail-cím' ],
		'ro'    => [ 'Introduceți o adresă de e-mail validă înainte de a continua cu plata.', 'Adresă de e-mail' ],
		'hi'    => [ 'भुगतान जारी रखने से पहले कृपया एक मान्य ईमेल पता दर्ज करें।', 'ईमेल पता' ],
		'th'    => [ 'โปรดป้อนที่อยู่อีเมลที่ถูกต้องก่อนดำเนินการชำระเงิน', 'ที่อยู่อีเมล' ],
		'ms'    => [ 'Sila masukkan alamat e-mel yang sah sebelum meneruskan pembayaran.', 'Alamat e-mel' ],
	];

	/** Other codes the sites, WPML or WordPress use for the same languages. */
	const ALIASES = [
		'aben'    => 'en',
		'nb'      => 'no',
		'nn'      => 'no',
		'se'      => 'sv', // the themes use "se" for the Swedish site
		'zh-hans' => 'zh',
		'zh-cn'   => 'zh',
		'zh-sg'   => 'zh',
		'zh-hant' => 'zh-TW',
		'zh-tw'   => 'zh-TW',
		'zh-hk'   => 'zh-TW',
		'zh-mo'   => 'zh-TW',
		'iw'      => 'he',
		'in'      => 'id',
	];

	/**
	 * @param string $locale The template's `locale` attribute ("ja", "zh-HK", "auto", "").
	 * @return array{error:string, label:string, language:string}
	 */
	public static function for_locale( string $locale ): array {
		$language = self::resolve( $locale );
		$texts    = self::TEXTS[ $language ];

		/**
		 * Change or add email-field texts.
		 *
		 * @param array  $texts    [ error, label ]
		 * @param string $language Resolved language key.
		 */
		$texts = (array) apply_filters( 'cellon_payment_collection_email_texts', $texts, $language );

		return [
			'error'    => (string) ( $texts[0] ?? self::TEXTS['en'][0] ),
			'label'    => (string) ( $texts[1] ?? self::TEXTS['en'][1] ),
			'language' => $language,
		];
	}

	/** A key of TEXTS: the template's locale, else WPML, else the WordPress site language, else en. */
	public static function resolve( string $locale ): string {
		$candidates = [];
		if ( $locale !== '' && strtolower( $locale ) !== 'auto' ) {
			$candidates[] = $locale;
		}
		$wpml = apply_filters( 'wpml_current_language', null );
		if ( is_string( $wpml ) && $wpml !== '' && $wpml !== 'all' ) {
			$candidates[] = $wpml;
		}
		$candidates[] = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		foreach ( $candidates as $candidate ) {
			$key = self::key( (string) $candidate );
			if ( $key !== '' ) {
				return $key;
			}
		}
		return 'en';
	}

	/** "zh_HK" / "zh-hant" / "pt-BR" / "nb_NO" → a TEXTS key, or '' when unknown. */
	private static function key( string $code ): string {
		$code = strtolower( str_replace( '_', '-', trim( $code ) ) );
		if ( $code === '' ) {
			return '';
		}
		$primary = strtok( $code, '-' );
		foreach ( [ $code, (string) $primary ] as $try ) {
			if ( isset( self::ALIASES[ $try ] ) ) {
				return self::ALIASES[ $try ];
			}
			foreach ( array_keys( self::TEXTS ) as $known ) {
				if ( strtolower( $known ) === $try ) {
					return $known;
				}
			}
		}
		return '';
	}
}
