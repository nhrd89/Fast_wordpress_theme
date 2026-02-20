/**
 * PinLightning Smart Ad Engine — Phase 3
 *
 * Intelligent ad placement engine for Ad.Plus via Google Publisher Tags (GPT).
 * 12 modules: Config, Engagement Gate, GPT Loader, Zone Discovery,
 * Zone Activation, Display Renderer, Interstitial, Anchor, Pause Banner,
 * Viewability Tracker, Data Recorder, Debug Overlay.
 *
 * Zero TBT: IntersectionObserver + requestIdleCallback + passive listeners.
 *
 * @package PinLightning
 * @since   1.0.0
 */
;(function() {
'use strict';

/* ================================================================
 * MODULE 1: CONFIG
 * ================================================================ */

var cfg = window.plAds || {};

// wp_localize_script converts ALL values to strings. Parse numerics now.
cfg.maxAds = parseInt(cfg.maxAds, 10) || 4;
cfg.gateScrollPct = parseInt(cfg.gateScrollPct, 10) || 15;
cfg.gateTimeSec = parseInt(cfg.gateTimeSec, 10) || 5;
cfg.gateDirChanges = parseInt(cfg.gateDirChanges, 10) || 0;
cfg.minSpacingPx = parseInt(cfg.minSpacingPx, 10) || 800;
cfg.pauseMinAds = parseInt(cfg.pauseMinAds, 10) || 2;

// Bail if not configured (ad engine disabled or missing config).
if (!cfg.networkCode && !cfg.dummy) return;

// Bot detection — exit before loading any ad code.
if (navigator.webdriver) return;
if (/bot|crawl|spider|slurp|googlebot|bingbot|baiduspider|yandexbot|duckduckbot|sogou|exabot|ia_archiver|facebot|facebookexternalhit|ahrefsbot|semrushbot|mj12bot|dotbot|petalbot|applebot|dataforseobot|bytespider|gptbot|claudebot|ccbot|amazonbot|anthropic|headlesschrome|phantomjs|slimerjs|lighthouse|pagespeed|pingdom|uptimerobot|wget|curl|python-requests|go-http-client|java\/|libwww/i.test(navigator.userAgent)) return;
if (window.innerWidth === 0 && window.innerHeight === 0) return;

var isMobile = window.innerWidth <= 768;
var isDesktop = !isMobile;

// Device gate — exit early if ads disabled for this device.
if (isMobile && !cfg.mobileEnabled) return;
if (isDesktop && !cfg.desktopEnabled) return;

var state = {
	gateOpen: false,
	gptLoaded: false,
	gptReady: false,
	activeAds: 0,
	viewableCount: 0,       // zones that got at least 1 viewable impression
	resolvedCount: 0,       // zones fully resolved (viewable OR confirmed missed)
	budgetExhausted: false,  // true when viewability rate drops below 40% after 2+ resolved
	pendingRetries: 0,      // zones missed — will retry at next opportunity
	totalRetries: 0,        // total retry activations used this session
	retriesSuccessful: 0,   // retries that became viewable
	maxRetriesPerSession: 4,
	zones: [],
	slots: {},
	viewability: {},
	scrollPct: 0,
	timeOnPage: 0,
	dirChanges: 0,
	lastScrollY: 0,
	lastScrollDir: 0,
	scrollSpeed: 0,
	pauseTimer: null,
	sessionStart: Date.now(),
	dataSent: false
};

/* ================================================================
 * MODULE 2: ENGAGEMENT GATE
 *
 * Three signals must all be met before any ads load:
 * 1. Scroll depth >= threshold (proves user is reading)
 * 2. Time on page >= threshold (proves engagement)
 * 3. Direction changes >= threshold (proves active scrolling)
 * ================================================================ */

var gateChecks = {
	scroll: false,
	time: false,
	direction: false
};

function checkGate() {
	if (state.gateOpen) return true;

	if (gateChecks.scroll && gateChecks.time && gateChecks.direction) {
		state.gateOpen = true;
		if (cfg.debug) console.log('[PL-Ads] Gate OPEN — scroll:' + Math.round(state.scrollPct) + '% time:' + Math.round(state.timeOnPage) + 's dirs:' + state.dirChanges);
		onGateOpen();
		showAnchor(); // Anchor is #1 revenue — fire immediately on gate open.
		return true;
	}
	return false;
}

// Read from engagement.js bridge when available (richer data),
// fall back to own tracking on non-listicle pages.
function readBridge() {
	var b = window.__plEngagement;
	if (b) {
		state.scrollPct = b.scrollDepth;
		state.dirChanges = b.directionChanges;
		state.scrollSpeed = b.scrollSpeed;
		// ALWAYS compute own timeOnPage — the bridge only updates on scroll
		// events, so it stays at ~0 for non-scrolling visitors. This caused
		// the time gate to never fire for 0%-scroll sessions (BUG 3).
		state.timeOnPage = (Date.now() - state.sessionStart) / 1000;
		return true;
	}
	return false;
}

// Scroll listener — throttled at 200ms.
var scrollTimer = null;

function onScroll() {
	if (scrollTimer) return;
	scrollTimer = setTimeout(function() {
		scrollTimer = null;

		// Prefer engagement.js bridge data when available.
		if (!readBridge()) {
			// Fallback: own tracking for non-listicle pages.
			var y = window.scrollY || window.pageYOffset;
			var docHeight = document.documentElement.scrollHeight - window.innerHeight;
			var pct = docHeight > 0 ? (y / docHeight) * 100 : 0;
			state.scrollPct = pct;

			// Direction change detection.
			var dir = y > state.lastScrollY ? 1 : (y < state.lastScrollY ? -1 : 0);
			if (dir !== 0 && dir !== state.lastScrollDir) {
				if (state.lastScrollDir !== 0) {
					state.dirChanges++;
				}
				state.lastScrollDir = dir;
			}

			// Scroll speed (px/s based on 200ms tick).
			state.scrollSpeed = Math.abs(y - state.lastScrollY) / 0.2;
			state.lastScrollY = y;
		}

		// Check gate thresholds.
		if (!gateChecks.scroll && state.scrollPct >= cfg.gateScrollPct) {
			gateChecks.scroll = true;
			if (cfg.debug) console.log('[PL-Ads] Gate: scroll ' + Math.round(state.scrollPct) + '%');
		}
		if (!gateChecks.direction && state.dirChanges >= cfg.gateDirChanges) {
			gateChecks.direction = true;
			if (cfg.debug) console.log('[PL-Ads] Gate: directions ' + state.dirChanges);
		}

		checkGate();

		// Pause detection for pause banner.
		clearTimeout(state.pauseTimer);
		state.pauseTimer = setTimeout(onScrollPause, 3000);
	}, 200);
}

// Time tracking — 1s interval until threshold met.
function startTimeTracking() {
	var interval = setInterval(function() {
		// Read from bridge if available, else compute own time.
		if (!readBridge()) {
			state.timeOnPage = (Date.now() - state.sessionStart) / 1000;
		}
		if (!gateChecks.time && state.timeOnPage >= cfg.gateTimeSec) {
			gateChecks.time = true;
			if (cfg.debug) console.log('[PL-Ads] Gate: time ' + Math.round(state.timeOnPage) + 's');
			checkGate();
			showAnchor(); // Anchor fires on time gate alone — no scroll required.
			clearInterval(interval);
		}
	}, 1000);
}

/* ================================================================
 * MODULE 3: GPT LOADER
 *
 * Lazy-loads GPT only when the first ad zone is ready to render.
 * Falls back to dummy mode on load failure.
 * ================================================================ */

var gptCallbacks = [];

function loadGPT(callback) {
	if (callback) gptCallbacks.push(callback);

	if (state.gptReady) {
		flushGptCallbacks();
		return;
	}

	if (state.gptLoaded) return; // Script loading, callbacks queued.
	state.gptLoaded = true;

	// Dummy mode: skip GPT entirely.
	if (cfg.dummy) {
		state.gptReady = true;
		flushGptCallbacks();
		return;
	}

	window.googletag = window.googletag || { cmd: [] };

	var script = document.createElement('script');
	script.src = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
	script.async = true;
	script.onerror = function() {
		if (cfg.debug) console.warn('[PL-Ads] GPT failed to load — falling back to dummy');
		cfg.dummy = true;
		state.gptReady = true;
		flushGptCallbacks();
	};
	document.head.appendChild(script);

	googletag.cmd.push(function() {
		googletag.pubads().enableSingleRequest();
		googletag.pubads().collapseEmptyDivs(true);
		googletag.enableServices();
		state.gptReady = true;
		if (cfg.debug) console.log('[PL-Ads] GPT ready');
		flushGptCallbacks();
	});
}

function flushGptCallbacks() {
	while (gptCallbacks.length) {
		gptCallbacks.shift()();
	}
}

/* ================================================================
 * MODULE 4: ZONE DISCOVERY
 *
 * Finds all .ad-zone elements injected by the PHP content scanner
 * and builds the zone list with per-zone metadata.
 * ================================================================ */

function discoverZones() {
	var els = document.querySelectorAll('.ad-zone[data-zone-id]');
	state.zones = [];
	for (var i = 0; i < els.length; i++) {
		state.zones.push({
			el: els[i],
			id: els[i].getAttribute('data-zone-id'),
			sizeMobile: els[i].getAttribute('data-size-mobile') || '300x250',
			sizeDesktop: els[i].getAttribute('data-size-desktop') || '300x250',
			score: parseInt(els[i].getAttribute('data-score') || '0', 10),
			activated: false,
			injected: false
		});
	}
	if (cfg.debug) console.log('[PL-Ads] Discovered ' + state.zones.length + ' zones');
}

/* ================================================================
 * MODULE 5: VIEWABILITY-FIRST ZONE ACTIVATION
 *
 * Strategy: Only inject ads when confident the user will see them.
 *
 * - IO triggers 300px BELOW viewport (forward-looking only)
 * - Zones already scrolled past are NEVER activated (wasted impression)
 * - Speed-gated activation:
 *     < 600px/s  → reading speed, activate immediately
 *     600-1200   → fast scan, downgrade large sizes to 300x250
 *     > 1200     → flying past, SKIP this zone entirely
 *   First ad uses stricter 800px/s threshold.
 * - Viewability budget: after 2+ ads, if <40% viewable → stop serving
 * - Anchor/interstitial/pause unaffected (out-of-page formats)
 * ================================================================ */

var zoneObserver = null;

function initZoneObserver() {
	if (!('IntersectionObserver' in window)) return;

	zoneObserver = new IntersectionObserver(function(entries) {
		for (var i = 0; i < entries.length; i++) {
			if (!entries[i].isIntersecting) continue;
			var el = entries[i].target;
			if (!state.gateOpen) continue; // Zone enters trigger area before gate — leave observed.
			zoneObserver.unobserve(el);
			tryActivateZone(el);
		}
	}, {
		rootMargin: '0px 0px 300px 0px', // 300px below viewport only.
		threshold: 0
	});

	for (var i = 0; i < state.zones.length; i++) {
		zoneObserver.observe(state.zones[i].el);
	}
}

/**
 * Called once when the gate opens. Activates zones currently IN or
 * just below the viewport. Zones already scrolled past are skipped —
 * they would produce 0% viewability.
 */
function onGateOpen() {
	// Don't activate zones if user hasn't scrolled at all.
	// Gate may have opened on time alone — wait for actual scroll engagement.
	// The IO will activate zones as the user scrolls.
	if (state.scrollPct < 1) {
		if (cfg.debug) console.log('[PL-Ads] onGateOpen: scroll ' + Math.round(state.scrollPct) + '% < 1% — deferring to IO');
		return;
	}

	for (var i = 0; i < state.zones.length; i++) {
		var zone = state.zones[i];
		if (zone.activated) continue;
		var rect = zone.el.getBoundingClientRect();
		// In viewport or within 300px below. NOT above viewport (rect.bottom <= 0).
		if (rect.bottom > 0 && rect.top < window.innerHeight + 300) {
			if (zoneObserver) zoneObserver.unobserve(zone.el);
			tryActivateZone(zone.el);
		} else if (rect.bottom <= 0) {
			// Zone is above viewport — user already scrolled past it.
			if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' SKIP: already scrolled past (above viewport)');
			if (zoneObserver) zoneObserver.unobserve(zone.el);
			collapseZone(zone.el, 'scrolled-past');
		}
	}
}

/**
 * Speed-gated, budget-aware zone activation.
 * Decides whether to activate, downgrade, or skip based on real-time
 * scroll speed and session viewability rate.
 */
function tryActivateZone(el) {
	var zone = getZoneByEl(el);
	if (!zone || zone.activated) return;

	// --- Viewability budget (only count RESOLVED impressions) ---
	if (state.budgetExhausted) {
		if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' SKIP: viewability budget exhausted (' + state.viewableCount + '/' + state.resolvedCount + ' resolved viewable)');
		collapseZone(el, 'budget');
		return;
	}

	// --- Max ads ---
	if (state.activeAds >= cfg.maxAds) {
		if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' SKIP: maxAds (' + state.activeAds + '/' + cfg.maxAds + ' [type:' + typeof cfg.maxAds + '])');
		collapseZone(el, 'max');
		return;
	}

	var speed = state.scrollSpeed;
	var isFirst = state.activeAds === 0;

	// --- Retry mode: relaxed speed gates ---
	// If we have pending retries (missed ads), activate this zone with
	// relaxed thresholds to compensate — but still skip if >2000px/s.
	var isRetry = state.pendingRetries > 0 && state.totalRetries < state.maxRetriesPerSession;

	if (isRetry) {
		if (speed > 2000) {
			if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' SKIP: retry but speed ' + Math.round(speed) + 'px/s > 2000 ceiling');
			collapseZone(el, 'speed');
			return;
		}
		// Consume a retry.
		state.pendingRetries--;
		state.totalRetries++;
		if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' RETRY activation (pending=' + state.pendingRetries + ', used=' + state.totalRetries + ')');
	} else {
		// --- Normal speed gate ---
		// First ad is stricter: must be < 800px/s (high confidence).
		// Subsequent: must be < 1200px/s.
		if (isFirst && speed > 800) {
			if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' SKIP: first-ad speed ' + Math.round(speed) + 'px/s > 800');
			collapseZone(el, 'speed');
			return;
		}
		if (!isFirst && speed > 1200) {
			if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' SKIP: speed ' + Math.round(speed) + 'px/s > 1200');
			collapseZone(el, 'speed');
			return;
		}
	}

	// Determine size for this device.
	var size = isMobile ? zone.sizeMobile : zone.sizeDesktop;

	// --- Speed-based size downgrade ---
	// 600-1200px/s: fast scanner — downgrade large desktop formats
	// to 300x250 (smaller = faster to view, higher viewability).
	if (speed >= 600 && (size === '970x250' || size === '728x90')) {
		if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' downgrade: ' + size + ' \u2192 300x250 (speed ' + Math.round(speed) + 'px/s)');
		size = '300x250';
	}

	// Standard checks.
	if (!isFormatEnabled(size)) {
		if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' SKIP: format ' + size + ' disabled');
		collapseZone(el, 'format');
		return;
	}
	if (!checkSpacing(el)) {
		if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' SKIP: spacing < ' + cfg.minSpacingPx + 'px');
		collapseZone(el, 'spacing');
		return;
	}

	// --- ACTIVATE ---
	var label = speed < 600 ? 'reading' : 'scanning';
	if (isRetry) label = 'retry';
	zone.activated = true;
	zone.activatedSize = size;
	zone.isRetry = isRetry;
	state.activeAds++;
	if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' ACTIVATED (' + size + ', ' + label + ' ' + Math.round(speed) + 'px/s) \u2014 total: ' + state.activeAds);

	el.classList.add('pl-ad-active', 'pl-ad-' + size);

	loadGPT(function() {
		renderDisplayAd(zone, size);
	});
}

