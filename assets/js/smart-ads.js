/**
 * PinLightning Smart Ad Engine — Phase 1
 *
 * 1. Smart Injector (scroll-speed aware, IntersectionObserver)
 * 2. Viewability Tracker (per-zone IAB measurement)
 * 3. Data Recorder (sends session data to server via sendBeacon)
 */
(function() {
    'use strict';

    // ========== CONFIG ==========
    var C = {
        dummy: true,
        debug: false,
        record: true,
        maxAds: 6,
        viewThreshold: 0.5,
        viewTimeMs: 1000,
        maxScrollSpeed: 800,
        minReadMs: 1500,
        recordEndpoint: '',
        nonce: '',
        postId: 0,
        postSlug: ''
    };
    if (window.plAds) {
        for (var k in window.plAds) {
            if (window.plAds.hasOwnProperty(k)) C[k] = window.plAds[k];
        }
    }

    // ========== STATE ==========
    var S = {
        speed: 0,
        speeds: [],          // rolling window for avg calculation
        dir: 'down',
        lastY: 0,
        lastT: 0,
        reading: true,
        depth: 0,
        loadT: Date.now(),
        injected: 0,
        zones: {},
        dbgEl: null
    };

    // ========== DUMMY AD SPECS ==========
    var SPEC = {
        '300x250': { w:300, h:250, l:'300\u00d7250', bg:'#f0f4ff', bc:'#4a90d9' },
        '970x250': { w:970, h:250, l:'970\u00d7250', bg:'#fff8f0', bc:'#d98a4a' },
        '728x90':  { w:728, h:90,  l:'728\u00d790',  bg:'#fff4f0', bc:'#d94a4a' },
        '320x100': { w:320, h:100, l:'320\u00d7100', bg:'#f0fff4', bc:'#4ad94a' },
        '300x600': { w:300, h:600, l:'300\u00d7600', bg:'#fff0f8', bc:'#d94a90' },
        '336x280': { w:336, h:280, l:'336\u00d7280', bg:'#f4f0ff', bc:'#6a4ad9' }
    };

    // ========== 1. SCROLL TRACKER ==========
    function trackScroll() {
        var timer = null;
        window.addEventListener('scroll', function() {
            var now = Date.now();
            var y = window.scrollY;
            var dt = now - S.lastT;
            if (dt > 0 && dt < 500) {
                var spd = (Math.abs(y - S.lastY) / dt) * 1000;
                S.speed = spd;
                S.dir = y > S.lastY ? 'down' : 'up';
                S.reading = spd < C.maxScrollSpeed;
                // Rolling avg (keep last 20)
                S.speeds.push(spd);
                if (S.speeds.length > 20) S.speeds.shift();
            }
            var dh = document.documentElement.scrollHeight - window.innerHeight;
            if (dh > 0) S.depth = Math.min(100, (y / dh) * 100);
            S.lastY = y;
            S.lastT = now;
            if (timer) clearTimeout(timer);
            timer = setTimeout(function() { S.speed = 0; S.reading = true; }, 200);
        }, { passive: true });
    }

    function getAvgSpeed() {
        if (!S.speeds.length) return 0;
        var sum = 0;
        for (var i = 0; i < S.speeds.length; i++) sum += S.speeds[i];
        return sum / S.speeds.length;
    }

    function getScrollPattern() {
        var avg = getAvgSpeed();
        var timeOnPage = Date.now() - S.loadT;
        if (timeOnPage < 10000 && S.depth < 30) return 'bouncer';
        if (avg > 600) return 'scanner';
        return 'reader';
    }

    // ========== 2. SMART INJECTOR ==========
    function initInjector() {
        var zones = document.querySelectorAll('.ad-zone[data-injected="false"]');
        if (!zones.length) return;

        var obs = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (!entry.isIntersecting) return;
                var el = entry.target;
                if (el.getAttribute('data-injected') === 'true') return;
                if ((Date.now() - S.loadT) < C.minReadMs) return;
                if (!S.reading && S.speed > 0) return;
                if (S.injected >= C.maxAds) return;
                inject(el);
                obs.unobserve(el);
            });
        }, { rootMargin: '400px 0px', threshold: [0.1] });

        zones.forEach(function(z) { obs.observe(z); });

        // Re-check for zones missed due to fast scrolling
        setInterval(function() {
            if (!S.reading) return;
            var pending = document.querySelectorAll('.ad-zone[data-injected="false"]');
            pending.forEach(function(z) {
                var r = z.getBoundingClientRect();
                if (r.top < window.innerHeight + 400 && r.bottom > -200 &&
                    S.injected < C.maxAds && (Date.now() - S.loadT) >= C.minReadMs) {
                    inject(z);
                    obs.unobserve(z);
                }
            });
        }, 800);
    }

    function inject(el) {
        var zid = el.getAttribute('data-zone-id');
        var mobile = window.innerWidth < 768;
        var size = mobile
            ? el.getAttribute('data-size-mobile')
            : el.getAttribute('data-size-desktop');
        if (!size) size = '300x250';

        el.setAttribute('data-injected', 'true');
        el.setAttribute('data-ad-size', size);
        S.injected++;

        if (C.dummy) {
            renderDummy(el, zid, size);
        }
        // Real ads: Phase 2

        // Smooth expand (zero CLS)
        el.style.overflow = 'hidden';
        el.style.maxHeight = '0';
        el.style.transition = 'max-height 0.4s ease, margin 0.3s ease';
        requestAnimationFrame(function() {
            var sp = SPEC[size] || SPEC['300x250'];
            el.style.maxHeight = (sp.h + 24) + 'px';
            el.style.margin = '1.5rem 0';
        });
        el.classList.add('ad-zone-active');

        // Start viewability tracking
        trackViewability(el, zid, size);
    }

    function renderDummy(el, zid, size) {
        var sp = SPEC[size] || SPEC['300x250'];
        var mobile = window.innerWidth < 768;
        var w = mobile ? Math.min(sp.w, window.innerWidth - 32) : sp.w;
        var h = w < sp.w ? Math.round((w / sp.w) * sp.h) : sp.h;

        el.innerHTML =
            '<div style="width:' + w + 'px;height:' + h + 'px;' +
            'background:' + sp.bg + ';border:2px dashed ' + sp.bc + ';' +
            'display:flex;align-items:center;justify-content:center;flex-direction:column;gap:4px;' +
            'margin:0 auto;border-radius:8px;font-family:system-ui,sans-serif;position:relative;">' +
            '<div style="font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:1px;">Advertisement</div>' +
            '<div style="font-weight:700;color:' + sp.bc + ';font-size:14px;">' + sp.l + '</div>' +
            '<div style="font-size:10px;color:#888;">' + zid + '</div>' +
            '<div id="vb-' + zid + '" style="position:absolute;bottom:4px;right:4px;' +
            'padding:2px 6px;border-radius:4px;font-size:10px;background:#eee;color:#666;">0.0s</div>' +
            '</div>';
    }

    // ========== 3. VIEWABILITY TRACKER ==========
    function trackViewability(el, zid, size) {
        S.zones[zid] = {
            el: el,
            size: size,
            visible: false,
            since: null,
            totalMs: 0,
            sessMs: 0,
            impressions: 0,
            ratio: 0,
            maxRatio: 0,
            ratioSamples: [],
            firstViewT: null,
            injectedAtDepth: S.depth,
            injectedAtSpeed: S.speed
        };

        var vo = new IntersectionObserver(function(entries) {
            var e = entries[0];
            var z = S.zones[zid];
            z.ratio = e.intersectionRatio;
            if (e.intersectionRatio > z.maxRatio) z.maxRatio = e.intersectionRatio;
            z.ratioSamples.push(e.intersectionRatio);
            if (z.ratioSamples.length > 100) z.ratioSamples.shift();

            if (e.intersectionRatio >= C.viewThreshold) {
                if (!z.visible) {
                    z.visible = true;
                    z.since = Date.now();
                    if (!z.firstViewT) z.firstViewT = Date.now() - S.loadT;
                }
            } else {
                if (z.visible && z.since) {
                    var dur = Date.now() - z.since;
                    z.totalMs += dur;
                    if (dur >= C.viewTimeMs) z.impressions++;
                    z.visible = false;
                    z.since = null;
                }
            }
        }, { threshold: [0, 0.1, 0.25, 0.5, 0.75, 1.0] });

        vo.observe(el);
    }

    function tickViewability() {
        var ids = Object.keys(S.zones);
        for (var i = 0; i < ids.length; i++) {
            var z = S.zones[ids[i]];
            z.sessMs = (z.visible && z.since) ? (Date.now() - z.since) : 0;
            var total = z.totalMs + z.sessMs;

            // Update dummy badge
            if (C.dummy) {
                var badge = document.getElementById('vb-' + ids[i]);
                if (badge) {
                    badge.textContent = (total / 1000).toFixed(1) + 's';
                    badge.style.background = total >= C.viewTimeMs ? '#4caf50' : (z.visible ? '#ff9800' : '#eee');
                    badge.style.color = total >= C.viewTimeMs ? '#fff' : (z.visible ? '#fff' : '#666');
                }
            }
        }
    }

    // ========== 4. DATA RECORDER ==========
    function sendData() {
        if (!C.record || !C.recordEndpoint) return;

        var ids = Object.keys(S.zones);
        var totalViewable = 0;
        var zonesData = [];

        for (var i = 0; i < ids.length; i++) {
            var z = S.zones[ids[i]];
            var totalMs = z.totalMs + z.sessMs;
            if (totalMs >= C.viewTimeMs) totalViewable++;

            var avgRatio = 0;
            if (z.ratioSamples.length) {
                var sum = 0;
                for (var j = 0; j < z.ratioSamples.length; j++) sum += z.ratioSamples[j];
                avgRatio = Math.round((sum / z.ratioSamples.length) * 100) / 100;
            }

            zonesData.push({
                zoneId: ids[i],
                adSize: z.size,
                totalVisibleMs: Math.round(z.totalMs + z.sessMs),
                viewableImpressions: z.impressions + (z.sessMs >= C.viewTimeMs ? 1 : 0),
                maxRatio: Math.round(z.maxRatio * 100) / 100,
                avgRatio: avgRatio,
                timeToFirstView: z.firstViewT ? Math.round(z.firstViewT) : -1,
                injectedAtDepth: Math.round(z.injectedAtDepth * 10) / 10,
                scrollSpeedAtInjection: Math.round(z.injectedAtSpeed)
            });
        }

        var payload = {
            session: true,
            postId: C.postId,
            postSlug: C.postSlug,
            device: window.innerWidth < 768 ? 'mobile' : (window.innerWidth < 1024 ? 'tablet' : 'desktop'),
            viewportW: window.innerWidth,
            viewportH: window.innerHeight,
            timeOnPage: Date.now() - S.loadT,
            maxDepth: Math.round(S.depth * 10) / 10,
            avgScrollSpeed: Math.round(getAvgSpeed()),
            scrollPattern: getScrollPattern(),
            totalAdsInjected: S.injected,
            totalViewable: totalViewable,
            viewabilityRate: S.injected > 0 ? Math.round((totalViewable / S.injected) * 100) : 0,
            zones: zonesData
        };

        // Use sendBeacon (non-blocking, survives page unload)
        if (navigator.sendBeacon) {
            var blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            navigator.sendBeacon(C.recordEndpoint, blob);
        } else {
            // Fallback: sync XHR on unload
            var xhr = new XMLHttpRequest();
            xhr.open('POST', C.recordEndpoint, false);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(JSON.stringify(payload));
        }
    }

    // ========== 5. DEBUG OVERLAY (optional) ==========
    function initDebug() {
        if (!C.debug) return;
        var el = document.createElement('div');
        el.id = 'pl-ad-dbg';
        el.style.cssText =
            'position:fixed;bottom:60px;left:8px;z-index:9999;background:rgba(0,0,0,.88);' +
            'color:#0f0;font:10px/1.5 monospace;padding:8px 10px;border-radius:8px;' +
            'max-width:260px;pointer-events:none;backdrop-filter:blur(4px);';
        document.body.appendChild(el);
        S.dbgEl = el;
    }

    function tickDebug() {
        if (!S.dbgEl) return;
        var ids = Object.keys(S.zones);
        var tv = 0, ta = ids.length;
        for (var i = 0; i < ids.length; i++) {
            var z = S.zones[ids[i]];
            if ((z.totalMs + z.sessMs) >= C.viewTimeMs) tv++;
        }
        var lines = [
            '<b style="color:#ff0">\u26a1 PinLightning Ads</b>',
            '\ud83d\udd04 ' + Math.round(S.speed) + 'px/s ' + (S.reading ? '\ud83d\udcd6' : '\ud83d\udca8') + ' ' + S.dir,
            '\ud83d\udcca Depth:' + Math.round(S.depth) + '% Ads:' + S.injected + '/' + C.maxAds,
            '\ud83d\udc41 Viewability: ' + (ta > 0 ? Math.round(tv / ta * 100) : 0) + '% (' + tv + '/' + ta + ')',
            '\ud83e\udde0 Pattern: ' + getScrollPattern(),
            '\u2014'
        ];
        for (var i = 0; i < ids.length; i++) {
            var z = S.zones[ids[i]];
            var t = z.totalMs + z.sessMs;
            lines.push(
                (z.visible ? '\ud83d\udfe2' : '\u26aa') + ' ' + ids[i] + ': ' +
                (t / 1000).toFixed(1) + 's ' + Math.round(z.ratio * 100) + '% \u2713' + z.impressions
            );
        }
        S.dbgEl.innerHTML = lines.join('<br>');
    }

    // ========== MAIN ==========
    function tick() {
        tickViewability();
        if (C.debug) tickDebug();
    }

    function init() {
        trackScroll();
        initInjector();
        if (C.debug) initDebug();
        setInterval(tick, 250);

        // Record data on page unload
        if (C.record) {
            // Send data when user leaves
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'hidden') sendData();
            });
            // Backup: also send on unload
            window.addEventListener('pagehide', sendData);
            // Also send periodically every 30 seconds (in case user stays long)
            setInterval(sendData, 30000);
        }
    }

    // PinLightning performance rules
    if (document.body.classList.contains('single')) {
        if ('requestIdleCallback' in window) {
            requestIdleCallback(init);
        } else {
            setTimeout(init, 200);
        }
    }
})();
