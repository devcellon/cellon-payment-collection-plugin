/* Cellon Payment Collection: Solidgate gateway (payment form, Google Pay / Apple Pay button).
 *
 * Nothing account-specific is in the page: merchant id, signature and the payment intent come from the
 * API at intent. Solidgate renders its own email field (email: "provider") and its own submit button and
 * processing state, so the page shows neither its Pay button nor its green processing notice.
 *
 * Options (Solidgate_Provider::browser_options): walletContainerId, googlePayButtonParams,
 * applePayButtonParams, formParams, styles, hideWallets, cardForm (false: wallet button only), walletButton,
 * walletWaitMs.
 */
(function (window, document) {
    "use strict";

    var CP = window.CellonPC;
    var SOLIDGATE_JS = "https://cdn.solidgate.com/js/solid-form.js";
    var WALLET_CSS_ID = "cellon-solidgate-wallet-css";
    var WALLET_CSS =
        ".cellon-solidgate__wallet>*{display:block!important;width:100%!important;height:100%!important}" +
        ".cellon-solidgate__wallet .gpay-card-info-container,.cellon-solidgate__wallet apple-pay-button{display:block!important;" +
        "width:100%!important;height:100%!important;min-height:0!important}" +
        ".cellon-solidgate__wallet *{border-radius:inherit!important}";

    function assign(target) {
        for (var i = 1; i < arguments.length; i++) {
            var source = arguments[i] || {};
            Object.keys(source).forEach(function (key) { target[key] = source[key]; });
        }
        return target;
    }

    function cssSize(value, fallback) {
        if (value === undefined || value === null || value === "") return fallback;
        return typeof value === "number" ? value + "px" : value;
    }

    function hasApplePay() {
        return "ApplePaySession" in window && !!window.ApplePaySession;
    }

    /** Solidgate's wallet button in the page's container: Apple Pay where the browser has it, else Google Pay. */
    function walletParams(options) {
        if (!options) return {};
        if (options.hideWallets) {
            return { googlePayButtonParams: { enabled: false }, applePayButtonParams: { enabled: false }, paypalButtonParams: { enabled: false } };
        }
        var containerId = options.walletContainerId;
        if (!containerId) return {};
        if (hasApplePay()) {
            return {
                applePayButtonParams: assign({ integrationType: "js", containerId: containerId }, options.applePayButtonParams),
                googlePayButtonParams: { enabled: false },
            };
        }
        return {
            googlePayButtonParams: assign({
                containerId: containerId,
                color: "black",
                type: "plain",
                allowedAuthMethods: ["PAN_ONLY", "CRYPTOGRAM_3DS"],
            }, options.googlePayButtonParams),
        };
    }

    function mount(ctx) {
        var gateway = ctx.gateway, host = ctx.host, api = ctx.api, events = ctx.events;
        var options = ctx.options || {};

        return api.intent({}).catch(function (error) { throw CP.atStage("intent", error); }).then(function (intent) {
            if (!intent || !intent.merchantId || !intent.signature || !intent.paymentIntent) {
                throw CP.GatewayError("intent", "missing_merchant_data", "The API returned no Solidgate merchant data");
            }
            var accountId = CP.cleanValue(intent.payment_provider_detail_id) || gateway.accountId;

            // Wallet only: the button in the box, the card form off screen (it still runs the payment)
            var walletOnly = options.cardForm === false;
            var formContainerId = host.id;
            var solidgateOptions = options;
            if (walletOnly) {
                var wallet = document.createElement("div");
                wallet.id = host.id + "-wallet";
                wallet.className = "cellon-solidgate__wallet";
                var size = options.walletButton || {};
                var height = cssSize(size.height, "48px");
                var radius = cssSize(size.borderRadius, "4px");
                wallet.style.height = height;
                wallet.style.borderRadius = radius;
                wallet.style.overflow = "hidden";
                wallet.style.setProperty("--apple-pay-button-height", height);
                wallet.style.setProperty("--apple-pay-button-border-radius", radius);
                wallet.style.setProperty("--apple-pay-button-width", "100%");
                if (!document.getElementById(WALLET_CSS_ID)) {
                    var style = document.createElement("style");
                    style.id = WALLET_CSS_ID;
                    style.textContent = WALLET_CSS;
                    document.head.appendChild(style);
                }
                var formBox = document.createElement("div");
                formBox.id = host.id + "-form";
                formBox.className = "cellon-solidgate__form";
                formBox.setAttribute("style", "position:absolute;left:-10000px;top:0;width:400px;height:1px;overflow:hidden");
                formBox.setAttribute("aria-hidden", "true");
                host.appendChild(wallet);
                host.appendChild(formBox);
                formContainerId = formBox.id;
                solidgateOptions = assign({}, options, { hideWallets: false, walletContainerId: wallet.id });
            }

            return CP.loadScript(SOLIDGATE_JS, { globalName: "PaymentFormSdk" }).then(function (sdk) {
                var form = sdk.init(assign({
                    merchantData: { merchant: intent.merchantId, signature: intent.signature, paymentIntent: intent.paymentIntent },
                    iframeParams: { containerId: formContainerId, width: "100%" },
                    formParams: solidgateOptions.formParams,
                    styles: solidgateOptions.styles,
                }, walletParams(solidgateOptions)));

                var submitted = false;
                var walletTimer = null;
                form.on("mounted", function (event) {
                    var entity = event && event.data && event.data.entity;
                    if (!walletOnly) {
                        if (!entity || entity === "form") events.ready();
                        return;
                    }
                    if (entity === "googlebtn" || entity === "applebtn") {
                        clearTimeout(walletTimer);
                        events.ready();
                    } else if (entity === "form" || !entity) {
                        // The form is up; no wallet button soon = this device or account has no wallet here
                        walletTimer = setTimeout(function () {
                            events.failed({ stage: "load", code: "wallet_unavailable", message: "Solidgate showed no Apple Pay / Google Pay button" });
                        }, options.walletWaitMs || 6000);
                    }
                });
                form.on("submit", function () {
                    submitted = true;
                    events.processing();
                });
                form.on("success", function (event) {
                    api.confirm({ payment_obj: event.data }, accountId).then(function (data) {
                        events.succeeded({ gateway: "solidgate", accountId: accountId, data: data });
                    }, function (error) {
                        events.failed(CP.toFailure("solidgate", CP.atStage("confirm", error), "confirm"));
                    });
                });
                form.on("fail", function (event) {
                    var data = (event && event.data) || {};
                    events.failed({ stage: "payment", code: String(data.code || "fail"), message: data.message || "Payment declined", raw: data });
                });
                form.on("error", function (event) {
                    var data = (event && event.data) || {};
                    var details = data.details || {};
                    events.failed({
                        // After submit we cannot tell whether Solidgate charged the card
                        stage: submitted ? "payment" : "load",
                        code: submitted ? "outcome_unknown" : String(details.code || "sdk_error"),
                        message: details.message || (data.value && data.value.message) || "Solidgate form error",
                        raw: data,
                    });
                });

                return {
                    destroy: function () {
                        clearTimeout(walletTimer);
                        if (form.destroy) form.destroy();
                    },
                };
            });
        });
    }

    CP.registerGateway({
        slug: "solidgate",
        mount: mount,
        ownButton: true,
        // Solidgate's form has its own email field; the server reads it from the success event at confirm
        email: "provider",
        // The order keeps the customer_email it was created with
        emailAtIntent: true,
        // A declined order cannot be paid again: a retry needs a new order
        remountOnRetry: true,
        // The form shows its own processing state
        processingNotice: false,
        // The themes style Solidgate's wallet button through #gpay_btn.solid_gpay
        walletClass: "solid_gpay",
    });
})(window, document);