function getZoneByEl(el) {
	for (var i = 0; i < state.zones.length; i++) {
		if (state.zones[i].el === el) return state.zones[i];
	}
	return null;
}

function checkSpacing(el) {
	var rect = el.getBoundingClientRect();
	var myTop = rect.top + (window.scrollY || window.pageYOffset);

	var activeZones = document.querySelectorAll('.ad-zone.pl-ad-active');
	for (var i = 0; i < activeZones.length; i++) {
		if (activeZones[i] === el) continue;
		var otherRect = activeZones[i].getBoundingClientRect();
		var otherTop = otherRect.top + (window.scrollY || window.pageYOffset);
		if (Math.abs(myTop - otherTop) < cfg.minSpacingPx) {
			return false;
		}
	}
	return true;
}

function isFormatEnabled(size) {
	if (size === '300x250') return cfg.fmt300x250;
	if (size === '970x250') return cfg.fmt970x250;
	if (size === '728x90') return cfg.fmt728x90;
	return true;
}

function collapseZone(el, reason) {
	el.classList.add('pl-ad-collapse');
	if (reason) el.setAttribute('data-skip-reason', reason);
	var zone = getZoneByEl(el);
	if (zone) zone.activated = true; // Prevent re-processing.

	// In dummy mode, show why the zone was skipped.
	if (cfg.dummy && reason) {
		var speed = Math.round(state.scrollSpeed);
		var msg = 'SKIPPED';
		if (reason === 'speed') msg = 'SKIPPED: speed ' + speed + 'px/s > threshold';
		else if (reason === 'budget') msg = 'SKIPPED: viewability budget exhausted';
		else if (reason === 'scrolled-past') msg = 'SKIPPED: already scrolled past';
		else if (reason === 'max') msg = 'SKIPPED: max ads reached';
		else if (reason === 'spacing') msg = 'SKIPPED: too close to another ad';
		else if (reason === 'format') msg = 'SKIPPED: format disabled';

		el.classList.add('pl-ad-dummy');
		el.style.cssText = 'width:300px;height:40px;max-width:100%;background:#ffebee;border:2px dashed #ef5350;border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:monospace;font-size:11px;font-weight:700;color:#c62828;margin:8px auto';
		el.textContent = msg;
	}
}

