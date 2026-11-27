# Cellon Payment Collection

One WordPress plugin, one source folder, installed unchanged on **iqpro**, **wwiqtest** and **wwpersonality**. It replaces each site's payment area with one shortcode:

```
[cellon_payment_checkout flow="first-sale"]
[cellon_payment_checkout flow="cross-sale"]
```

The shortcode shows the payment providers the Cellon API configures for the quiz result. The result is fetched through the installed quiz-funnel-core plugin (`cellonnexus()->quiz->get_results()`).

- **Provider sequence** from `payment_provider_details`, lowest `order` first. Provider 1 mounts; after a declined payment, provider 2 mounts in the same place, then provider 3: every provider is tried once and a failed one is never mounted again (`max_switches`, `last_gateway`). A provider that cannot load is skipped. BridgerPay BLOX also shows BridgerPay's Google Pay / Apple Pay in the wallet slot.
- **Direct providers** from `direct_payment_provider_details`, for example PayPal. Each loads on its own, next to the wallet button (Google Pay / Apple Pay); the plugin lays out that row itself (`express_layout`).
- **Customer messages** in the page's language (built in for en, ja, ko, zh, id, vi, th, ms; others English).

Each site keeps its current behaviour, ported from the themes' `stripe-new.js` / `stripe_new.js`, `solidgate.js` and `paypal-*.js` ([INTEGRATION.md §7](docs/INTEGRATION.md#7-site-behaviour-carried-over-from-the-themes)):

