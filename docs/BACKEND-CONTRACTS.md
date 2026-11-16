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
| entry `payment_provider_slug` | `stripe` · `solidgate` · `paypal` · `bridgerpay`. Any other slug is skipped. |
| entry `payment_provider_detail_id` | Account id (positive integer), or `null` = the site's generic account. |
| entry `public_key` | Stripe publishable key / PayPal client id / Solidgate merchant / BridgerPay checkout key. **PayPal needs it here**, because the PayPal SDK loads before the order exists. |
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
