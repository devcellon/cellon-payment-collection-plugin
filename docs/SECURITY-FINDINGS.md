# Security findings in the current site code

These are from a read-only review of the three themes and the Core plugins on 2026-11-05. Secrets are shown by their **first 6 characters** only. This plugin removes the need for every item below, but removing the old code from production does **not** make a leaked credential safe again.

**Rotate every credential in this list.** They are in source files, and most of them are in pages every visitor downloads.

## Credentials to rotate

| # | What | Where | Exposure |
| --- | --- | --- | --- |
| S1 | Cellon API site token `3d2454…` | `class-api-client.php:254` (comment) in all three Core plugins; also pasted in chat | Source |
| S2 | **PayPal live app** client id `AbjA1q…` / **secret `EHBqvm…`**, sandbox `AS8PO9…` / `EMsSpl…` | wwiqtest `quiz-payment.php:158-165` (live page), `quiz-checkout-v2/v3/jp/ko/abjap.php`, `template-parts/quiz-payment-gpay-applepay.php:78-83`; wwpersonality `quiz-checkout-jp/ko.php`, `template-parts/quiz-payment-gpay-applepay.php:78-83` (live page); iqpro `new-checkout-payment.php:190-196` | **Printed into page JavaScript** (`PAYPAL_APP_SECRET`). Anyone can use it to issue refunds, read transactions or create payouts. Rotate first. |
| S3 | PayPal second live app `Actbwh…` / `EDzFTD…` | wwiqtest and wwpersonality `template-parts/quiz-payment-normal.php:113-114` | Source |
| S4 | BlueSnap API users and passwords: sandbox `API_16…` / `dev@WW…` and `API_17…` / `9@e6,>…`; live `API_16…` / `B67091…` | iqpro `new-checkout-payment.php:130-135`; wwiqtest and wwpersonality `quiz-payment-normal.php:54-61` | Source |
| S5 | Solidgate API keys `api_pk…` / `api_sk…` (test and live) | iqpro `new-checkout-payment.php:230-234`; commented in wwiqtest `quiz-payment.php:207-211` | Source |
| S6 | Solidgate webhook keys `wh_pk_…` / `wh_sk_…` | iqpro `inc/custom-functions.php:272-276` | Source |
| S7 | iqbooster save-token `x-api-key` `solid_…` | iqpro `inc/custom-functions.php:330` | Source |
| S8 | GA4 Measurement Protocol secret `0ul0c4…` | wwiqtest and wwpersonality `inc/custom-events.php:5, 56, 249` | Source |
| S9 | Encryption key / iv `my_sim…` | iqpro `custom-functions.php:140-141`, wwiqtest `custom-functions.php:135-136`, wwpersonality `functions.php:571-572` | Source |
| S10 | Tranzila terminal `wwiqte…` | iqpro `new-checkout-payment.php:112/118`; `quiz-payment-normal.php:43/46` | Source (identifier, low risk) |

Public identifiers that are fine to be public: the Google Pay merchant `BCR2DN…` and Stripe publishable keys `pk_liv…`. Even so, once each page is migrated, take them from the API rather than hard-coding them.

## Unsafe endpoints and handlers

| # | Finding | Fix |
| --- | --- | --- |
| E1 | iqpro `POST /wp-json/paypal/v1/token` (`inc/custom-functions.php:545-606`) returns a **live PayPal merchant access token** to any visitor. The `wp_rest` nonce it checks is printed for anonymous users, and the caller chooses `test` or `live`. | Retire it with the plugin's guard right after iqpro's PayPal is migrated, then delete the route. |
| E2 | `paypal_checkout_api_payment` (Core plugin, all sites) forwards `'payment_status' => 'paid'` and browser-supplied capture ids to the API. Anyone can post a made-up order id. | Plugin: server-side create-order and confirm only. API: contract P1. Retire the handler with the guard. |
| E3 | The browser creates and captures PayPal orders itself (`paypal-checkout.js`, `paypal-subscription-checkout.js`) using S2 or E1. | Removed by the migration (§7 in INTEGRATION.md). |
| E4 | `solid_payment_sucess_frm` forwards the browser's Solidgate success event as `payment_obj`. | API: verify with Solidgate (contract G). |
| E5 | wwiqtest `functions.php:441-464` serves `admin-ajax.php` with `Access-Control-Allow-Origin: *` **and** `Allow-Credentials: true`. iqpro `custom-functions.php:535-542` answers every OPTIONS request with `*`. | Remove, or restrict to known origins. |
| E6 | wwiqtest `secrateajax.php`: a copy of admin-ajax that runs `wp_ajax_*` and `wp_ajax_nopriv_*` without authentication. | Delete. |
| E7 | `quiz-payment-normal.php` (orphan) has a raw card-number / CVC form (`tranzila_api_payment_frm`). | Delete the file. |
| E8 | The Core plugin's `Request_Helper::get_ip()` trusts `Client-IP` / `X-Forwarded-For`, which any client can set. | This plugin uses `CF-Connecting-IP` or `REMOTE_ADDR` (filterable). Consider the same in the Core plugin. |

## What the new plugin does instead

- **API token:** in the quiz-funnel-core plugin's settings only; this plugin has no copy and no settings screen. It is sent server-to-server and never printed.
- **Provider keys:** always from the API response for the quiz result.
- **Browser endpoints** (`/wp-json/cellon-payment/v1/checkouts/{token}/…`) require all of these:
  - a 256-bit per-render checkout token, stored server-side for 2 h and bound to the PHP session;
  - a same-origin request;
  - a provider and account that the API listed for this quiz result;
  - provider fields that pass an allow-list. The quiz result, email and pricing fields always come from the server session.
- **Duplicate charges:**
  - Steps are serialized per checkout with an atomic database lock.
  - A confirm must reference an intent of the same checkout.
  - After an unclear outcome, the payment status is checked before any new attempt.
  - Once paid, every later step answers "already paid".
- **Card testing:** at most 25 intents per checkout (filter).
- **Logs:** off by default. Tokens, signatures, card tokens, `payment_obj` and emails are redacted.