- languages, passed by each template as shortcode attributes (the plugin has no language rules of its own);
- dummy-email collection, saved by the plugin itself after the first sale (`PUT /customer/update-customer-email`, same body as the Core plugin's `submit_email`);
- `dataLayer`, `analytics` and `gtag` events;
- ACF labels;
- the theme markup and classes, in the Quiz Payment dialog or inline on the checkout page.

The plugin's one setting is its on/off switch: **Settings → Payment Collection**, an ACF options page (Secure Custom Fields or ACF PRO). The checkout runs only when the plugin is active **and** that switch is On. It is per site (every site of a multisite network has its own switch) and, on WPML sites, can be limited to some languages. Off, the theme keeps its own payment code. The API URL, API secret, x-host and redirect pages come from quiz-funnel-core's settings, so they are configured in one place.

Every payment call runs on the server. The browser talks only to this plugin's REST endpoints and to the providers' own SDKs. It never receives the Cellon API token or a provider secret, and never reports a payment result itself. The API confirms each payment, and for PayPal it captures the order.

| Provider | Status |
| --- | --- |
| Stripe (Payment Element, Apple Pay / Google Pay) | Supported |
| Solidgate (card form, wallets) | Supported |
| PayPal (server-side create-order and confirm) | Supported. Needs the API to capture on every site: [contract P1](docs/BACKEND-CONTRACTS.md) |
| BridgerPay (widget, BLOX) | Supported, on by default. Needs the API's BridgerPay routes; without them the sequence moves on. |
| Evervault (its own card form, Apple Pay and Google Pay; one API call runs the payment on the account's processors, no time limit) | Card: supported, on by default. Apple Pay / Google Pay: the button shows (the API sends the merchant id); paying with it waits for the API to accept the wallet body ([contract E2](docs/BACKEND-CONTRACTS.md)). The page takes the first sale only: the cross-sale and renewals are charged by the backend. 3D Secure (card form): built to the funnel contract v0.1 (3DS session → bank's window → charge); it pays without 3DS until the API's `3ds-session` route is deployed and switched on (E3). |
| BlueSnap, Tranzila | Out of scope (not required now). Skipped if listed. |

## Documents

- [docs/INTEGRATION.md](docs/INTEGRATION.md): configuration, installation per site, the shortcode, the context hook, styling, and a migration that never runs two payment handlers for one checkout.
- [docs/BACKEND-CONTRACTS.md](docs/BACKEND-CONTRACTS.md): what the Cellon API must provide or change. Covers PayPal capture, provider fields, Solidgate verification, BlueSnap, Tranzila and BridgerPay.
- [docs/SECURITY-FINDINGS.md](docs/SECURITY-FINDINGS.md): credentials found in theme and plugin source that must be 
rotated, and unsafe endpoints to retire.

## Layout

```
cellon-payment-collection.php        bootstrap
includes/
  class-config.php                   server-only config, read from quiz-funnel-core: API URL, secret, x-host, client IP
  api/                               Payment_Api interface + Current_Payment_Api (per-provider routes)
                                     + Common_Payment_Api (initiate/confirm) + Http_Transport (POST / PUT)
                                     + Customer_Api (customer email update after the first sale)
  providers/                         one class per provider (Stripe, Solidgate, PayPal, BridgerPay, Evervault) + registry:
                                     field allow-lists, body building, intent ↔ confirm references, its API routes,
                                     its browser file and options, its analytics names, an email its form collected;
                                     One_Call_Provider for a payment the API runs in one call (Evervault)
  checkout/                          Provider_Config (normalizes provider lists), Checkout_Context + Context_Resolver
                                     (hook, then session), Checkout_Session + Session_Store (state, atomic lock),
                                     Payment_Orchestrator (server rules against duplicate charges)
  http/class-rest-controller.php     the browser's endpoints (cellon-payment/v1)
  site/class-site-profile.php        site-wide behaviour from the themes: email collection, labels, site events; languages from shortcode attributes
  site/class-email-texts.php         email field texts per language; resolves the page language
  site/class-message-texts.php       customer messages + "Or Pay with Card" per language
  frontend/                          shortcode (theme markup), assets
  migration/class-legacy-guard.php   retires old admin-ajax / REST payment handlers, one switch per handler
assets/js/core.js                    window.CellonPC: gateway registry, REST client, SDK loader, failover policy
assets/js/sequence.js                CellonPC.Sequence: one provider at a time, in-place fallback
assets/js/gateways/<slug>.js         one file per provider; each registers itself with its own settings
assets/js/checkout.js                page code: boxes, email field, express row, analytics, redirects
tools/package.ps1                    makes the installable zip
```

### Browser code

The browser code is this plugin's own plain JavaScript (ES2017). There is no npm, no bundler and no build step: edit the files in `assets/js/` and reload. It does not use or bundle `nexus-payment-gateways`; that package was reference reading only. WordPress loads the files in order through script dependencies (`includes/frontend/class-assets.php`): `core.js` → `sequence.js` → every enabled provider's gateway file → `checkout.js`. Provider SDKs (Stripe.js, Solidgate, PayPal, BridgerPay, Evervault) load from the providers' own URLs when a gateway mounts, and card fields stay in the providers' iframes.

Nothing in `core.js`, `sequence.js` or `checkout.js` names a gateway. Each gateway file declares what differs:

```js
CellonPC.registerGateway({
    slug: "solidgate",
    mount: function (ctx) { /* → Promise<{ submit?, destroy }> */ },
    ownButton: true,              // the provider has its own pay button (else the page's Pay button)
    email: "provider",            // who collects the email: "page" | "element" | "provider"
    emailAtIntent: true,          // the intent keeps its email: remount when the page's email changes
    remountOnRetry: true,         // a declined intent cannot be paid again
    intentOnClick: false,         // PayPal: its button makes the order on click; a refused order keeps the button
    verifyUntilKnown: false,      // Evervault: after an unclear outcome, ask the server until it knows
    processingNotice: false,      // the provider shows its own processing state
    walletClass: "solid_gpay",    // class on the page's wallet container while active
    analytics: null,              // e.g. { referenceKey: "payment_intent_id" } for first_sale_fronted
    resume: null,                 // back from a provider redirect: { params, body(reference) }
});
```

Any setting may be a function `(options, gateway)`, for settings that depend on the site's options (Stripe collects the email only when its Contact Details Element is on).

### Adding a gateway

1. **Browser:** `assets/js/gateways/<slug>.js` calling `CellonPC.registerGateway({ slug, mount, … })`. `mount(ctx)` gets `ctx.gateway`, `ctx.host`, `ctx.options` (from the PHP class), `ctx.checkout`, `ctx.api.intent / confirm / chargeToken / pay` (this plugin's REST endpoints; `pay` = a payment the API runs in one call), `ctx.events.ready / processing / succeeded / failed / cancelled / accountChanged` and `ctx.page.email / reportEmail`. Report a failure as `{ stage: "load" | "intent" | "payment" | "confirm", code, message }`; the sequence decides whether to move on.
2. **Server:** `includes/providers/class-<slug>-provider.php` extending `Abstract_Provider`: `slug()`, `label()`, the field allow-lists, `intent_reference()` / `confirm_reference()`, and as needed `api_routes()` (today's per-gateway routes; none with the common API), `browser_options( $env )`, `site_events()`, `collected_email()`, `redirect_return()`. Register it in `Provider_Registry` (and `cellon-payment-collection.php`), or from outside through the `cellon_payment_collection_providers` filter with `script_url()` pointing at its browser file.

Nothing else changes: the provider is enabled by default, its script is enqueued, its options and events reach the page, and the REST endpoints accept it when the API lists it for the quiz result.

Requirements: WordPress 6.0+, PHP 7.4+. HTTPS is required for Apple Pay / Google Pay.
