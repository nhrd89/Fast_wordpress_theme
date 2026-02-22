/**
 * PinLightning Smart Ad Engine — v5 Scroll-Driven Dynamic Injection
 *
 * Philosophy: Zero ads in HTML. Watch the visitor scroll. When behavior
 * guarantees the ad will be seen, inject ONE ad. Wait for viewability
 * confirmation. Only then consider the next.
 *
 * Zero TBT: IntersectionObserver + requestIdleCallback + passive listeners.
 *
 * @package PinLightning
 * @since   3.0.0
 */
;(function() {
'use strict';

/* ================================================================
 * MODULE 1: CONFIG
 * ================================================================ */

var SLOT_BASE = '/21849154601,22953639975/';

var cfg = window.plAds || {};
var debug = cfg.debug || false;

// Bail if not configured (ad engine disabled or missing config).
if (!cfg.networkCode && !cfg.dummy) return;

// Bot detection — exit before loading any ad code.
if (navigator.webdriver) return;
if (/bot|crawl|spider|slurp|googlebot|bingbot|baiduspider|yandexbot|duckduckbot|sogou|exabot|ia_archiver|facebot|facebookexternalhit|ahrefsbot|semrushbot|mj12bot|dotbot|petalbot|applebot|dataforseobot|bytespider|gptbot|claudebot|ccbot|amazonbot|anthropic|headlesschrome|phantomjs|slimerjs|lighthouse|pagespeed|pingdom|uptimerobot|wget|curl|python-requests|go-http-client|java\/|libwww/i.test(navigator.userAgent)) return;
if (window.innerWidth === 0 && window.innerHeight === 0) return;

var isMobile = window.innerWidth < 768;
var isTablet = window.innerWidth >= 768 && window.innerWidth < 1024;
var isDesktop = window.innerWidth >= 1024;

// Device gate.
if (isMobile && cfg.mobileEnabled === '0') return;
if (isDesktop && cfg.desktopEnabled === '0') return;

// ─── Injection Constants ───
var MIN_DISTANCE_PX = 400;          // minimum 400px between ads
var MIN_TIME_BETWEEN_ADS_MS = 4000; // minimum 4 seconds between injections
var MAX_SPEED_FOR_INJECT = 500;     // don't inject if scrolling faster (px/s) — data: 0% viewability above 800
var VIEWABILITY_WAIT_MS = 8000;     // wait max 8s for previous ad viewability
var VIEWABLE_RATIO = 0.5;           // IAB standard: 50% visible
var VIEWABLE_MS = 1000;             // for 1 continuous second
var SPEED_SAMPLE_MS = 300;          // sample scroll speed every 300ms
var SPEED_HISTORY_SIZE = 10;        // keep last 10 speed samples
var SPEED_READER = 150;             // < 150 px/s = reading carefully
var SPEED_SCANNER = 600;            // 150-600 px/s = scanning
var SPEED_FAST = 1200;              // 600-1200 px/s = fast scrolling
var PAUSE_THRESHOLD_MS = 3000;      // 3 seconds of no scroll = pause
var REFRESH_COOLDOWN_MS = 30000;    // 30s between refreshes (Google policy)
var MAX_REFRESHES = 15;             // per session
var PAUSE_BANNER_POSITIONS = ['item3-after-p', 'item6-after-p', 'item9-after-p', 'item12-after-p'];
var MAX_PAUSE_BANNERS = 2;

/* ================================================================
 * MODULE 2: STATE
 * ================================================================ */

var state = {
	// Scroll tracking.
	scrollY: 0,
	scrollSpeed: 0,
	speedHistory: [],
	scrollDirection: 'down',
	directionChanges: 0,
	maxScrollPct: 0,

	// Ad injection state.
	injectedAds: [],
	lastInjectionY: -999,
	lastInjectionTime: 0,
	totalInjected: 0,
	totalViewable: 0,
	totalRefreshes: 0,
	pauseBannersInjected: 0,
	videoInjected: false,

	// Gate.
	gateOpen: false,
	gateOpenTime: 0,
	gateViaTimeOnly: false,

	// Visitor classification.
	pattern: 'unknown',
	timeOnPage: 0,
	sessionStart: Date.now(),
	dataSent: false,

	// Available anchors.
	anchors: [],
	nextAnchorIndex: 0,

	// Overlays.
	anchorSlot: null,
	topAnchorSlot: null,
	interstitialSlot: null,
	anchorFiredAt: 0,
	anchorImpressions: 0,
	anchorViewableImps: 0,
	topAnchorFiredAt: 0,
	interstitialShownAt: 0,
	interstitialClosedAt: 0,
	interstitialViewable: 0,
	anchorFilled: false,
	topAnchorFilled: false,
	interstitialFilled: false,

	// Ad fill tracking.
	totalRequested: 0,
	totalFilled: 0,
	totalEmpty: 0,
	totalUnfilled: 0,
	viewportAdsInjected: 0,

	// Pause refresh.
	scrollPauseTimer: null,
	lastRefreshTime: {},

	// GPT state.
	gptLoaded: false,
	gptReady: false,
	slotCounter: 0
};

/* ================================================================
 * MODULE 3: SCROLL SPEED TRACKER
 * ================================================================ */

var lastSampleY = window.scrollY;
var lastSampleTime = Date.now();

function sampleScrollSpeed() {
	var now = Date.now();
	var dy = Math.abs(window.scrollY - lastSampleY);
	var dt = (now - lastSampleTime) / 1000;

	if (dt > 0) {
		var instantSpeed = dy / dt;
		state.speedHistory.push(instantSpeed);
		if (state.speedHistory.length > SPEED_HISTORY_SIZE) {
			state.speedHistory.shift();
		}
		// Weighted average (newer samples heavier).
		var total = 0, weight = 0;
		for (var i = 0; i < state.speedHistory.length; i++) {
			var w = i + 1;
			total += state.speedHistory[i] * w;
			weight += w;
		}
		state.scrollSpeed = Math.round(total / weight);
	}

	// Direction tracking.
	if (dy > 20) {
		var newDir = window.scrollY > lastSampleY ? 'down' : 'up';
		if (newDir !== state.scrollDirection) {
			state.scrollDirection = newDir;
			state.directionChanges++;
		}
	}

	lastSampleY = window.scrollY;
	lastSampleTime = now;

	state.scrollY = window.scrollY;
	var docHeight = document.documentElement.scrollHeight - window.innerHeight;
	state.maxScrollPct = Math.max(state.maxScrollPct, docHeight > 0 ? (window.scrollY / docHeight * 100) : 0);
	state.timeOnPage = (Date.now() - state.sessionStart) / 1000;
}

/* ================================================================
 * MODULE 4: VISITOR CLASSIFIER
 * ================================================================ */

function classifyVisitor() {
	var t = state.timeOnPage;
	var s = state.scrollSpeed;

	if (t < 5 && state.maxScrollPct < 10) {
		state.pattern = 'bouncer';
	} else if (s < SPEED_READER && t > 15) {
		state.pattern = 'reader';
	} else if (s < SPEED_SCANNER) {
		state.pattern = 'scanner';
	} else {
		state.pattern = 'fast-scanner';
	}
}

/* ================================================================
 * MODULE 5: SIZE SELECTION ENGINE
 * ================================================================ */

function selectAdSize(anchorEl) {
	var speed = state.scrollSpeed;
	var adsShown = state.totalInjected;
	var loc = anchorEl.getAttribute('data-location') || 'content';

	// Nav position — device-targeted fixed sizes.
	if (loc === 'nav') {
		if (isMobile) return { size: [320, 100], slot: 'Ad.Plus-320x100' };
		if (isTablet) return { size: [728, 90], slot: 'Ad.Plus-728x90' };
		return { size: [970, 250], slot: 'Ad.Plus-970x250' };
	}

	// Sidebar.
	if (loc === 'sidebar-top') {
		return { size: [160, 600], slot: 'Ad.Plus-160x600' };
	}
	if (loc === 'sidebar-bottom') {
		return { size: [300, 250], slot: 'Ad.Plus-300x250' };
	}

	// ─── Content ads: speed-based selection ───

	// Very slow / paused = reading carefully → highest eCPM size.
	if (speed < SPEED_READER) {
		if (adsShown % 3 === 0) return { size: [336, 280], slot: 'Ad.Plus-336x280' };
		return { size: [300, 250], slot: 'Ad.Plus-300x250' };
	}

	// Medium speed = scanning → mix of sizes.
	if (speed < SPEED_SCANNER) {
		if (adsShown % 4 === 0) return { size: [300, 600], slot: 'Ad.Plus-300x600' };
		if (adsShown % 3 === 0) return { size: [250, 250], slot: 'Ad.Plus-250x250' };
		return { size: [300, 250], slot: 'Ad.Plus-300x250' };
	}

	// Fast scrolling → tall ad that fills viewport.
	if (speed < SPEED_FAST) {
		return { size: [300, 600], slot: 'Ad.Plus-300x600' };
	}

	// Too fast → don't inject.
	return null;
}

/* ================================================================
 * MODULE 6: ENGAGEMENT GATE
 * ================================================================ */

function checkGate() {
	if (state.gateOpen) return;

	var scrollOk = state.maxScrollPct >= 3;
	var timeOk = state.timeOnPage >= 2;

	// Path A: scroll + time (normal scrolling visitor).
	if (scrollOk && timeOk) {
		openGate(false);
		return;
	}

	// Path B: time-only (engaged non-scroller — e.g. Pinterest visitor reading intro).
	// 4s on page with <1% scroll = clearly reading but not scrolling.
	if (state.timeOnPage >= 4 && state.maxScrollPct < 1) {
		openGate(true);
	}
}

function openGate(timeOnly) {
	state.gateOpen = true;
	state.gateOpenTime = Date.now();
	state.gateViaTimeOnly = timeOnly;

	// Discover all content anchors in DOM order.
	var allAnchors = document.querySelectorAll('.ad-anchor:not([data-location="nav"]):not([data-location="sidebar-top"]):not([data-location="sidebar-bottom"])');
	state.anchors = [];
	for (var i = 0; i < allAnchors.length; i++) {
		state.anchors.push(allAnchors[i]);
	}

	// Nav + sidebar already handled by initViewportAds() at 1s — no activateStaticAds() call.

	if (debug) console.log('[SmartAds] Gate OPEN via ' + (timeOnly ? 'TIME-ONLY (non-scroller)' : 'scroll+time') + '. Content anchors:', state.anchors.length);

	// Path B: immediately inject one ad at the first visible anchor.
	if (timeOnly && state.totalInjected === 0) {
		injectFirstVisibleAd();
	}
}

/**
 * Non-scroller path: find the first anchor currently visible in the viewport
 * and inject a 300x250 (highest fill rate) immediately.
 */
function injectFirstVisibleAd() {
	for (var i = 0; i < state.anchors.length; i++) {
		var anchor = state.anchors[i];
		var rect = anchor.getBoundingClientRect();
		// Anchor is in the viewport (top is below page top, above fold).
		if (rect.top > 0 && rect.top < window.innerHeight) {
			var adChoice = { size: [300, 250], slot: 'Ad.Plus-300x250' };
			state.nextAnchorIndex = i + 1;
			injectAd(anchor, adChoice);
			if (debug) console.log('[SmartAds] Non-scroller: injected 300x250 at', anchor.getAttribute('data-position'));
			return;
		}
	}
	if (debug) console.log('[SmartAds] Non-scroller: no visible anchor found in viewport');
}

/* ================================================================
 * MODULE 7: VIEWPORT ADS — Above-the-Fold, Bypass Gate
 * ================================================================ */

/**
 * Inject ads visible in the initial viewport at 1s after load.
 * These bypass the engagement gate entirely — they're above the fold
 * and will be seen regardless of scroll behavior.
 *
 * Called via setTimeout(initViewportAds, 1000) from init().
 */
function initViewportAds() {
	// Nav ad — device-targeted, always inject.
	var navAnchors = document.querySelectorAll('.ad-anchor[data-location="nav"]');
	for (var i = 0; i < navAnchors.length; i++) {
		var nav = navAnchors[i];
		if (nav.classList.contains('ad-active')) continue;
		if (window.getComputedStyle(nav).display === 'none') continue;
		var adChoice = selectAdSize(nav);
		if (adChoice) {
			injectAd(nav, adChoice);
			state.viewportAdsInjected++;
		}
	}

	// Sidebar ads — desktop only (>= 1024px).
	if (isDesktop) {
		var sidebarAnchors = document.querySelectorAll('.ad-anchor[data-location="sidebar-top"], .ad-anchor[data-location="sidebar-bottom"]');
		for (var s = 0; s < sidebarAnchors.length; s++) {
			var sb = sidebarAnchors[s];
			if (sb.classList.contains('ad-active')) continue;
			var sbChoice = selectAdSize(sb);
			if (sbChoice) {
				injectAd(sb, sbChoice);
				state.viewportAdsInjected++;
			}
		}
	}

	// First content anchor currently in viewport — inject 300x250.
	var contentAnchors = document.querySelectorAll('.ad-anchor:not([data-location="nav"]):not([data-location="sidebar-top"]):not([data-location="sidebar-bottom"])');
	for (var c = 0; c < contentAnchors.length; c++) {
		var anchor = contentAnchors[c];
		if (anchor.classList.contains('ad-active')) continue;
		var rect = anchor.getBoundingClientRect();
		if (rect.top > 0 && rect.top < window.innerHeight) {
			injectAd(anchor, { size: [300, 250], slot: 'Ad.Plus-300x250' });
			state.viewportAdsInjected++;
			break; // Only first one.
		}
	}

	if (debug) console.log('[SmartAds] Viewport ads injected:', state.viewportAdsInjected);
}

/* ================================================================
 * MODULE 8: INJECTION DECISION ENGINE (THE CORE)
 * ================================================================ */

function evaluateInjection() {
	if (!state.gateOpen) return;
	if (state.nextAnchorIndex >= state.anchors.length) {
		if (debug) console.log('[SmartAds] eval: no anchors remaining');
		return;
	}

	// Fast-scanners get zero in-content ads — 0% viewability at high speed.
	if (state.pattern === 'fast-scanner') {
		if (debug) console.log('[SmartAds] eval: SKIP — fast-scanner pattern');
		return;
	}

	var now = Date.now();

	// Condition 1: Previous ad viewability.
	if (state.injectedAds.length > 0) {
		var lastAd = state.injectedAds[state.injectedAds.length - 1];
		var timeSinceInjection = now - lastAd.injectedAt;

		if (!lastAd.viewable && timeSinceInjection < VIEWABILITY_WAIT_MS) {
			if (debug) console.log('[SmartAds] eval: SKIP — waiting viewability (' + Math.round((VIEWABILITY_WAIT_MS - timeSinceInjection) / 1000) + 's left)');
			return;
		}
	}

	// Condition 2: Minimum time between injections.
	if (now - state.lastInjectionTime < MIN_TIME_BETWEEN_ADS_MS) {
		if (debug) console.log('[SmartAds] eval: SKIP — cooldown (' + Math.round((MIN_TIME_BETWEEN_ADS_MS - (now - state.lastInjectionTime)) / 1000) + 's left)');
		return;
	}

	// Condition 3: Minimum distance.
	if (Math.abs(window.scrollY - state.lastInjectionY) < MIN_DISTANCE_PX) {
		if (debug) console.log('[SmartAds] eval: SKIP — too close (' + Math.round(Math.abs(window.scrollY - state.lastInjectionY)) + '/' + MIN_DISTANCE_PX + 'px)');
		return;
	}

	// Condition 4: Speed check.
	if (state.scrollSpeed > MAX_SPEED_FOR_INJECT) {
		if (debug) console.log('[SmartAds] eval: SKIP — speed ' + state.scrollSpeed + 'px/s > ' + MAX_SPEED_FOR_INJECT);
		return;
	}

	// Condition 5: Predictive anchor targeting.
	// Use scroll speed to predict where visitor will be in ~1s.
	// Faster scrollers get more lookahead → ad renders before they arrive.
	var lookahead = state.scrollDirection === 'up' ? 200 : Math.min(800, Math.max(200, state.scrollSpeed * 1.0));
	var predictedY = window.scrollY + window.innerHeight + lookahead;

	var targetAnchor = null;
	for (var i = state.nextAnchorIndex; i < state.anchors.length; i++) {
		var anchor = state.anchors[i];

		// Skip anchors already activated by initViewportAds or previous injection.
		if (anchor.classList.contains('ad-active')) {
			state.nextAnchorIndex = i + 1;
			continue;
		}

		var anchorY = anchor.getBoundingClientRect().top + window.scrollY;

		// Skip anchors we've already passed significantly.
		if (anchorY < window.scrollY - 100) {
			state.nextAnchorIndex = i + 1;
			continue;
		}

		// Found an anchor within predicted range.
		if (anchorY <= predictedY && anchorY >= window.scrollY - 50) {
			targetAnchor = anchor;
			state.nextAnchorIndex = i + 1;
			break;
		}

		// Anchor is beyond prediction window — not ready yet.
		if (anchorY > predictedY) break;
	}

	if (!targetAnchor) {
		if (debug) console.log('[SmartAds] eval: SKIP — no anchor in range (scrollY=' + Math.round(window.scrollY) + ', lookahead=' + Math.round(lookahead) + 'px, predictedY=' + Math.round(predictedY) + ')');
		return;
	}

	// Check for pause banner position.
	if (checkPauseBannerInjection(targetAnchor)) {
		injectPauseBanner(targetAnchor);
		return;
	}

	// Condition 6: Select size based on behavior.
	var adChoice = selectAdSize(targetAnchor);
	if (!adChoice) {
		if (debug) console.log('[SmartAds] eval: SKIP — no ad size for speed ' + state.scrollSpeed + 'px/s');
		return;
	}

	// ALL CONDITIONS MET — INJECT.
	if (debug) console.log('[SmartAds] eval: INJECT at', targetAnchor.getAttribute('data-position'), adChoice.slot);
	injectAd(targetAnchor, adChoice);
}

/* ================================================================
 * MODULE 9: AD INJECTOR
 * ================================================================ */

function injectAd(anchorEl, adChoice) {
	state.slotCounter++;
	var zoneId = 'dyn-' + state.slotCounter;

	// Create the ad container.
	var zoneEl = document.createElement('div');
	zoneEl.className = 'ad-zone ad-dynamic';
	zoneEl.id = zoneId;
	zoneEl.setAttribute('data-zone', zoneId);
	zoneEl.setAttribute('data-slot', adChoice.slot);
	zoneEl.setAttribute('data-size', adChoice.size.join(','));
	zoneEl.setAttribute('data-position', anchorEl.getAttribute('data-position') || '');
	zoneEl.setAttribute('data-speed', state.scrollSpeed);
	zoneEl.setAttribute('data-pattern', state.pattern);

	// Activate the anchor and insert ad zone.
	anchorEl.classList.add('ad-active');
	anchorEl.appendChild(zoneEl);

	// Track this injection.
	var adRecord = {
		anchor: anchorEl,
		zoneEl: zoneEl,
		zoneId: zoneId,
		slot: adChoice.slot,
		size: adChoice.size,
		position: anchorEl.getAttribute('data-position') || '',
		injectedAt: Date.now(),
		injectedAtScrollY: window.scrollY,
		injectedAtSpeed: state.scrollSpeed,
		injectedAtPattern: state.pattern,
		viewable: false,
		visibleMs: 0,
		maxRatio: 0,
		filled: false,
		isPause: false,
		refreshCount: 0
	};
	state.injectedAds.push(adRecord);
	state.totalInjected++;
	state.lastInjectionY = window.scrollY;
	state.lastInjectionTime = Date.now();

	if (cfg.dummy) {
		renderDummy(zoneEl, adChoice, anchorEl);
		adRecord.filled = true;
	} else {
		// Define GPT slot and display.
		googletag.cmd.push(function() {
			var slot = googletag.defineSlot(
				SLOT_BASE + adChoice.slot,
				[adChoice.size],
				zoneId
			);
			if (slot) {
				slot.addService(googletag.pubads());
				googletag.display(zoneId);
				zoneEl._gptSlot = slot;
			}
		});
	}

	// Start viewability tracking.
	trackAdViewability(adRecord);

	if (debug) console.log('[SmartAds] Injected:', zoneId, adChoice.slot, 'speed:', state.scrollSpeed, 'pattern:', state.pattern, 'pos:', adRecord.position);
}

/* ================================================================
 * MODULE 10: PAUSE BANNER INJECTION
 * ================================================================ */

function checkPauseBannerInjection(anchorEl) {
	if (state.pauseBannersInjected >= MAX_PAUSE_BANNERS) return false;
	var pos = anchorEl.getAttribute('data-position') || '';
	if (PAUSE_BANNER_POSITIONS.indexOf(pos) === -1) return false;
	if (state.pattern !== 'reader') return false;
	if (state.timeOnPage < 20) return false;
	return true;
}

function injectPauseBanner(anchorEl) {
	state.slotCounter++;
	var zoneId = 'pause-' + (state.pauseBannersInjected + 1);

	var zoneEl = document.createElement('div');
	zoneEl.className = 'ad-zone ad-pause ad-dynamic';
	zoneEl.id = zoneId;
	zoneEl.setAttribute('data-zone', zoneId);
	zoneEl.setAttribute('data-pause', 'true');

	anchorEl.classList.add('ad-active');
	anchorEl.appendChild(zoneEl);

	var adRecord = {
		anchor: anchorEl,
		zoneEl: zoneEl,
		zoneId: zoneId,
		slot: 'Ad.Plus-Pause-300x250',
		size: [300, 250],
		position: anchorEl.getAttribute('data-position') || '',
		injectedAt: Date.now(),
		injectedAtScrollY: window.scrollY,
		injectedAtSpeed: state.scrollSpeed,
		injectedAtPattern: state.pattern,
		viewable: false,
		visibleMs: 0,
		maxRatio: 0,
		filled: false,
		isPause: true,
		refreshCount: 0
	};

	if (cfg.dummy) {
		var dw = isMobile ? Math.min(300, window.innerWidth - 32) : 300;
		var dh = dw < 300 ? Math.round((dw / 300) * 250) : 250;
		zoneEl.style.cssText = 'width:' + dw + 'px;height:' + dh + 'px;background:rgba(147,51,234,0.15);border:2px dashed #9333ea;display:flex;align-items:center;justify-content:center;font:12px/1.3 system-ui;color:#9333ea;margin:10px auto;text-align:center';
		zoneEl.innerHTML = '<div><b>PAUSE BANNER</b><br>Ad.Plus-Pause-300x250<br>Pattern: ' + state.pattern + '</div>';
		adRecord.filled = true;
	} else {
		googletag.cmd.push(function() {
			var slot = googletag.defineSlot(
				SLOT_BASE + 'Ad.Plus-Pause-300x250',
				[[300, 250]],
				zoneId
			);
			if (slot) {
				slot.setConfig({ contentPause: true });
				slot.addService(googletag.pubads());
				googletag.display(zoneId);
				zoneEl._gptSlot = slot;
			}
		});
	}

	state.pauseBannersInjected++;
	state.totalInjected++;
	state.injectedAds.push(adRecord);
	state.lastInjectionTime = Date.now();

	trackAdViewability(adRecord);

	if (debug) console.log('[SmartAds] Pause banner injected:', zoneId);
}

/* ================================================================
 * MODULE 11: VIEWABILITY TRACKER (Per-Ad)
 * ================================================================ */

function trackAdViewability(adRecord) {
	var el = adRecord.zoneEl;
	var visibleStart = null;
	var totalVisibleMs = 0;

	if (!('IntersectionObserver' in window)) return;

	var observer = new IntersectionObserver(function(entries) {
		for (var ei = 0; ei < entries.length; ei++) {
			var entry = entries[ei];
			var ratio = entry.intersectionRatio;

			if (ratio > adRecord.maxRatio) adRecord.maxRatio = ratio;

			// Store ratio for pause-refresh to read.
			el._viewRatio = ratio;

			if (ratio >= VIEWABLE_RATIO) {
				if (!visibleStart) visibleStart = Date.now();
			} else {
				if (visibleStart) {
					totalVisibleMs += Date.now() - visibleStart;
					visibleStart = null;
				}
			}

			// Update record.
			adRecord.visibleMs = totalVisibleMs + (visibleStart ? Date.now() - visibleStart : 0);

			// Check IAB viewability: 50% visible for 1+ second.
			if (adRecord.visibleMs >= VIEWABLE_MS && !adRecord.viewable) {
				adRecord.viewable = true;
				state.totalViewable++;
				if (debug) console.log('[SmartAds] Viewable:', adRecord.zoneId, Math.round(adRecord.visibleMs) + 'ms');
			}
		}
	}, {
		threshold: [0, 0.25, 0.5, 0.75, 1.0]
	});

	observer.observe(el);
	adRecord._observer = observer;

	// Retroactive check after GPT renders.
	setTimeout(function() {
		var rect = el.getBoundingClientRect();
		var vpH = window.innerHeight;
		if (rect.top < vpH && rect.bottom > 0) {
			var visH = Math.min(rect.bottom, vpH) - Math.max(rect.top, 0);
			var elH = rect.height || 1;
			var r = visH / elH;
			if (r >= VIEWABLE_RATIO && !visibleStart) {
				visibleStart = Date.now();
				adRecord.maxRatio = Math.max(adRecord.maxRatio, r);
				el._viewRatio = r;
			}
		}
	}, 500);
}

/* ================================================================
 * MODULE 12: DUMMY MODE RENDERER
 * ================================================================ */

function renderDummy(zoneEl, adChoice, anchorEl) {
	var w = adChoice.size[0];
	var h = adChoice.size[1];
	var displayW = isMobile ? Math.min(w, window.innerWidth - 32) : w;
	var displayH = displayW < w ? Math.round((displayW / w) * h) : h;
	var pos = anchorEl.getAttribute('data-position') || '';

	zoneEl.style.cssText = 'width:' + displayW + 'px;height:' + displayH + 'px;background:rgba(59,130,246,0.15);border:2px dashed #3b82f6;display:flex;align-items:center;justify-content:center;font:12px/1.3 system-ui;color:#3b82f6;margin:10px auto;text-align:center;line-height:1.3;padding:4px';
	zoneEl.innerHTML = '<div><b>' + adChoice.slot + '</b><br>' + w + '\u00d7' + h + '<br>Speed: ' + state.scrollSpeed + 'px/s<br>Pattern: ' + state.pattern + '<br>Pos: ' + pos + '</div>';
}

/* ================================================================
 * MODULE 13: OVERLAYS — Load Independently of Gate
 * ================================================================ */

var anchorShown = false;
var topAnchorShown = false;

function initOverlays() {
	if (cfg.dummy) {
		// Dummy overlays.
		setTimeout(function() {
			if (cfg.fmtAnchor) {
				anchorShown = true;
				state.anchorFiredAt = Date.now();
				state.anchorImpressions = 1;
				setTimeout(function() { state.anchorViewableImps = 1; }, 1000);
				var anchor = document.createElement('div');
				anchor.style.cssText = 'position:fixed;bottom:0;left:0;width:100%;z-index:9999;text-align:center;background:#fff3e0;border-top:2px dashed #ff9800;padding:8px;font-family:monospace;font-size:11px;font-weight:700;color:#e65100';
				anchor.textContent = 'BOTTOM ANCHOR \u2014 Ad.Plus-Anchor (320x50)';
				document.body.appendChild(anchor);
				setInterval(function() { state.anchorImpressions++; state.anchorViewableImps++; }, 30000);
			}
			if (cfg.fmtTopAnchor) {
				topAnchorShown = true;
				state.topAnchorFiredAt = Date.now();
				var top = document.createElement('div');
				top.style.cssText = 'position:fixed;top:0;left:0;width:100%;z-index:9999;text-align:center;background:#e8f5e9;border-bottom:2px dashed #4caf50;padding:8px;font-family:monospace;font-size:11px;font-weight:700;color:#1b5e20';
				top.textContent = 'TOP ANCHOR \u2014 Ad.Plus-Anchor-Small';
				document.body.appendChild(top);
			}
		}, 1000);
		return;
	}

	googletag.cmd.push(function() {
		// Interstitial — GPT decides when to show.
		if (cfg.fmtInterstitial) {
			var interSlot = googletag.defineOutOfPageSlot(
				SLOT_BASE + 'Ad.Plus-Interstitial',
				googletag.enums.OutOfPageFormat.INTERSTITIAL
			);
			if (interSlot) {
				interSlot.addService(googletag.pubads());
				state.interstitialSlot = interSlot;
			}
		}

		googletag.pubads().enableSingleRequest();
		googletag.pubads().collapseEmptyDivs(true);
		googletag.enableServices();

		// Attach fill tracker.
		googletag.pubads().addEventListener('slotRenderEnded', onSlotRenderEnded);

		// Display interstitial after services enabled.
		if (state.interstitialSlot) {
			googletag.display(state.interstitialSlot);

			// Track interstitial show/dismiss.
			googletag.pubads().addEventListener('slotVisibilityChanged', function(event) {
				if (event.slot === state.interstitialSlot) {
					if (event.inViewPercentage > 0 && !state.interstitialShownAt) {
						state.interstitialShownAt = Date.now();
					}
					if (event.inViewPercentage === 0 && state.interstitialShownAt && !state.interstitialClosedAt) {
						state.interstitialClosedAt = Date.now();
						state.interstitialViewable = (state.interstitialClosedAt - state.interstitialShownAt >= 1000) ? 1 : 0;
					}
				}
			});
		}

		if (debug) console.log('[SmartAds] GPT ready, overlays initialized');
	});

	// Bottom Anchor — 1s delay.
	setTimeout(function() {
		if (!cfg.fmtAnchor) return;
		anchorShown = true;
		state.anchorFiredAt = Date.now();
		state.anchorImpressions = 1;
		setTimeout(function() { state.anchorViewableImps = 1; }, 1000);

		googletag.cmd.push(function() {
			var slot = googletag.defineOutOfPageSlot(
				SLOT_BASE + 'Ad.Plus-Anchor',
				googletag.enums.OutOfPageFormat.BOTTOM_ANCHOR
			);
			if (slot) {
				slot.addService(googletag.pubads());
				googletag.display(slot);
				state.anchorSlot = slot;
				setInterval(function() {
					googletag.pubads().refresh([slot]);
					state.anchorImpressions++;
					state.anchorViewableImps++;
				}, 30000);
			}
		});
		if (debug) console.log('[SmartAds] Bottom anchor shown');
	}, 1000);

	// Top Anchor — 1s delay.
	setTimeout(function() {
		if (!cfg.fmtTopAnchor) return;
		topAnchorShown = true;
		state.topAnchorFiredAt = Date.now();

		googletag.cmd.push(function() {
			var slot = googletag.defineOutOfPageSlot(
				SLOT_BASE + 'Ad.Plus-Anchor-Small',
				googletag.enums.OutOfPageFormat.TOP_ANCHOR
			);
			if (slot) {
				slot.addService(googletag.pubads());
				googletag.display(slot);
				state.topAnchorSlot = slot;
				setInterval(function() {
					googletag.pubads().refresh([slot]);
				}, 30000);
			}
		});
		if (debug) console.log('[SmartAds] Top anchor shown');
	}, 1000);
}

/* ================================================================
 * MODULE 14: AD FILL TRACKER
 * ================================================================ */

function onSlotRenderEnded(event) {
	var slot = event.slot;
	var divId = slot.getSlotElementId();

	state.totalRequested++;

	// Find matching injected ad.
	var matchedAd = null;
	for (var i = 0; i < state.injectedAds.length; i++) {
		if (state.injectedAds[i].zoneId === divId) {
			matchedAd = state.injectedAds[i];
			break;
		}
	}

	if (matchedAd) {
		if (event.isEmpty) {
			state.totalEmpty++;
			state.totalUnfilled++;
			// Collapse empty ad and stop tracking viewability.
			matchedAd.zoneEl.style.display = 'none';
			matchedAd.anchor.classList.remove('ad-active');
			matchedAd.filled = false;
			matchedAd.discarded = true;
			if (matchedAd._observer) {
				matchedAd._observer.disconnect();
				matchedAd._observer = null;
			}
			if (debug) console.log('[SmartAds] Fill: ' + divId + ' NO-FILL — collapsed, excluded from viewability');
		} else {
			state.totalFilled++;
			matchedAd.filled = true;
			if (debug) console.log('[SmartAds] Fill: ' + divId + ' FILLED — size=' + (event.size ? event.size.join('x') : 'unknown'));
		}
		return;
	}

	// Out-of-page slots.
	if (state.interstitialSlot && slot === state.interstitialSlot) {
		state.interstitialFilled = !event.isEmpty;
		if (!event.isEmpty) state.totalFilled++;
		else state.totalEmpty++;
		return;
	}
	if (state.anchorSlot && slot === state.anchorSlot) {
		if (!event.isEmpty) { state.anchorFilled = true; state.totalFilled++; }
		else state.totalEmpty++;
		return;
	}
	if (state.topAnchorSlot && slot === state.topAnchorSlot) {
		if (!event.isEmpty) { state.topAnchorFilled = true; state.totalFilled++; }
		else state.totalEmpty++;
		return;
	}
}

/* ================================================================
 * MODULE 15: DYNAMIC VIDEO INJECTION
 * ================================================================ */

function tryInjectVideo() {
	if (state.videoInjected) return;
	if (!cfg.fmtVideo) return;
	if (state.timeOnPage < 5) return;
	if (state.maxScrollPct < 5) return;

	var videoAnchor = document.querySelector('.ad-anchor[data-position="intro-after-p3"]');
	if (!videoAnchor) return;

	var rect = videoAnchor.getBoundingClientRect();
	if (rect.top > window.innerHeight + 500) return;

	state.videoInjected = true;
	videoAnchor.classList.add('ad-active');

	var videoDiv = document.createElement('div');
	videoDiv.className = 'ad-zone ad-video';
	videoDiv.setAttribute('data-zone', 'video-1');
	videoAnchor.appendChild(videoDiv);

	if (cfg.dummy) {
		videoDiv.style.cssText = 'width:300px;height:200px;background:rgba(239,68,68,0.15);border:2px dashed #ef4444;display:flex;align-items:center;justify-content:center;font:12px/1.3 system-ui;color:#ef4444;margin:10px auto;text-align:center';
		videoDiv.innerHTML = '<div><b>INPAGE VIDEO</b><br>playerPro<br>22953639975</div>';
	} else {
		var script1 = document.createElement('script');
		script1.async = true;
		script1.src = 'https://cdn.ad.plus/player/adplus.js';
		videoDiv.appendChild(script1);

		var script2 = document.createElement('script');
		script2.textContent = '(playerPro=window.playerPro||[]).push({id:"z2I717k6zq5b",after:document.querySelector(".ad-video"),appParams:{"C_NETWORK_CODE":"22953639975","C_WEBSITE":"cheerlives.com"}});';
		videoDiv.appendChild(script2);
	}

	if (debug) console.log('[SmartAds] Video injected at intro-after-p3');
}

/* ================================================================
 * MODULE 16: PAUSE-TRIGGERED AD REFRESH
 * ================================================================ */

function initPauseRefresh() {
	window.addEventListener('scroll', function() {
		clearTimeout(state.scrollPauseTimer);
		state.scrollPauseTimer = setTimeout(onScrollPause, PAUSE_THRESHOLD_MS);
	}, { passive: true });
}

function onScrollPause() {
	if (state.totalRefreshes >= MAX_REFRESHES) return;

	var bestAd = null;
	var bestRatio = 0;

	for (var i = 0; i < state.injectedAds.length; i++) {
		var ad = state.injectedAds[i];
		if (!ad.zoneEl._gptSlot || !ad.filled || ad.isPause) continue;

		var rect = ad.zoneEl.getBoundingClientRect();
		var viewH = window.innerHeight;
		var elH = rect.height || 1;
		var visibleH = Math.max(0, Math.min(viewH, rect.bottom) - Math.max(0, rect.top));
		var ratio = visibleH / elH;

		if (ratio > bestRatio && ratio >= 0.5) {
			var now = Date.now();
			if (!state.lastRefreshTime[ad.zoneId] || now - state.lastRefreshTime[ad.zoneId] >= REFRESH_COOLDOWN_MS) {
				bestRatio = ratio;
				bestAd = ad;
			}
		}
	}

	if (!bestAd) return;

	googletag.pubads().refresh([bestAd.zoneEl._gptSlot]);
	state.lastRefreshTime[bestAd.zoneId] = Date.now();
	state.totalRefreshes++;
	bestAd.refreshCount++;

	if (debug) console.log('[SmartAds] Refreshed:', bestAd.zoneId, 'total:', state.totalRefreshes);

	// Schedule next check if still paused.
	state.scrollPauseTimer = setTimeout(onScrollPause, REFRESH_COOLDOWN_MS);
}

/* ================================================================
 * MODULE 17: SESSION ANALYTICS REPORTER
 * ================================================================ */

var sessionSid = null;

function getSessionId() {
	if (sessionSid) return sessionSid;
	try {
		sessionSid = sessionStorage.getItem('pl_sid');
		if (!sessionSid) {
			sessionSid = Math.random().toString(36).substr(2, 12);
			sessionStorage.setItem('pl_sid', sessionSid);
		}
	} catch (e) {
		sessionSid = Math.random().toString(36).substr(2, 12);
	}
	return sessionSid;
}

function buildSessionReport() {
	// Finalize visible times.
	for (var i = 0; i < state.injectedAds.length; i++) {
		var ad = state.injectedAds[i];
		// Update visibleMs from the observer data.
		if (ad._observer) {
			// The observer callback tracks this in real-time via closure.
		}
	}

	var ads = [];
	for (var j = 0; j < state.injectedAds.length; j++) {
		var a = state.injectedAds[j];
		// Skip unfilled/discarded slots — they don't count toward performance.
		if (a.discarded) continue;
		ads.push({
			zoneId: a.zoneId,
			slot: a.slot,
			size: a.size.join('x'),
			position: a.position,
			speedAtInjection: a.injectedAtSpeed,
			patternAtInjection: a.injectedAtPattern,
			viewable: a.viewable,
			visibleMs: Math.round(a.visibleMs),
			maxRatio: Math.round(a.maxRatio * 100) / 100,
			filled: a.filled,
			isPause: a.isPause,
			refreshCount: a.refreshCount
		});
	}

	var b = window.__plEngagement;

	return {
		session: true,
		sid: getSessionId(),
		postId: cfg.postId,
		postSlug: cfg.postSlug,
		device: isMobile ? 'mobile' : (isTablet ? 'tablet' : 'desktop'),
		viewportW: window.innerWidth,
		viewportH: window.innerHeight,
		timeOnPage: Math.round(state.timeOnPage * 10) / 10,
		maxDepth: Math.round(state.maxScrollPct * 10) / 10,
		scrollSpeed: state.scrollSpeed,
		scrollPattern: state.pattern,
		dirChanges: state.directionChanges,
		itemsSeen: b ? b.itemsSeen : 0,
		totalItems: b ? b.totalItems : 0,
		gateOpen: state.gateOpen,

		// Ad injection stats.
		viewportAdsInjected: state.viewportAdsInjected,
		totalInjected: state.totalInjected,
		totalViewable: state.totalViewable,
		// Viewability = viewable / filled (not viewable / injected).
		// Ad.Plus sees "of the ads that filled, X% were viewable".
		viewabilityRate: state.totalFilled > 0 ? Math.round(state.totalViewable / state.totalFilled * 100) : 0,
		totalRefreshes: state.totalRefreshes,
		pauseBannersInjected: state.pauseBannersInjected,
		videoInjected: state.videoInjected,

		// Fill tracking.
		totalRequested: state.totalRequested,
		totalFilled: state.totalFilled,
		totalEmpty: state.totalEmpty,
		totalUnfilled: state.totalUnfilled,
		fillRate: state.totalRequested > 0 ? Math.round(state.totalFilled / state.totalRequested * 100) : 0,

		// Overlay status.
		anchorStatus: anchorShown ? 'firing' : 'off',
		topAnchorStatus: topAnchorShown ? 'firing' : 'off',
		interstitialStatus: state.interstitialShownAt ? 'fired' : 'off',
		anchorImpressions: state.anchorImpressions,
		anchorViewable: state.anchorViewableImps,
		anchorVisibleMs: state.anchorFiredAt ? Date.now() - state.anchorFiredAt : 0,
		anchorFilled: state.anchorFilled,
		topAnchorFilled: state.topAnchorFilled,
		interstitialFilled: state.interstitialFilled,
		interstitialViewable: state.interstitialViewable,
		interstitialDurationMs: state.interstitialClosedAt ? state.interstitialClosedAt - state.interstitialShownAt : (state.interstitialShownAt ? Date.now() - state.interstitialShownAt : 0),

		referrer: document.referrer || '',
		language: (navigator.language || '').substring(0, 5),

		// Per-ad details.
		zones: ads
	};
}

function sendData() {
	if (!cfg.record || state.dataSent) return;
	if (!state.gateOpen && state.timeOnPage < 2) return;
	state.dataSent = true;

	var payload = buildSessionReport();
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

	if (debug) console.log('[SmartAds] Session data sent', payload);
}

/* ================================================================
 * MODULE 18: LIVE MONITOR HEARTBEAT
 * ================================================================ */

var heartbeatActive = false;
var heartbeatFails = 0;

function startHeartbeat() {
	if (!cfg.liveMonitor || heartbeatActive) return;
	heartbeatActive = true;

	setInterval(function() {
		if (heartbeatFails >= 3) return;

		var payload = buildSessionReport();
		// Add heartbeat-specific fields.
		payload.postTitle = document.title.split(' - ')[0].split(' | ')[0].substring(0, 80);
		delete payload.session;

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
	}, 3000);
}

/* ================================================================
 * MODULE 19: MAIN LOOP + INITIALIZATION
 * ================================================================ */

function mainLoop() {
	sampleScrollSpeed();
	classifyVisitor();
	checkGate();
	if (state.gateOpen) {
		evaluateInjection();
		tryInjectVideo();
	}
}

function init() {
	// Load GPT (or skip in dummy mode).
	if (!cfg.dummy) {
		window.googletag = window.googletag || { cmd: [] };
		var gptScript = document.createElement('script');
		gptScript.async = true;
		gptScript.src = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
		gptScript.onerror = function() {
			if (debug) console.warn('[SmartAds] GPT failed to load — falling back to dummy');
			cfg.dummy = true;
			state.gptReady = true;
		};
		document.head.appendChild(gptScript);
	}

	// Init overlays (not gated, not scroll-driven).
	initOverlays();

	// Viewport ads — above-the-fold, bypass gate, 1s after load.
	setTimeout(initViewportAds, 1000);

	// Init pause refresh.
	initPauseRefresh();

	// Main scroll-driven loop.
	setInterval(mainLoop, SPEED_SAMPLE_MS);

	// Send data on page exit.
	document.addEventListener('visibilitychange', function() {
		if (document.visibilityState === 'hidden') sendData();
	});
	window.addEventListener('pagehide', sendData);

	// Live monitor heartbeat.
	startHeartbeat();

	if (debug) {
		console.log('[SmartAds] v5 Init', {
			dummy: cfg.dummy,
			device: isMobile ? 'mobile' : (isTablet ? 'tablet' : 'desktop'),
			networkCode: cfg.networkCode
		});
		setInterval(function() {
			console.log('[SmartAds]', {
				gate: state.gateOpen,
				injected: state.totalInjected,
				viewable: state.totalViewable,
				viewRate: state.totalFilled > 0 ? Math.round(state.totalViewable / state.totalFilled * 100) + '%' : 'n/a',
				speed: state.scrollSpeed + 'px/s',
				pattern: state.pattern,
				scroll: Math.round(state.maxScrollPct) + '%',
				time: Math.round(state.timeOnPage) + 's',
				refreshes: state.totalRefreshes,
				pauseBanners: state.pauseBannersInjected,
				video: state.videoInjected
			});
		}, 5000);
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
