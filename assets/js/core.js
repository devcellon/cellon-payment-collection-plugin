/* Cellon Payment Collection: browser core (window.CellonPC).
 *
 * Plain ES2017, no build step. Loaded before the gateway files and the page code (assets/js/checkout.js).
 *
 *   registerGateway(definition)   a gateway file (assets/js/gateways/<slug>.js) registers itself here
 *   gateway(slug)                 the registered definition, or null
 *   normalizeGateways(details)    the server's provider list → gateways that have a registered definition
 *   capability(def, name, …)      a definition's setting, resolved when it is a function
 *   restClient(base)              POST to this plugin's REST endpoints ({ meta, data } envelope)
 *   loadScript(src, options)      loads a provider SDK once per URL from the provider's own domain
 *   decide(failure)               failover decision for a failed attempt
 *   GatewayError, ApiError, atStage, toFailure
 *
 * A gateway definition:
 *
 *   CellonPC.registerGateway({
 *     slug: "example",                // payment_provider_slug the API sends
 *     mount: function (ctx) { … },    // async; resolves { submit?: fn, destroy: fn }
 *     ownButton: false,               // true: the provider renders its own pay button (else the page's Pay button)
 *     email: "page",                  // who collects the customer's email: "page" (the page's field),
 *                                     // "element" (a provider element: it reports it through ctx.page.reportEmail)
 *                                     // or "provider" (the provider's own form; the server reads it at confirm)
 *     emailAtIntent: false,           // the intent keeps the email it was created with: remount when it changes
 *     remountOnRetry: false,          // a declined intent cannot be paid again: mount a new one to retry
 *     processingNotice: true,         // the page shows its green "Processing your payment…" notice on Pay
 *     walletClass: "",                // class on the page's wallet container while this gateway is active
 *     analytics: { referenceKey: "" },// dataLayer first_sale_fronted gets { [referenceKey]: reference }
 *     resume: null,                   // back from a provider redirect: { params: [query keys], body: fn(reference) }
 *   });
 *
 * Every setting except slug / mount may also be a function (options, gateway) returning the value, for
 * settings that depend on the site's options (Stripe collects the email only with its Contact Details Element).
 *
 * The mount context:
 *   gateway   { slug, providerId, accountId, publicKey, order, checkoutMode }
 *   host      element to render into (removed after the gateway unmounts)
 *   options   this gateway's options from the server (site look, labels, wallet container …)
 *   checkout  { amount, currency, customerEmail }
 *   api       { intent(fields), confirm(fields, accountId), chargeToken(fields) } for this gateway
 *   events    { ready, processing, succeeded(result), failed(failure), cancelled, accountChanged(gateway) }
 *   page      { email(), reportEmail(address) }
 */