/* ================================================================
 * MODULE 6: DISPLAY AD RENDERER
 *
 * Renders either a real GPT ad or a dummy placeholder box.
 * Sets up viewability tracking after render.
 * ================================================================ */

function renderDisplayAd(zone, size) {
	if (zone.injected) return;
	zone.injected = true;

	var el = zone.el;
	var parts = size.split('x');
	var w = parseInt(parts[0], 10);
	var h = parseInt(parts[1], 10);

	if (cfg.dummy) {
		renderDummy(el, size, w, h, zone.id, zone.score);
		trackViewability(zone);
		return;
	}

	// Create GPT ad container.
	var adDiv = document.createElement('div');
	var slotId = 'pl-gpt-' + zone.id;
	adDiv.id = slotId;
	adDiv.style.width = w + 'px';
	adDiv.style.height = h + 'px';
	adDiv.style.margin = '0 auto';
	el.appendChild(adDiv);

	// Define and display GPT slot.
	var slotName = cfg.slots[size] || cfg.slots['300x250'];
	var slotPath = cfg.slotPrefix + slotName;

	googletag.cmd.push(function() {
		var slot = googletag.defineSlot(slotPath, [w, h], slotId);
		if (slot) {
			slot.addService(googletag.pubads());
			googletag.display(slotId);
			state.slots[zone.id] = slot;
			if (cfg.debug) console.log('[PL-Ads] Slot: ' + slotPath + ' (' + size + ')');
		}
	});

	el.setAttribute('data-injected', 'true');
	el.setAttribute('aria-hidden', 'false');

	trackViewability(zone);

	if (cfg.debug) {
		addDebugInfo(el, zone.id, size, zone.score);
	}
}

