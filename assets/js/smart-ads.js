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
		activateVisibleZones();
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
		state.timeOnPage = b.timeOnPage;
		state.dirChanges = b.directionChanges;
		state.scrollSpeed = b.scrollSpeed;
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

		// Re-check zones on every scroll when gate is open.
		if (state.gateOpen) {
			activateVisibleZones();
		}

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
 * MODULE 5: ZONE ACTIVATION
 *
 * Uses IntersectionObserver to activate zones as they approach
 * the viewport (200px pre-fetch). Enforces max ads, min spacing,
 * and format toggles before activating.
 * ================================================================ */

var zoneObserver = null;

function initZoneObserver() {
	if (!('IntersectionObserver' in window)) {
		// Fallback: activate visible zones on gate open.
		return;
	}

	zoneObserver = new IntersectionObserver(function(entries) {
		for (var i = 0; i < entries.length; i++) {
			if (entries[i].isIntersecting && state.gateOpen) {
				var el = entries[i].target;
				zoneObserver.unobserve(el);
				activateZone(el);
			}
		}
	}, {
		rootMargin: '200px 0px',
		threshold: 0
	});

	for (var i = 0; i < state.zones.length; i++) {
		zoneObserver.observe(state.zones[i].el);
	}
}

function activateVisibleZones() {
	if (!state.gateOpen) return;
	// Desktop: mouse-wheel scrolling is fast (1000-2000px/s). Zones may scroll
	// past the viewport before the gate opens. Use a generous look-behind so
	// zones that were recently scrolled past still get activated.
	var lookBehind = isDesktop ? -2000 : -400;
	for (var i = 0; i < state.zones.length; i++) {
		var zone = state.zones[i];
		if (zone.activated) continue;
		var rect = zone.el.getBoundingClientRect();
		if (rect.top < window.innerHeight + 400 && rect.bottom > lookBehind) {
			activateZone(zone.el);
		}
	}
}

function getZoneByEl(el) {
	for (var i = 0; i < state.zones.length; i++) {
		if (state.zones[i].el === el) return state.zones[i];
	}
	return null;
}

function activateZone(el) {
	var zone = getZoneByEl(el);
	if (!zone || zone.activated) return;

	// Enforce max ads.
	if (state.activeAds >= cfg.maxAds) {
		collapseZone(el);
		return;
	}

	// Determine size for this device.
	var size = isMobile ? zone.sizeMobile : zone.sizeDesktop;

	// Check format toggle.
	if (!isFormatEnabled(size)) {
		collapseZone(el);
		return;
	}

	// Enforce min spacing.
	if (!checkSpacing(el)) {
		collapseZone(el);
		return;
	}

	zone.activated = true;
	state.activeAds++;

	// Add CSS classes (reserves dimensions before ad loads).
	el.classList.add('pl-ad-active', 'pl-ad-' + size);

	// Load GPT then render.
	loadGPT(function() {
		renderDisplayAd(zone, size);
	});
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

function collapseZone(el) {
	el.classList.add('pl-ad-collapse');
	var zone = getZoneByEl(el);
	if (zone) zone.activated = true; // Prevent re-processing.
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

	el.style.width = displayW + 'px';
	el.style.height = displayH + 'px';
	el.style.maxWidth = '100%';
	el.innerHTML = '<span>' + size + '</span>';
	el.setAttribute('data-injected', 'true');

	if (cfg.debug) {
		addDebugInfo(el, zoneId, size, score);
		console.log('[PL-Ads] Dummy: ' + zoneId + ' (' + size + ')');
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
 * #1 revenue format ($18.52/wk). Fires immediately when engagement
 * gate opens — no additional scroll requirement. Reuses shared GPT
 * instance from Module 3 via loadGPT().
 * ================================================================ */

var anchorShown = false;

function showAnchor() {
	if (anchorShown || !cfg.fmtAnchor || !state.gateOpen) return;
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
		adSize: isMobile ? zone.sizeMobile : zone.sizeDesktop,
		totalVisibleMs: 0,
		viewableImpressions: 0,
		maxRatio: 0,
		ratioSum: 0,
		ratioCount: 0,
		timeToFirstView: 0,
		firstViewRecorded: false,
		injectedAtDepth: state.scrollPct,
		scrollSpeedAtInjection: state.scrollSpeed,
		visibleStart: 0,
		isVisible: false,
		viewableTimer: null
	};
	state.viewability[zone.id] = data;

	if (!('IntersectionObserver' in window)) return;

	var observer = new IntersectionObserver(function(entries) {
		var entry = entries[0];
		var ratio = entry.intersectionRatio;

		data.ratioSum += ratio;
		data.ratioCount++;
		if (ratio > data.maxRatio) data.maxRatio = ratio;

		if (ratio >= 0.5) {
			if (!data.isVisible) {
				data.isVisible = true;
				data.visibleStart = Date.now();

				if (!data.firstViewRecorded) {
					data.firstViewRecorded = true;
					data.timeToFirstView = Date.now() - state.sessionStart;
				}

				// 1-second continuous visibility = viewable impression.
				data.viewableTimer = setTimeout(function() {
					data.viewableImpressions++;
				}, 1000);
			}
		} else {
			if (data.isVisible) {
				data.totalVisibleMs += Date.now() - data.visibleStart;
				data.isVisible = false;
				clearTimeout(data.viewableTimer);
			}
		}
	}, {
		threshold: [0, 0.25, 0.5, 0.75, 1.0]
	});

	observer.observe(zone.el);
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
		zoneDetail: zoneDetail
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
