/* Cellon Payment Collection: provider sequence (CellonPC.Sequence).
 *
 * Mounts the providers of one box one at a time, lowest order first, and moves to the next one in the
 * same place (no page reload) when the failover policy says so:
 *   - a provider that fails to load (SDK, key, intent / API error) is skipped silently and does not count
 *     as a switch;
 *   - a declined payment moves to the next provider at most `maxDeclineSwitches` times (default 1); after
 *     that the customer stays on the current provider (`lastGatewayDecline` "retry") or the box ends ("fail");
 *   - an unclear outcome asks the server (`verifyPayment`) before anything else.
 *
 * The sequence knows no gateway by name: each gateway's behaviour comes from its registered definition
 * (CellonPC.registerGateway). Plain ES2017.
 *
 * State (getState()): status idle | loading | ready | processing | verify | succeeded | failed,
 * gateways, activeIndex, active, def (definition of the active gateway), needsPayButton, attempts,
 * notice { decision, failure, message }, result, canSwitch.
 */
(function (window, document) {
    "use strict";

    var CP = window.CellonPC;
    var BUSY = ["processing", "verify", "succeeded"];

    function assign(target) {
        for (var i = 1; i < arguments.length; i++) {
            var source = arguments[i] || {};
            Object.keys(source).forEach(function (key) { target[key] = source[key]; });
        }
        return target;
    }

    /**
     * @param {object} opts
     *   gateways            normalized gateways (CellonPC.normalizeGateways)
     *   rest                CellonPC.restClient(…) of the checkout
     *   checkout            { amount, currency, customerEmail }
     *   options             { <slug>: options for that gateway }
     *   page                { email(), reportEmail(address) } handed to every gateway
     *   messages            customer texts per decision
     *   maxDeclineSwitches  default 1
     *   switchOn            soft | fixable | all (default soft): which declines move on
     *   lastGatewayDecline  retry (default) | fail
     *   switchDelayMs       pause with the notice before the next provider mounts (default 0)
     *   readyTimeoutMs      a provider not ready by then failed to load (default 20000)
     *   emailRemountDelayMs (default 400)
     *   verifyPayment(attempt) → Promise<true | false | null>
     *   onSuccess(result), onAttemptFailed(attempt), onExhausted(attempts)
     */
    function Sequence(opts) {
        this.opts = opts;
        this.listeners = [];
        this.container = null;
        this.host = null;
        this.mounted = null;
        this.mountId = 0;
        this.readyTimer = null;
        this.emailTimer = null;
        this.declineSwitches = 0;
        this.ended = false;
        this.mountedEmail = undefined;
        this.accountReloads = {};
        this.state = this.derive({
            status: "idle",
            gateways: opts.gateways || [],
            activeIndex: 0,
            attempts: [],
            notice: null,
            result: null,
            canSwitch: true,
        });
    }

    Sequence.prototype.getState = function () {
        return this.state;
    };

    Sequence.prototype.subscribe = function (listener) {
        var listeners = this.listeners;
        listeners.push(listener);
        return function () {
            var index = listeners.indexOf(listener);
            if (index !== -1) listeners.splice(index, 1);
        };
    };

    Sequence.prototype.optionsFor = function (gateway) {
        var all = this.opts.options || {};
        return (gateway && all[gateway.slug]) || {};
    };

    Sequence.prototype.derive = function (state) {
        var active = state.gateways[state.activeIndex] || null;
        var def = active ? CP.gateway(active.slug) : null;
        var ownButton = def ? CP.capability(def, "ownButton", this.optionsFor(active), active) : false;
        return assign({}, state, { active: active, def: def, needsPayButton: !!def && !ownButton });
    };

    Sequence.prototype.set = function (patch) {
        this.state = this.derive(assign({}, this.state, patch));
        this.listeners.slice().forEach(function (listener) { listener(); });
    };

    /** Latest checkout data / callbacks; read at the next mount or event. */
    Sequence.prototype.setOptions = function (options) {
        this.opts = assign({}, this.opts, options);
        if (options.checkout) this.scheduleEmailRemount();
    };

    Sequence.prototype.capability = function (name) {
        var active = this.state.active;
        return active ? CP.capability(this.state.def, name, this.optionsFor(active), active) : undefined;
    };

    /** The mounted gateway keeps the email of its intent and the page's address changed since. */
    Sequence.prototype.emailStale = function () {
        if (!this.capability("emailAtIntent")) return false;
        var now = (this.opts.checkout && this.opts.checkout.customerEmail) || undefined;
        return now !== (this.mountedEmail || undefined);
    };

    Sequence.prototype.scheduleEmailRemount = function () {
        var self = this;
        clearTimeout(this.emailTimer);
        if (this.ended || !this.container || !this.emailStale()) return;
        if (this.state.status !== "ready" && this.state.status !== "loading") return;
        this.emailTimer = setTimeout(function () {
            if (self.ended || !self.emailStale()) return;
            if (self.state.status !== "ready" && self.state.status !== "loading") return;
            self.mount(self.state.activeIndex);
        }, this.opts.emailRemountDelayMs == null ? 400 : this.opts.emailRemountDelayMs);
    };

    Sequence.prototype.attach = function (container) {
        this.container = container;
        if (this.state.status !== "succeeded" && !this.ended) this.mount(this.state.activeIndex);
    };

    Sequence.prototype.detach = function () {
        this.mountId++;
        this.unmount();
        this.container = null;
    };

    Sequence.prototype.submit = function () {
        var self = this;
        if (this.state.status !== "ready" || !this.mounted || !this.mounted.submit) return Promise.resolve();
        // The address changed after this intent: pay on a new one with it, never with the old address
        if (this.emailStale()) {
            clearTimeout(this.emailTimer);
            return this.mount(this.state.activeIndex);
        }
        this.set({ notice: null });
        return Promise.resolve().then(function () { return self.mounted.submit(); });
    };

    /** Mount the current gateway again with a new intent (after "verify" said not paid, or a stuck form). */
    Sequence.prototype.retry = function () {
        if (this.state.status === "succeeded" || this.state.status === "processing") return;
        this.ended = false;
        this.mount(this.state.activeIndex);
    };

    Sequence.prototype.unmount = function () {
        clearTimeout(this.readyTimer);
        clearTimeout(this.emailTimer);
        try {
            if (this.mounted) this.mounted.destroy();
        } catch (e) {
            // a provider SDK that throws on destroy must not block the next gateway
        }
        this.mounted = null;
        if (this.host) this.host.remove();
        this.host = null;
    };

    Sequence.prototype.mount = function (index, notice) {
        var self = this;
        if (notice === undefined) notice = this.state.notice;
        this.unmount();
        var id = ++this.mountId;
        var gateway = this.state.gateways[index];

        if (!gateway) {
            this.set({ status: "failed", activeIndex: Math.max(0, Math.min(index, this.state.gateways.length - 1)), notice: notice });
            if (this.opts.onExhausted) this.opts.onExhausted(this.state.attempts);
            return Promise.resolve();
        }
        if (!this.container) {
            this.set({ status: "idle", activeIndex: index, notice: notice });
            return Promise.resolve();
        }
        this.set({ status: "loading", activeIndex: index, notice: notice });

        var def = CP.gateway(gateway.slug);
        if (!def) {
            return this.fail(id, { gateway: gateway.slug, stage: "load", code: "no_gateway", message: "No gateway registered for " + gateway.slug });
        }
        var checkout = assign({}, this.opts.checkout);
        var emailAtMount = checkout.customerEmail || undefined;
        this.mountedEmail = emailAtMount;
        var emailAtIntent = CP.capability(def, "emailAtIntent", this.optionsFor(gateway), gateway);

        // Each mount gets its own element: a late SDK callback can never write into the next gateway
        var host = document.createElement("div");
        host.id = "cellon-pay-" + gateway.slug + "-" + id;
        this.container.appendChild(host);
        this.host = host;

        this.readyTimer = setTimeout(function () {
            self.fail(id, { gateway: gateway.slug, stage: "load", code: "ready_timeout", message: gateway.slug + " was not ready in time" });
        }, this.opts.readyTimeoutMs || 20000);

        var live = function () { return id === self.mountId; };
        var events = {
            ready: function () {
                if (!live()) return;
                clearTimeout(self.readyTimer);
                if (self.state.status === "loading") self.set({ status: "ready" });
            },
            processing: function () {
                if (live()) self.set({ status: "processing", notice: null });
            },
            succeeded: function (paid) {
                if (!live()) return;
                clearTimeout(self.readyTimer);
                var result = paid;
                // The address the provider stored with this intent
                if (emailAtIntent && emailAtMount && !paid.customerEmail) result = assign({}, paid, { customerEmail: emailAtMount });
                self.set({ status: "succeeded", result: result, notice: null });
                if (self.opts.onSuccess) self.opts.onSuccess(result);
            },
            failed: function (failure) {
                self.fail(id, assign({ gateway: gateway.slug }, failure));
            },
            cancelled: function () {
                if (live()) self.set({ status: "ready" });
            },
            accountChanged: function (next) {
                if (live()) self.reloadAccount(id, index, next);
            },
        };

        var ctx = {
            gateway: gateway,
            host: host,
            options: this.optionsFor(gateway),
            checkout: checkout,
            api: this.opts.rest.forGateway(gateway, checkout),
            events: events,
            page: this.opts.page || { email: function () { return ""; }, reportEmail: function () {} },
        };

        return Promise.resolve()
            .then(function () { return def.mount(ctx); })
            .then(function (mounted) {
                if (!live()) {
                    // replaced while loading (failover, detach)
                    try { if (mounted) mounted.destroy(); } catch (e) { /* ignore */ }
                    return;
                }
                self.mounted = mounted || { destroy: function () {} };
            })
            .catch(function (error) {
                return self.fail(id, CP.toFailure(gateway.slug, error, "load"));
            });
    };

    Sequence.prototype.fail = function (id, failure) {
        var self = this;
        if (id !== this.mountId || this.state.status === "succeeded") return Promise.resolve();
        clearTimeout(this.readyTimer);

        var gateways = this.state.gateways;
        var activeIndex = this.state.activeIndex;
        var gateway = gateways[activeIndex];
        var decision = CP.decide(failure);
        if (failure.stage === "payment") {
            var switchOn = this.opts.switchOn || "soft";
            if ((decision === "retry_same" && switchOn !== "soft") || (decision === "stop" && switchOn === "all")) decision = "next_gateway";
        }

        // Another gateway may still be tried (switch budget left, no hard decline before)
        var maxSwitches = this.opts.maxDeclineSwitches == null ? 1 : this.opts.maxDeclineSwitches;
        var canMoveOn = this.state.canSwitch && activeIndex + 1 < gateways.length && this.declineSwitches < maxSwitches;
        if (decision === "next_gateway" && failure.stage === "payment") {
            if (canMoveOn) this.declineSwitches++;
            else decision = "retry_same";
        }
        // Nothing left to try: the box ends instead of mounting the same gateway again
        var exhausted = this.opts.lastGatewayDecline === "fail" && failure.stage === "payment" &&
            (decision === "stop" || (decision === "retry_same" && !canMoveOn));
        if (exhausted) decision = "stop";

        var attempt = {
            gateway: failure.gateway,
            accountId: gateway ? gateway.accountId : null,
            order: gateway ? gateway.order : activeIndex + 1,
            failure: failure,
            decision: decision,
            at: Date.now(),
        };
        var attempts = this.state.attempts.concat([attempt]);
        if (this.opts.onAttemptFailed) this.opts.onAttemptFailed(attempt);
        var messages = this.opts.messages || {};
        var notice = function (kind) {
            return { decision: kind, failure: failure, message: messages[kind] || CP.defaultMessages[kind] || "" };
        };

        if (exhausted) {
            this.ended = true;
            this.unmount();
            this.set({ attempts: attempts, status: "failed", canSwitch: false, notice: notice("stop") });
            if (this.opts.onExhausted) this.opts.onExhausted(attempts);
            return Promise.resolve();
        }

        if (decision === "next_gateway") {
            // Before the customer typed anything (SDK / key / intent problem) the switch is silent
            var silent = failure.stage === "load" || failure.stage === "intent";
            var delay = silent ? 0 : this.opts.switchDelayMs || 0;
            var next = function () {
                if (id !== self.mountId) return; // detached, retried or replaced meanwhile
                return self.mount(activeIndex + 1, silent ? self.state.notice : notice("next_gateway"));
            };
            if (delay > 0) {
                this.set({ attempts: attempts, status: "processing", notice: notice("next_gateway") });
                return CP.wait(delay).then(next);
            }
            this.set({ attempts: attempts });
            return next() || Promise.resolve();
        }
        if (decision === "stop") {
            this.set({ attempts: attempts, canSwitch: false });
            return this.stay(id, notice("stop"));
        }
        if (decision === "retry_same") {
            this.set({ attempts: attempts });
            return this.stay(id, notice("retry_same"));
        }

        // verify
        this.set({ attempts: attempts, status: "verify", notice: notice("verify") });
        if (!this.opts.verifyPayment) return Promise.resolve();
        return this.opts.verifyPayment(attempt).catch(function () { return null; }).then(function (paid) {
            if (id !== self.mountId) return;
            if (paid === true) {
                var result = { gateway: failure.gateway, accountId: attempt.accountId, data: null };
                self.set({ status: "succeeded", result: result, notice: null });
                if (self.opts.onSuccess) self.opts.onSuccess(result);
            } else if (paid === false) {
                // Not paid: try again on the same gateway, never silently on another one
                return self.mount(activeIndex, notice("retry_same"));
            }
        });
    };

    Sequence.prototype.stay = function (id, notice) {
        if (this.capability("remountOnRetry") || !this.mounted || id !== this.mountId) {
            return this.mount(this.state.activeIndex, notice);
        }
        this.set({ status: "ready", notice: notice });
        return Promise.resolve();
    };

    /** The API used another account than the loaded SDK: reload the same gateway once with the new key. */
    Sequence.prototype.reloadAccount = function (id, index, next) {
        if (this.accountReloads[index]) {
            this.fail(id, { gateway: next.slug, stage: "load", code: "account_changed_again", message: "Payment account changed twice" });
            return;
        }
        this.accountReloads[index] = true;
        this.set({ gateways: this.state.gateways.map(function (gateway, i) { return i === index ? next : gateway; }) });
        this.mount(index);
    };

    Sequence.BUSY = BUSY;
    CP.Sequence = Sequence;
})(window, document);