function renderDummy(el, size, w, h, zoneId, score) {
	el.classList.add('pl-ad-dummy');

	// Responsive: cap width on mobile.
	var displayW = isMobile ? Math.min(w, window.innerWidth - 32) : w;
	var displayH = displayW < w ? Math.round((displayW / w) * h) : h;

	var zone = getZoneByEl(el);
	var speed = Math.round(state.scrollSpeed);
	var label = speed < 600 ? 'reading' : 'scanning';
	if (zone && zone.isRetry) label = 'RETRY #' + state.totalRetries;
	var statusText = 'ACTIVATED: ' + speed + 'px/s \u2014 ' + label;

	el.style.width = displayW + 'px';
	el.style.height = displayH + 'px';
	el.style.maxWidth = '100%';
	el.innerHTML = '<span style="font-size:11px;line-height:1.3">' + size + '<br>' + statusText + '</span>';
	el.setAttribute('data-injected', 'true');

	if (cfg.debug) {
		addDebugInfo(el, zoneId, size, score);
		console.log('[PL-Ads] Dummy: ' + zoneId + ' (' + size + ') ' + statusText);
	}
}

/* ================================================================
 * MODULE 7: INTERSTITIAL
 *
 * Full-screen overlay ad. Triggers after 2+ display ads have loaded.
 * Auto-closes after 15 seconds. User can dismiss with close button.
 * ================================================================ */

var interstitialShown = false;

function showInterstitial() {
	if (interstitialShown || !cfg.fmtInterstitial || !state.gateOpen) return;
	interstitialShown = true;

	// Ensure shared GPT instance is loaded before rendering.
	loadGPT(function() {
		var overlay = document.createElement('div');
		overlay.className = 'pl-ad-interstitial pl-ad-active';

		var inner = document.createElement('div');
		inner.className = 'pl-ad-interstitial-inner';

		var closeBtn = createCloseBtn(function() {
			overlay.classList.remove('pl-ad-active');
			setTimeout(function() { if (overlay.parentNode) overlay.remove(); }, 300);
		});
		inner.appendChild(closeBtn);

		if (cfg.dummy) {
			inner.appendChild(createDummyBlock('Interstitial 300x250', 300, 250, '#e8f5e9'));
		} else {
			var adDiv = document.createElement('div');
			adDiv.id = 'pl-gpt-interstitial';
			adDiv.style.cssText = 'width:300px;height:250px;max-width:100%;margin:0 auto';
			inner.appendChild(adDiv);

			var slotPath = cfg.slotPrefix + cfg.slots.interstitial;
			googletag.cmd.push(function() {
				var slot = googletag.defineSlot(slotPath, [300, 250], 'pl-gpt-interstitial');
				if (slot) {
					slot.addService(googletag.pubads());
					googletag.display('pl-gpt-interstitial');
				}
			});
		}

		overlay.appendChild(inner);
		document.body.appendChild(overlay);

		// Auto-close after 15s.
		setTimeout(function() {
			if (overlay.parentNode) {
				overlay.classList.remove('pl-ad-active');
				setTimeout(function() { if (overlay.parentNode) overlay.remove(); }, 300);
			}
		}, 15000);

		if (cfg.debug) console.log('[PL-Ads] Interstitial shown');
	});
}

/* ================================================================
 * MODULE 8: ANCHOR (BOTTOM STICKY)
 *
 * #1 revenue format ($18.52/wk). Fires on TIME gate only — no scroll
 * required. Users on page 5+ seconds without scrolling are real humans
 * (e.g., Pinterest users reading the hero area). Anchor at viewport
 * bottom generates revenue from these sessions.
 * ================================================================ */

var anchorShown = false;