(function (window, document) {
    "use strict";

    var CP = window.CellonPC = window.CellonPC || {};
    var registry = {};

    CP.EMAIL_RE = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;

    var DEFAULTS = {
        ownButton: false,
        email: "page",
        emailAtIntent: false,
        remountOnRetry: false,
        processingNotice: true,
        walletClass: "",
        analytics: null,
        resume: null,
    };

    /* ------------------------------------------------------------------ registry */

    CP.registerGateway = function (definition) {
        var slug = definition && String(definition.slug || "").toLowerCase();
        if (!slug || typeof definition.mount !== "function") {
            if (window.console) console.error("Cellon Payment Collection: a gateway needs a slug and a mount function", definition);
            return;
        }
        var def = {};
        Object.keys(DEFAULTS).forEach(function (key) { def[key] = DEFAULTS[key]; });
        Object.keys(definition).forEach(function (key) { def[key] = definition[key]; });
        def.slug = slug;
        registry[slug] = def;
    };

    CP.gateway = function (slug) {
        return registry[String(slug || "").toLowerCase()] || null;
    };

    CP.gatewaySlugs = function () {
        return Object.keys(registry);
    };

    /** A definition's setting; functions get the gateway's options and config. */
    CP.capability = function (def, name, options, gateway) {
        if (!def) return DEFAULTS[name];
        var value = def[name];
        return typeof value === "function" && name !== "mount" ? value(options || {}, gateway || null) : value;
    };

    /* -------------------------------------------------------------------- errors */

    function GatewayError(stage, code, message, raw) {
        var error = new Error(message || code);
        error.name = "GatewayError";
        error.isGatewayError = true;
        error.stage = stage;
        error.code = code;
        error.raw = raw;
        return error;
    }

    function ApiError(message, status, code, data) {
        var error = new Error(message || "Request failed");
        error.name = "ApiError";
        error.isApiError = true;
        error.status = status || 0;
        error.code = code;
        error.data = data;
        return error;
    }

    CP.GatewayError = GatewayError;
    CP.ApiError = ApiError;

    /** Tags an API / network error with the step it happened at (intent vs confirm decide the failover). */
    CP.atStage = function (stage, error) {
        if (error && error.isGatewayError) return error;
        if (error && error.isApiError) return GatewayError(stage, error.code || "http_" + error.status, error.message, error);
        return GatewayError(stage, "request_failed", error && error.message ? error.message : String(error), error);
    };

    CP.toFailure = function (slug, error, fallbackStage) {
        if (error && error.isGatewayError) {
            return { gateway: slug, stage: error.stage, code: error.code, message: error.message, raw: error.raw };
        }
        if (error && error.isApiError) {
            return { gateway: slug, stage: fallbackStage, code: error.code || "http_" + error.status, message: error.message, raw: error };
        }
        return { gateway: slug, stage: fallbackStage, code: "unknown_error", message: error && error.message ? error.message : String(error), raw: error };
    };

    /* ------------------------------------------------------------- provider list */

    // The API sends String(undefined) / String(null) for empty values
    CP.cleanValue = function (value) {
        return value == null || value === "" || value === "null" || value === "undefined" ? null : String(value);
    };

    /** The server's provider list → gateways with a registered definition, lowest order first. */
    CP.normalizeGateways = function (details) {
        if (!Array.isArray(details)) return [];
        return details
            .filter(function (detail) { return detail && detail.is_active !== false; })
            .map(function (detail, index) {
                var gateway = {
                    slug: String(detail.payment_provider_slug || "").toLowerCase(),
                    providerId: String(detail.payment_provider_id),
                    accountId: CP.cleanValue(detail.payment_provider_detail_id),
                    publicKey: CP.cleanValue(detail.public_key),
                    order: Number(CP.cleanValue(detail.order)) || index + 1,
                };
                if (detail.checkout_mode) gateway.checkoutMode = String(detail.checkout_mode);
                return gateway;
            })
            .filter(function (gateway) { return !!registry[gateway.slug]; })
            .sort(function (a, b) { return a.order - b.order; });
    };

    /** API rule: payment_provider_detail_id is a positive integer, or null (the site's generic account). */
    CP.detailId = function (accountId) {
        var id = Number(accountId);
        return accountId != null && Number.isInteger(id) && id > 0 ? id : null;
    };

    /* --------------------------------------------------------------- REST client */

    /** POST to this plugin; unwraps the { meta, data } envelope, throws ApiError otherwise. */
    CP.post = function (url, body) {
        return fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify(body || {}),
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (json) {
                var meta = json && json.meta;
                if (!response.ok || !meta || meta.success === false) {
                    var message = (meta && meta.message) || response.statusText || "Request failed";
                    throw ApiError(message, response.status, json && json.data && json.data.code, json && json.data);
                }
                return json.data;
            });
        }, function (error) {
            throw ApiError(error && error.message ? error.message : "Network error", 0, "network_error");
        });
    };

    /**
     * Endpoints of one checkout (…/checkouts/<token>). The server builds the API body from its own session;
     * the browser sends the gateway's fields, the account id and, with an intent, the typed address as
     * customer_email: the common API binds the session (and its card token) to it, and the same address is
     * saved on the customer after payment — a mismatch breaks the cross sale ("Invalid token"). The current
     * per-gateway routes reject the key and the server strips it there.
     */
    CP.restClient = function (base) {
        function provider(slug, step, body) {
            return CP.post(base + "/providers/" + encodeURIComponent(slug) + "/" + step, body);
        }
        return {
            base: base,
            post: function (path, body) { return CP.post(base + path, body); },
            status: function () { return CP.post(base + "/status", {}); },
            provider: provider,
            /** The api object a gateway's mount context gets. */
            forGateway: function (gateway, checkout) {
                var withAccount = function (fields, accountId) {
                    var body = {};
                    Object.keys(fields || {}).forEach(function (key) { body[key] = fields[key]; });
                    body.payment_provider_detail_id = CP.detailId(accountId === undefined ? gateway.accountId : accountId);
                    return body;
                };
                return {
                    intent: function (fields) {
                        var body = withAccount(fields);
                        if (checkout && checkout.customerEmail) body.customer_email = checkout.customerEmail;
                        // The plan picked on a multi-plan page ('' = default price); the server accepts only the
                        // quiz result's price_list categories and keeps it for the confirm and the cross sale
                        var category = checkout && typeof checkout.priceCategory === "function" ? checkout.priceCategory() : null;
                        if (typeof category === "string") body.ab_testing_price_category = category;
                        return provider(gateway.slug, "intent", body);
                    },
                    confirm: function (fields, accountId) { return provider(gateway.slug, "confirm", withAccount(fields, accountId)); },
                    chargeToken: function (fields, accountId) {
                        // The BLOX charge takes the intent's body (the session and its card token are bound
                        // to the customer_email the session was opened with)
                        var body = withAccount(fields, accountId);
                        if (checkout && checkout.customerEmail) body.customer_email = checkout.customerEmail;
                        return provider(gateway.slug, "charge-token", body);
                    },
                };
            },
        };
    };

    /* ------------------------------------------------------------- script loader */

    var pending = {};

    /**
     * Loads a provider SDK once per URL. `replace` (a RegExp) removes other scripts of the same SDK first
     * (PayPal allows one client id per page).
     */
    CP.loadScript = function (src, opts) {
        opts = opts || {};
        var globalName = opts.globalName;
        var scripts = Array.prototype.slice.call(document.scripts);
        var existing = scripts.filter(function (script) { return script.src === src; })[0];
        if (window[globalName] && (existing || !opts.replace)) return Promise.resolve(window[globalName]);
        if (pending[src]) return pending[src];

        if (opts.replace) {
            scripts.filter(function (script) { return script.src !== src && opts.replace.test(script.src); })
                .forEach(function (script) { script.remove(); });
            try { delete window[globalName]; } catch (e) { window[globalName] = undefined; }
        }
        if (existing) existing.remove();

        pending[src] = new Promise(function (resolve, reject) {
            var script = document.createElement("script");
            var timer = setTimeout(function () { fail("sdk_timeout", src + " did not load in time"); }, opts.timeoutMs || 15000);
            function fail(code, message) {
                clearTimeout(timer);
                delete pending[src];
                script.remove();
                reject(GatewayError("load", code, message));
            }
            script.src = src;
            script.async = true;
            script.onload = function () {
                clearTimeout(timer);
                delete pending[src];
                if (window[globalName]) resolve(window[globalName]);
                else fail("sdk_missing_global", src + " loaded without window." + globalName);
            };
            script.onerror = function () { fail("sdk_load_failed", src + " failed to load (blocked or offline)"); };
            document.head.appendChild(script);
        });
        return pending[src];
    };

    /* ---------------------------------------------------------- failover policy */

    function set(list) {
        var out = {};
        list.forEach(function (key) { out[key] = true; });
        return out;
    }

    /**
     * Hard declines: the issuer will never approve this card. Sending it to another gateway is card testing
     * in the networks' eyes and costs retry fees.
     */
    var STOP = set([
        // Stripe decline_code
        "stolen_card", "lost_card", "pickup_card", "fraudulent", "merchant_blacklist", "restricted_card",
        "security_violation", "revocation_of_all_authorizations", "revocation_of_authorization",
        "stop_payment_order", "do_not_try_again", "invalid_account", "highest_risk_level",
        // Solidgate
        "0.03", "3.10", "3.11", "3.12", "4.01", "4.02", "4.03", "4.04", "4.05", "4.06", "4.07", "4.09", "5.02", "5.08",
        // BridgerPay deposit status
        "declined_do_not_try_again",
    ]);

    /** The customer can fix it on the same form (card data, funds, 3DS): another gateway gets the same answer. */
    var RETRY_SAME = set([
        // Stripe code / decline_code
        "validation_error", "incomplete_number", "incomplete_cvc", "incomplete_expiry",
        "incorrect_cvc", "invalid_cvc", "incorrect_number", "invalid_number", "invalid_expiry_month", "invalid_expiry_year",
        "expired_card", "incorrect_zip", "insufficient_funds", "card_velocity_exceeded", "withdrawal_count_limit_exceeded",
        "authentication_required", "payment_intent_authentication_failure", "call_issuer", "transaction_not_allowed",
        // Solidgate
        "2.06", "2.08", "2.09", "2.12", "2.15", "3.01", "3.02", "3.03", "3.05", "3.06", "3.07", "3.09", "3.13", "4.08",
        "5.01", "7.01", "7.02", "7.05",
        // PayPal: the buyer chose a funding source the issuer declined
        "instrument_declined",
        // BridgerPay deposit status
        "declined_due_to_3d_auth_failure", "declined_due_to_funds",
    ]);

    /**
     * next_gateway | retry_same | stop | verify. Everything else at "payment" is a soft or processor decline
     * (generic_decline, do_not_honor, Solidgate 0.01 / 3.04 / 5.xx, BridgerPay declined): another acquirer may
     * approve it.
     */
    CP.decide = function (failure) {
        // Nothing was charged yet: an SDK, key or intent problem always moves on (silently)
        if (failure.stage === "load") return "next_gateway";
        if (failure.stage === "intent") return /already exists/i.test(failure.message || "") ? "verify" : "next_gateway";
        // The provider took the money but the API did not record it (or we never saw its answer)
        if (failure.stage === "confirm") return "verify";

        var code = String(failure.code || "").toLowerCase();
        if (code === "outcome_unknown" || code === "processing") return "verify";
        if (STOP[code]) return "stop";
        if (RETRY_SAME[code]) return "retry_same";
        return "next_gateway";
    };

    CP.defaultMessages = {
        next_gateway: "Your payment could not be processed. Please enter your card details again to complete the payment.",
        retry_same: "Your payment was declined. Please check your card details or use another card.",
        stop: "Your payment was declined. Please contact your bank or use another card.",
        verify: "We are checking the status of your payment. Please do not pay again.",
    };

    CP.wait = function (ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    };
})(window, document);
