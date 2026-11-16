/* Cellon Payment Collection: Stripe gateway (Payment Element, Express Checkout wallets, Contact Details).
 *
 * Options (from the server, Stripe_Provider::browser_options):
 *   paymentMethodTypes  ["card"] by default (a saved Link card would otherwise be charged as Link)
 *   deferred            { setupFutureUsage } : Elements mount without an intent; the intent is created on Pay
 *   elements, appearance, paymentElement
 *   expressCheckout     { container: "#gpay_btn", options } : Apple Pay / Google Pay in the page's wallet container
 *   contactDetails      { options } : Stripe asks for the email above the card fields (dummy-email collection)
 *   country, customerIp billing country; Alipay mandate data
 *   returnUrl           3DS / Alipay redirect target (default: this page)
 *
 * A wallet (Google Pay / Apple Pay) decline is a Stripe payment failure like a card decline, so the
 * sequence moves to the next provider for it.
 */
(function (window, document) {
    "use strict";

    var CP = window.CellonPC;
    var STRIPE_JS = "https://js.stripe.com/v3/";
    var PAID = ["succeeded", "requires_capture"];
    // Stripe charges these in the major unit: ¥199 is 199, not 19900
    var ZERO_DECIMAL = ["bif", "clp", "djf", "gnf", "jpy", "kmf", "krw", "mga", "pyg", "rwf", "ugx", "uyi", "vnd", "vuv", "xaf", "xof", "xpf"];

    function toStripeAmount(amount, currency) {
        return ZERO_DECIMAL.indexOf(String(currency).toLowerCase()) !== -1 ? Math.round(amount) : Math.round(amount * 100);
    }

    function assign(target) {
        for (var i = 1; i < arguments.length; i++) {
            var source = arguments[i] || {};
            Object.keys(source).forEach(function (key) { target[key] = source[key]; });
        }
        return target;
    }

    function container(selector) {
        if (!selector) return null;
        return typeof selector === "string" ? document.querySelector(selector) : selector;
    }

    function failure(stage, code, message, raw) {
        return { gateway: "stripe", stage: stage, code: code, message: message, raw: raw };
    }

    function mount(ctx) {
        var gateway = ctx.gateway, host = ctx.host, api = ctx.api, events = ctx.events, checkout = ctx.checkout;
        var settings = ctx.options || {};
        var deferred = settings.deferred;
        var methods = settings.paymentMethodTypes || ["card"];
        var fields = methods.length ? { payment_method_type: methods } : {};
        var clientSecret = null;
        var accountId = gateway.accountId;
        var publicKey = null;

        function createIntent() {
            return api.intent(fields).catch(function (error) { throw CP.atStage("intent", error); }).then(function (intent) {
                var secret = intent && intent.client_secret;
                var key = CP.cleanValue(intent && intent.public_key) || gateway.publicKey;
                if (!secret || !key) throw CP.GatewayError("intent", "missing_client_secret", "The API returned no client_secret or public_key");
                var account = CP.cleanValue(intent && intent.payment_provider_detail_id);
                accountId = account || accountId;
                return { clientSecret: secret, publicKey: key, accountId: account };
            });
        }

        var start;
        if (deferred) {
            if (!gateway.publicKey) return Promise.reject(CP.GatewayError("load", "missing_public_key", "No Stripe publishable key for this account"));
            if (!checkout.amount || !checkout.currency) return Promise.reject(CP.GatewayError("load", "missing_amount", "Deferred Stripe needs the amount and currency"));
            publicKey = gateway.publicKey;
            start = Promise.resolve();
        } else {
            start = createIntent().then(function (created) {
                clientSecret = created.clientSecret;
                publicKey = created.publicKey;
            });
        }

        return start.then(function () {
            return CP.loadScript(STRIPE_JS, { globalName: "Stripe" });
        }).then(function (StripeJs) {
            var stripe = StripeJs(publicKey);
            var elementsOptions = deferred
                ? assign({}, settings.elements, {
                    mode: "payment",
                    amount: toStripeAmount(checkout.amount, checkout.currency),
                    currency: String(checkout.currency).toLowerCase(),
                    appearance: settings.appearance,
                }, methods.length ? { paymentMethodTypes: methods } : null,
                deferred.setupFutureUsage ? { setupFutureUsage: deferred.setupFutureUsage } : null,
                deferred.captureMethod ? { captureMethod: deferred.captureMethod } : null)
                : assign({}, settings.elements, { clientSecret: clientSecret, appearance: settings.appearance });
            var elements = stripe.elements(elementsOptions);
            // Link rides on `card`, so the method list alone does not hide it; the Element's wallets option does
            var paymentElementOptions = settings.paymentElement || {};
            if (methods.length) {
                paymentElementOptions = assign({}, paymentElementOptions, { wallets: assign({ link: "never" }, paymentElementOptions.wallets) });
            }
            var element = elements.create("payment", paymentElementOptions);

            // Apple Pay / Google Pay: the page's wallet container, else one above the Payment Element
            var express = settings.expressCheckout;
            var paymentHost = host;
            var walletHost = null;
            if (express) {
                walletHost = container(express.container);
                if (!walletHost) {
                    walletHost = document.createElement("div");
                    walletHost.className = "cellon-payment__wallets";
                    paymentHost = document.createElement("div");
                    host.appendChild(walletHost);
                    host.appendChild(paymentHost);
                }
            }

            // Email above the card fields, in the same Elements group, so confirmPayment sends it as the billing email
            var contact = null;
            if (settings.contactDetails) {
                var contactHost = document.createElement("div");
                contactHost.className = "cellon-payment__contact";
                if (paymentHost === host) {
                    paymentHost = document.createElement("div");
                    host.appendChild(contactHost);
                    host.appendChild(paymentHost);
                } else {
                    host.insertBefore(contactHost, paymentHost);
                }
                contact = elements.create("contactDetails", settings.contactDetails.options);
                contact.on("change", function (event) {
                    ctx.page.reportEmail(String((event && event.value && event.value.email) || ""));
                });
                contact.mount(contactHost);
            }

            element.on("ready", function () { events.ready(); });
            element.on("loaderror", function (event) {
                var error = event && event.error;
                events.failed(failure("load", (error && error.code) || "element_load_error", (error && error.message) || "Payment Element failed to load", event));
            });
            element.mount(paymentHost);

            function confirm(paymentIntentId) {
                return api.confirm({ payment_intent_id: paymentIntentId }, accountId).then(function (data) {
                    events.succeeded({ gateway: "stripe", accountId: accountId, data: data });
                }, function (error) {
                    events.failed(CP.toFailure("stripe", CP.atStage("confirm", error), "confirm"));
                });
            }

            function settle(paymentIntent) {
                if (PAID.indexOf(paymentIntent.status) !== -1) return confirm(paymentIntent.id);
                if (paymentIntent.status === "processing") return events.failed(failure("payment", "processing", "Payment is still processing"));
                var last = paymentIntent.last_payment_error || {};
                events.failed(failure("payment", last.decline_code || last.code || paymentIntent.status, last.message || "Payment failed", paymentIntent));
            }

            // Billing country, the page's email (when Stripe does not collect it), Alipay mandate
            function confirmParams() {
                var params = {
                    return_url: settings.returnUrl || window.location.href,
                    payment_method_data: { billing_details: { address: { country: settings.country || "US" } } },
                };
                var email = ctx.page.email();
                if (!contact && CP.EMAIL_RE.test(email)) params.payment_method_data.billing_details.email = email;
                if (methods.indexOf("alipay") !== -1 && settings.customerIp) {
                    params.mandate_data = { customer_acceptance: { type: "online", online: { ip_address: settings.customerIp, user_agent: navigator.userAgent } } };
                }
                return params;
            }

            function outcome(error, paymentIntent) {
                if (paymentIntent) return settle(paymentIntent);
                // Incomplete fields: the Element shows the message itself; not an attempt
                if (error && error.type === "validation_error") return events.cancelled();
                // The request may have reached Stripe: ask Stripe before deciding anything
                if (error && error.type === "api_connection_error" && clientSecret) {
                    return stripe.retrievePaymentIntent(clientSecret).catch(function () { return null; }).then(function (retrieved) {
                        if (retrieved && retrieved.paymentIntent) return settle(retrieved.paymentIntent);
                        events.failed(failure("payment", "outcome_unknown", error.message, error));
                    });
                }
                events.failed(failure("payment", (error && (error.decline_code || error.code || error.type)) || "card_declined", (error && error.message) || "Payment failed", error));
            }

            /**
             * One intent per mount. Deferred: created on the first submit; a decline keeps it (Stripe lets a
             * PaymentIntent be confirmed again with another card).
             */
            function ensureClientSecret() {
                if (clientSecret) return Promise.resolve(clientSecret);
                return createIntent().then(function (created) {
                    if (created.publicKey !== publicKey) {
                        // The API created the intent on another account than the SDK on the page: reload with its key
                        events.accountChanged(assign({}, gateway, { publicKey: created.publicKey, accountId: created.accountId }));
                        return null;
                    }
                    clientSecret = created.clientSecret;
                    return clientSecret;
                });
            }

            /** Card form (the page's Pay button) and wallet buttons: validate, intent, confirm. */
            function confirmWith(group) {
                var validated = deferred ? group.submit() : Promise.resolve({});
                return validated.then(function (submitted) {
                    if (submitted && submitted.error) return events.cancelled();
                    events.processing();
                    return ensureClientSecret().then(function (secret) {
                        if (!secret) return; // accountChanged: the sequence is remounting
                        return stripe.confirmPayment(assign({
                            elements: group,
                            redirect: "if_required",
                            confirmParams: confirmParams(),
                        }, deferred ? { clientSecret: secret } : null)).then(function (result) {
                            return outcome(result.error, result.paymentIntent);
                        });
                    }, function (error) {
                        events.failed(CP.toFailure("stripe", error, "intent"));
                    });
                });
            }

            var wallets = null;
            if (walletHost) {
                var walletBox = walletHost;
                // Own Elements group on the same intent, so confirming a wallet never picks up the card form
                var walletElements = stripe.elements(elementsOptions);
                wallets = walletElements.create("expressCheckout", express.options);
                var changeSeen = false;
                // No wallet on this device (HTTP, no saved card, domain not registered): no empty gap.
                // data-wallets = how many buttons show (Apple Pay + Google Pay = 2) for the page's layout.
                var showWallets = function (available) {
                    var count = available ? Object.keys(available).filter(function (key) {
                        var method = available[key];
                        return method && typeof method === "object" ? !!method.available : !!method;
                    }).length : 0;
                    walletBox.hidden = count === 0;
                    walletBox.setAttribute("data-wallets", String(count));
                };
                wallets.on("availablepaymentmethodschange", function (event) {
                    changeSeen = true;
                    showWallets(event && event.paymentMethods);
                });
                wallets.on("ready", function (event) {
                    if (!changeSeen) showWallets(event && event.availablePaymentMethods);
                });
                wallets.on("loaderror", function () { walletBox.hidden = true; });
                // A wallet decline goes through the same decision as a card decline (next provider)
                wallets.on("confirm", function () { confirmWith(walletElements); });
                wallets.mount(walletBox);
            }

            return {
                submit: function () { return confirmWith(elements); },
                destroy: function () {
                    element.destroy();
                    if (contact) contact.destroy();
                    if (wallets) {
                        wallets.destroy();
                        // The page's container goes back to the next gateway (Solidgate puts its wallet there)
                        if (walletHost && !host.contains(walletHost)) {
                            walletHost.hidden = false;
                            walletHost.removeAttribute("data-wallets");
                        }
                    }
                },
            };
        });
    }

    CP.registerGateway({
        slug: "stripe",
        mount: mount,
        // Payment Element has no button of its own: the page's Pay button submits it
        ownButton: false,
        // With the Contact Details Element Stripe collects the email; otherwise the page's field does
        email: function (options) { return options.contactDetails ? "element" : "page"; },
        emailAtIntent: false,
        processingNotice: true,
        analytics: { referenceKey: "payment_intent_id" },
        // Back from a 3DS / Alipay redirect: ?payment_intent=pi_…&redirect_status=…
        resume: {
            params: ["payment_intent", "payment_intent_client_secret", "redirect_status"],
            body: function (reference) { return { payment_intent_id: reference }; },
        },
    });
})(window, document);
