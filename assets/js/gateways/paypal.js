/* Cellon Payment Collection: PayPal gateway (PayPal JS SDK v5 Buttons).
 *
 * The client id must be in the SDK URL before an order exists, so it comes from the provider list; when
 * the API creates the order on another account the gateway reports accountChanged and reloads PayPal once
 * with that client id. The server creates and captures the order; the browser only approves it.
 *
 * Options (Paypal_Provider::browser_options): style, sdkParams, returnUrl, cancelUrl.
 */
(function (window) {
    "use strict";

    var CP = window.CellonPC;
    var PAYPAL_JS = "https://www.paypal.com/sdk/js";

    function mount(ctx) {
        var gateway = ctx.gateway, host = ctx.host, api = ctx.api, events = ctx.events, checkout = ctx.checkout;
        var options = ctx.options || {};
        if (!gateway.publicKey) return Promise.reject(CP.GatewayError("load", "missing_public_key", "No PayPal client id for this account"));

        var params = { "client-id": gateway.publicKey, components: "buttons", intent: "capture" };
        if (checkout.currency) params.currency = checkout.currency;
        Object.keys(options.sdkParams || {}).forEach(function (key) { params[key] = options.sdkParams[key]; });
        var src = PAYPAL_JS + "?" + new URLSearchParams(params).toString();

        // One PayPal SDK per page: another client id replaces the old script
        return CP.loadScript(src, { globalName: "paypal", replace: /paypal\.com\/sdk\/js/ }).then(function (paypal) {
            var accountId = gateway.accountId;
            var orderFailure = null;

            var buttons = paypal.Buttons({
                style: options.style,
                createOrder: function () {
                    orderFailure = null;
                    return api.intent({
                        return_url: options.returnUrl || window.location.href,
                        cancel_url: options.cancelUrl || window.location.href,
                    }).then(function (order) {
                        var orderKey = CP.cleanValue(order && order.public_key);
                        if (orderKey && orderKey !== gateway.publicKey) {
                            var next = {};
                            Object.keys(gateway).forEach(function (key) { next[key] = gateway[key]; });
                            next.publicKey = orderKey;
                            next.accountId = CP.cleanValue(order.payment_provider_detail_id);
                            events.accountChanged(next);
                            throw CP.GatewayError("intent", "account_changed", "PayPal account changed; reloading");
                        }
                        if (!order || !order.order_id) throw CP.GatewayError("intent", "missing_order_id", "The API returned no PayPal order id");
                        accountId = CP.cleanValue(order.payment_provider_detail_id) || accountId;
                        return order.order_id;
                    }).catch(function (error) {
                        orderFailure = CP.toFailure("paypal", CP.atStage("intent", error), "intent");
                        throw error;
                    });
                },
                onApprove: function (data) {
                    events.processing();
                    return api.confirm({ paypal_order_id: data.orderID }, accountId).then(function (result) {
                        events.succeeded({ gateway: "paypal", accountId: accountId, data: result });
                    }, function (error) {
                        events.failed(CP.toFailure("paypal", CP.atStage("confirm", error), "confirm"));
                    });
                },
                onCancel: function () { events.cancelled(); },
                onError: function (error) {
                    // accountChanged already started a reload
                    if (orderFailure && orderFailure.code === "account_changed") return;
                    // The buyer closed PayPal's window before approving: PayPal reports that through onError
                    // ("Window is closed, can not determine type") instead of onCancel; it is not a failed payment
                    if (!orderFailure && /window is closed|window closed|popup.*clos/i.test(String((error && error.message) || error || ""))) {
                        events.cancelled();
                        return;
                    }
                    events.failed(orderFailure || { stage: "payment", code: "paypal_error", message: (error && error.message) || "PayPal error", raw: error });
                },
            });

            if (typeof buttons.isEligible === "function" && !buttons.isEligible()) {
                throw CP.GatewayError("load", "not_eligible", "PayPal buttons are not eligible for this buyer");
            }
            return buttons.render(host).then(function () {
                events.ready();
                return {
                    destroy: function () {
                        Promise.resolve(buttons.close && buttons.close()).catch(function () {});
                    },
                };
            });
        });
    }

    CP.registerGateway({
        slug: "paypal",
        mount: mount,
        ownButton: true,
        // createOrder runs on the buyer's click: an order the API refuses keeps the PayPal button (with a notice)
        intentOnClick: true,
        // PayPal has the buyer's PayPal address; the page asks for none
        email: "provider",
        processingNotice: true,
    });
})(window);
