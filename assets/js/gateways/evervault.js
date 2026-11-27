/* Cellon Payment Collection: Evervault gateway (Evervault UI Components: card form, Apple Pay, Google Pay).
 *
 * No payment logic here. The card is typed into Evervault's iframe, and Apple Pay / Google Pay are Evervault's own
 * buttons (not a processor's), so this file only ever holds Evervault ciphertext. On the page's Pay button, or when
 * the customer authorizes a wallet payment, it sends that once to this plugin (…/providers/evervault/pay). The server
 * makes ONE call to the API, which runs the whole payment on the processors of the Evervault account
 * (Evervault_Provider, Payment_Orchestrator::pay()). The answer is the outcome only:
 *   - paid: the checkout goes on to the next page;
 *   - an error whose data.stage says what failed: intent (nothing charged), payment (declined: data.code) or
 *     confirm (unknown: the checkout asks the server until it knows).
 * No timer limits the payment: the API may try several processors before it answers.
 *
 * 3D Secure (card form only; BACKEND-CONTRACTS.md E3), every Pay press:
 *   1. …/providers/evervault/3ds-session with the same card: "skipped" → step 3 without a session; "evervault" → keep
 *      its three_ds_session_id. Nothing is charged; a refusal is an intent failure.
 *   2. Only when its status is "action-required": the bank's window (evervault.ui.threeDSecure, a modal). Whatever it
 *      ends with (success, failure, error), step 3: the server decides.
 *   3. The payment with three_ds_session_id. PAYMENT_3DS_FAILED (402): the bank did not confirm, nothing charged
 *      ("Your bank did not confirm the payment"; the failover may try the next provider). A session the API cannot use
 *      (THREE_DS_NOT_FINISHED, THREE_DS_SESSION_EXPIRED / _MISMATCH / _NOT_FOUND): nothing charged, the form stays and
 *      the customer presses Pay again, which starts again from step 1 (decision "again", core.js).
 * One session per Pay press, never reused; the card is not read again in between, so the session's card is the one
 * charged. Apple Pay / Google Pay pay without this step (authenticated in the wallet).
 * Sandbox: 4242 4242 4242 4242 shows the bank's window; Evervault's 3DS page lists the other test cards.
 *
 * Keys: Team ID (gateway.teamId) and App ID (gateway.publicKey); wallets: the Evervault merchant (gateway.merchantId,
 * else options.wallets.merchantId).
 * Options (Evervault_Provider::browser_options): theme ("clean"), translations { number, expiry, cvc: { label,
 * placeholder } }, threeDSecure (evervault.ui.threeDSecure options), wallets { containerId, merchantId, transaction
 * { amount (smallest unit), currency, country }, locale, applePay {…}, googlePay {…} } (absent: no wallets).
 */
