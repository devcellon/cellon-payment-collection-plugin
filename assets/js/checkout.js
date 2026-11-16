/* Cellon Payment Collection: page code for [cellon_payment_checkout].
 *
 * Uses the plugin's own browser core (assets/js/core.js, sequence.js) and the gateway files
 * (assets/js/gateways/*.js), which register themselves. Nothing here names a gateway: what differs per
 * gateway (who collects the email, its pay button, the processing notice, the wallet container class,
 * analytics, a redirect return) comes from its registered definition, and the per-site event names and
 * options come from the server.
 *
 * Provider 1 is mounted; after a declined payment provider 2 mounts in the same place. A provider that
 * fails to load is skipped silently. Every payment call goes to this plugin's REST endpoints; the browser
 * never talks to the Cellon API, never holds its token and never claims a payment result.
 *
 * Plain ES2017, not transpiled. Events on the wrapper (bubble):
 *   cellon:payment:mounted, cellon:payment:success (cancelable: preventDefault() keeps the page),
 *   cellon:payment:attempt-failed, cellon:payment:exhausted
 */
(function () {
    "use strict";

    var CP = window.CellonPC;
    var settings = window.cellonPaymentCollection || {};
    var messages = settings.messages || {};
    var SHOWN = ["ready", "processing", "verify", "succeeded"]; // a gateway's form is on the page
    var EMAIL_RE = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;

    function emit(el, name, detail) {
        var event = new CustomEvent("cellon:payment:" + name, { bubbles: true, cancelable: true, detail: detail });
        el.dispatchEvent(event);
        return event;
    }

    function post(url, body) {
        return CP.post(url, body);
    }

    /* ------------------------------------------------------------ site analytics */

    function push(event, extra) {
        if (!event) return;
        window.dataLayer = window.dataLayer || [];
        var item = { event: event };
        if (extra) for (var key in extra) if (Object.prototype.hasOwnProperty.call(extra, key)) item[key] = extra[key];
        window.dataLayer.push(item);
    }

    function storage(kind, key, value) {
        try {
            var store = window[kind];
            if (value === undefined) return store.getItem(key);
            store.setItem(key, value);
        } catch (e) { /* private mode */ }
        return null;
    }

    /** Event names per gateway come from the server (each PHP provider declares its own per site). */
    function siteEvents(site, quizResultId) {
        var events = (site && site.events) || {};
        var of = function (slug) { return (events.gateways || {})[slug] || {}; };
        return {
            success: function (slug, data) {
                push(of(slug).success);
                if (events.first_sale_fronted && quizResultId) {
                    var key = "first_sale_fronted_" + quizResultId;
                    if (!storage("localStorage", key)) {
                        var def = CP.gateway(slug);
                        var referenceKey = def && def.analytics && def.analytics.referenceKey;
                        var extra = null;
                        if (referenceKey && data && data.reference) {
                            extra = {};
                            extra[referenceKey] = data.reference;
                        }
                        push("first_sale_fronted", extra);
                        storage("localStorage", key, "1");
                    }
                }
                if (of(slug).first_sale && data && data.first_sale_usd_price) {
                    try {
                        if (window.analytics && typeof window.analytics.track === "function") {
                            window.analytics.track("first_sale", { amount: parseFloat(data.first_sale_usd_price) || 0 }, "custom");
                        }
                    } catch (e) { /* analytics must never break the checkout */ }
                }
            },
            failed: function (slug) {
                var name = of(slug).failed;
                if (name && !storage("sessionStorage", "paymentFailedRecorded")) {
                    push(name);
                    storage("sessionStorage", "paymentFailedRecorded", "true");
                }
            },
            submitted: function (slug) {
                var name = of(slug).submit_gtag;
                if (name && typeof window.gtag === "function") {
                    window.gtag("event", name, { event_category: name });
                }
            },
        };
    }

    /* Core plugin's submit_email (dummy-email A/B test), with the themes' cookie / Chargeblast / GTM events. */
    function submitCollectedEmail(site, quizResultId, email) {
        if (!email || !EMAIL_RE.test(email) || !site.emailNonce) return Promise.resolve();
        document.cookie = "user_email=" + encodeURIComponent(email) + "; expires=" + new Date(Date.now() + 2 * 60 * 60 * 1000).toUTCString() + "; path=/";
        if (site.events && site.events.chargeblast && typeof window.Chargeblast !== "undefined") {
            try { window.Chargeblast.identify(email); } catch (e) { /* ignore */ }
        }
        var body = new URLSearchParams({ action: "submit_email", email: email, quiz_data_id: quizResultId, email_info_frm_nonce: site.emailNonce });
        var request = fetch(site.ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json && json.success === true) {
                    push("autoEvent", { eventCategory: "funnel", eventAction: "email_insert", type: { email: email } });
                    try { if (window.analytics) window.analytics.track("email_submitted", {}, "click"); } catch (e) { /* ignore */ }
                }
            })
            .catch(function () { /* the payment is recorded; the email update is best effort, as before */ });
        // Do not hold the redirect longer than 2.5 s
        return Promise.race([request, new Promise(function (resolve) { setTimeout(resolve, 2500); })]);
    }

    function preloader(site, show) {
        if (!site || !site.preloader || !window.jQuery) return;
        var $ = window.jQuery;
        var el = $(site.preloader);
        if (!el.length) return;
        if (show) { el.find(".loader-text").hide(); el.fadeIn("fast"); }
        else { el.fadeOut(1000); el.find(".loader-text").show(); }
    }

    /* ---------------------------------------------------------------- first sale */

    function mountFirstSale(root, config) {
        var site = config.site || {};
        var labels = site.labels || {};
        var ids = site.ids || {};
        var response = document.getElementById(ids.response) || root.querySelector(".cpc-response");
        var analytics = siteEvents(site, config.quizResultId);

        function say(text, kind) {
            if (!response) return;
            response.innerHTML = text ? '<p class="wpf_form_notices wpf_form_notice_' + (kind || "error") + '"></p>' : "";
            if (text) response.firstChild.textContent = text;
        }

        if (!CP || !CP.Sequence) {
            if (window.console) console.error("Cellon Payment Collection: browser core missing (assets/js/core.js, sequence.js)");
            say(messages.unavailable);
            return;
        }

        var rest = CP.restClient(config.rest);
        var options = config.options || {};
        var policy = config.policy || {};
        var units = [];
        var done = false;
        var lastStatus = null;

        function chainUnit() {
            return units.filter(function (unit) { return !unit.isDirect; })[0] || null;
        }
        function activeOf(unit) {
            var state = unit && unit.checkout.getState();
            return state && state.active ? state : null;
        }
        /** page | element | provider: who collects the email for the card chain's active gateway. */
        function emailMode() {
            var state = activeOf(chainUnit());
            if (!state) return "page";
            return CP.capability(state.def, "email", options[state.active.slug], state.active) || "page";
        }

        // ---- email collection (dummy email): the active gateway decides who asks for it
        var emailWrap = root.querySelector("[data-cpc-email]");
        var emailInput = emailWrap ? emailWrap.querySelector("input") : null;
        var reportedEmail = "";   // last address a provider element reported (Stripe Contact Details)
        var copiedEmail = "";     // last reported address copied into the page's email field

        function typedEmail() {
            if (emailMode() === "element") return reportedEmail.trim();
            return emailInput ? emailInput.value.trim() : "";
        }
        function emailOk(showError) {
            var mode = emailMode();
            if (mode === "provider") return true;
            if (mode === "element") {
                // The element validates an empty / incomplete address itself on submit; a typed invalid one stops here
                var typed = typedEmail();
                if (typed && !EMAIL_RE.test(typed)) { if (showError) say(labels.email_error); return false; }
                return true;
            }
            if (!emailInput || emailWrap.hidden) return true;
            var ok = EMAIL_RE.test(typedEmail());
            if (!ok && showError) { say(labels.email_error); emailInput.focus(); }
            return ok;
        }
        function liveCheck(value) {
            if (value === "" || EMAIL_RE.test(value.trim())) say("");
            else say(labels.email_error);
        }
        if (emailInput) {
            emailInput.addEventListener("input", function () { liveCheck(emailInput.value); });
        }

        /** Handed to every gateway's mount context. */
        var page = {
            email: typedEmail,
            reportEmail: function (address) {
                reportedEmail = address || "";
                var trimmed = reportedEmail.trim();
                // The next gateway gets the same address: the page's field shows it filled in, and the next
                // intent carries it as customer_email (common API: the session binds its card token to it)
                if (emailInput && (emailInput.value === "" || emailInput.value === copiedEmail)) {
                    emailInput.value = trimmed;
                    copiedEmail = trimmed;
                }
                var chain = chainUnit();
                if (chain && EMAIL_RE.test(trimmed)) chain.checkout.setOptions({ checkout: checkoutInput(trimmed) });
                liveCheck(reportedEmail);
            },
        };

        function checkoutInput(customerEmail) {
            var input = {
                currency: config.checkout && config.checkout.currency ? config.checkout.currency : undefined,
                amount: config.checkout && config.checkout.amount ? Number(config.checkout.amount) : undefined,
            };
            if (customerEmail) input.customerEmail = customerEmail;
            return input;
        }

        function verifyPayment() {
            return rest.status().then(function (data) {
                lastStatus = data;
                if (data && data.paid === true) return true;
                if (data && data.paid === false) return false;
                return null;
            });
        }

        // One payment at a time: while one box processes or verifies, the others are locked (the server enforces it too)
        function syncLocks() {
            var busy = null;
            units.forEach(function (unit) {
                if (CP.Sequence.BUSY.indexOf(unit.checkout.getState().status) !== -1) busy = unit;
            });
            units.forEach(function (unit) {
                var locked = !!busy && busy !== unit;
                unit.box.toggleAttribute("data-locked", locked);
                unit.box.setAttribute("aria-disabled", locked ? "true" : "false");
            });
            syncLayout();
        }

        // Express row (wallets + direct providers) and the "Or Pay with Card" line only when something shows there
        var express = root.querySelector(".cpc-express");
        var wallets = ids.wallets ? document.getElementById(ids.wallets) : null;
        var line = root.querySelector(".cpc-line");
        var walletClass = "";
        function syncLayout() {
            var walletShown = !!(wallets && !wallets.hidden && wallets.children.length);
            var directShown = !!root.querySelector(".cpc-box--direct:not([hidden])");
            if (wallets) wallets.style.display = walletShown ? "" : "none";
            if (express) {
                express.hidden = !walletShown && !directShown;
                express.classList.toggle("cpc-express--single", !(walletShown && directShown));
            }
            if (line) line.hidden = express ? express.hidden : true;

            var state = activeOf(chainUnit());
            var mounted = !!state && SHOWN.indexOf(state.status) !== -1;
            // The page's field is for gateways that use it, and only once their form has mounted (a provider
            // that is still loading, or fails to load, shows no empty field)
            if (emailWrap) emailWrap.hidden = !state || !mounted || emailMode() !== "page";
            // The active gateway's class on the wallet container (Solidgate: #gpay_btn.solid_gpay)
            if (wallets) {
                var next = state ? CP.capability(state.def, "walletClass", options[state.active.slug], state.active) || "" : "";
                if (next !== walletClass) {
                    if (walletClass) wallets.classList.remove(walletClass);
                    if (next) wallets.classList.add(next);
                    walletClass = next;
                }
            }
        }
        if (wallets && window.MutationObserver) new MutationObserver(syncLayout).observe(wallets, { childList: true, attributes: true, attributeFilter: ["hidden", "data-wallets"] });

        function succeed(result) {
            if (done) return;
            done = true;
            var data = (result && result.data) || {};
            var slug = (result && result.gateway) || data.gateway || "";
            var url = data.redirect_url || (lastStatus && lastStatus.redirect_url) || config.successUrl;
            analytics.success(slug, data);
            say(data.message || labels.success, "success");
            preloader(site, true);
            var email = data.collected_email || (EMAIL_RE.test(typedEmail()) ? typedEmail() : "");
            var next = site.collectEmail && email ? submitCollectedEmail(site, config.quizResultId, email) : Promise.resolve();
            next.then(function () {
                var event = emit(root, "success", result);
                if (!event.defaultPrevented && url) window.location.href = url;
            });
        }

        function mountBox(box, details, isDirect) {
            var gateways = CP.normalizeGateways(details);
            var el = function (name) { return box.querySelector(".cpc-" + name); };
            var host = el("host"), pay = el("pay"), actions = el("actions"), verify = el("verify");
            var payText = pay ? pay.querySelector(".cpc-pay__label") : null;
            var payLabel = payText ? payText.textContent : "";
            var previous = { status: "idle", slug: "", notice: "" };

            if (!gateways.length) {
                box.hidden = true;
                return null;
            }

            var checkout = new CP.Sequence({
                gateways: gateways,
                rest: rest,
                checkout: checkoutInput(),
                options: options,
                page: page,
                messages: messages,
                maxDeclineSwitches: isDirect ? 0 : (policy.maxDeclineSwitches == null ? 1 : policy.maxDeclineSwitches),
                switchOn: policy.switchOn,
                lastGatewayDecline: isDirect ? "retry" : policy.lastGatewayDecline,
                switchDelayMs: policy.switchDelayMs,
                verifyPayment: verifyPayment,
                onSuccess: succeed,
                onAttemptFailed: function (attempt) {
                    var stage = attempt && attempt.failure && attempt.failure.stage;
                    // Only real payment failures count for analytics (not a provider that could not load)
                    if (stage === "payment" || stage === "confirm") analytics.failed(attempt.gateway);
                    preloader(site, false);
                    emit(root, "attempt-failed", { box: isDirect ? "direct" : "chain", attempt: attempt });
                },
                onExhausted: function (attempts) {
                    var paid = attempts.some(function (a) { return a.failure && (a.failure.stage === "payment" || a.failure.stage === "confirm"); });
                    if (isDirect) {
                        // A direct provider that cannot load (no client id, not eligible) leaves quietly
                        if (!attempts.some(function (a) { return a.failure && a.failure.stage !== "load" && a.failure.stage !== "intent"; })) box.hidden = true;
                    } else {
                        say(paid ? messages.failed : messages.unavailable);
                    }
                    syncLayout();
                    emit(root, "exhausted", { box: isDirect ? "direct" : "chain", attempts: attempts });
                },
            });

            function render() {
                var state = checkout.getState();
                var slug = state.active ? state.active.slug : "";
                box.setAttribute("data-status", state.status);
                box.setAttribute("data-gateway", slug);

                var text = state.notice && state.notice.message;
                if (text && text !== previous.notice) say(text, state.notice.decision === "verify" ? "success" : "error");
                if (!text && previous.notice && state.status === "ready") say("");

                if (state.status === "processing" && previous.status !== "processing") {
                    analytics.submitted(slug);
                    // The pay button shows the short processing label; the green notice line the full
                    // "Processing your payment… Please wait." (not for a gateway that shows its own state)
                    if (state.active && CP.capability(state.def, "processingNotice", options[slug], state.active) !== false && !(state.notice && state.notice.message)) {
                        say(site.processingNotice === false ? "" : messages.processing, "success");
                    }
                }
                if (state.status === "failed" || (state.status === "ready" && previous.status === "processing")) preloader(site, false);

                pay.hidden = !(state.needsPayButton && (state.status === "ready" || state.status === "processing"));
                pay.disabled = state.status !== "ready";
                if (payText) payText.textContent = state.status === "processing" ? (labels.processing || payLabel) : payLabel;
                actions.hidden = pay.hidden;
                verify.hidden = state.status !== "verify";
                if (state.status === "failed" && isDirect) box.hidden = true;

                previous = { status: state.status, slug: slug, notice: text || "" };
                syncLocks();
            }

            pay.addEventListener("click", function () {
                if (!emailOk(true)) return;
                checkout.submit();
            });
            if (emailInput && !isDirect) {
                // Gateways created with an email keep it: give the typed one to the sequence on blur
                emailInput.addEventListener("blur", function () {
                    if (emailMode() === "page" && EMAIL_RE.test(typedEmail())) checkout.setOptions({ checkout: checkoutInput(typedEmail()) });
                });
            }
            el("retry").addEventListener("click", function () { checkout.retry(); });
            el("verify-again").addEventListener("click", function () {
                rest.status().then(function (data) {
                    lastStatus = data;
                    if (data && data.paid === true) {
                        var state = checkout.getState();
                        succeed({ gateway: state.active ? state.active.slug : null, accountId: null, data: data });
                    }
                }).catch(function () { /* the notice keeps saying "checking" */ });
            });

            checkout.subscribe(render);
            render();
            checkout.attach(host);
            box.cellonCheckout = checkout;
            return { box: box, checkout: checkout, gateways: gateways, isDirect: isDirect };
        }

        function mountAll() {
            Array.prototype.forEach.call(root.querySelectorAll("[data-cpc-box]"), function (box) {
                var isDirect = box.getAttribute("data-cpc-box") === "direct";
                var details = config.chain || [];
                if (isDirect) {
                    try { details = JSON.parse(box.getAttribute("data-cpc-details") || "[]"); } catch (e) { details = []; }
                }
                var unit = mountBox(box, details, isDirect);
                if (unit) units.push(unit);
            });
            syncLayout();
            root.cellonCheckouts = units.map(function (unit) { return unit.checkout; });
            emit(root, "mounted", { gateways: units.map(function (unit) { return unit.gateways; }) });
        }

        // ---- back from a provider redirect (3DS, Alipay): confirm it on the checkout that created it
        var resume = config.resume;
        var resumeDef = resume && resume.provider ? CP.gateway(resume.provider) : null;
        function cleanUrl() {
            var params = (resumeDef && resumeDef.resume && resumeDef.resume.params) || [];
            try {
                var url = new URL(window.location.href);
                params.forEach(function (key) { url.searchParams.delete(key); });
                window.history.replaceState({}, document.title, url.toString());
            } catch (e) { /* ignore */ }
        }
        if (resume && resume.rest && resume.status !== "failed" && resumeDef && resumeDef.resume) {
            say(messages.processing, "success");
            preloader(site, true);
            CP.restClient(resume.rest).provider(resume.provider, "confirm", resumeDef.resume.body(resume.reference)).then(function (data) {
                succeed({ gateway: resume.provider, accountId: null, data: data });
            }).catch(function (error) {
                preloader(site, false);
                analytics.failed(resume.provider);
                cleanUrl();
                say(error && error.message);
                mountAll();
            });
            return;
        }
        if (resume && resume.status === "failed") {
            cleanUrl();
            say(labels.redirect_failed || "Payment failed");
        }
        mountAll();
    }

    /* ---------------------------------------------------------------- cross sale */

    function mountCrossSale(root, config) {
        var site = config.site || {};
        var notice = root.querySelector(".cpc-notice");
        var buttons = root.querySelectorAll("button");
        var running = false;

        function setBusy(busy, status) {
            running = busy;
            root.setAttribute("data-status", status);
            Array.prototype.forEach.call(buttons, function (button) { button.disabled = busy; });
        }
        function say(text) {
            notice.hidden = !text;
            notice.textContent = text || "";
        }
        function go(url, detail) {
            var event = emit(root, "success", detail);
            if (!event.defaultPrevented && url) window.location.href = url;
        }

        function send(action) {
            if (running) return;
            setBusy(true, "processing");
            say(action === "accept" ? messages.processing : "");
            if (action === "accept") preloader(site, true);
            post(config.rest + "/cross-sale", { action: action }).then(function (data) {
                setBusy(true, "succeeded");
                go(data && data.redirect_url, { action: action, data: data });
            }).catch(function (error) {
                var status = error && error.status;
                var data = (error && error.data) || {};
                // Declined by the API: move on without the offer, like the old offer page did
                if (data.redirect_url) {
                    say(error.message);
                    go(data.redirect_url, { action: action, declined: true, data: data });
                    return;
                }
                // Unclear (network, timeout, another payment running): never resend; check the status first
                if (!status || status === 409 || status >= 500) {
                    setBusy(true, "verify");
                    say(messages.verify);
                    var check = function () {
                        post(config.rest + "/status", {}).then(function (state) {
                            if (state && state.paid === true) return go(state.redirect_url, { action: action, data: state });
                            if (state && state.paid === false) { setBusy(false, "failed"); preloader(site, false); return say(messages.pay_again); }
                            setTimeout(check, 5000);
                        }).catch(function () { setTimeout(check, 5000); });
                    };
                    setTimeout(check, 3000);
                    return;
                }
                preloader(site, false);
                setBusy(false, "failed");
                say(error && error.message);
            });
        }

        var accept = root.querySelector(".cpc-accept");
        var skip = root.querySelector(".cpc-skip");
        if (accept) accept.addEventListener("click", function () { send("accept"); });
        if (skip) skip.addEventListener("click", function () { send("skip"); });
        emit(root, "mounted", { flow: "cross-sale" });
    }

    /* ---------------------------------------------------------------------- init */

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll("[data-cpc-checkout]"), function (root) {
            if (root.getAttribute("data-cpc-mounted")) return;
            root.setAttribute("data-cpc-mounted", "1");
            var config;
            try {
                config = JSON.parse(root.getAttribute("data-cpc-checkout"));
            } catch (error) {
                if (window.console) console.error("Cellon Payment Collection: invalid data-cpc-checkout", error);
                return;
            }
            if (config.flow === "cross-sale") mountCrossSale(root, config);
            else mountFirstSale(root, config);
        });
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
    else init();
    window.cellonPaymentCollectionMount = init; // checkouts inserted later (AJAX-loaded steps)
})();
