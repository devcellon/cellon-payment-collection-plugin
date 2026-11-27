# Backend contracts the plugin depends on

This lists what the Cellon API must provide, or change, for each flow. It covers what the plugin sends, what it expects back, and what is missing. No route here was invented: each one is used by the Core plugin today or documented in `nexus-payment-gateways`. Where a better route would help, it is marked **Proposal**.

Status key: **Ready** = the plugin can run it against today's routes. **Needed** = the plugin is built for it but depends on the API change. **Proposal** = no contract exists; the plugin does not run it.

---

## C. Checkout configuration

### C1. Provider lists on the quiz result: Needed (confirm field names and meaning)

The plugin reads these from `quiz_result_data` (POST `/questions/results`) or its `pricing_info`. A theme can also pass them through the context hook.

| Field | Meaning the plugin applies |
| --- | --- |
| `payment_provider_details[]` | Automatic fallback chain. |
| `direct_payment_provider_details[]` | Providers shown on their own and loaded independently (PayPal). `fixed_payment_provider_details` is accepted as an alias: the name the `nexus-payment-gateways` docs use for the same list. |
| entry `payment_provider_slug` | `stripe` · `solidgate` · `paypal` · `bridgerpay` · `evervault`. Any other slug is skipped. |
| entry `payment_provider_detail_id` | Account id (positive integer), or `null` = the site's generic account. |
| entry `public_key` | Stripe publishable key / PayPal client id / Solidgate merchant / BridgerPay checkout key / Evervault App ID. **PayPal and Evervault need it here**, because their SDKs load before any order exists. |
| entry `team_id` | Evervault only: the Team ID its SDK needs next to the App ID. |
| entry `merchant_id` | Evervault only (E1, **sent by the API since 2026-11-26**): the site's Evervault merchant (`merchant_…`, Nexus `evervault_merchant_id`) for Apple Pay / Google Pay. `evervault_merchant_id` is accepted too. |
| entry `order` | **Lower number = tried first**; ties keep the array order; entries without an order go after the ordered ones. *Please confirm this is the intended meaning.* |
| entry `is_active` | `false` = dropped. |
| entry `checkout_mode` | BridgerPay only: `blox` or `checkout`. |

None of the three themes reads these fields today; they use the legacy `payment_gateways` names. Until the quiz result carries the new lists, the plugin falls back to `payment_gateways` (SolidGate if listed, else Stripe; no PayPal, since there is no client id).

### C2. Payment status check: Ready (heuristic); Proposal for a dedicated route

After an unclear confirm, the plugin asks `POST /questions/results`. It treats `redirect_page` beyond `INITIAL_PAYMENT_PAGE` (cross-sale: beyond `CROSS_SELL_OFFER_PAGE`) as paid.

**Proposal:** `POST /payment/first-sale/payments/status { quiz_result_id }` → `{ status: "paid" | "pending" | "not_paid", payment_provider_slug }`, so "pending" and "not paid" can be told apart. Adopting it means one method in the API adapter (`fetch_quiz_result` / `check_paid`).

### C3. Machine-readable error codes: Needed (gaps G3 / B4 in nexus-payment-gateways)

"First sale transaction already exists" and BridgerPay declines are recognised by message text. Please return `data.code` (`FIRST_SALE_EXISTS`, decline codes). The plugin already passes `data.code` through.

---

## P. PayPal

### P1. Confirm must capture or verify on every site: Needed (blocking for PayPal)

`POST /payment/paypal/first-sale/payments/confirm`. The plugin sends only:

```json
{ "quiz_result_id": 123, "user_on_iqbooster": false, "prc_id": "", "pricing_discount": { "mdid": "" },
  "ab_testing_price_category": "…(when set)", "paypal_order_id": "5O190127TN364715T" }
```

On the common API it also sends `payment_provider_slug`, `payment_provider_detail_id`.

Required behaviour:

1. **The API captures the order** with its own PayPal credentials. If the order is already captured, it reads the capture with `GET /v2/checkout/orders/{id}`. It does this for **every** site. Today, per `nexus-payment-gateways`, it captures only on `paypalVaultSites`; other sites expect the browser to capture.
2. The API decides paid from PayPal's answer: order `COMPLETED`, capture `COMPLETED`, amount and currency equal to the quiz result's price, and the order created for this quiz result.
3. The API **ignores or rejects** a `payment_status` field. Today the Core plugin's `paypal_checkout_api_payment` handler hard-codes `'payment_status' => 'paid'` and forwards browser-supplied `charge_id`, `payment_method_id` (vault id), `paypal_customer_id` and `paypal_customer_email`. With server capture, the API takes those from the capture response.
4. Vault for later charges (subscriptions / cross sale): the themes request `store_in_vault: ON_SUCCESS`. Create-order (P2) must set it when the site needs it, and the vault id is read from the capture.
5. Decline (`INSTRUMENT_DECLINED`, …) → 4xx with a message; success → the usual confirm data (`redirect_page`, `first_sale_usd_price`, `cross_sale_usd_price`).