function showAnchor() {
	if (anchorShown || !cfg.fmtAnchor || !gateChecks.time) return;
	anchorShown = true;

	// Ensure shared GPT instance is loaded before rendering.
	loadGPT(function() {
		var anchor = document.createElement('div');
		anchor.className = 'pl-ad-anchor pl-ad-active';

		var closeBtn = createCloseBtn(function() {
			anchor.classList.remove('pl-ad-active');
			setTimeout(function() { if (anchor.parentNode) anchor.remove(); }, 300);
		});
		anchor.appendChild(closeBtn);

		if (cfg.dummy) {
			anchor.appendChild(createDummyBlock('Anchor 320x50', 320, 50, '#fff3e0'));
		} else {
			var adDiv = document.createElement('div');
			adDiv.id = 'pl-gpt-anchor';
			adDiv.style.cssText = 'width:320px;height:50px';
			anchor.appendChild(adDiv);

			var slotPath = cfg.slotPrefix + cfg.slots.anchor;
			googletag.cmd.push(function() {
				var slot = googletag.defineSlot(slotPath, [320, 50], 'pl-gpt-anchor');
				if (slot) {
					slot.addService(googletag.pubads());
					googletag.display('pl-gpt-anchor');
				}
			});
		}

		document.body.appendChild(anchor);

		if (cfg.debug) console.log('[PL-Ads] Anchor shown');
	});
}

/* ================================================================
 * MODULE 9: PAUSE BANNER
 *
 * Centered overlay shown when user pauses scrolling for 3+ seconds
 * past 30% scroll depth. Auto-closes after 10 seconds.
 * ================================================================ */

var pauseShown = false;

function onScrollPause() {
	if (pauseShown || !cfg.fmtPause || !state.gateOpen) return;
	if (state.scrollPct < 30) return;
	// Require minimum display ads served before showing pause banner.
	var minAds = cfg.pauseMinAds >= 0 ? cfg.pauseMinAds : 2;
	if (state.activeAds < minAds) {
		if (cfg.debug) console.log('[PL-Ads] Pause: skipped — only ' + state.activeAds + '/' + minAds + ' display ads served');
		return;
	}

	pauseShown = true;

	// Ensure shared GPT instance is loaded before rendering.
	loadGPT(function() {
		var pause = document.createElement('div');
		pause.className = 'pl-ad-pause pl-ad-active';

		var closeBtn = createCloseBtn(function() {
			pause.classList.remove('pl-ad-active');
			setTimeout(function() { if (pause.parentNode) pause.remove(); }, 300);
		});
		pause.appendChild(closeBtn);

		if (cfg.dummy) {
			pause.appendChild(createDummyBlock('Pause 300x250', 300, 250, '#fce4ec'));
		} else {
			var adDiv = document.createElement('div');
			adDiv.id = 'pl-gpt-pause';
			adDiv.style.cssText = 'width:300px;height:250px;max-width:100%;margin:0 auto';
			pause.appendChild(adDiv);

			var slotPath = cfg.slotPrefix + cfg.slots.pause;
			googletag.cmd.push(function() {
				var slot = googletag.defineSlot(slotPath, [300, 250], 'pl-gpt-pause');
				if (slot) {
					slot.addService(googletag.pubads());
					googletag.display('pl-gpt-pause');
				}
			});
		}

		document.body.appendChild(pause);

		// Auto-close after 10s.
		setTimeout(function() {
			if (pause.parentNode) {
				pause.classList.remove('pl-ad-active');
				setTimeout(function() { if (pause.parentNode) pause.remove(); }, 300);
			}
		}, 10000);

		if (cfg.debug) console.log('[PL-Ads] Pause banner shown');
	});
}

/* ================================================================
 * MODULE 10: VIEWABILITY TRACKER
 *
 * IAB standard: 50% of pixels visible for 1 continuous second
 * = 1 viewable impression. Tracks per-zone.
 * ================================================================ */