(function (window, document) {
    "use strict";

    var CP = window.CellonPC;
    var SLUG = "evervault";
    var EVERVAULT_JS = "https://js.evervault.com/v2";
    var STAGES = ["intent", "payment", "confirm"];

    function assign(target) {
        for (var i = 1; i < arguments.length; i++) {
            var source = arguments[i] || {};
            Object.keys(source).forEach(function (key) { target[key] = source[key]; });
        }
        return target;
    }

    /** The server's failure as the sequence reads it. Without a stage: a lost or unclear answer is checked first. */
    function payFailure(error) {
        var data = (error && error.data) || {};
        var status = error && error.status;
        var stage = STAGES.indexOf(data.stage) !== -1 ? data.stage
            : status === 402 ? "payment"
            : !status || status === 409 || status >= 500 ? "confirm"
            : "intent";
        return {
            gateway: SLUG,
            stage: stage,
            code: (error && error.code) || (status ? "http_" + status : "network_error"),
            message: (error && error.message) || "Payment failed",
            raw: error,
        };
    }

    /** { month, year } of an Evervault expiry, whichever way the component spells it. */
    function expiryOf(source) {
        var expiry = (source && source.expiry) || {};
        return {
            exp_month: Number(expiry.month != null ? expiry.month : source.expMonth),
            exp_year: Number(expiry.year != null ? expiry.year : source.expYear),
        };
    }

    /**
     * Evervault's Apple Pay / Google Pay payload → the server's wallet body. Apple Pay gives networkToken, Google Pay
     * token (both with an encrypted DPAN and a cryptogram); Google Pay PAN_ONLY gives the encrypted card instead.
     */
    function walletPayload(type, data) {
        if (!data) return null;
        var token = data.networkToken || data.token;
        var card = data.card || {};
        var out = { type: type };
        if (token && token.number) {
            out.network_token = assign({ number: token.number }, expiryOf(token));
            if (token.tokenServiceProvider) out.network_token.token_service_provider = String(token.tokenServiceProvider);
            out.cryptogram = data.cryptogram;
            if (data.eci != null && data.eci !== "") out.eci = String(data.eci);
        } else if (card.number) {
            out.card = assign({ number: card.number }, expiryOf(card));
        } else {
            return null;
        }
        if (data.paymentDataType) out.payment_data_type = String(data.paymentDataType);
        if (card.brand) out.brand = String(card.brand);
        if (card.lastFour) out.last_four = String(card.lastFour);
        return out;
    }

    function mount(ctx) {
        var gateway = ctx.gateway, host = ctx.host, api = ctx.api, events = ctx.events;
        var settings = ctx.options || {};

        if (!gateway.teamId || !gateway.publicKey) {
            return Promise.reject(CP.GatewayError("load", "missing_keys", "No Evervault Team ID / App ID for this account"));
        }

        return CP.loadScript(EVERVAULT_JS, { globalName: "Evervault" }).then(function (Evervault) {
            var evervault;
            try {
                evervault = new Evervault(gateway.teamId, gateway.publicKey);
            } catch (error) {
                throw CP.GatewayError("load", "sdk_init_failed", (error && error.message) || "Evervault could not start", error);
            }

            var live = true, busy = false, state = null, wallet = null, step = null;

            /* ------------------------------------------------------------ the payment */

            /** The one payment call. Resolves { paid: data } | { failure }. */
            function pay(fields) {
                return api.pay(fields).then(function (data) {
                    return { paid: data || {} };
                }, function (error) {
                    return { failure: payFailure(error) };
                });
            }

            /**
             * 3DS step 1: the session for this card (nothing charged). Resolves { sessionId (null = pay without one),
             * window } | { paid } (another tab paid meanwhile) | { failure }.
             */
            function startThreeDs(fields) {
                if (typeof api.threeDsSession !== "function") return Promise.resolve({ sessionId: null, window: false });
                return api.threeDsSession(fields).then(function (data) {
                    data = data || {};
                    if (data.paid) return { paid: data };
                    if (data.three_ds !== "evervault" || !data.three_ds_session_id) return { sessionId: null, window: false };
                    return { sessionId: String(data.three_ds_session_id), window: data.status === "action-required" };
                }, function (error) {
                    return { failure: payFailure(error) };
                });
            }

            /**
             * 3DS step 2: the bank's window for the session (Evervault's modal). Resolves with how it ended (success,
             * failure, error); the payment goes on either way and the server decides.
             */
            function threeDSecure(sessionId) {
                return new Promise(function (resolve) {
                    var ended = false;
                    function end(how) {
                        if (ended) return;
                        ended = true;
                        if (step) {
                            try { step.unmount(); } catch (e) { /* the component unmounts itself after success / failure */ }
                        }
                        step = null;
                        resolve(how);
                    }
                    try {
                        step = evervault.ui.threeDSecure(sessionId, settings.threeDSecure || {});
                        step.on("success", function () { end("success"); });
                        step.on("failure", function () { end("failure"); }); // wrong code, bank refused, window closed
                        step.on("error", function () { end("error"); });
                        step.mount(); // no selector: Evervault's modal
                    } catch (error) {
                        end("error");
                    }
                });
            }

            /**
             * The card form's payment, one Pay press: 3DS session → the bank's window when asked → the payment naming
             * the session (whatever the window ended with). Resolves like pay(), or { stopped } when the form was
             * removed meanwhile (nothing charged).
             */
            function payCard(fields) {
                return startThreeDs(fields).then(function (start) {
                    if (start.failure || start.paid) return start;
                    return (start.window ? threeDSecure(start.sessionId) : Promise.resolve()).then(function () {
                        if (!live) return { stopped: true };
                        return pay(start.sessionId ? assign({}, fields, { three_ds_session_id: start.sessionId }) : fields);
                    });
                });
            }

            /** The outcome on the page: paid → next page; else the failure goes to the checkout's failover. */
            function settle(outcome) {
                if (!live || outcome.stopped) return Promise.resolve();
                if (outcome.paid) {
                    events.succeeded({ gateway: SLUG, accountId: gateway.accountId, data: outcome.paid });
                    return Promise.resolve();
                }
                events.failed(outcome.failure);
                return Promise.resolve();
            }

            function release() {
                busy = false;
            }

            /* --------------------------------------------------------------- card form */

            var themes = (evervault.ui && evervault.ui.themes) || {};
            var formOptions = { translations: settings.translations };
            if (typeof themes[settings.theme] === "function") formOptions.theme = themes[settings.theme]();
            var form = evervault.ui.card(formOptions);
            var slot = document.createElement("div");
            slot.className = "cpc-evervault";
            slot.id = host.id + "-card";
            host.appendChild(slot);
            form.on("ready", function () { if (live) events.ready(); });
            form.on("change", function (payload) {
                state = payload && payload.detail ? payload.detail : payload;
            });
            form.mount("#" + slot.id);

            /** The card as the server takes it (ev: number and CVC), or null while the form is incomplete. */
            function encryptedCard() {
                var card = state && state.card;
                if (!card || !state.isComplete || !card.number || !card.cvc) return null;
                return assign({ number: card.number, cvc: card.cvc }, expiryOf(card));
            }

            function submit() {
                if (busy || !live) return Promise.resolve();
                var card = encryptedCard();
                if (!card) {
                    // Not a payment attempt: the form shows its fields' errors and the customer stays on it
                    if (typeof form.validate === "function") form.validate();
                    events.cancelled();
                    return Promise.resolve();
                }
                busy = true;
                events.processing();
                // The Pay button stays disabled from the 3DS session until the payment's answer
                return payCard({ card: card }).then(settle).then(release, release);
            }

            /* ------------------------------------------------ Apple Pay / Google Pay */

            /**
             * Evervault's wallet button in the page's wallet container: Apple Pay where the browser has it, else Google
             * Pay. Without a merchant, a container, HTTPS or an available wallet the container stays empty.
             */
            function mountWallet() {
                var wallets = settings.wallets;
                var merchantId = gateway.merchantId || (wallets && wallets.merchantId);
                var container = wallets && wallets.containerId ? document.getElementById(wallets.containerId) : null;
                if (!container || !merchantId || !window.isSecureContext || !evervault.transactions || !evervault.ui) return null;

                var apple = "ApplePaySession" in window && !!window.ApplePaySession;
                var type = apple ? "apple_pay" : "google_pay";
                var create = apple ? evervault.ui.applePay : evervault.ui.googlePay;
                if (typeof create !== "function") return null;

                var el = document.createElement("div");
                el.className = "cellon-evervault__wallet";
                el.id = host.id + "-wallet";
                var button = null, removed = false;

                function remove() {
                    if (removed) return;
                    removed = true;
                    try { if (button) button.unmount(); } catch (e) { /* ignore */ }
                    if (el.parentNode) el.parentNode.removeChild(el);
                }

                var options = assign({}, apple ? wallets.applePay : wallets.googlePay, {
                    locale: wallets.locale,
                    // The customer authorized the payment in the wallet's sheet
                    process: function (data, helpers) {
                        var fail = function (message) {
                            try { if (helpers && typeof helpers.fail === "function") helpers.fail({ message: message }); } catch (e) { /* ignore */ }
                        };
                        var payload = walletPayload(type, data);
                        if (!payload || busy || !live) {
                            fail("Your payment could not be processed.");
                            return Promise.resolve();
                        }
                        busy = true;
                        events.processing();
                        // A wallet's payment was authenticated in the wallet: no 3DS session
                        return pay({ wallet: payload }).then(function (outcome) {
                            if (outcome.failure) fail(outcome.failure.message);
                            // The sheet closes; the outcome goes on on the page (next page or the notice)
                            Promise.resolve().then(function () { return settle(outcome); }).then(release, release);
                        });
                    },
                });

                try {
                    var transaction = evervault.transactions.create(assign({}, wallets.transaction, { merchantId: merchantId }));
                    button = create.call(evervault.ui, transaction, options);
                } catch (error) {
                    return null;
                }
                if (typeof button.on === "function") {
                    // This device, browser or account has no such wallet, or the button could not render
                    button.on("unavailable", remove);
                    button.on("error", function () { if (!busy) remove(); });
                }

                function attach() {
                    if (removed || !live) return;
                    container.appendChild(el);
                    try { button.mount("#" + el.id); } catch (e) { remove(); }
                }
                if (apple && typeof button.availability === "function") {
                    Promise.resolve(button.availability()).then(function (availability) {
                        if (availability === "unsupported") remove();
                        else attach();
                    }, remove);
                } else {
                    attach();
                }
                return { destroy: remove };
            }

            wallet = mountWallet();

            return {
                submit: submit,
                destroy: function () {
                    live = false;
                    if (wallet) wallet.destroy();
                    if (step) {
                        try { step.unmount(); } catch (e) { /* ignore */ }
                        step = null;
                    }
                    try { if (typeof form.unmount === "function") form.unmount(); } catch (e) { /* ignore */ }
                    while (host.firstChild) host.removeChild(host.firstChild);
                },
            };
        });
    }

    CP.registerGateway({
        slug: SLUG,
        mount: mount,
        // Evervault's card form has no button of its own: the page's Pay button submits it
        ownButton: false,
        email: "page",
        // Every Pay is a new payment with the address typed by then
        emailAtIntent: false,
        // The card stays in the form after a decline; the next Pay sends it again
        remountOnRetry: false,
        // A lost answer: the API may still be running the processors, so the checkout asks until it knows
        verifyUntilKnown: true,
        processingNotice: true,
    });
})(window, document);