Until P1 is live on a site, the effect is: the customer approves in PayPal, the confirm fails, nothing is captured (an approved but uncaptured order moves no money), and the plugin shows "checking", then lets the customer pay again. Keep PayPal unchecked in Settings on a site until P1 is deployed for it.

### P2. Create order: Ready (route exists), please confirm fields

`POST /payment/paypal/first-sale/payments/create-order`, with `email`, `quiz_result_id`, the pricing fields, `return_url` and `cancel_url` (always on the site's own host).

Expected response: `order_id`, `public_key` (client id of the account used), `payment_provider_detail_id`. When the order is created on another account than the page loaded, the browser reloads the PayPal SDK once with the new `public_key`.

---

## S. Stripe: Ready

| Step | Route | Fields |
| --- | --- | --- |
| Intent | `POST /payment/stripe/create-payment-intent` | `email`, `quiz_result_id`, pricing fields, `payment_method_type: ["card"]` |
| Expected back | | `client_secret`, `public_key`, `payment_provider_detail_id` (`payment_intent_id` optional) |
| Confirm | `POST /payment/stripe/first-sale/payments/confirm` | `payment_intent_id` |

Requests to the backend:

- The API should check that the PaymentIntent's metadata names the same quiz result before recording it. The plugin already only confirms intents its own checkout created.
- **G1:** accept `payment_provider_detail_id` at confirm, or remember intent → account, so an admin switch between intent and confirm cannot leave a charge unrecorded.

## G. Solidgate: Ready, with one request

| Step | Route | Fields |
| --- | --- | --- |
| Intent | `POST /payment/solidgate/create-payment-intent` | context fields |
| Expected back | | `merchantId`, `signature`, `paymentIntent`, `payment_provider_detail_id` |
| Confirm | `POST /payment/solidgate/first-sale/payments/confirm` | `payment_obj` (Solidgate's success event, as today) |

**Request:** the API must read the order status from Solidgate (`/api/v1/status` by `order_id`) and must not treat `payment_obj` as proof of payment. Please confirm it does.

## X. Cross sale: Ready

`POST /payment/cross-sale/payments/confirm` with `quiz_result_id`, `user_on_iqbooster`, `prc_id`, `pricing_discount`, `ab_testing_price_category`, the same body the Core plugin sends. The charge runs on the first sale's saved method. There is no common-API cross-sale route; both adapters use this one.

---

## B. BridgerPay: Needed (routes exist on the API `bridgerpay-gateway` release, not on staging `main`)

The routes are documented and were verified against local Nexus in `nexus-payment-gateways/docs/payment-plugin-bridgerpay-v0.1.md`:

- `POST /payment/bridgerpay/create-payment-intent`
- `POST /payment/bridgerpay/first-sale/payments/confirm`: 409 while processing; the browser polls.
- `POST /payment/bridgerpay/first-sale/payments/charge-token`: BLOX.

BridgerPay is **on** by default and takes its place in `payment_provider_details` like any other provider. On an API environment without these routes, the 404 at intent moves the checkout silently to the next provider.

Requests to the backend:

- Deploy the `bridgerpay-gateway` release to the environments the sites use.
- Send BridgerPay entries (with `checkout_mode` and `public_key`) in `payment_provider_details`.

Open points B1–B5 are in the BridgerPay document. B2, a top-level 3DS redirect that leaves the page, needs a return handler, which is not built.

---

## E. Evervault: card and merchant id Ready; wallet payments (E2) and 3DS (E3) Needed

Evervault's own UI Components collect the payment: its card form, and its Apple Pay and Google Pay buttons (not a processor's wallet), so WordPress and the API only ever see Evervault ciphertext. Nothing in the plugin names a processor: the Evervault account decides which processors it uses and in which order, so processors added to that account later need no plugin change.

**One call (2026-11-26).** The browser sends the payment data once to the plugin (`POST …/checkouts/{token}/providers/evervault/pay`). WordPress makes one API call and answers the browser with the outcome. There is no confirm and no Stripe.js:

| | |
| --- | --- |
| Route | `POST /payment/first-sale/payments/initiate`, with both adapters (`current` and `common`): the per-gateway `/payment/evervault/*` routes are not used |
| Body | `payment_provider_slug: "evervault"`, `payment_provider_detail_id`, `email`, `quiz_result_id`, `user_on_iqbooster`, `prc_id`, `pricing_discount` (+ `ab_testing_price_category`, `customer_email` when set) and **one** of `card` / `wallet` (+ `three_ds_session_id` after 3DS, E3) |
| `card` | `{ number, cvc, exp_month, exp_year }`: number and CVC `ev:` ciphertext only (Nexus's own pattern), expiry month 1–12 and a 2- or 4-digit year; other keys are dropped before the call |
| 200 | Paid: the confirm's answer (`redirect_page`, `cross_sale` …, plus `psp_name`, `processor_payment_provider_detail_id`) |
| 402 | Declined on every processor the API tried: `data.code` decides the failover (`PAYMENT_HARD_DECLINED` / `PAYMENT_BLOCKED` = stop; `PAYMENT_DECLINED_FUNDS` / `PAYMENT_3DS_FAILED` = the customer fixes it on the same form; others = next gateway), `data.decline_code` is passed on |
| 504 | `PAYMENT_RESULT_UNKNOWN`: never paid again before the quiz result was checked (the next Pay is held, the browser asks `/status`) |

**No time limit.** The API tries the account's processors in turn, so WordPress waits for the answer without a limit: cURL without timeout (WordPress itself cannot go below 1 s), `set_time_limit(0)`, and the request keeps going when the browser leaves (`ignore_user_abort`), so the outcome is recorded either way. The checkout stays locked meanwhile (30 min safety margin for a killed PHP process; released at once when the API answers, and at shutdown after a fatal error). The browser has no timer either: when its answer is lost (network, proxy), it asks `/status` every 5 s until the server knows. Proxies in front of WordPress or Nexus (Cloudflare 100 s, nginx `proxy_read_timeout`) can still cut a very long answer; the outcome is then read through `/status`. Optional limit: filter `cellon_payment_collection_evervault_timeout` (seconds, default 0).

Requests to the backend:

- **E1: `merchant_id` on `evervault` entries: done (2026-11-26).** The API sends it (`merchant_0353e9725a51` on the staging account), and the Evervault wallet button shows in the wallet slot next to PayPal. The `cellon_payment_collection_evervault_merchant_id` filter can still override it. Apple Pay also needs each domain registered with that Evervault merchant (and Evervault's `/.well-known/apple-developer-merchantid-domain-association` file served on it). Google Pay needs no registration. Both need HTTPS.
- **E2: accept `wallet` on the Evervault initiate** (today's Joi schema allows `card` only, so a wallet payment gets 422 and nothing is charged). The plugin sends what Evervault's components give, validated (ciphertext `ev:` only for the card numbers):

  ```json
  "wallet": {
    "type": "apple_pay | google_pay",
    "network_token": { "number": "ev:…", "exp_month": 12, "exp_year": 2029, "token_service_provider": "Apple" },
    "cryptogram": "ev:… (or Google's base64)",
    "eci": "05",
    "payment_data_type": "3DSecure",
    "brand": "visa",
    "last_four": "4242"
  }
  ```

  Apple Pay and Google Pay `CRYPTOGRAM_3DS` give `network_token` + `cryptogram` (charge as a network-token payment with that cryptogram, no CVC). Google Pay `PAN_ONLY` gives `"card": { "number": "ev:…", "exp_month", "exp_year" }` instead (no CVC). Same answers as the card (200 / 402 / 504). Wallet payments get no 3DS step from the page (E3 is the card form's). Evervault's Google Pay guide recommends 3DS for a `PAN_ONLY` card. If the API wants that, `3ds-session` would have to accept `wallet` too; tell us and the page will run the same three steps for it.

  **Later charges without the customer (cross-sale, second split, renewals) are the backend's.** The page only takes the first sale. Save the wallet's payment method exactly as for an Evervault card today: the processor's saved method (`payment_method_id` + `gateway_customer_id`), set up for off-session use. The Offer page's cross-sale, the second split and every renewal then charge it as they charge a card. What the wallets give:
  - **Google Pay** in desktop and iPhone browsers gives `card` (`PAN_ONLY`): the card itself, kept like the card form's card (no CVC). Android may give `network_token` + `cryptogram`.
  - **Apple Pay** gives `network_token` + `cryptogram`. The page asks for a one-off payment (no recurring payment request), so Apple returns a device token (DPAN), not a merchant token (MPAN). Whether a processor accepts a DPAN for later merchant-initiated charges is that processor's rule.
  - **Cryptogram:** it is for this payment only and is never stored. Later charges use the token number and expiry with the processor's stored-credential reference of this first charge.
- **E3: Evervault 3D Secure. Built in the plugin to "Evervault 3D Secure: funnel integration" v0.1 (2026-11-26); the API route is not on staging yet** (`3ds-session` answered 404 on 2026-11-26). For the card form, every Pay press:
  1. **Start 3D Secure.** `POST /payment/first-sale/payments/3ds-session` with the **same body** as the payment (`three_ds_profiling_id` is not sent yet). Nothing is charged.
     - `"three_ds": "skipped"` (any `reason`): the payment goes on without a session.
     - `"three_ds": "evervault"`: the plugin keeps `three_ds_session_id` with the checkout.
     - Refused (422 `VALIDATION_ERROR`, `PRICE_NOT_AVAILABLE`, `PAYMENT_ACCOUNT_REQUIRED` …): nothing charged; the card chain moves on (intent failure).
     - **No route (404 without `data.code`):** the plugin counts it as skipped (`ROUTE_NOT_AVAILABLE`), so card payments keep working until the route is deployed. The API's `EVERVAULT_3DS_ENABLED` switch decides everything else; it never needs a plugin release.
  2. **The bank's window**, only when `status` is `"action-required"`: `evervault.ui.threeDSecure(session_id)`, a modal, with the same Evervault instance as the card form. Whatever it ends with (success, failure, error), step 3: the server decides.
  3. **The charge.** `POST /payment/first-sale/payments/initiate` with the same body plus `three_ds_session_id`.
     - 402 `PAYMENT_3DS_FAILED`: the bank did not confirm (nothing charged). The customer sees "Your bank did not confirm the payment. Please try again." With the site's `switch_on` the next card gateway may try (rule 6: not a hard decline); with `"soft"` the customer presses Pay again, which starts a new session.
     - 409 `THREE_DS_NOT_FINISHED`, 422 `THREE_DS_SESSION_EXPIRED` / `_MISMATCH` / `_NOT_FOUND`: nothing charged and not a decline. The same form stays with "Your payment was not completed and you were not charged. Please press Pay again."; the next Pay starts again from step 1 with a new session. No automatic restart (one session per Pay press), never another gateway, and it does not use up a gateway switch (`CellonPC.decide` → `"again"`).
     - 504 `PAYMENT_RESULT_UNKNOWN`: never paid again automatically; the page asks the plugin's `/status` until it knows.

  **Sandbox test cards** (Evervault's 3DS page; any future expiry and CVC): `4242 4242 4242 4242` shows the bank's window; `4111 1101 1663 8870` pays with no window; `4111 1117 3897 3695` gives `PAYMENT_3DS_FAILED`. Only the cards on Evervault's 3DS test list give a known answer in its sandbox. A processor's sandbox may refuse another provider's test card.

  **Rules the plugin enforces:**
  - Pay stays disabled from step 1 until step 3 answers.
  - The card is read once, so the session's card is the one charged.
  - On the server, a payment may name only the session this checkout started last, for the same body (card, price fields, account), and only once. Anything else gets 422 `three_ds_session_mismatch` before any API call.
  - Apple Pay / Google Pay skip the 3DS step: the wallet authenticated the payment.
  - A `next_action` in a payment's answer is no longer used and is refused as "not available".

---

## Out of scope: BlueSnap, Tranzila

Not required now. Neither has a working flow on the sites today:

- their AJAX actions are registered nowhere;
- their JS files are missing;
- nothing records their sales in the API.

Entries with these slugs are skipped and never run. Their hard-coded credentials are still listed in SECURITY-FINDINGS.md, for rotation.

---

## O. Other flows not in the plugin yet

- **Subscription intent** (`/create-intent`, `setupFutureUsage: off_session` on the old Stripe pages): the API decides `setup_future_usage` on the intent it creates. When the API sets it, the site must pass `stripe.deferred.setupFutureUsage` only if it uses the deferred card form.
- **Stripe redirect methods** (`return_url` flows): not used by the current pages (cards and wallets only).
- **Split payments:** the API decides the charged amount; the plugin only shows `first_sale_first_split.amount` to Stripe's deferred form.