function trackViewability(zone) {
	var data = {
		zoneId: zone.id,
		adSize: zone.activatedSize || (isMobile ? zone.sizeMobile : zone.sizeDesktop),
		totalVisibleMs: 0,
		viewableImpressions: 0,
		maxRatio: 0,
		ratioSum: 0,
		ratioCount: 0,
		timeToFirstView: 0,
		firstViewRecorded: false, // ratio >= 0.5 (IAB viewable threshold)
		firstSeenRecorded: false, // ratio > 0 (any part entered viewport)
		injectedAtDepth: state.scrollPct,
		scrollSpeedAtInjection: state.scrollSpeed,
		visibleStart: 0,
		isVisible: false,
		viewableTimer: null,
		resolved: false, // true once confirmed viewable OR confirmed missed
		missed: false,   // true if scrolled out without becoming viewable
		isRetry: !!zone.isRetry
	};
	state.viewability[zone.id] = data;

	if (!('IntersectionObserver' in window)) {
		if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' — IO not supported');
		return;
	}

	var observer = new IntersectionObserver(function(entries) {
		for (var ei = 0; ei < entries.length; ei++) {
			var entry = entries[ei];
			var ratio = entry.intersectionRatio;

			data.ratioSum += ratio;
			data.ratioCount++;
			if (ratio > data.maxRatio) data.maxRatio = ratio;

			// Track first time any part of zone enters viewport.
			if (ratio > 0 && !data.firstSeenRecorded) {
				data.firstSeenRecorded = true;
				if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' FIRST SEEN (ratio=' + Math.round(ratio * 100) + '%)');
			}

			if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' IO fired ratio=' + Math.round(ratio * 100) + '% isVisible=' + data.isVisible + ' impressions=' + data.viewableImpressions);

			if (ratio >= 0.5) {
				if (!data.isVisible) {
					data.isVisible = true;
					data.visibleStart = Date.now();
					if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' VISIBLE >=50% (ratio=' + Math.round(ratio * 100) + '%), starting 1s timer');

					if (!data.firstViewRecorded) {
						data.firstViewRecorded = true;
						data.timeToFirstView = Date.now() - state.sessionStart;
					}

					// 1-second continuous visibility = viewable impression.
					data.viewableTimer = setTimeout(function() {
						data.viewableImpressions++;
						if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' 1s timer COMPLETED — marking VIEWABLE (impression #' + data.viewableImpressions + ')');
						// Track first viewable impression per zone for budget.
						if (data.viewableImpressions === 1) {
							state.viewableCount++;
							// Mark as resolved (viewable).
							if (!data.resolved) {
								data.resolved = true;
								state.resolvedCount++;
								// Track successful retry.
								if (data.isRetry) state.retriesSuccessful++;
							}
							if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' VIEWABLE — session: ' + state.viewableCount + '/' + state.resolvedCount + ' resolved' + (data.isRetry ? ' (retry success)' : ''));
							checkViewabilityBudget();
						}
					}, 1000);
				}
			} else {
				if (data.isVisible) {
					var elapsed = Date.now() - data.visibleStart;
					data.totalVisibleMs += elapsed;
					data.isVisible = false;
					clearTimeout(data.viewableTimer);
					if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' dropped below 50% (ratio=' + Math.round(ratio * 100) + '%), clearing timer (was visible ' + elapsed + 'ms, total ' + data.totalVisibleMs + 'ms)');
				}

				// --- Missed detection ---
				// Zone scrolled completely out (ratio=0) and was seen at some point
				// but never became viewable → mark as "missed", queue retry.
				// Uses firstSeenRecorded (ratio > 0) not firstViewRecorded (ratio >= 0.5)
				// because fast scrollers may never reach 50% but the zone WAS in viewport.
				if (ratio === 0 && !data.resolved && data.viewableImpressions === 0 && data.firstSeenRecorded) {
					data.resolved = true;
					data.missed = true;
					state.resolvedCount++;
					if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' MISSED — scrolled out with 0 viewable (visible ' + data.totalVisibleMs + 'ms, maxRatio=' + Math.round(data.maxRatio * 100) + '%)');
					// Queue a retry if under session cap.
					if (state.totalRetries < state.maxRetriesPerSession) {
						state.pendingRetries++;
						if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' retry queued (pending=' + state.pendingRetries + ')');
						// Proactively trigger retry: the next zone may have already
						// been speed-gated before this missed detection fired.
						tryRetryNearbyZone();
					} else {
						if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' retry cap reached (' + state.totalRetries + '/' + state.maxRetriesPerSession + ')');
					}
					checkViewabilityBudget();
				}
			}
		}
	}, {
		threshold: [0, 0.25, 0.5, 0.75, 1.0]
	});

	observer.observe(zone.el);
	if (cfg.debug) console.log('[PL-Ads] Viewability: ' + data.zoneId + ' tracking started, observing element ' + zone.el.className + ' (' + zone.el.offsetWidth + 'x' + zone.el.offsetHeight + 'px)');
}

function checkViewabilityBudget() {
	// Budget check: after 2+ resolved impressions, if <40% viewable → stop.
	if (state.resolvedCount >= 2 && state.viewableCount / state.resolvedCount < 0.4) {
		state.budgetExhausted = true;
		if (cfg.debug) console.log('[PL-Ads] Viewability budget EXHAUSTED: ' + state.viewableCount + '/' + state.resolvedCount + ' resolved (' + Math.round(state.viewableCount / state.resolvedCount * 100) + '%)');
	}
}

/**
 * Proactive retry: find a zone that was collapsed due to speed-gating
 * and is still in or near the viewport. Reset it and re-try activation.
 *
 * Called from missed detection — solves the timing issue where zone B
 * gets speed-gated BEFORE zone A's missed detection fires.
 */
function tryRetryNearbyZone() {
	if (state.pendingRetries <= 0) return;

	for (var i = 0; i < state.zones.length; i++) {
		var zone = state.zones[i];
		// Only consider zones that were collapsed specifically for speed.
		if (!zone.activated || zone.injected) continue;
		if (zone.el.getAttribute('data-skip-reason') !== 'speed') continue;

		var rect = zone.el.getBoundingClientRect();
		// Must be in viewport or within 500px below (still reachable).
		if (rect.bottom <= 0 || rect.top > window.innerHeight + 500) continue;

		// Reset the collapsed zone for re-evaluation.
		zone.activated = false;
		zone.el.classList.remove('pl-ad-collapse', 'pl-ad-dummy');
		zone.el.removeAttribute('data-skip-reason');
		zone.el.textContent = '';
		zone.el.style.cssText = '';
		if (cfg.debug) console.log('[PL-Ads] Retry: resurrecting speed-skipped zone ' + zone.id + ' for retry activation');

		tryActivateZone(zone.el);
		return; // One retry per missed detection.
	}

	if (cfg.debug) console.log('[PL-Ads] Retry: no speed-skipped zones near viewport — retry will apply to next IO trigger');
}

/* ================================================================
 * MODULE 11: DATA RECORDER
 *
 * Sends viewability session data to REST endpoint on page unload.
 * Uses navigator.sendBeacon for reliability, XHR as fallback.
 * ================================================================ */

