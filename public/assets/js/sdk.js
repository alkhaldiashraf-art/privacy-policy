(function () {
    'use strict';

    var scriptEl = document.currentScript;
    if (!scriptEl) {
        return;
    }

    var publicKey = scriptEl.getAttribute('data-key');
    if (!publicKey) {
        return;
    }

    var collectUrl;
    try {
        collectUrl = new URL('/api/collect', scriptEl.src).toString();
    } catch (e) {
        return;
    }

    var STORAGE_KEY = 'siugoals_session_uid';
    var HEARTBEAT_MS = 30000;
    var FLUSH_MS = 5000;

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function getSessionUid() {
        try {
            var existing = window.localStorage.getItem(STORAGE_KEY);
            if (existing) {
                return existing;
            }
            var fresh = uuid();
            window.localStorage.setItem(STORAGE_KEY, fresh);
            return fresh;
        } catch (e) {
            if (!getSessionUid._fallback) {
                getSessionUid._fallback = uuid();
            }
            return getSessionUid._fallback;
        }
    }

    function detectOs(ua) {
        if (/Windows/.test(ua)) return 'Windows';
        if (/Mac OS X/.test(ua)) return 'Mac OS X';
        if (/Android/.test(ua)) return 'Android';
        if (/iPhone|iPad|iOS/.test(ua)) return 'iOS';
        if (/Linux/.test(ua)) return 'Linux';
        return 'Unknown';
    }

    function detectBrowser(ua) {
        if (/HeadlessChrome/.test(ua)) return 'HeadlessChrome';
        if (/Edg\//.test(ua)) return 'Edge';
        if (/Chrome\//.test(ua)) return 'Chrome';
        if (/Firefox\//.test(ua)) return 'Firefox';
        if (/Safari\//.test(ua) && !/Chrome/.test(ua)) return 'Safari';
        return 'Unknown';
    }

    var sessionUid = getSessionUid();
    var ua = navigator.userAgent || '';
    var identity = null;
    var queue = [];
    var flushTimer = null;

    function nowIso() {
        return new Date().toISOString();
    }

    function scheduleFlush() {
        if (flushTimer) {
            return;
        }
        flushTimer = window.setTimeout(function () {
            flushTimer = null;
            flush(false);
        }, FLUSH_MS);
    }

    function buildPayload(events) {
        return {
            key: publicKey,
            session_uid: sessionUid,
            identity: identity,
            os: detectOs(ua),
            browser: detectBrowser(ua),
            headless: !!navigator.webdriver || /HeadlessChrome/.test(ua),
            events: events,
        };
    }

    function flush(useBeacon) {
        if (queue.length === 0) {
            return;
        }
        var events = queue.splice(0, queue.length);
        var payload = JSON.stringify(buildPayload(events));

        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon(collectUrl, new Blob([payload], { type: 'application/json' }));
            return;
        }

        fetch(collectUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: payload,
            keepalive: true,
            mode: 'cors',
        }).catch(function () { /* best-effort telemetry: drop on failure */ });
    }

    function push(event) {
        event.occurred_at = event.occurred_at || nowIso();
        queue.push(event);
        scheduleFlush();
    }

    window.siugoals = window.siugoals || {};
    window.siugoals.track = function (name, data) {
        if (typeof name !== 'string' || !name) {
            return;
        }
        push({ type: 'signal', name: name, message: data ? JSON.stringify(data).slice(0, 4000) : null });
    };
    window.siugoals.identify = function (id) {
        if (typeof id === 'string' && id) {
            identity = id.slice(0, 255);
            push({ type: 'signal', name: 'identify' });
        }
    };

    push({ type: 'pageview', name: location.pathname });

    window.addEventListener('error', function (e) {
        push({
            type: 'error',
            name: (e.error && e.error.name) || 'Error',
            message: e.message || String(e.error),
            stack: e.error && e.error.stack ? String(e.error.stack).slice(0, 4000) : null,
        });
    });

    window.addEventListener('unhandledrejection', function (e) {
        var reason = e.reason;
        push({
            type: 'error',
            name: 'UnhandledPromiseRejection',
            message: reason && reason.message ? reason.message : String(reason),
            stack: reason && reason.stack ? String(reason.stack).slice(0, 4000) : null,
        });
    });

    window.setInterval(function () {
        if (document.visibilityState === 'visible') {
            push({ type: 'heartbeat' });
        }
    }, HEARTBEAT_MS);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush(true);
        }
    });
    window.addEventListener('pagehide', function () {
        flush(true);
    });
})();
