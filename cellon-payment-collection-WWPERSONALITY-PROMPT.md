# Integrate Cellon Payment Collection on wwpersonality (with a new Evervault gateway)

Goal: wwpersonality's first-sale payment pages render through the Cellon Payment Collection plugin, as iqpro already does, and the plugin gains an **Evervault** gateway so it carries every gateway this site uses. Work in phases and stop where a phase says so.

## What exists (verify, don't assume)

- **Plugin source (the only place to edit plugin code):** `c:\wamp64\www\iqpro.local\wp-content\plugins\cellon-payment-collection`. It is outside this project: if you can't read or edit it, ask me to run `/add-dir` for it. Don't work from a copy. It's a git repo on branch `Dev` with uncommitted work: don't commit, push, stash or reset it. Read `README.md` ("Browser code", "Adding a gateway"), `docs/INTEGRATION.md` (§2–4, §7, §8, §9 wwpersonality rows) and `docs/BACKEND-CONTRACTS.md`. The copy in `D:\Nexus_Vault_frontend\cellon-payment-collection` is stale; ignore it.
- **Reference integration (iqpro, already live locally):** the helper at `c:\wamp64\www\iqpro.local\wp-content\themes\test_iq\inc\cellon-payment-collection.php`, plus how `page-template/quiz-payment.php`, `checkout-page-v2.php` and `checkout-page-v2-jp.php` in the same theme wrap the old code behind `$cpc`.
- **Site:** `c:\wamp64\www\wwpersonalitytest.local`, a subdomain multisite:
  - blog 1 `wwpersonalitytest.local` (WPML: en, ko)
  - blog 2 `jp.`
  - blog 3 `abjp.`
  - blog 4 `ab.`

  Only `wwpersonalitytest.local` and `jp.` are in the hosts file. The site is HTTP only (no :443 vhost).
- **Theme:** `wp-content/themes/test_iq`, branch `feature/evervualt_payment_getway`, with uncommitted Evervault work. That work is in `page-template/quiz-checkout-jp.php`, `page-template/quiz-checkout-ko.php`, `template-parts/quiz-payment-gpay-applepay.php` and the new `js/evervault.js`. Build on top of it, on this branch.
  - The working copies are LF while git stores CRLF, so don't touch line endings. Review diffs with `git diff --ignore-cr-at-eol`.
  - `c:\wamp64\www\wwpersonality_test` is a separate theme clone and is not used by the site. Ignore it.
- **Core plugin:** `wp-content/plugins/quiz-funnel-core` (network-active) is on the same branch, with uncommitted Evervault code. Don't modify Core.
  - `services/class-payment-service.php`: `create_evervault_intent` → `POST /payment/evervault/create-payment-intent`, and `confirm_first_sale_evervault` → `POST /payment/evervault/first-sale/payments/confirm`.
  - `controllers/class-payment-controller.php`: the `wp_ajax_evervault_payment_confirm` handler.
  - `helpers/class-request-helper.php`: maps `*.local` to `*.com` for the x-host.
  - API settings are network site options (`cellonnexus_env` = development). The plugin reads them as they are.
- **PHP CLI:** `c:\wamp64\bin\php\php8.2.26\php.exe` (not on PATH). Scripts that load `wp-load.php` from Git Bash need `MSYS_NO_PATHCONV=1` and must set `$_SERVER['HTTP_HOST']` and `$_SERVER['REQUEST_METHOD']`.

## Decisions already made (don't re-ask)

1. Evervault becomes a plugin gateway. When the plugin is Off, the theme's own Evervault code keeps working unchanged.
2. The Offer page (`offer.php`, cross-sale) stays on the old code. Don't migrate it.
3. Enabling is done only through the plugin's ACF switch, **Settings → Payment Collection**, per site (and per WPML language on blog 1). Don't put site or language enable rules in code. The switch is Off by default.
4. Plugin code stays site-agnostic. wwpersonality-specific behaviour goes in the theme helper; the plugin's existing site profile covers the rest. Plugin defaults stay as they are (each card provider tried once, one provider on screen at a time).

## Phase 1: find the templates, report, then STOP for my OK

Find every template that renders a **first-sale** payment, per blog and language. Starting points from the local DB (verify each one):

