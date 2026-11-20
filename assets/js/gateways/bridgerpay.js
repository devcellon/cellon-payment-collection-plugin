/* Cellon Payment Collection: BridgerPay gateway (Checkout widget or BLOX card fields).
 *
 * Mode: options.mode wins; unset, the account's checkout_mode from the provider list; else the widget.
 *   checkout  the launcher renders BridgerPay's widget (its own Pay button); [bp]:deposit tells us when to confirm
 *   blox      BridgerPay's iframes take card number, expiry and CVV; the page's email field and Pay button do
 *             the rest. On Pay: BLOX token → the server charges it → confirm records it (3DS shows in the box)
 *
 * BridgerPay cascades between its own PSPs first, so a decline here means every PSP declined.
 *
 * Options (Bridgerpay_Provider::browser_options): mode, session, attributes, blox { labels, placeholders,
 * style, fontsSources, classNames }, confirmPollMs, confirmTimeoutMs, threeDsTimeoutMs,
 * wallet (BLOX: Google Pay / Apple Pay in walletContainerId, default on), walletSession (extra session
 * options for that wallet widget).
 */
(function (window, document) {
    "use strict";

    var CP = window.CellonPC;
    var LAUNCHER_JS = "https://checkout.bridgerpay.com/v2/launcher";
    var BLOX_SDK = "https://checkout.bridgerpay.com/sdk";
    var PROCESSING_EVENTS = ["processingPayment", "processingDeposit", "loadingPSP", "processingPSP", "showProgressScreen"];
    var DECLINED = /declin|fail|reject|cancel|void|error|block/i;
    var INVALID_COLOR = "#eb1c26";
    var CSS_ID = "cellon-bridgerpay-css";
    var CSS =
        // After a Pay click with incomplete fields: BLOX flags each empty or invalid field with
        // bp-blox--invalid (and drops the class as the customer fixes it); the red outline beats the
        // browser's focus ring on the name field
        ".cellon-blox--checked .bp-blox--invalid,.cellon-blox--checked .cellon-blox__input:invalid{border-color:" + INVALID_COLOR + "!important}" +
        ".cellon-blox--checked .cellon-blox__input:invalid{outline-color:" + INVALID_COLOR + "}" +
        // The native wallet button fills the slot at the height of Stripe's and Solidgate's wallet buttons
        ".cellon-bridgerpay__wallet{width:100%}" +
        ".cellon-bridgerpay__wallet .wallet-google-pay,.cellon-bridgerpay__wallet .wallet-google-pay>div{width:100%!important}" +
        ".cellon-bridgerpay__wallet button.gpay-button{display:block;width:100%!important;min-width:0!important;height:50px!important;border-radius:4px!important}" +
        ".cellon-bridgerpay__wallet apple-pay-button{display:block;width:100%;--apple-pay-button-width:100%;--apple-pay-button-height:50px;--apple-pay-button-border-radius:4px}";

    function addStyles() {
        if (document.getElementById(CSS_ID)) return;
        var style = document.createElement("style");
        style.id = CSS_ID;
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    var SESSION_KEYS = {
        singlePaymentMethod: "single_payment_method",
        directPaymentMethod: "direct_payment_method",
        hidePaymentMethods: "hide_payment_methods",
        hidePaymentProviders: "hide_payment_providers",
        alwaysVisibleInputs: "always_visible_inputs_for_providers",
        theme: "theme",
        useSystemColorScheme: "use_system_color_scheme",
        depositButtonText: "deposit_button_text",
        payMode: "pay_mode",
        hideLanguagesDropdown: "hide_languages_dropdown",
        showPlaceholderForCvv: "show_placeholder_for_cvv",
        dontRenderCardHolderNameInUppercase: "dont_render_card_holder_name_in_uppercase",
        hideCardHolderNameWhenFullNameIsAvailable: "hide_card_holder_name_when_full_name_is_available",
        firstName: "first_name",
        lastName: "last_name",
        address: "address",
        city: "city",
        state: "state",
        zipCode: "zip_code",
        phone: "phone",
    };

    var DEFAULT_TEXT = {
        labels: { cardHolder: "Cardholder name", cardNumber: "Card number", cardExpiry: "Expiry date", cardCvv: "CVV" },
        placeholders: { cardHolder: "", cardNumber: "1234 1234 1234 1234", cardExpiry: "MM / YY", cardCvv: "CVV" },
    };
    var DEFAULT_STYLES = {
        root: "display:grid;gap:14px;text-align:left",
        field: "display:grid;gap:6px;text-align:left",
        label: "font-size:14px;color:#121212;text-align:left;font-weight:600",
        input: "height:48px;padding:0 14px;border:1px solid #c9ced4;border-radius:8px;font:inherit;font-size:16px;color:#121212;background:#fff;width:100%;box-sizing:border-box",
        slot: "height:48px;padding:14px;border:1px solid #c9ced4;border-radius:8px;background:#fff;box-sizing:border-box",
        row: "display:grid;grid-template-columns:1fr 1fr;gap:12px",
        threeDs: "width:100%;height:520px;border:0",
    };

    function assign(target) {
        for (var i = 1; i < arguments.length; i++) {
            var source = arguments[i] || {};
            Object.keys(source).forEach(function (key) { target[key] = source[key]; });
        }
        return target;
    }

    function mode(options, gateway) {
        return (options && options.mode) || (gateway && gateway.checkoutMode) || "checkout";
    }

    function hasApplePay() {
        return "ApplePaySession" in window && !!window.ApplePaySession;
    }

    function toSession(session) {
        if (!session) return null;
        var body = {};
        Object.keys(SESSION_KEYS).forEach(function (key) {
            if (session[key] !== undefined && session[key] !== "") body[SESSION_KEYS[key]] = session[key];
        });
        // "wallet": the wallet this browser can show
        if (body.single_payment_method === "wallet") body.single_payment_method = hasApplePay() ? "wallet_apple_pay_direct" : "wallet_google_pay";
        return Object.keys(body).length ? body : null;
    }

    /**
     * The API records the sale only when BridgerPay reports it final: 409 (still processing, 3DS not done)
     * means ask again; 404 with a decline reason means nothing was charged; anything else after a payment is
     * a confirm problem (money may have moved). `paid` = BridgerPay took the payment request.
     */
    function pollConfirm(ctx, opts) {
        var deadline = Date.now() + opts.timeoutMs;
        var notFound = 0;
        function attempt() {
            return ctx.api.confirm({ order_id: opts.orderId }, opts.accountId).then(function (data) {
                if (opts.isLive()) ctx.events.succeeded({ gateway: "bridgerpay", accountId: opts.accountId, data: data });
            }, function (error) {
                if (!opts.isLive()) return;
                var status = error && error.isApiError ? error.status : 0;
                var message = error && error.message ? error.message : String(error);
                var missing = status === 404 && /not found/i.test(message);
                var declined = status === 404 && !missing && !/invalid/i.test(message);
                if (declined) return ctx.events.failed({ stage: "payment", code: "declined", message: message, raw: error });
                // No order at BridgerPay after a widget error: the customer was not charged
                if (missing && !opts.paid) return ctx.events.failed(assign({ raw: error }, opts.fallback));
                var stillProcessing = status === 409 || (missing && ++notFound <= 3);
                if (stillProcessing && Date.now() < deadline) {
                    return CP.wait(opts.pollMs).then(function () { if (opts.isLive()) return attempt(); });
                }
                if (stillProcessing) return ctx.events.failed({ stage: "payment", code: "processing", message: "Payment is still processing", raw: error });
                ctx.events.failed(CP.toFailure("bridgerpay", CP.atStage("confirm", error), "confirm"));
            });
        }
        return attempt();
    }

    /* Checkout widget: the launcher renders inside the host and reports through window [bp]:<name> events */
    function mountCheckout(ctx) {
        var gateway = ctx.gateway, host = ctx.host, events = ctx.events;
        var settings = ctx.options || {};
        var session = toSession(settings.session);

        return ctx.api.intent(session ? { bridgerpay_session: session } : {}).catch(function (error) { throw CP.atStage("intent", error); }).then(function (intent) {
            var cashierKey = CP.cleanValue(intent && intent.cashier_key);
            var cashierToken = CP.cleanValue(intent && intent.cashier_token);
            var orderId = CP.cleanValue(intent && intent.order_id);
            if (!cashierKey || !cashierToken || !orderId) {
                throw CP.GatewayError("intent", "missing_session", "The API returned no BridgerPay session (cashier_key, cashier_token, order_id)");
            }
            var accountId = CP.cleanValue(intent.payment_provider_detail_id) || gateway.accountId;
            var live = true, processing = false, settled = false, lastError = null;
            var listeners = [];

            function on(name, handler) {
                var listener = function (event) { if (live) handler(event && event.detail); };
                window.addEventListener("[bp]:" + name, listener);
                listeners.push(["[bp]:" + name, listener]);
            }
            function markProcessing() {
                if (processing) return;
                processing = true;
                events.processing();
            }
            function fail(failure) {
                if (settled) return;
                settled = true;
                events.failed(failure);
            }
            function confirm(paid, fallback) {
                if (settled) return;
                settled = true;
                markProcessing();
                return pollConfirm(ctx, {
                    orderId: orderId,
                    accountId: accountId,
                    isLive: function () { return live; },
                    paid: paid,
                    fallback: fallback,
                    pollMs: settings.confirmPollMs || 3000,
                    timeoutMs: settings.confirmTimeoutMs || 60000,
                });
            }

            on("contentRendered", function () { events.ready(); });
            PROCESSING_EVENTS.forEach(function (name) { on(name, markProcessing); });
            on("requestPaymentError", function (detail) { lastError = detail; });
            on("deposit", function (detail) {
                detail = detail || {};
                var type = String(detail.type || detail.status || "").toLowerCase().replace(/\s+/g, "_");
                if (type && DECLINED.test(type)) {
                    var reason = detail.declineReason || detail.decline_reason || detail.reason || detail.message || (lastError && lastError.message);
                    return fail({ stage: "payment", code: type, message: reason || "Payment declined", raw: detail });
                }
                // approved, or pending: the API decides from BridgerPay's own record
                confirm(true, { stage: "payment", code: "outcome_unknown", message: "Payment outcome unknown" });
            });
            on("error", function (detail) {
                detail = detail || {};
                var code = String(detail.code || detail.type || "widget_error");
                var message = detail.message || "BridgerPay widget error";
                // After the customer paid we cannot tell whether a PSP charged the card: the API checks the order
                if (processing) return confirm(false, { stage: "payment", code: code, message: message, raw: detail });
                // Before that, a widget error is a load problem (bad key, expired session)
                fail({ stage: "load", code: code, message: message, raw: detail });
            });

            var script = document.createElement("script");
            script.src = LAUNCHER_JS;
            script.async = true;
            assign(script.dataset, settings.attributes, { cashierKey: cashierKey, cashierToken: cashierToken });
            script.onerror = function () { fail({ stage: "load", code: "sdk_load_failed", message: LAUNCHER_JS + " failed to load (blocked or offline)" }); };
            // The launcher renders next to its own script tag; removing the host destroys the widget
            host.appendChild(script);

            return {
                destroy: function () {
                    live = false;
                    listeners.forEach(function (entry) { window.removeEventListener(entry[0], entry[1]); });
                },
            };
        });
    }

    /**
     * Google Pay / Apple Pay beside BLOX. BLOX has no wallets, so a second session (single payment method
     * "wallet": Apple Pay where ApplePaySession exists, else Google Pay) runs the launcher in wallet button
     * mode (data-button-mode="wallet"): BridgerPay's wallet script draws the native Google Pay / Apple Pay
     * button in the page's wallet slot, like Stripe's and Solidgate's, and shows its own 3DS modal. It reports
     * on `[bp][checkout:<cashier_key>]:wallet-*` events and pays its own order_id; a decline there is a
     * decline of this gateway. Any problem before the customer pays only leaves the slot empty.
     */
    function mountWallet(ctx, isLive) {
        var settings = ctx.options || {};
        var slot = settings.walletContainerId ? document.getElementById(settings.walletContainerId) : null;
        if (!slot || settings.wallet === false) return Promise.resolve(null);

        var session = toSession(assign({}, settings.session, settings.walletSession, {
            singlePaymentMethod: "wallet",
            alwaysVisibleInputs: undefined,
            hideLanguagesDropdown: true,
        }));
        return ctx.api.intent({ bridgerpay_mode: "checkout", bridgerpay_session: session }).then(function (intent) {
            var cashierKey = CP.cleanValue(intent && intent.cashier_key);
            var cashierToken = CP.cleanValue(intent && intent.cashier_token);
            var orderId = CP.cleanValue(intent && intent.order_id);
            if (!isLive() || !cashierKey || !cashierToken || !orderId) return null;
            var accountId = CP.cleanValue(intent.payment_provider_detail_id) || ctx.gateway.accountId;

            var host = document.createElement("div");
            host.className = "cellon-bridgerpay__wallet";
            // Built off screen; it moves into the slot once its button is ready (the page shows the wallet
            // row whenever the slot has children)
            host.style.cssText = "position:absolute;left:-10000px;top:0;width:300px";
            addStyles();
            var processing = false, settled = false;
            var listeners = [];
            var prefix = "[bp][checkout:" + cashierKey + "]:";
            function on(name, handler) {
                var listener = function (event) { if (isLive() && !settled) handler(event.detail || {}); };
                window.addEventListener(prefix + name, listener);
                listeners.push([prefix + name, listener]);
            }
            function remove() {
                listeners.forEach(function (entry) { window.removeEventListener(entry[0], entry[1]); });
                listeners = [];
                if (host.parentNode) host.parentNode.removeChild(host);
            }
            function confirm(paid, fallback) {
                settled = true;
                return pollConfirm(ctx, {
                    orderId: orderId,
                    accountId: accountId,
                    isLive: isLive,
                    paid: paid,
                    fallback: fallback,
                    pollMs: settings.confirmPollMs || 3000,
                    timeoutMs: settings.confirmTimeoutMs || 60000,
                });
            }

            on("wallet-button-is-ready", function () {
                host.style.cssText = "";
                slot.appendChild(host);
            });
            // No wallet on this device or browser, or the account has none enabled
            on("wallet-provider-cannot-be-used", remove);
            on("no-wallet-provider-found", remove);
            on("wallet-processing-payment", function () {
                if (processing) return;
                processing = true;
                ctx.events.processing();
            });
            // The customer closed the wallet sheet: not an attempt
            on("wallet-cancelled", function () {
                if (!processing) return;
                processing = false;
                ctx.events.cancelled();
            });
            on("wallet-result", function (result) {
                var status = String(result.status || "").toLowerCase().replace(/\s+/g, "_");
                if (!status || DECLINED.test(status)) {
                    settled = true;
                    return ctx.events.failed({ stage: "payment", code: status || "declined", message: result.reason || result.message || "Payment declined", raw: result });
                }
                // approved / pending / authorized: the server reads BridgerPay's own record
                confirm(true, { stage: "payment", code: "outcome_unknown", message: "Payment outcome unknown" });
            });

            var script = document.createElement("script");
            script.src = LAUNCHER_JS;
            script.async = true;
            assign(script.dataset, settings.attributes, { cashierKey: cashierKey, cashierToken: cashierToken, buttonMode: "wallet" });
            script.onerror = remove;
            host.appendChild(script);
            document.body.appendChild(host);
            return { destroy: remove };
        }, function () { return null; });
    }

    /* BLOX: card fields in BridgerPay's iframes, the page's email field and Pay button */
    function mountBlox(ctx) {
        var gateway = ctx.gateway, host = ctx.host, events = ctx.events;
        var settings = ctx.options || {};
        var blox = settings.blox || {};

        function element(tag, part) {
            var node = document.createElement(tag);
            var custom = blox.classNames && blox.classNames[part];
            node.className = ["cellon-blox__" + part, custom].filter(Boolean).join(" ");
            if (!custom) node.setAttribute("style", DEFAULT_STYLES[part]);
            return node;
        }

        return ctx.api.intent({ bridgerpay_mode: "blox" }).catch(function (error) { throw CP.atStage("intent", error); }).then(function (intent) {
            var bloxToken = CP.cleanValue(intent && intent.cashier_token);
            var orderId = CP.cleanValue(intent && intent.order_id);
            if (!bloxToken || !orderId || (intent.checkout_mode && intent.checkout_mode !== "blox")) {
                throw CP.GatewayError("intent", "blox_unsupported", "The API returned no BLOX session");
            }
            var accountId = CP.cleanValue(intent.payment_provider_detail_id) || gateway.accountId;

            return CP.loadScript(BLOX_SDK, { globalName: "BP" }).then(function (BP) {
                var labels = assign({}, DEFAULT_TEXT.labels, blox.labels);
                var placeholders = assign({}, DEFAULT_TEXT.placeholders, blox.placeholders);
                var id = host.id || "cellon-blox-" + Math.random().toString(36).slice(2, 8);

                function field(key, label, content) {
                    var wrap = element("div", "field");
                    var caption = element("label", "label");
                    caption.textContent = label;
                    if (content.id) caption.htmlFor = content.id;
                    wrap.appendChild(caption);
                    wrap.appendChild(content);
                    wrap.setAttribute("data-field", key);
                    return wrap;
                }
                function slot(name) {
                    var node = element("div", "slot");
                    node.id = id + "-" + name;
                    return node;
                }

                addStyles();
                var root = element("div", "root");
                var holder = element("input", "input");
                holder.id = id + "-holder";
                holder.autocomplete = "cc-name";
                holder.required = true;
                holder.placeholder = placeholders.cardHolder;
                var number = slot("number"), expiry = slot("expiry"), cvv = slot("cvv");
                var row = element("div", "row");
                row.appendChild(field("cardExpiry", labels.cardExpiry, expiry));
                row.appendChild(field("cardCvv", labels.cardCvv, cvv));
                root.appendChild(field("cardHolder", labels.cardHolder, holder));
                root.appendChild(field("cardNumber", labels.cardNumber, number));
                root.appendChild(row);
                host.appendChild(root);

                var controller = new BP.BloxController({ token: bloxToken, style: blox.style, fontsSources: blox.fontsSources });
                var blocks = [
                    controller.createBlox("cardNumber", { placeholder: placeholders.cardNumber }).mount("#" + number.id),
                    controller.createBlox("cardExpiryDate", { placeholder: placeholders.cardExpiry }).mount("#" + expiry.id),
                    controller.createBlox("cardCvv", { placeholder: placeholders.cardCvv }).mount("#" + cvv.id),
                ];
                return Promise.all(blocks).catch(function (error) {
                    throw CP.GatewayError("load", "blox_mount_failed", (error && error.message) || "BLOX fields could not be mounted", error);
                }).then(function () {
                    events.ready();
                    var live = true, busy = false, wallet = null;
                    var isLive = function () { return live; };
                    // Its own session, opened after the card session (one intent at a time per checkout)
                    mountWallet(ctx, isLive).then(function (mounted) {
                        if (mounted && !live) mounted.destroy();
                        else wallet = mounted;
                    });

                    // Incomplete fields are not a payment attempt (as Stripe): mark them and stay on this form
                    function incomplete(focusHolder) {
                        if (controller.validateAll) controller.validateAll();
                        root.classList.add("cellon-blox--checked");
                        if (focusHolder) holder.focus();
                        events.cancelled();
                        return Promise.resolve();
                    }

                    function submit() {
                        if (busy || !live) return Promise.resolve();
                        var cardHolderName = holder.value.trim();
                        if (!cardHolderName || controller.isAllValid === false) return incomplete(!cardHolderName);
                        busy = true;
                        // Multi-use: the cross sale charges the same card again
                        return Promise.resolve(controller.createPaymentCardToken({ cardHolderName: cardHolderName, singleUse: false })).then(function (card) {
                            events.processing();
                            return ctx.api.chargeToken({
                                order_id: orderId,
                                card_holder_name: cardHolderName,
                                credit_card_token: card.token,
                                encrypted_cvv: card.encryptedCvv,
                            }).then(function (charge) {
                                if (charge && charge.status === "failed") {
                                    busy = false;
                                    var code = String(charge.provider_status || "declined").toLowerCase().replace(/\s+/g, "_");
                                    return events.failed({ stage: "payment", code: code, message: charge.decline_reason || "Payment declined", raw: charge });
                                }
                                var threeDsUrl = CP.cleanValue(charge && charge.three_ds_url);
                                var frame = null;
                                if (threeDsUrl) {
                                    frame = element("iframe", "threeDs");
                                    frame.title = "3D Secure";
                                    frame.src = threeDsUrl;
                                    root.hidden = true;
                                    host.appendChild(frame);
                                }
                                return pollConfirm(ctx, {
                                    orderId: orderId,
                                    accountId: accountId,
                                    isLive: isLive,
                                    paid: true,
                                    fallback: { stage: "payment", code: "outcome_unknown", message: "Payment outcome unknown" },
                                    pollMs: settings.confirmPollMs || 3000,
                                    timeoutMs: threeDsUrl ? settings.threeDsTimeoutMs || 300000 : settings.confirmTimeoutMs || 60000,
                                }).then(function () {
                                    if (frame && live) {
                                        frame.remove();
                                        root.hidden = false;
                                    }
                                    busy = false;
                                });
                            }, function (error) {
                                busy = false;
                                var status = error && error.isApiError ? error.status : 0;
                                // Timeout or network: BridgerPay may have charged; the server checks the order first
                                if (status === 0 || status >= 500) return events.failed(CP.toFailure("bridgerpay", CP.atStage("confirm", error), "confirm"));
                                // First-sale guard: already paid
                                if (/already exists/i.test(error.message || "")) return events.failed({ stage: "intent", code: "already_paid", message: error.message, raw: error });
                                events.failed(CP.toFailure("bridgerpay", CP.atStage("payment", error), "payment"));
                            });
                        }, function () {
                            // BLOX refused to tokenize the fields: nothing was sent for payment
                            busy = false;
                            return incomplete(false);
                        });
                    }

                    return {
                        submit: submit,
                        destroy: function () {
                            live = false;
                            if (wallet) wallet.destroy();
                            while (host.firstChild) host.removeChild(host.firstChild);
                        },
                    };
                });
            });
        });
    }

    CP.registerGateway({
        slug: "bridgerpay",
        mount: function (ctx) {
            return mode(ctx.options, ctx.gateway) === "blox" ? mountBlox(ctx) : mountCheckout(ctx);
        },
        // The Checkout widget has its own Pay button; BLOX uses the page's
        ownButton: function (options, gateway) { return mode(options, gateway) !== "blox"; },
        // BLOX uses the page's email field. The widget owns its whole form (the session carries the email;
        // alwaysVisibleInputs shows the input inside the widget), so the page's field stays hidden for it.
        email: function (options, gateway) { return mode(options, gateway) === "blox" ? "page" : "provider"; },
        // The session (and the card token the cross sale charges) keeps the email it was opened with
        emailAtIntent: true,
        // A session belongs to one order id; after a decline the customer pays on a fresh session
        remountOnRetry: true,
        processingNotice: true,
    });
})(window, document);