function buildSessionData() {
	var zones = [];
	var totalViewable = 0;

	for (var zid in state.viewability) {
		if (!state.viewability.hasOwnProperty(zid)) continue;
		var d = state.viewability[zid];

		// Close open visibility window.
		if (d.isVisible) {
			d.totalVisibleMs += Date.now() - d.visibleStart;
			d.isVisible = false;
			clearTimeout(d.viewableTimer);
		}

		var avgRatio = d.ratioCount > 0 ? d.ratioSum / d.ratioCount : 0;
		totalViewable += d.viewableImpressions;

		// Keys are camelCase — ad-data-recorder.php converts to snake_case.
		zones.push({
			zoneId: d.zoneId,
			adSize: d.adSize,
			totalVisibleMs: d.totalVisibleMs,
			viewableImpressions: d.viewableImpressions,
			maxRatio: Math.round(d.maxRatio * 100) / 100,
			avgRatio: Math.round(avgRatio * 100) / 100,
			timeToFirstView: d.timeToFirstView,
			injectedAtDepth: Math.round(d.injectedAtDepth * 10) / 10,
			scrollSpeedAtInjection: Math.round(d.scrollSpeedAtInjection)
		});
	}

	var b = window.__plEngagement;

	// Keys are camelCase — ad-data-recorder.php converts to snake_case for storage.
	return {
		session: true,
		sid: sessionId,
		postId: cfg.postId,
		postSlug: cfg.postSlug,
		device: isMobile ? 'mobile' : 'desktop',
		viewportW: window.innerWidth,
		viewportH: window.innerHeight,
		timeOnPage: Date.now() - state.sessionStart,
		maxDepth: Math.round(state.scrollPct * 10) / 10,
		avgScrollSpeed: Math.round(state.scrollSpeed),
		scrollPattern: classifyPattern(),
		itemsSeen: b ? b.itemsSeen : 0,
		totalItems: b ? b.totalItems : 0,
		gateOpen: state.gateOpen,
		gateScroll: gateChecks.scroll,
		gateTime: gateChecks.time,
		gateDirection: gateChecks.direction,
		totalAdsInjected: state.activeAds,
		totalViewable: totalViewable,
		viewabilityRate: state.activeAds > 0 ? Math.round((totalViewable / state.activeAds) * 100) / 100 : 0,
		viewableZones: state.viewableCount,
		budgetExhausted: state.budgetExhausted,
		retriesUsed: state.totalRetries,
		retriesSuccessful: state.retriesSuccessful,
		anchorStatus: anchorShown ? 'firing' : 'off',
		interstitialStatus: interstitialShown ? 'fired' : 'off',
		pauseStatus: pauseShown ? 'fired' : 'off',
		zones: zones
	};
}

function classifyPattern() {
	// Prefer engagement.js bridge classification when available.
	var b = window.__plEngagement;
	if (b && b.pattern) return b.pattern;
	var t = (Date.now() - state.sessionStart) / 1000;
	if (t < 10 && state.scrollPct < 30) return 'bouncer';
	if (state.dirChanges > 5 && t > 30) return 'reader';
	return 'scanner';
}

function sendData() {
	if (!cfg.record || state.dataSent) return;
	// Record if gate opened OR user spent 2+ seconds (captures full funnel).
	var elapsed = (Date.now() - state.sessionStart) / 1000;
	if (!state.gateOpen && elapsed < 2) return;
	state.dataSent = true;

	var payload = buildSessionData();
	var json = JSON.stringify(payload);

	if (navigator.sendBeacon) {
		navigator.sendBeacon(cfg.recordEndpoint, new Blob([json], { type: 'application/json' }));
	} else {
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.recordEndpoint, true);
		xhr.setRequestHeader('Content-Type', 'application/json');
		xhr.setRequestHeader('X-WP-Nonce', cfg.nonce);
		xhr.send(json);
	}

	if (cfg.debug) console.log('[PL-Ads] Data sent', payload);
}

/* ================================================================
 * MODULE 12: DEBUG OVERLAY
 *
 * Shows zone ID, size, and score as a small label on each ad.
 * Logs periodic state dumps to console.
 * ================================================================ */

function addDebugInfo(el, zoneId, size, score) {
	var info = document.createElement('span');
	info.className = 'pl-ad-debug';
	info.textContent = zoneId + ' | ' + size + ' | s:' + score;
	el.appendChild(info);
}

function debugLog() {
	console.log('[PL-Ads]', {
		gate: state.gateOpen,
		ads: state.activeAds + '/' + cfg.maxAds,
		viewable: state.viewableCount + '/' + state.resolvedCount + ' resolved',
		budget: state.budgetExhausted ? 'EXHAUSTED' : 'ok',
		retries: state.pendingRetries + ' pending, ' + state.totalRetries + '/' + state.maxRetriesPerSession + ' used, ' + state.retriesSuccessful + ' ok',
		anchor: anchorShown ? 'firing' : 'off',
		interstitial: interstitialShown ? 'fired' : 'off',
		pause: pauseShown ? 'fired' : 'off',
		zones: state.zones.length,
		scroll: Math.round(state.scrollPct) + '%',
		time: Math.round(state.timeOnPage) + 's',
		dirs: state.dirChanges,
		speed: Math.round(state.scrollSpeed)
	});
}

/* ================================================================
 * SHARED HELPERS
 * ================================================================ */

function createCloseBtn(onClick) {
	var btn = document.createElement('button');
	btn.className = 'pl-ad-close';
	btn.textContent = '\u00d7';
	btn.setAttribute('aria-label', 'Close ad');
	btn.addEventListener('click', onClick);
	return btn;
}

function createDummyBlock(label, w, h, bg) {
	var el = document.createElement('div');
	el.style.cssText = 'width:' + w + 'px;height:' + h + 'px;max-width:100%;background:' + bg + ';border:2px dashed rgba(0,0,0,.12);border-radius:12px;display:flex;align-items:center;justify-content:center;font-family:monospace;font-size:12px;font-weight:700;color:rgba(0,0,0,.35)';
	el.textContent = label;
	return el;
}

/* ================================================================
 * MODULE 13: LIVE MONITOR HEARTBEAT
 *
 * Sends lightweight heartbeat to admin Live Sessions dashboard.
 * ZERO frontend impact: only activates when plAds.liveMonitor is true,
 * uses requestIdleCallback + sendBeacon, silently stops on failure.
 * ================================================================ */

var sessionId = Math.random().toString(36).substring(2, 10) + Date.now().toString(36).slice(-4);
var heartbeatActive = false;
var heartbeatFails = 0;

