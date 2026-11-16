# Integration and migration

Same plugin zip on all three sites. Nothing in the Core plugin (`quiz-funnel-core` / `Cellon-Nexus-Core`) changes. Each site's theme changes only where you swap the payment block for the shortcode, and this plugin makes none of those edits.

1. [Build and package](#1-build-and-package)
2. [Configure (server only)](#2-configure-server-only)
3. [Install on each site](#3-install-on-each-site)
4. [The shortcode](#4-the-shortcode)
5. [Where the quiz result comes from](#5-where-the-quiz-result-comes-from)
6. [Provider sequence: 1 → 2 → 3](#6-provider-sequence-1--2--3)
7. [Site behaviour carried over from the themes](#7-site-behaviour-carried-over-from-the-themes)
8. [Migration without duplicate handlers](#8-migration-without-duplicate-handlers)
9. [Per-site page map](#9-per-site-page-map)
10. [How a payment runs](#10-how-a-payment-runs)
11. [Hooks reference](#11-hooks-reference)

---

## 1. Build and package

```powershell
cd D:\Nexus_Vault_frontend\cellon-payment-collection
powershell -ExecutionPolicy Bypass -File tools\package.ps1        # → dist\cellon-payment-collection-1.1.0.zip
```

There is no build step: the browser code is the plain JavaScript in `assets\js` (README "Browser code"). Upload the same zip to every site. Never edit a copy on one site; change this folder and repackage.

## 2. Configure (server only)

The plugin has **no settings page** and stores no settings. Everything that is already configured in the **quiz-funnel-core** plugin is read from there, so it exists in one place only:

| What | Where it comes from |
| --- | --- |
| API URL | Core plugin settings: the selected environment (`cellonnexus_env`) and its URL (`cellonnexus_api_url_<env>`), with the Core plugin's own staging default. |
| API secret | Core plugin settings (`cellonnexus_api_secret`). Server-side only; never sent to the browser. |
| `x-host`, `ip_address`, User-Agent | The Core plugin's `Request_Helper`, so payment calls send exactly what its quiz-result calls send. The x-host is stored when the page renders and reused for every API call of that checkout (REST calls run outside the page's WPML language). |
| Redirect pages | The Core plugin's page map (`cellonnexus()->quiz->get_redirect_url()`, filter `quiz_funnel_redirect_page_ids`). See §4. |
| Provider keys | The API response for the quiz result (Stripe publishable key, PayPal client id, Solidgate merchant, BridgerPay key). |
| Languages | The template (§7). |

When the Core plugin is inactive or its secret is empty, the shortcode shows nothing to customers, and admins see a notice.

Rotate the API secret before production: it is in a source comment in all three Core plugins (SECURITY-FINDINGS S1).

The few switches that are not Core settings are code-only (`wp-config.php` or the theme's `functions.php`). Leave them out to get the defaults:

```php
define( 'CELLON_PAYMENT_SITE', 'wwiqtest' );        // iqpro | wwiqtest | wwpersonality; default: detected from the host
define( 'CELLON_PAYMENT_API_ADAPTER', 'common' );   // default 'current' (per-provider routes); 'common' = /payment/first-sale/payments/initiate|confirm
define( 'CELLON_PAYMENT_DEBUG', true );             // payment steps to the PHP error log (no tokens, card data or emails); staging only

// Only some providers (default: every registered provider; the API's list still decides per quiz result)
add_filter( 'cellon_payment_collection_enabled_providers', fn() => [ 'stripe', 'solidgate', 'paypal' ] );
// Retire old handlers (§8 step 4); default: none
add_filter( 'cellon_payment_collection_legacy_guards', fn() => [ 'paypal_checkout_api_payment' ] );
```

`CELLON_PAYMENT_SITE` is needed only on hosts whose name contains none of `iqpro`, `wwiqtest`, `personality`.

## 3. Install on each site

Repeat these steps on iqpro, then wwiqtest, then wwpersonality, staging first:

1. **Plugins → Add New → Upload Plugin** → `cellon-payment-collection-1.1.0.zip` → Activate. quiz-funnel-core must be active and configured (it already is on all three sites).
2. No admin notice from Cellon Payment Collection means the Core plugin's API settings were found.
3. Add the language helper to the theme (§7) if the site has languages.
4. Exclude the payment and checkout pages from page caching and the CDN. Every render carries its own checkout token. The plugin also sends `DONOTCACHEPAGE` and no-cache headers.
5. Migrate page by page (§8, §9).

## 4. The shortcode

```php
<?php echo do_shortcode( '[cellon_payment_checkout flow="first-sale"]' ); ?>
<?php echo do_shortcode( '[cellon_payment_checkout flow="cross-sale" accept_label="…" skip_label="…"]' ); ?>
```

It renders the **inner** payment block the templates have today: the `.payment-methods` content. That is the wallet button and PayPal row, "Or Pay with Card", the card form with the price and the orange button, and `#paymentResponse`. It uses the themes' class names and ids, so `style.css` and `css/newpayment_popup.css` keep styling it.

The wrapper around it stays in the template, whichever layout the page uses:

- **Payment page**, Quiz Payment on all three sites: an always-open dialog, `.pmp > .modal-dialog > .modal-content > #IQpaymentModal`, with the close button back to checkout and `.modal-backdrop`.
- **Checkout page**, the templates that embed the payment: the inline `#iq-report` section the page's buttons scroll to.

| Attribute | Default | Meaning |
| --- | --- | --- |
| `flow` | `first-sale` | `first-sale`, or `cross-sale` (offer accept / skip; the API charges the first sale's saved method). |
| `direct` | `show` | `hide` drops the direct providers (PayPal). |
| `pay_label` | ACF `get_result_button__label` | Stripe / BLOX pay button. Solidgate and PayPal render their own buttons. |
| `pay_class` | `orange-btn big float-right` | Classes of the pay button. |
| `price` | `1` | Shows the price line in `.pprice`: `first_sale_price_label`, or `first_and_cross_sale_price_label` when `cross_sale_compulsory`. |
| `separator` | `Or Pay with Card` (translated, §7) | Line between the express row and the card form. |
| `express_layout` | `row` | Wallets (Google Pay / Apple Pay) and PayPal. `row`: side by side, one per row when there is no room for two, a single button full width, two wallets on a row of their own with PayPal under them. `stack`: every button a full-width row. The plugin lays this row out itself, whatever the theme's old `#gpay_btn` / `#paypal-button-container` CSS says. |
| `switch_on` | `all` | Which failures move to the next provider (§6). |
| `last_gateway` | `fail` | A failure on the last provider ends the checkout; `retry` keeps its form for another card. |
| `max_switches` | `1` | Switches after a **declined** payment: one fallback (Stripe → Solidgate), then the customer stays there with the message. A provider that fails to load is always skipped. |
| `switch_delay` | `1500` | ms the failed provider and its message stay visible before the next one mounts. |
| `theme_ids` | `1` | Use `#gpay_btn`, `#paypal-button-container` and `#paymentResponse`. Only the first checkout on a page gets them. |
| `locale`, `direction`, `solidgate_locale`, `payment_methods`, `email_error`, `collect_email` | | Language and email settings from the template (§7). |
| `class`, `accept_label`, `skip_label`, `show_skip` | | As named. |

When the shortcode is echoed from a template, call `cellon_payment_collection()->nocache();` before `get_header()`.

**Redirects** are not set on the shortcode. After a payment the customer goes to the API's `redirect_page` (`CROSS_SELL_OFFER_PAGE`, `CUSTOMER_DETAILS_PAGE`, `THANK_YOU_PAGE` …), resolved through the Core plugin's page map with `?qid=`, exactly as the Core plugin's own payment handlers do. A skipped or declined cross-sale goes to `CUSTOMER_DETAILS_PAGE`. A tag the map does not know reloads the checkout page, and the Core plugin's redirect controller forwards the customer. Where the page ids are set: on **wwiqtest** in the Core plugin's settings screen (the `qfc_redirect_*` options); on **iqpro** and **wwpersonality** in the Core plugin's code (363, 9989, the Offer Page template, 691, 370, 10), changeable with its `quiz_funnel_redirect_page_ids` filter. WPML gives each language its translated page.

## 5. Where the quiz result comes from

The plugin uses the installed **quiz-funnel-core** plugin, the same call the payment templates make:

```php
cellonnexus()->quiz->get_results( $qid );   // POST /questions/results
```

- **qid** is `?qid=`, or the session's quiz result.
- **Result storage:** the result is stored in `$_SESSION['quiz_result_data']`, as the templates do.
- **Calls per request:** one, shared by every shortcode on the page.
- **Fallbacks, in order:**
  1. `cellon_payment_checkout_context` filter: a theme returns the context array itself.
  2. The Core plugin call.
  3. `$_SESSION['quiz_result_data']`, only for the same qid.
  - `cellon_payment_collection_use_core_results` → `false` skips the Core plugin call.

From the result it takes:

| Field | Source |
| --- | --- |
| quiz result id, email, `payment_mode` | the quiz result |
| currency | `pricing_info.currency_code` |
| amount | `pricing_info.transactions.first_sale_first_split.amount`, else `first_sale.amount` |
| price labels | `pricing_info` |
| pricing fields | `$_SESSION['prc_id']`, `['mdid']`, `['ab_testing_price_category']` |
| provider lists | `payment_provider_details` (sequence) and `direct_payment_provider_details` (or `fixed_payment_provider_details`), from the quiz result or `pricing_info` |

Without either provider list, the legacy `payment_gateways` names are used: SolidGate if listed, else Stripe.

## 6. Provider sequence: 1 → 2 → 3

`payment_provider_details` is tried in `order`, lowest first. Only one provider is on screen at a time:

```
provider 1 ─ declined ─▶ provider 2 mounts in the same place ─ declined ─▶ stays on provider 2 with the message
     (a provider that cannot load is skipped: provider 1 ─ no load ─▶ provider 2 ─ no load ─▶ provider 3)
```

- **What counts as a failure:** the SDK cannot load, the API refuses the intent, or the payment is declined, any decline, including card errors and a declined Google Pay / Apple Pay payment (`switch_on="all"`). The customer re-enters the card on the next provider; the email typed in Stripe's field goes with it.
- **One fallback after a decline** (`max_switches="1"`, the default): a decline on the second card provider keeps the customer there with "declined, use another card", as the themes' old Stripe → Solidgate fallback did. A higher number allows more switches.
- **The failed provider's message stays for 1.5 s** (`switch_delay`) before the next one mounts. This replaces the themes' "Do Not Honor → Solidgate" cookie and page reload.
- **Exception: the outcome is unclear.** If the provider took the payment but the API did not answer, the chain never switches. The server checks with the API first, so a customer is never charged twice.
- **Direct providers (PayPal)** from `direct_payment_provider_details` sit next to the wallet button and load on their own; a PayPal failure does not affect the card chain.
- **One payment at a time:** while one box processes, the others are locked, and the server refuses a second payment as well.

`switch_on="fixable"` keeps hard declines (stolen, lost, fraud) on the same provider, and `switch_on="soft"` switches on soft declines only. Card networks discourage sending a hard-declined card to another acquirer, so consider `fixable` if acquirers raise excessive-retry flags.

## 7. Site behaviour carried over from the themes

The plugin ports what the themes' `stripe-new.js` / `stripe_new.js`, `solidgate.js` and `paypal-*.js` do per site. Site-wide parts (email collection, labels, `first_sale_fronted`, Chargeblast) are in `includes/site/class-site-profile.php` (filter `cellon_payment_collection_site_profile`). What differs per provider (its look, its event names, Solidgate's `PaymentPage` gtag) is in that provider's class in `includes/providers/` (`browser_options()`, `site_events()`; filter `cellon_payment_collection_adapter_options` for the options).

### Languages: decided by the template

The plugin has no language rules. The template passes them on the shortcode, and the plugin only applies them:

| Attribute | Used for | Default |
| --- | --- | --- |
| `locale` | Stripe Elements language (`ja`, `zh-HK`, `zh-TW` …) | `auto` (browser language) |
| `direction` | `rtl` puts the Stripe and Solidgate card fields right to left | `ltr` |
| `solidgate_locale` | Solidgate form language (`ar-SA`; its language is the part before `-`) | Solidgate's own |
| `payment_methods` | Stripe methods next to the card (`card,alipay`). Alipay also sends the customer-IP mandate. | `card` |
| `email_error` | Message when the collected email is invalid | ACF `email_collection_error_msg`, else the built-in message for the language (below) |
| `collect_email` | `0` turns email collection off on that page, `1` forces it on | `auto`: collect when the quiz result has no real email |

Keep each site's rules in one theme helper and call it from every payment template. These helpers reproduce what the old scripts did:

```php
// iqpro — functions.php (WPML)
function iq_payment_language_atts( array $quiz_result_data ): array {
	$lang = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : 'en';
	$map  = [ 'aben' => 'en', 'zh-hans' => 'zh', 'zh-hant' => 'zh-TW' ];
	$cny  = strtolower( $quiz_result_data['pricing_info']['currency_code'] ?? '' ) === 'cny';
	return [
		'locale'          => $map[ $lang ] ?? $lang,
		'payment_methods' => $cny && in_array( $lang, [ 'zh-hans', 'zh-hant' ], true ) ? 'card,alipay' : 'card',
	];
}

// wwiqtest — functions.php (multisite, one blog per language; map from stripe_new.js)
function iq_payment_language_atts( array $quiz_result_data = [] ): array {
	$blog    = get_current_blog_id();
	$locales = [ 2 => 'no', 10 => 'no', 3 => 'de', 11 => 'de', 6 => 'fr', 12 => 'fr', 5 => 'es', 20 => 'es',
		7 => 'pt', 19 => 'pt', 8 => 'it', 14 => 'pl', 26 => 'nl', 27 => 'id', 21 => 'zh', 15 => 'sv',
		22 => 'zh-HK', 24 => 'zh-HK', 25 => 'tr', 28 => 'tr', 23 => 'ar', 13 => 'ja', 16 => 'ja', 17 => 'ko', 18 => 'ko' ];
	return [
		'locale'           => $locales[ $blog ] ?? 'auto',
		'direction'        => $blog === 23 ? 'rtl' : 'ltr',
		'solidgate_locale' => $blog === 23 ? 'ar-SA' : '',
	];
}

// wwpersonality — functions.php
function iq_payment_language_atts( array $quiz_result_data = [] ): array {
	$lang = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : '';
	return [ 'locale' => in_array( get_current_blog_id(), [ 2, 3 ], true ) ? 'ja' : ( $lang === 'ko' ? 'ko' : 'auto' ) ];
}
```

In each payment template:

```php
<?php echo cellon_payment_collection()->render( [ 'flow' => 'first-sale' ] + iq_payment_language_atts( $quiz_result_data ) ); ?>
```

**Email field texts in every language.** The invalid-email message and the field's placeholder are built in (`includes/site/class-email-texts.php`) for: en, no, de, fr, es, pt, it, pl, nl, id, tr, ja, vi, ko, sv, da, fi, ar, he, zh (Simplified), zh-TW (Traditional, also zh-HK), ru, uk, cs, el, hu, ro, hi, th, ms. The 13 messages from iqpro's `stripe-new.js` are kept word for word.

The language is the template's `locale`; with `auto` or none, the page's WPML language, else the WordPress site language (each wwiqtest blog has its own). WPML and WordPress codes are understood (`zh-hans`, `zh_HK`, `nb_NO`, `aben`, the themes' `se` for Swedish …); an unknown language gets English.

A page's ACF field `email_collection_error_msg` still wins over the built-in text, and the template's `email_error` wins over both. To add or change a language without a template change: `cellon_payment_collection_email_texts` filter.

**Customer messages and the separator.** The checkout's messages (declined, processing, unavailable, expired …) and "Or Pay with Card" are built in (`includes/site/class-message-texts.php`) for: ja, ko, zh (Simplified), id, vi, th, ms, the iqpro languages; English is the plugin's own text. Other languages show English until added there; the language is found as for the email field. A site changes any message with the `cellon_payment_collection_messages` filter, the separator with the `separator` attribute.

All sites: the billing country comes from the theme's `getVisitorCountry()` (default US), because the card form hides the country field.

Other texts come from the page's ACF fields:

- pay button and Solidgate submit: `get_result_button__label`
- processing text: `processing`
- card placeholder: `credit_card_placeholder`
- success text: `payment_successful_message`

### Email collection (dummy email)

**Collected in every language, on every site,** whenever the quiz result has no real email: the email is empty or the dummy `…_dummy@cellon.ai`. A template can switch it off for its page with `collect_email="0"`.

| Site | Before | Now |
| --- | --- | --- |
| iqpro, wwpersonality | `is_email_position_ab_test()`, which already returns true for all languages | same result: all languages |
| wwiqtest | only the Japanese blog (`iq_feature('jap_site')`) | all blogs and languages |

When collecting:

- **Stripe and BridgerPay BLOX:** an email field shows above the card form. It is validated before paying, with the message in the page's language, and sent to Stripe as the billing email.
- **Solidgate:** its own form shows the email field, as today. The plugin reads the address from Solidgate's success event on the server.
- **After the API records the sale:**
  1. The address goes to the Core plugin's `submit_email` action, with the same nonce, so it updates the customer through `/customer/update-customer-email` and the session as before.
  2. The `user_email` cookie is set (2 h).
  3. `Chargeblast.identify(email)` runs.
  4. `dataLayer` gets `autoEvent / funnel / email_insert`, and `analytics.track('email_submitted')` fires.
  5. Only then does the redirect run, waiting at most 2.5 s.

### Events

| Event | iqpro | wwiqtest | wwpersonality |
| --- | --- | --- | --- |
| `dataLayer` `stripe_payment` / `solidgate_payment` after the sale | ✓ | ✓ | ✓ |
| `dataLayer` `paypal_payment` | – | ✓ | ✓ |
| `dataLayer` `stripe_payment_failed` / `solidgate_payment_failed` (once per session, `paymentFailedRecorded`) | ✓ | ✓ | ✓ |
| `dataLayer` `paypal_payment_failed` (once per session) | – | ✓ | ✓ |
| `dataLayer` `first_sale_fronted` once per quiz (`localStorage first_sale_fronted_<qid>`; Stripe adds `payment_intent_id`) | – | ✓ | – |
| `analytics.track('first_sale', { amount: first_sale_usd_price }, 'custom')` | Stripe, PayPal | all | all |
| `gtag('event', 'PaymentPage')` on Solidgate submit, blogs 10 and 20 | ✓ | ✓ | ✓ |
| `do_action('ab_testing_main_total', …)` on the server (Core plugin parity, skipped for `ab_utm_source = Emails`) | – | ✓ | ✓ |
| BridgerPay (new): `bridgerpay_payment`, `bridgerpay_payment_failed` | ✓ | ✓ | ✓ |

The theme's `.preload` overlay shows while a payment is recorded and before the redirect, as before. After the sale the browser goes to the page for the API's `redirect_page` tag, using the Core plugin's page map with `?qid=`, just like the old handlers.

### Provider look

- **Stripe:**
  - Payment Element: wallets hidden inside it, country hidden, `tabs` layout on iqpro, card first.
  - Elements are created before the intent: `mode: payment` with the amount, `setupFutureUsage: off_session`. This needs the Stripe `public_key` in `payment_provider_details` and an amount. Without them (legacy `payment_gateways`), the intent is created when the form mounts.
  - Apple Pay / Google Pay: Express Checkout in `#gpay_btn`, buttons 50 px on iqpro and 55 px on the others, with each site's layout.
  - The theme font and colours.
- **Solidgate:**
  - Form parameters and styles as in each site's `solidgate.js`: no cardholder field, CVV hidden, orange submit button.
  - Apple Pay or Google Pay button in `#gpay_btn`, with the class `solid_gpay` while Solidgate is active.
- **PayPal:** blue button, 50 px on iqpro and 55 px on the others; `disable-funding=credit,card,paylater,venmo`; `vault=true`.
- **BridgerPay:**
  - Checkout widget: `credit_card` only, light theme, "Pay" button.
  - BLOX: the site font and the card placeholder.

### Back from a Stripe redirect

Alipay and some bank 3DS pages leave the page and come back with `?payment_intent=…&redirect_status=…`. The checkout that created that intent confirms it with the API. A failed redirect shows "Payment failed" and the form again, like `stripe-new.js` did.

## 8. Migration without duplicate handlers

Per page:

**Step 1. Swap the block behind a switch, so rollback is one line.** Wrap the old `.payment-methods` block, from the express row to the end of `#paymentResponse`, **and** the template's payment `<script>` block. Keep the `.pmp` / modal wrapper or the `#iq-report` section, the `.preload` overlay, the price title, `sub_disclaimer_message` and the timers outside.

```php
<?php $cpc = function_exists( 'cellon_payment_collection' ) && apply_filters( 'site_use_cellon_payment_collection', true ); ?>

<?php if ( $cpc ) : ?>
    <?php echo do_shortcode( '[cellon_payment_checkout flow="first-sale"]' ); ?>
<?php else : ?>
    … old .payment-methods block …
<?php endif; ?>

…

<?php if ( ! $cpc ) : ?>
    … old <script> block: STRIPE_PUBLISHABLE_KEY, PAYPAL_*, merchant_key, Stripe / Solidgate / PayPal SDK tags,
      stripe_new.js / stripe-new.js, solidgate.js, paypal-*.js, gpay settings …
<?php endif; ?>
```

The PHP above the markup that creates the Solidgate intent (`create_solidgate_intent`) and picks the gateway (`$show_stripe` / `$show_solidgate` / `$show_paypal`) must also go inside `if ( ! $cpc )`. Otherwise the page creates an unused Solidgate intent on every view. Keep `get_results()`; the shortcode reuses its session.

**Step 2. Test on staging.** Run Stripe and Solidgate test cards and a PayPal sandbox buyer. Check:

- success → the cross-sell page;
- a decline on provider 1 → provider 2 mounts;
- dummy-email collection on a Japanese page;
- the events, in GTM preview.

**Step 3. Remove the old block** once the page has run cleanly in production for a release.

**Step 4. Retire the old handlers** with the `cellon_payment_collection_legacy_guards` filter (§2), only after **every** page that used them is migrated:

| Guard | Retire when |
| --- | --- |
| `paypal_checkout_api_payment` | No page loads `paypal-checkout.js` / `paypal-subscription-checkout.js`. **First**: it forwards a browser-claimed `payment_status: "paid"`. |
| `stripe_payment_element_frm`, `stripe_payment_confirm` | No page loads `stripe-new.js` / `stripe_new.js`. |
| `solid_payment_sucess_frm` | No page loads `solidgate.js`. |
| `cross_sale_payment` | Every Offer Page uses `flow="cross-sale"`. |
| `/wp-json/paypal/v1/token` (iqpro) | iqpro's PayPal no longer uses `paypal-checkout.js`. As early as possible. |

`submit_email` stays: the plugin uses it.

**Step 5. Rotate the exposed credentials** ([SECURITY-FINDINGS.md](SECURITY-FINDINGS.md)).

Rollback: `add_filter( 'site_use_cellon_payment_collection', '__return_false' );`, and switch the guards off.

## 9. Per-site page map

Line numbers are from the current files and shift as you edit.

### iqpro (`iqpro/theme/test_iq`)

| Page | Layout | Old payment markup | Old scripts | Shortcode |
| --- | --- | --- | --- | --- |
| `page-template/quiz-payment.php` (Quiz Payment, page 9989) | Payment page dialog (`.pmp` / `#IQpaymentModal`) | 200–252 | 279–430 | `flow="first-sale"` |
| `page-template/checkout-page-v2.php` (Checkout Page V2, aben; via Quiz Checkout) | Checkout page, inline | 556–611 | 786–909 | `flow="first-sale"` |
| `page-template/checkout-page-v2-jp.php` (Checkout Page V2, ja; via Quiz Checkout) | Checkout page, inline | 473–526 | 687–840 | `flow="first-sale"` |
| `page-template/offer.php` (Offer Page) | Cross sale | form 75–106 | 116–207 | `flow="cross-sale"` |
| `page-template/new-checkout-payment.php` (blog 20) | Legacy Tranzila / BlueSnap page | — | — | Not migrated. Retire the page. |

### wwiqtest (`wwiqtest/themes/test_iq`)

| Page | Layout | Old payment markup | Old scripts | Shortcode |
| --- | --- | --- | --- | --- |
| `page-template/quiz-payment.php` (Quiz Payment) | Payment page dialog | 269–346 | 360–567 | `flow="first-sale"` |
| `page-template/quiz-checkout-v2.php` (Quiz Checkout V2) | Checkout page, inline | 374–432 | 586–760 | `flow="first-sale"` |
| `page-template/quiz-checkout-v3.php` (Quiz Checkout V3) | Checkout page, inline, multi-plan | 600–692 / 697–756 | 913–1030 | `flow="first-sale"`, plus `ab_testing_price_category` from the plan picker via `cellon_payment_checkout_context_data` |
| `page-template/quiz-checkout-jp.php` (Quiz Checkout JP) | Checkout page, inline | 527–615 | 875–1097 | `flow="first-sale"` |
| `page-template/quiz-checkout-abjap.php` (Quiz Checkout ABJAP) | Checkout page, inline | 782–851 | 1066–1180 | `flow="first-sale"` |
| `page-template/offer.php` (Offer Page) | Cross sale | form 161–226 | 254–367 | `flow="cross-sale"`, `show_skip="0"` when `cross_sale_compulsory` |

Migrate the checkout templates that are actually assigned to pages (Pages → Template column).

### wwpersonality (`wwpersonality/themes/test_iq`)

| Page | Layout | Old payment markup | Old scripts | Shortcode |
| --- | --- | --- | --- | --- |
| `template-parts/quiz-payment-gpay-applepay.php` (included by Quiz Payment) | Payment page dialog | 140–224 | 235–413 | `flow="first-sale"` |
| `page-template/quiz-checkout-ko.php` (Quiz Checkout for KO) | Checkout page, inline | 475–561 | 798–1018 | `flow="first-sale"` |
| `page-template/quiz-checkout-jp.php` (Quiz Checkout JP) | Checkout page, inline | 529–621 | 881–1106 | `flow="first-sale"` |
| `page-template/offer.php` (Offer Page) | Cross sale | form 83–116 | 123–221 | `flow="cross-sale"` |

`quiz-checkout.php` on wwpersonality also holds a dead `#paymentModal` popup (its open button is commented out). Leave it alone or delete it.

## 10. How a payment runs

```
render    shortcode → quiz result (Core plugin get_results) → provider lists → site profile
          → checkout session stored server-side (token, context, x-host; 2 h) → markup + config

browser   provider 1 of the sequence mounts (and PayPal on its own)
 intent   POST /wp-json/cellon-payment/v1/checkouts/{token}/providers/{slug}/intent
          server: lock, provider listed for this quiz result, unclear earlier attempt settled first,
          body = server-side context + allow-listed provider fields → API intent route
 pay      the customer pays in the provider's SDK (Stripe, Solidgate form, PayPal approval, BridgerPay)
 confirm  POST …/providers/{slug}/confirm  → API confirm route (PayPal: API captures) → paid
          → events, email (submit_email), redirect to the API's redirect_page
 fail     next provider mounts in place (1 → 2 → 3), after 1.5 s
 unclear  never switch; POST …/status asks the API whether the sale was recorded
```

## 11. Hooks reference

| Hook | Type | Purpose |
| --- | --- | --- |
| `cellon_payment_checkout_context` | filter | Context array instead of the Core plugin result. |
| `cellon_payment_checkout_context_data` | filter | Adjust the context data (any source). |
| `cellon_payment_collection_use_core_results` | filter | `false` = do not call the Core plugin's `get_results()`. |
| `cellon_payment_collection_site_profile` | filter | Events, labels, email collection, provider environment per site (§7). |
| `cellon_payment_collection_adapter_options` | filter | Final browser options per provider, keyed by slug (each provider builds its own in `browser_options()`). |
| `cellon_payment_collection_stripe_email_element` | filter | `false` = Stripe uses the page's email field instead of its Contact Details Element. |
| `cellon_payment_collection_messages` | filter | Customer texts. |
| `cellon_payment_collection_redirect_url` | filter | URL for a `redirect_page` tag. |
| `cellon_payment_collection_payment_succeeded` | action | `( $slug, $api_data, $context )` after the API recorded a sale. |
| `cellon_payment_collection_fire_ab_event` | filter | Fire `ab_testing_main_total`. |
| `cellon_payment_collection_provider_config` | action | Inspect or extend the provider lists. |
| `cellon_payment_collection_providers` | filter | Add or replace provider handlers (README "Adding a gateway"). |
| `cellon_payment_collection_payment_api` | filter | Swap the API adapter (future common payment API). |
| `cellon_payment_collection_current_routes` / `_common_routes` | filter | Route maps (the current map is built from each provider's `api_routes()`). |
| `cellon_payment_collection_enabled_providers`, `_legacy_guards`, `_api_adapter`, `_api_timeout` | filter | Code-level switches (§2). The API URL, secret and headers have no hook here: they are the Core plugin's. |
| `cellon_payment_collection_api_headers` | filter | Headers of one API call (server only). |
| `cellon_payment_collection_is_paid`, `_unknown_grace_seconds`, `_max_intents` | filter | Status check, wait after an unclear outcome (90 s), intents per checkout (25). |
| `cellon_payment_collection_paypal_return_hosts`, `_same_origin` | filter | PayPal return hosts, REST origin check. |