- `page-template/quiz-payment.php`, which includes `template-parts/quiz-payment-gpay-applepay.php`. It's on blog 1 (page 9989 en, 213828 ko) and page 9989 on blogs 2, 3 and 4.
- `page-template/quiz-checkout.php`. Blog 1 uses pages 363 (en) and 213832 (ko); on ko it `require`s `quiz-checkout-ko.php`, which has an inline payment. It's also page 2421570 on blog 2 and page 363 on blogs 3 and 4. Check whether the English version renders a payment anywhere; INTEGRATION §9 says its `#paymentModal` is dead.
- `page-template/quiz-checkout-jp.php` (inline payment). Blog 1 uses pages 370640 and 370652; blog 2 uses 363 and 2421561.
- `google-pay.php` and `braingym-quiz-payment.php` are not assigned to any page locally. Ask me whether staging or live uses them.

For each template, record:

- where the old payment markup is;
- the old `<script>` block;
- the PHP that creates intents (`create_solidgate_intent`, `create_evervault_intent`) and picks the gateway (`$show_*`);
- which gateways it can show;
- its blog and language rules, the ACF labels it uses, and its events.

Then check against the dev API with a real wwpersonality quiz result:

- Is Evervault listed in `payment_provider_details` / `direct_payment_provider_details` (with what slug and order), or only as name `Evervault` in `pricing_info.payment_gateways`? Which gateways appear per blog and language?
- Does the common API (`/payment/first-sale/payments/initiate|confirm`) accept Evervault, or only the per-gateway routes above? Which adapter should wwpersonality use? The plugin defaults to `current`; iqpro forces `common` in its helper.

Report a table of template × blog/language × gateways × layout (dialog or inline), with your plan and any backend gaps. **Wait for my OK.**

## Phase 2: Evervault gateway in the plugin

Follow README "Adding a gateway". `core.js`, `sequence.js` and `checkout.js` must not name a gateway.

- **Server:** add `includes/providers/class-evervault-provider.php`, extending `Abstract_Provider`, and register it in `Provider_Registry` and the bootstrap.
  - **Intent:** goes to `/payment/evervault/create-payment-intent` with the same body Core sends: `email`, `quiz_result_id`, `user_on_iqbooster`, plus the plugin's standard pricing fields (`prc_id`, `pricing_discount`, …). The intent reference is `payment_intent_id`.
  - **Browser ids:** `team_id` and `app_id` from the intent response go to the browser. They are public ids.
  - **Confirm:** goes to `/payment/evervault/first-sale/payments/confirm` with `payment_intent`, `card`, `quiz_result_id`, `user_on_iqbooster`, `prc_id` and `pricing_discount`.
  - **Card allow-list:** `card.number` and `card.cvc` must be Evervault-encrypted `ev:…` strings; reject anything else and never log them. Also allow `exp_month`, `exp_year`, `brand`, `bin` and `last_four`.
  - **Common API:** add a common-API mapping if Phase 1 showed it supports Evervault.
- **Provider lists:** if Nexus names Evervault only in the legacy `payment_gateways`, extend `Provider_Config`'s legacy fallback with the theme's precedence (SolidGate, then Evervault, then Stripe).
- **Browser:** add `assets/js/gateways/evervault.js`.
  - At mount, load `https://js.evervault.com/v2` through the core SDK loader, then `new Evervault(team_id, app_id)` and `ui.card` with the clean theme and translated field labels.
  - It uses the page's Pay button and email field, like Stripe and BLOX.
  - An incomplete card raises `events.cancelled`; that is not a failure.
  - A decline raises `events.failed` with stage `payment`, so the sequence moves on.
  - Use one intent per attempt; check whether a declined Evervault intent can be confirmed again.
  - Not a secure context (not HTTPS): report a load failure so the sequence skips Evervault.
- **Texts:** card labels, placeholders and the card-incomplete message go in the plugin per language. Take ja, ko and en from the theme's `js/evervault.js`; other languages get English.
- **Events:** keep parity with the theme and Core:
  - `dataLayer` `evervault_payment`;
  - `evervault_payment_failed`, once per session (`paymentFailedRecorded`);
  - `analytics` `first_sale` with `first_sale_usd_price`;
  - the `ab_testing_main_total` server event.
- **Email:** the same flow as the other gateways that use the page's email field: validate before paying, then save after the sale through the plugin's `/email` endpoint. Ask me whether the typed email must also travel as `customer_email` on the Evervault intent or confirm, as it does for BridgerPay. Core currently sends the quiz email at intent.
- **Docs:** update the README provider table, INTEGRATION.md (§7 provider look and events, §9 wwpersonality rows, §11 for any new hooks) and BACKEND-CONTRACTS.md for any backend gap.