function startHeartbeat() {
	if (!cfg.liveMonitor || heartbeatActive) return;
	heartbeatActive = true;

	setInterval(function() {
		if (heartbeatFails >= 3) return; // Stop after 3 failures.
		if ('requestIdleCallback' in window) {
			requestIdleCallback(sendHeartbeat, { timeout: 2000 });
		} else {
			sendHeartbeat();
		}
	}, 3000);
}

function sendHeartbeat() {
	var zoneDetail = [];
	for (var zid in state.viewability) {
		if (!state.viewability.hasOwnProperty(zid)) continue;
		var d = state.viewability[zid];
		zoneDetail.push({
			zoneId: d.zoneId,
			adSize: d.adSize,
			injectedAtDepth: d.injectedAtDepth,
			scrollSpeedAtInjection: d.scrollSpeedAtInjection,
			totalVisibleMs: d.totalVisibleMs + (d.isVisible ? Date.now() - d.visibleStart : 0),
			viewableImpressions: d.viewableImpressions,
			maxRatio: d.maxRatio,
			timeToFirstView: d.timeToFirstView
		});
	}

	var totalViewable = 0;
	for (var i = 0; i < zoneDetail.length; i++) {
		totalViewable += zoneDetail[i].viewableImpressions;
	}

	var activeZoneIds = [];
	for (var j = 0; j < state.zones.length; j++) {
		if (state.zones[j].activated && state.zones[j].injected) {
			activeZoneIds.push(state.zones[j].id);
		}
	}

	var b = window.__plEngagement;
	var payload = {
		sid: sessionId,
		postId: cfg.postId,
		postSlug: cfg.postSlug,
		postTitle: document.title.split(' - ')[0].split(' | ')[0].substring(0, 80),
		device: isMobile ? 'mobile' : 'desktop',
		viewportW: window.innerWidth,
		viewportH: window.innerHeight,
		timeOnPage: Math.round((Date.now() - state.sessionStart) / 1000),
		scrollPct: Math.round(state.scrollPct * 10) / 10,
		scrollSpeed: Math.round(state.scrollSpeed),
		scrollPattern: classifyPattern(),
		gateScroll: gateChecks.scroll,
		gateTime: gateChecks.time,
		gateDirection: gateChecks.direction,
		gateOpen: state.gateOpen,
		activeAds: state.activeAds,
		viewableAds: totalViewable,
		zonesActive: activeZoneIds.join(','),
		referrer: document.referrer || '',
		language: (navigator.language || '').substring(0, 5),
		zoneDetail: zoneDetail,
		// Out-of-page format status.
		anchorStatus: anchorShown ? 'firing' : (gateChecks.time ? 'waiting' : 'off'),
		interstitialStatus: interstitialShown ? 'fired' : (state.gateOpen && state.activeAds >= 2 ? 'waiting' : 'off'),
		pauseStatus: pauseShown ? 'fired' : (state.gateOpen ? 'waiting' : 'off'),
		// Retry stats.
		pendingRetries: state.pendingRetries,
		totalRetries: state.totalRetries,
		retriesSuccessful: state.retriesSuccessful
	};

	var json = JSON.stringify(payload);
	var url = cfg.heartbeatEndpoint;

	if (navigator.sendBeacon) {
		var ok = navigator.sendBeacon(url, new Blob([json], { type: 'application/json' }));
		if (!ok) heartbeatFails++;
		else heartbeatFails = 0;
	} else {
		try {
			fetch(url, { method: 'POST', body: json, headers: { 'Content-Type': 'application/json' }, keepalive: true })
				.then(function() { heartbeatFails = 0; })
				.catch(function() { heartbeatFails++; });
		} catch(e) { heartbeatFails++; }
	}
}

/* ================================================================
 * INIT — WIRE EVERYTHING UP
 * ================================================================ */

function init() {
	discoverZones();

	if (state.zones.length === 0 && !cfg.fmtInterstitial && !cfg.fmtAnchor && !cfg.fmtPause) {
		if (cfg.debug) console.log('[PL-Ads] No zones or overlay formats — exiting');
		return;
	}

	// Start engagement gate monitoring.
	window.addEventListener('scroll', onScroll, { passive: true });
	startTimeTracking();

	// Observe zones — they activate when gate opens + they enter viewport.
	initZoneObserver();

	// Send data on page unload.
	document.addEventListener('visibilitychange', function() {
		if (document.visibilityState === 'hidden') sendData();
	});
	window.addEventListener('pagehide', sendData);

	// Live monitor heartbeat (only if admin is watching).
	startHeartbeat();

	// Periodic check for overlay formats after gate opens.
	var fmtInterval = setInterval(function() {
		if (!state.gateOpen) return;

		// Anchor: fires immediately from checkGate(), but retry here
		// in case gate opened before init wired up the interval.
		if (!anchorShown) {
			showAnchor();
		}

		// Interstitial: after 2+ display ads have loaded.
		if (!interstitialShown && state.activeAds >= 2) {
			showInterstitial();
		}

		// Stop polling once all overlay formats are shown or disabled.
		var allDone = (anchorShown || !cfg.fmtAnchor) &&
		              (interstitialShown || !cfg.fmtInterstitial) &&
		              (pauseShown || !cfg.fmtPause);
		if (allDone) {
			clearInterval(fmtInterval);
		}
	}, 2000);

	if (cfg.debug) {
		console.log('[PL-Ads] Init', {
			dummy: cfg.dummy,
			maxAds: cfg.maxAds,
			gate: cfg.gateScrollPct + '% / ' + cfg.gateTimeSec + 's / ' + cfg.gateDirChanges + 'dirs',
			zones: state.zones.length,
			device: isMobile ? 'mobile' : 'desktop'
		});
		setInterval(debugLog, 5000);
	}
}

// Boot: wait for DOM ready + requestIdleCallback (zero TBT).
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', function() {
		if ('requestIdleCallback' in window) {
			requestIdleCallback(init);
		} else {
			setTimeout(init, 200);
		}
	});
} else {
	if ('requestIdleCallback' in window) {
		requestIdleCallback(init);
	} else {
		setTimeout(init, 200);
	}
}

})();