## Phase 3: theme integration (branch `feature/evervualt_payment_getway`)

- Add `inc/cellon-payment-collection.php`, modelled on iqpro's helper, and include it from `functions.php`. Check function names for collisions first. It should cover:
  - the switch: `cellon_payment_collection_enabled()` combined with the filter `site_use_cellon_payment_collection`;
  - nocache;
  - `iq_payment_language_atts()` for wwpersonality: blogs 2 and 3 → `ja`, WPML `ko` → `ko`, everything else `auto` (INTEGRATION §7);
  - ACF labels;
  - Solidgate texts through `cellon_payment_collection_adapter_options`;
  - the success text from `payment_successful_message`.
- In each template from Phase 1, apply the `$cpc` pattern from INTEGRATION §8:
  - **Inside `! $cpc`:** the old `.payment-methods` block, the old script block (Stripe, Solidgate, PayPal and Evervault SDKs and theme JS), and the PHP that creates intents and picks gateways.
  - **Stays outside:** `get_results()`, the wrapper (the `.pmp` dialog or the inline section), `.preload`, the price title, `sub_disclaimer_message` and the timers.
  - Call nocache before `get_header()`.
  - With the plugin Off, the page must behave exactly as it does now, Evervault included.
- Leave `offer.php` alone.

## Phase 4: local setup (ask before any system change)

- **Install:** the plugin was already copied into `wwpersonalitytest.local/wp-content/plugins/cellon-payment-collection` on 2026-11-23 (from plugin commit `1d1fbbd`, without `.git`). It is not activated yet; ask me before activating it. Network activation is fine, because the switch is per site and Off by default. Re-copy after every plugin change, and confirm the two copies are identical before each test.
- **HTTPS:** Evervault needs a secure context, and Google Pay / Apple Pay need HTTPS. iqpro.local already has a mkcert certificate and a `*:443` vhost in `c:\wamp64\bin\apache\apache*\conf\extra\httpd-vhosts.conf`. Propose the same for `wwpersonalitytest.local` and `*.wwpersonalitytest.local`, including each blog's home and siteurl. Apache, hosts and certificate changes need admin rights, so give me the exact steps instead of making them yourself.
- **Hosts:** give me the lines to add for `abjp.` and `ab.`.
- **Geo IP:** the API geolocates the `ip_address` header and answers "Country could not be detected" without it. `mu-plugins/fake_ip.php` only fakes the IP when REMOTE_ADDR is `192.168.1.1` or `::1`, or the host contains `.info`. Make sure local requests send a geolocatable IP before testing.
- **Switch:** tell me which blog and language to turn On in Settings → Payment Collection. I'll do it through ACF; don't set it yourself.

## Phase 5: test in the real site flow and report

- Follow the site's own flow: home → take the quiz → click the site's buttons through to payment. Use one fresh quiz per case. Never type checkout or payment URLs by hand, and never reuse a qid on another blog or language.
- In Playwright, block navigation to the live domains (`wwpersonalitytest.com` and its subdomains), because home pages can link to the live quiz. Click only relative or `.local` links.
- Cases, per blog and language with the switch On:
  - every gateway the API lists succeeds and redirects to the Offer page;
  - a decline on provider 1 mounts provider 2;
  - Evervault success, decline and incomplete card;
  - email collection when the quiz email is a dummy;
  - PayPal sandbox;
  - the old Offer page's cross-sale works after a first sale made through the plugin, including after Evervault;
  - with the switch Off, the old code, Evervault included, is unchanged.
- **Test cards:**
  - Stripe 4242 4242 4242 4242.
  - Solidgate sandbox: 4890838637940261 approves, 5151948477715326 declines.
  - Ask me for Evervault test cards and the PayPal sandbox login. Pass logins through env vars only, and never write them to files, logs or memory.
- I check results in Admin by quiz id. Don't re-post payments to verify them.
- Deliver a report artifact with steps and screenshots per blog, language and gateway.

## Rules

- Before starting, run `git fetch` in the theme and Core repos. If a branch is behind origin, tell me before pulling (there is uncommitted work).
- No commits or pushes in any repo unless I ask. Never stash, reset or check out over uncommitted work.
- Never print or write secrets. The theme's git remote URLs contain tokens, so don't run `git remote -v`. The same applies to the API secret and to the PayPal secrets in the templates.
- The Nexus backend (`D:\Cellon_nexus`) is read-only: no changes there. Report backend gaps instead.
