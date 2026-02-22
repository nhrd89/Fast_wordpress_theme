/**
 * PinLightning Smart Ad Engine — v4 Full Monetization
 *
 * Handles 30+ ad zones per page: display, pause banners (contentPause),
 * inpage video, triple overlay (interstitial, bottom anchor, top anchor),
 * and pause-triggered display refresh.
 *
 * Zone HTML format: <div class="ad-zone" id="{id}" data-zone="{id}"
 *   data-slot="{Ad.Plus-Size}" data-size="{w,h}" [data-pause="true"]>
 *
 * Zero TBT: IntersectionObserver + requestIdleCallback + passive listeners.
 *
 * @package PinLightning
 * @since   2.0.0
 */
;(function() {
'use strict';

/* ================================================================
 * MODULE 1: CONFIG
 * ================================================================ */

var cfg = window.plAds || {};

// wp_localize_script converts ALL values to strings. Parse numerics now.
cfg.maxAds = parseInt(cfg.maxAds, 10) || 35;
cfg.gateScrollPct = parseInt(cfg.gateScrollPct, 10) || 10;
cfg.gateTimeSec = parseInt(cfg.gateTimeSec, 10) || 3;
cfg.gateDirChanges = parseInt(cfg.gateDirChanges, 10) || 0;
cfg.minSpacingPx = parseInt(cfg.minSpacingPx, 10) || 200;
cfg.pauseMinAds = parseInt(cfg.pauseMinAds, 10) || 2;
cfg.passbackEnabled = cfg.passbackEnabled === '1' || cfg.passbackEnabled === true;
cfg.backfillScriptUrl = cfg.backfillScriptUrl || '';
cfg.backfillDisplayTags = cfg.backfillDisplayTags || [];
cfg.backfillAnchorTag = cfg.backfillAnchorTag || '';
cfg.backfillInterstitialTag = cfg.backfillInterstitialTag || '';
cfg.backfillCheckDelay = parseInt(cfg.backfillCheckDelay, 10) || 3000;

// Bail if not configured (ad engine disabled or missing config).
if (!cfg.networkCode && !cfg.dummy) return;

// Bot detection — exit before loading any ad code.
if (navigator.webdriver) return;
if (/bot|crawl|spider|slurp|googlebot|bingbot|baiduspider|yandexbot|duckduckbot|sogou|exabot|ia_archiver|facebot|facebookexternalhit|ahrefsbot|semrushbot|mj12bot|dotbot|petalbot|applebot|dataforseobot|bytespider|gptbot|claudebot|ccbot|amazonbot|anthropic|headlesschrome|phantomjs|slimerjs|lighthouse|pagespeed|pingdom|uptimerobot|wget|curl|python-requests|go-http-client|java\/|libwww/i.test(navigator.userAgent)) return;
if (window.innerWidth === 0 && window.innerHeight === 0) return;

var SLOT_BASE = cfg.slotPrefix || '/21849154601,22953639975/';

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
	zonesActivated: 0,
	viewableCount: 0,
	scrollPct: 0,
	timeOnPage: 0,
	dirChanges: 0,
	lastScrollY: 0,
	lastScrollDir: 0,
	scrollSpeed: 0,
	sessionStart: Date.now(),
	dataSent: false,

	// Overlay state.
	anchorFiredAt: 0,
	anchorImpressions: 0,
	anchorViewableImps: 0,
	interstitialShownAt: 0,
	interstitialClosedAt: 0,
	interstitialViewable: 0,
	topAnchorFiredAt: 0,

	// Ad fill tracking.
	adFills: {},
	slotDivToZone: {},
	totalRequested: 0,
	totalFilled: 0,
	totalEmpty: 0,
	anchorSlotRef: null,
	topAnchorSlotRef: null,
	anchorFilled: false,
	topAnchorFilled: false,
	interstitialFilled: false,

	// Ad click tracking.
	adClicks: {},
	totalDisplayClicks: 0,
	anchorClicks: 0,
	interstitialClicks: 0,
	interstitialDismissed: 0,
	pauseClicks: 0,

	// Pause banner tracking (GPT contentPause zones).
	pauseBannersShown: 0,
	pauseBannersContinued: 0,

	// Refresh tracking.
	refreshCount: 0,
	refreshImpressions: 0,
	lastRefreshTime: {},

	// Newor Media (Waldo) passback state.
	waldoLoaded: false,
	waldoLoading: false,
	waldoTagPool: cfg.backfillDisplayTags,
	waldoTagIndex: 0,
	waldoAnchorTag: cfg.backfillAnchorTag,
	waldoFills: {},
	waldoTotalRequested: 0,
	waldoTotalFilled: 0,

	// Viewability per zone.
	viewability: {},
	zones: [],
	slots: {}
};

/* ================================================================
 * MODULE 2: ENGAGEMENT GATE
 *
 * Only gates IN-CONTENT display zones. Overlays, nav ads, and
 * sidebar ads load independently.
 *
 * Relaxed thresholds for v4:
 * - Scroll: 10% (was 15%)
 * - Time: 3s (was 5s)
 * - Direction changes: 0 (was 1)
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
		return true;
	}
	return false;
}

function readBridge() {
	var b = window.__plEngagement;
	if (b) {
		state.scrollPct = b.scrollDepth;
		state.dirChanges = b.directionChanges;
		state.scrollSpeed = b.scrollSpeed;
		state.timeOnPage = (Date.now() - state.sessionStart) / 1000;
		return true;
	}
	return false;
}

var scrollTimer = null;

function onScroll() {
	if (scrollTimer) return;
	scrollTimer = setTimeout(function() {
		scrollTimer = null;

		if (!readBridge()) {
			var y = window.scrollY || window.pageYOffset;
			var docHeight = document.documentElement.scrollHeight - window.innerHeight;
			var pct = docHeight > 0 ? (y / docHeight) * 100 : 0;
			state.scrollPct = pct;

			var dir = y > state.lastScrollY ? 1 : (y < state.lastScrollY ? -1 : 0);
			if (dir !== 0 && dir !== state.lastScrollDir) {
				if (state.lastScrollDir !== 0) state.dirChanges++;
				state.lastScrollDir = dir;
			}

			state.scrollSpeed = Math.abs(y - state.lastScrollY) / 0.2;
			state.lastScrollY = y;
		}

		if (!gateChecks.scroll && state.scrollPct >= cfg.gateScrollPct) {
			gateChecks.scroll = true;
			if (cfg.debug) console.log('[PL-Ads] Gate: scroll ' + Math.round(state.scrollPct) + '%');
		}
		if (!gateChecks.direction && state.dirChanges >= cfg.gateDirChanges) {
			gateChecks.direction = true;
			if (cfg.debug) console.log('[PL-Ads] Gate: directions ' + state.dirChanges);
		}

		checkGate();
	}, 200);
}

function startTimeTracking() {
	var interval = setInterval(function() {
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
 * ================================================================ */

var gptCallbacks = [];
var interstitialSlot = null;

function loadGPT(callback) {
	if (callback) gptCallbacks.push(callback);

	if (state.gptReady) {
		flushGptCallbacks();
		return;
	}

	if (state.gptLoaded) return;
	state.gptLoaded = true;

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
		// Define interstitial at GPT init (MUST be before enableServices).
		if (cfg.fmtInterstitial) {
			var interSlot = googletag.defineOutOfPageSlot(
				SLOT_BASE + 'Ad.Plus-Interstitial',
				googletag.enums.OutOfPageFormat.INTERSTITIAL
			);
			if (interSlot) {
				interstitialSlot = interSlot;
				interSlot.addService(googletag.pubads());
				if (cfg.debug) console.log('[PL-Ads] Interstitial defined at GPT init');
			}
		}

		googletag.pubads().enableSingleRequest();
		googletag.pubads().collapseEmptyDivs(true);
		googletag.enableServices();
		googletag.pubads().addEventListener('slotRenderEnded', onSlotRenderEnded);

		// Display interstitial after services enabled — GPT triggers natively.
		if (interstitialSlot) {
			googletag.display(interstitialSlot);

			// Track when GPT shows/dismisses the interstitial.
			googletag.pubads().addEventListener('slotVisibilityChanged', function(event) {
				if (event.slot === interstitialSlot) {
					if (event.inViewPercentage > 0 && !state.interstitialShownAt) {
						state.interstitialShownAt = Date.now();
						if (cfg.debug) console.log('[PL-Ads] Interstitial shown by GPT');
					}
					if (event.inViewPercentage === 0 && state.interstitialShownAt && !state.interstitialClosedAt) {
						state.interstitialClosedAt = Date.now();
						state.interstitialViewable = (state.interstitialClosedAt - state.interstitialShownAt >= 1000) ? 1 : 0;
						if (cfg.debug) console.log('[PL-Ads] Interstitial dismissed: ' + (state.interstitialClosedAt - state.interstitialShownAt) + 'ms');
					}
				}
			});
		}

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
 * NEWOR MEDIA (WALDO) PASSBACK LOADER
 * ================================================================ */

var waldoCallbacks = [];

function loadWaldoScript(callback) {
	if (callback) waldoCallbacks.push(callback);

	if (state.waldoLoaded) {
		while (waldoCallbacks.length) waldoCallbacks.shift()();
		return;
	}
	if (state.waldoLoading) return;

	if (!cfg.backfillScriptUrl) {
		while (waldoCallbacks.length) waldoCallbacks.shift()();
		return;
	}

	state.waldoLoading = true;

	var script = document.createElement('script');
	script.src = cfg.backfillScriptUrl;
	script.async = true;
	script.onload = function() {
		state.waldoLoaded = true;
		if (cfg.debug) console.log('[PL-Ads] Backfill script loaded');
		while (waldoCallbacks.length) waldoCallbacks.shift()();
	};
	script.onerror = function() {
		state.waldoLoading = false;
		while (waldoCallbacks.length) waldoCallbacks.shift()();
	};
	document.head.appendChild(script);
}

/* ================================================================
 * MODULE 4: v4 ZONE DISCOVERY
 *
 * Reads data-zone, data-slot, data-size from zone divs.
 * Three types: display (.ad-zone), pause (.ad-pause), video (.ad-video).
 * ================================================================ */

function discoverZones() {
	var els = document.querySelectorAll('.ad-zone[data-zone]');
	state.zones = [];
	for (var i = 0; i < els.length; i++) {
		var el = els[i];
		// Skip video zones — they use their own playerPro script.
		if (el.classList.contains('ad-video')) continue;

		state.zones.push({
			el: el,
			id: el.getAttribute('data-zone'),
			slot: el.getAttribute('data-slot') || 'Ad.Plus-300x250',
			size: el.getAttribute('data-size') || '300,250',
			isPause: el.getAttribute('data-pause') === 'true',
			isSidebar: el.classList.contains('sidebar-ad'),
			isNav: el.id && el.id.indexOf('nav-') === 0,
			activated: false,
			injected: false,
			gptSlot: null
		});
	}
	if (cfg.debug) console.log('[PL-Ads] Discovered ' + state.zones.length + ' zones (excl video)');
}

/* ================================================================
 * MODULE 5: v4 ZONE ACTIVATION
 *
 * Strategy change from v3: No speed gating, no viewability budget.
 * v4 monetizes the FULL article. All zones activate when:
 *   - Gate is open (for in-content zones)
 *   - OR zone is nav/sidebar (loads independently)
 *   - Zone enters viewport via IntersectionObserver
 * ================================================================ */

var zoneObserver = null;

function initZoneObserver() {
	if (!('IntersectionObserver' in window)) return;

	zoneObserver = new IntersectionObserver(function(entries) {
		for (var i = 0; i < entries.length; i++) {
			if (!entries[i].isIntersecting) continue;
			var el = entries[i].target;
			var zone = getZoneByEl(el);
			if (!zone) continue;

			// Nav and sidebar zones load without gate.
			if (zone.isNav || zone.isSidebar) {
				zoneObserver.unobserve(el);
				activateZone(zone);
				continue;
			}

			// Content zones require gate.
			if (!state.gateOpen) continue;
			zoneObserver.unobserve(el);
			activateZone(zone);
		}
	}, {
		rootMargin: '0px 0px 400px 0px',
		threshold: 0
	});

	for (var i = 0; i < state.zones.length; i++) {
		zoneObserver.observe(state.zones[i].el);
	}
}

function onGateOpen() {
	// Activate content zones currently in or below viewport.
	for (var i = 0; i < state.zones.length; i++) {
		var zone = state.zones[i];
		if (zone.activated) continue;
		if (zone.isNav || zone.isSidebar) continue; // Already handled.

		var rect = zone.el.getBoundingClientRect();
		if (rect.bottom > 0 && rect.top < window.innerHeight + 400) {
			if (zoneObserver) zoneObserver.unobserve(zone.el);
			activateZone(zone);
		}
	}

	// Start pause-triggered refresh.
	initPauseRefresh();
}

function activateZone(zone) {
	if (zone.activated) return;
	if (state.activeAds >= cfg.maxAds) {
		if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' SKIP: maxAds (' + state.activeAds + '/' + cfg.maxAds + ')');
		return;
	}

	zone.activated = true;
	state.activeAds++;
	state.zonesActivated++;

	var dims = zone.size.split(',').map(Number);
	var w = dims[0] || 300;
	var h = dims[1] || 250;
	var sizeStr = w + 'x' + h;

	if (cfg.debug) console.log('[PL-Ads] Zone ' + zone.id + ' ACTIVATED (' + zone.slot + ' ' + sizeStr + (zone.isPause ? ' PAUSE' : '') + ') — total: ' + state.activeAds);

	loadGPT(function() {
		renderZone(zone, w, h, sizeStr);
	});
}

function renderZone(zone, w, h, sizeStr) {
	if (zone.injected) return;
	zone.injected = true;

	var el = zone.el;

	if (cfg.dummy) {
		renderDummy(el, sizeStr, w, h, zone.id, zone.isPause);
		trackViewability(zone, sizeStr);
		return;
	}

	// Create GPT div inside zone.
	var divId = zone.id;
	state.slotDivToZone[divId] = zone.id;

	var adDiv = document.createElement('div');
	adDiv.id = divId;
	adDiv.style.width = w + 'px';
	adDiv.style.height = h + 'px';
	adDiv.style.margin = '0 auto';
	el.appendChild(adDiv);

	var slotPath = SLOT_BASE + zone.slot;

	googletag.cmd.push(function() {
		var slot = googletag.defineSlot(slotPath, [w, h], divId);
		if (slot) {
			if (zone.isPause) {
				slot.setConfig({ contentPause: true });
			}
			slot.addService(googletag.pubads());
			googletag.display(divId);
			zone.gptSlot = slot;
			state.slots[zone.id] = slot;
			if (cfg.debug) console.log('[PL-Ads] Slot: ' + slotPath + ' (' + sizeStr + ')' + (zone.isPause ? ' [contentPause]' : ''));
		}
	});

	el.setAttribute('data-activated', 'true');
	trackViewability(zone, sizeStr);
}

function renderDummy(el, sizeStr, w, h, zoneId, isPause) {
	var displayW = isMobile ? Math.min(w, window.innerWidth - 32) : w;
	var displayH = displayW < w ? Math.round((displayW / w) * h) : h;
	var bg = isPause ? '#fce4ec' : '#e8eaf6';
	var label = zoneId + ' (' + sizeStr + ')' + (isPause ? ' PAUSE' : '');

	el.style.cssText = 'width:' + displayW + 'px;height:' + displayH + 'px;max-width:100%;background:' + bg + ';border:2px dashed rgba(0,0,0,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:monospace;font-size:10px;font-weight:700;color:rgba(0,0,0,.4);margin:8px auto;text-align:center;line-height:1.3;padding:4px';
	el.textContent = label;
	el.setAttribute('data-activated', 'true');
}

function getZoneByEl(el) {
	for (var i = 0; i < state.zones.length; i++) {
		if (state.zones[i].el === el) return state.zones[i];
	}
	return null;
}

function getZoneById(id) {
	for (var i = 0; i < state.zones.length; i++) {
		if (state.zones[i].id === id) return state.zones[i];
	}
	return null;
}

/* ================================================================
 * AD FILL TRACKER — slotRenderEnded
 * ================================================================ */

function onSlotRenderEnded(event) {
	var slot = event.slot;
	var divId = slot.getSlotElementId();
	var zoneId = state.slotDivToZone[divId];

	state.totalRequested++;

	if (zoneId) {
		var fillData = {
			filled: !event.isEmpty,
			size: event.isEmpty ? null : (event.size ? event.size.join('x') : null),
			advertiserId: event.advertiserId || null
		};
		state.adFills[zoneId] = fillData;

		var zone = getZoneById(zoneId);

		if (event.isEmpty) {
			state.totalEmpty++;
			if (cfg.debug) console.log('[PL-Ads] Fill: ' + zoneId + ' NO-FILL — collapsing');
			collapseEmptyZone(zoneId);
		} else {
			state.totalFilled++;
			if (cfg.debug) console.log('[PL-Ads] Fill: ' + zoneId + ' FILLED — size=' + fillData.size);

			// Track pause banner shown.
			if (zone && zone.isPause) {
				state.pauseBannersShown++;
			}

			// Re-attach viewability observer after GPT render.
			var vData = state.viewability[zoneId];
			if (vData && vData.observer) {
				var oldEl = vData.observedEl;
				vData.observer.unobserve(oldEl);
				vData.totalVisibleMs = 0;
				vData.viewableImpressions = 0;
				vData.maxRatio = 0;
				vData.isVisible = false;
				vData.visibleStart = 0;
				clearTimeout(vData.viewableTimer);

				(function(vd, did) {
					setTimeout(function() {
						var adEl = document.getElementById(did);
						var target = adEl ? (adEl.querySelector('iframe') || adEl) : oldEl;
						vd.observer.observe(target);
						vd.observedEl = target;
					}, 200);
				})(vData, divId);
			}
		}
		return;
	}

	// Out-of-page slots.
	if (interstitialSlot && slot === interstitialSlot) {
		state.interstitialFilled = !event.isEmpty;
		if (!event.isEmpty) state.totalFilled++;
		else state.totalEmpty++;
		if (cfg.debug) console.log('[PL-Ads] Fill: interstitial ' + (event.isEmpty ? 'NO-FILL' : 'FILLED'));
		return;
	}
	if (state.anchorSlotRef && slot === state.anchorSlotRef) {
		if (!event.isEmpty) { state.anchorFilled = true; state.totalFilled++; }
		else {
			state.totalEmpty++;
			if (cfg.passbackEnabled) tryWaldoAnchorPassback();
		}
		if (cfg.debug) console.log('[PL-Ads] Fill: bottom anchor ' + (event.isEmpty ? 'NO-FILL' : 'FILLED'));
		return;
	}
	if (state.topAnchorSlotRef && slot === state.topAnchorSlotRef) {
		if (!event.isEmpty) { state.topAnchorFilled = true; state.totalFilled++; }
		else state.totalEmpty++;
		if (cfg.debug) console.log('[PL-Ads] Fill: top anchor ' + (event.isEmpty ? 'NO-FILL' : 'FILLED'));
		return;
	}

	// Unknown slots — do NOT destroy (top anchor or other legitimate formats).
	if (cfg.debug) console.log('[PL-Ads] Fill: unknown slot ' + divId + ' — ' + (event.isEmpty ? 'NO-FILL' : 'FILLED'));
}

function collapseEmptyZone(zoneId) {
	var zone = getZoneById(zoneId);
	if (!zone) return;

	// Try Waldo passback before collapsing.
	if (cfg.passbackEnabled && cfg.backfillScriptUrl && state.waldoTagIndex < state.waldoTagPool.length) {
		tryWaldoPassback(zone);
		return;
	}
	doCollapse(zone);
}

function tryWaldoPassback(zone) {
	var tagId = state.waldoTagPool[state.waldoTagIndex++];
	state.waldoTotalRequested++;
	state.waldoFills[zone.id] = { tag: tagId, filled: false, network: 'newor' };

	if (cfg.debug) console.log('[PL-Ads] Passback: trying Waldo ' + tagId + ' for ' + zone.id);

	var gptDiv = zone.el.querySelector('[id]');
	if (gptDiv) gptDiv.style.display = 'none';

	var waldoDiv = document.createElement('div');
	waldoDiv.id = tagId;
	zone.el.appendChild(waldoDiv);

	loadWaldoScript(function() {
		setTimeout(function() {
			var hasContent = waldoDiv.querySelector('iframe') ||
				waldoDiv.querySelector('ins') ||
				waldoDiv.querySelector('img') ||
				waldoDiv.offsetHeight > 10;

			if (hasContent) {
				state.waldoTotalFilled++;
				state.waldoFills[zone.id].filled = true;
				if (cfg.debug) console.log('[PL-Ads] Passback: Waldo FILLED ' + zone.id);
			} else {
				waldoDiv.style.display = 'none';
				doCollapse(zone);
			}
		}, cfg.backfillCheckDelay);
	});
}

function doCollapse(zone) {
	zone.el.style.cssText = 'height:0;overflow:hidden;margin:0;padding:0';
	if (state.activeAds > 0) state.activeAds--;
	if (cfg.debug) console.log('[PL-Ads] Collapsed ' + zone.id);
}

function tryWaldoAnchorPassback() {
	if (!cfg.backfillScriptUrl || !state.waldoAnchorTag) return;

	state.waldoTotalRequested++;
	state.waldoFills['anchor'] = { tag: state.waldoAnchorTag, filled: false, network: 'newor' };

	loadWaldoScript(function() {
		var container = document.createElement('div');
		container.style.cssText = 'position:fixed;bottom:0;left:0;width:100%;z-index:9998;text-align:center;background:rgba(255,255,255,.95)';
		var waldoDiv = document.createElement('div');
		waldoDiv.id = state.waldoAnchorTag;
		waldoDiv.style.cssText = 'display:inline-block;margin:0 auto';
		container.appendChild(waldoDiv);
		document.body.appendChild(container);

		setTimeout(function() {
			var hasContent = waldoDiv.querySelector('iframe') ||
				waldoDiv.querySelector('ins') ||
				waldoDiv.offsetHeight > 10;
			if (hasContent) {
				state.waldoTotalFilled++;
				state.waldoFills['anchor'].filled = true;
				state.anchorFilled = true;
			} else {
				container.remove();
			}
		}, cfg.backfillCheckDelay);
	});
}

/* ================================================================
 * MODULE 6: OVERLAYS — Load Independently of Gate
 *
 * H1: Interstitial — defined at GPT init (above), GPT triggers natively
 * H2: Bottom Anchor — 2s delay, 30s refresh
 * H3: Top Anchor — 2s delay, 30s refresh
 * ================================================================ */

var anchorShown = false;
var topAnchorShown = false;

function showBottomAnchor() {
	if (anchorShown || !cfg.fmtAnchor) return;
	anchorShown = true;

	loadGPT(function() {
		state.anchorFiredAt = Date.now();
		state.anchorImpressions = 1;
		setTimeout(function() { state.anchorViewableImps = 1; }, 1000);

		if (cfg.dummy) {
			var anchor = document.createElement('div');
			anchor.style.cssText = 'position:fixed;bottom:0;left:0;width:100%;z-index:9999;text-align:center;background:#fff3e0;border-top:2px dashed #ff9800;padding:8px;font-family:monospace;font-size:11px;font-weight:700;color:#e65100';
			anchor.textContent = 'BOTTOM ANCHOR — Ad.Plus-Anchor (320x50)';
			document.body.appendChild(anchor);
			setInterval(function() { state.anchorImpressions++; state.anchorViewableImps++; }, 30000);
		} else {
			googletag.cmd.push(function() {
				var slot = googletag.defineOutOfPageSlot(
					SLOT_BASE + 'Ad.Plus-Anchor',
					googletag.enums.OutOfPageFormat.BOTTOM_ANCHOR
				);
				if (slot) {
					state.anchorSlotRef = slot;
					slot.addService(googletag.pubads());
					googletag.display(slot);
					if (cfg.debug) console.log('[PL-Ads] Bottom anchor defined');

					setInterval(function() {
						googletag.pubads().refresh([slot]);
						state.anchorImpressions++;
						state.anchorViewableImps++;
					}, 30000);
				}
			});
		}
		if (cfg.debug) console.log('[PL-Ads] Bottom anchor shown');
	});
}

function showTopAnchor() {
	if (topAnchorShown) return;
	topAnchorShown = true;

	loadGPT(function() {
		state.topAnchorFiredAt = Date.now();

		if (cfg.dummy) {
			var anchor = document.createElement('div');
			anchor.style.cssText = 'position:fixed;top:0;left:0;width:100%;z-index:9999;text-align:center;background:#e8f5e9;border-bottom:2px dashed #4caf50;padding:8px;font-family:monospace;font-size:11px;font-weight:700;color:#1b5e20';
			anchor.textContent = 'TOP ANCHOR — Ad.Plus-Anchor-Small';
			document.body.appendChild(anchor);
		} else {
			googletag.cmd.push(function() {
				var slot = googletag.defineOutOfPageSlot(
					SLOT_BASE + 'Ad.Plus-Anchor-Small',
					googletag.enums.OutOfPageFormat.TOP_ANCHOR
				);
				if (slot) {
					state.topAnchorSlotRef = slot;
					slot.addService(googletag.pubads());
					googletag.display(slot);
					if (cfg.debug) console.log('[PL-Ads] Top anchor defined');

					setInterval(function() {
						googletag.pubads().refresh([slot]);
					}, 30000);
				}
			});
		}
		if (cfg.debug) console.log('[PL-Ads] Top anchor shown');
	});
}

// Fire overlays after 2s delay (NOT gated).
function initOverlays() {
	setTimeout(function() {
		showBottomAnchor();
		showTopAnchor();
	}, 2000);
}

/* ================================================================
 * MODULE 7: VIEWABILITY TRACKER
 *
 * IAB standard: 50% of pixels visible for 1 continuous second.
 * ================================================================ */

function trackViewability(zone, sizeStr) {
	var data = {
		zoneId: zone.id,
		adSize: sizeStr,
		totalVisibleMs: 0,
		viewableImpressions: 0,
		maxRatio: 0,
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
		for (var ei = 0; ei < entries.length; ei++) {
			var entry = entries[ei];
			var ratio = entry.intersectionRatio;

			if (ratio > data.maxRatio) data.maxRatio = ratio;

			// Store current ratio on element for pause-refresh to read.
			zone.el._viewRatio = ratio;

			if (ratio >= 0.5) {
				if (!data.isVisible) {
					data.isVisible = true;
					data.visibleStart = Date.now();

					if (!data.firstViewRecorded) {
						data.firstViewRecorded = true;
						data.timeToFirstView = Date.now() - state.sessionStart;
					}

					data.viewableTimer = setTimeout(function() {
						data.viewableImpressions++;
						if (data.viewableImpressions === 1) {
							state.viewableCount++;
						}
						if (!zone.el._viewLogged) {
							zone.el._viewLogged = true;
						}
					}, 1000);
				}
			} else {
				if (data.isVisible) {
					data.totalVisibleMs += Date.now() - data.visibleStart;
					data.isVisible = false;
					clearTimeout(data.viewableTimer);
				}
			}
		}
	}, {
		threshold: [0, 0.25, 0.5, 0.75, 1.0]
	});

	data.observer = observer;
	data.observedEl = zone.el;
	observer.observe(zone.el);
}

/* ================================================================
 * MODULE 8: PAUSE-TRIGGERED DISPLAY REFRESH
 *
 * When user pauses scrolling for 5+ seconds, refresh the most
 * viewable display ad. 30s cooldown per zone, 20 max per session.
 * ================================================================ */

var PAUSE_THRESHOLD = 5000;
var REFRESH_COOLDOWN = 30000;
var MAX_REFRESHES = 20;
var scrollPauseTimer = null;

function initPauseRefresh() {
	window.addEventListener('scroll', function() {
		clearTimeout(scrollPauseTimer);
		scrollPauseTimer = setTimeout(onPauseRefresh, PAUSE_THRESHOLD);
	}, { passive: true });
}

function onPauseRefresh() {
	if (state.refreshCount >= MAX_REFRESHES) return;

	var best = null;
	var bestRatio = 0;
	for (var i = 0; i < state.zones.length; i++) {
		var z = state.zones[i];
		if (!z.activated || !z.injected || z.isPause || !z.gptSlot) continue;
		var ratio = z.el._viewRatio || 0;
		if (ratio >= 0.5 && ratio > bestRatio) {
			bestRatio = ratio;
			best = z;
		}
	}

	if (!best) return;

	var now = Date.now();
	if (state.lastRefreshTime[best.id] && now - state.lastRefreshTime[best.id] < REFRESH_COOLDOWN) return;

	googletag.pubads().refresh([best.gptSlot]);
	state.lastRefreshTime[best.id] = now;
	state.refreshCount++;
	state.refreshImpressions++;
	if (cfg.debug) console.log('[PL-Ads] Refresh #' + state.refreshCount + ': ' + best.id + ' (viewRatio=' + Math.round(bestRatio * 100) + '%)');

	scrollPauseTimer = setTimeout(onPauseRefresh, REFRESH_COOLDOWN);
}

/* ================================================================
 * MODULE 9: DATA RECORDER
 * ================================================================ */

var sessionId = Math.random().toString(36).substring(2, 10) + Date.now().toString(36).slice(-4);

function buildSessionData() {
	var zones = [];
	var totalViewable = 0;

	for (var zid in state.viewability) {
		if (!state.viewability.hasOwnProperty(zid)) continue;
		var d = state.viewability[zid];

		if (d.isVisible) {
			d.totalVisibleMs += Date.now() - d.visibleStart;
			d.isVisible = false;
			clearTimeout(d.viewableTimer);
		}

		totalViewable += d.viewableImpressions;

		var fill = state.adFills[d.zoneId];
		zones.push({
			zoneId: d.zoneId,
			adSize: d.adSize,
			totalVisibleMs: d.totalVisibleMs,
			viewableImpressions: d.viewableImpressions,
			maxRatio: Math.round(d.maxRatio * 100) / 100,
			timeToFirstView: d.timeToFirstView,
			injectedAtDepth: Math.round(d.injectedAtDepth * 10) / 10,
			scrollSpeedAtInjection: Math.round(d.scrollSpeedAtInjection),
			filled: fill ? fill.filled : null,
			fillSize: fill ? fill.size : null,
			clicks: state.adClicks[d.zoneId] ? state.adClicks[d.zoneId].count : 0,
			passback: state.waldoFills[d.zoneId] ? state.waldoFills[d.zoneId].filled : false,
			passbackNetwork: state.waldoFills[d.zoneId] ? state.waldoFills[d.zoneId].network : null
		});
	}

	var b = window.__plEngagement;

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
		zonesActivated: state.zonesActivated,
		totalViewable: totalViewable,
		viewabilityRate: state.activeAds > 0 ? Math.round((totalViewable / state.activeAds) * 100) / 100 : 0,
		viewableZones: state.viewableCount,
		anchorStatus: anchorShown ? 'firing' : 'off',
		topAnchorStatus: topAnchorShown ? 'firing' : 'off',
		interstitialStatus: state.interstitialShownAt ? 'fired' : 'off',
		pauseStatus: state.pauseBannersShown > 0 ? 'fired' : 'off',
		// Overlay viewability.
		anchorImpressions: state.anchorImpressions,
		anchorViewable: state.anchorViewableImps,
		anchorVisibleMs: state.anchorFiredAt ? Date.now() - state.anchorFiredAt : 0,
		interstitialViewable: state.interstitialViewable,
		interstitialDurationMs: state.interstitialClosedAt ? state.interstitialClosedAt - state.interstitialShownAt : (state.interstitialShownAt ? Date.now() - state.interstitialShownAt : 0),
		// Fill tracking.
		totalRequested: state.totalRequested,
		totalFilled: state.totalFilled,
		totalEmpty: state.totalEmpty,
		fillRate: state.totalRequested > 0 ? Math.round((state.totalFilled / state.totalRequested) * 100) : 0,
		anchorFilled: state.anchorFilled,
		topAnchorFilled: state.topAnchorFilled,
		interstitialFilled: state.interstitialFilled,
		referrer: document.referrer || '',
		language: (navigator.language || '').substring(0, 5),
		// Clicks.
		totalDisplayClicks: state.totalDisplayClicks,
		anchorClicks: state.anchorClicks,
		interstitialClicks: state.interstitialClicks,
		interstitialDismissed: state.interstitialDismissed,
		pauseClicks: state.pauseClicks,
		// Pause banners.
		pauseBannersShown: state.pauseBannersShown,
		pauseBannersContinued: state.pauseBannersContinued,
		// Refresh.
		refreshCount: state.refreshCount,
		refreshImpressions: state.refreshImpressions,
		// Waldo passback.
		waldoRequested: state.waldoTotalRequested,
		waldoFilled: state.waldoTotalFilled,
		waldoFills: state.waldoFills,
		zones: zones
	};
}

function classifyPattern() {
	var b = window.__plEngagement;
	if (b && b.pattern) return b.pattern;
	var t = (Date.now() - state.sessionStart) / 1000;
	if (t < 10 && state.scrollPct < 30) return 'bouncer';
	if (state.dirChanges > 5 && t > 30) return 'reader';
	return 'scanner';
}

function sendData() {
	if (!cfg.record || state.dataSent) return;
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
 * MODULE 10: LIVE MONITOR HEARTBEAT
 * ================================================================ */

var heartbeatActive = false;
var heartbeatFails = 0;

function startHeartbeat() {
	if (!cfg.liveMonitor || heartbeatActive) return;
	heartbeatActive = true;

	setInterval(function() {
		if (heartbeatFails >= 3) return;
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
		var fill = state.adFills[d.zoneId];
		zoneDetail.push({
			zoneId: d.zoneId,
			adSize: d.adSize,
			injectedAtDepth: d.injectedAtDepth,
			scrollSpeedAtInjection: d.scrollSpeedAtInjection,
			totalVisibleMs: d.totalVisibleMs + (d.isVisible ? Date.now() - d.visibleStart : 0),
			viewableImpressions: d.viewableImpressions,
			maxRatio: d.maxRatio,
			timeToFirstView: d.timeToFirstView,
			filled: fill ? fill.filled : null,
			fillSize: fill ? fill.size : null,
			clicks: state.adClicks[d.zoneId] ? state.adClicks[d.zoneId].count : 0,
			passback: state.waldoFills[d.zoneId] ? state.waldoFills[d.zoneId].filled : false,
			passbackNetwork: state.waldoFills[d.zoneId] ? state.waldoFills[d.zoneId].network : null
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
		zonesActivated: state.zonesActivated,
		viewableAds: totalViewable,
		zonesActive: activeZoneIds.join(','),
		referrer: document.referrer || '',
		language: (navigator.language || '').substring(0, 5),
		zoneDetail: zoneDetail,
		anchorStatus: anchorShown ? 'firing' : 'off',
		topAnchorStatus: topAnchorShown ? 'firing' : 'off',
		interstitialStatus: state.interstitialShownAt ? 'fired' : 'off',
		pauseStatus: state.pauseBannersShown > 0 ? 'fired' : 'off',
		anchorImpressions: state.anchorImpressions,
		anchorViewable: state.anchorViewableImps,
		anchorVisibleMs: state.anchorFiredAt ? Date.now() - state.anchorFiredAt : 0,
		interstitialViewable: state.interstitialViewable,
		interstitialDurationMs: state.interstitialClosedAt ? state.interstitialClosedAt - state.interstitialShownAt : (state.interstitialShownAt ? Date.now() - state.interstitialShownAt : 0),
		totalRequested: state.totalRequested,
		totalFilled: state.totalFilled,
		totalEmpty: state.totalEmpty,
		fillRate: state.totalRequested > 0 ? Math.round((state.totalFilled / state.totalRequested) * 100) : 0,
		anchorFilled: state.anchorFilled,
		topAnchorFilled: state.topAnchorFilled,
		interstitialFilled: state.interstitialFilled,
		totalDisplayClicks: state.totalDisplayClicks,
		anchorClicks: state.anchorClicks,
		interstitialClicks: state.interstitialClicks,
		interstitialDismissed: state.interstitialDismissed,
		pauseClicks: state.pauseClicks,
		pauseBannersShown: state.pauseBannersShown,
		pauseBannersContinued: state.pauseBannersContinued,
		refreshCount: state.refreshCount,
		refreshImpressions: state.refreshImpressions,
		waldoRequested: state.waldoTotalRequested,
		waldoFilled: state.waldoTotalFilled,
		waldoFills: state.waldoFills
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
 * AD CLICK TRACKER
 * ================================================================ */

function recordAdClick(zoneId, format) {
	if (format === 'display') {
		if (!state.adClicks[zoneId]) {
			state.adClicks[zoneId] = { count: 0, timestamps: [] };
		}
		state.adClicks[zoneId].count++;
		state.adClicks[zoneId].timestamps.push(Date.now());
		state.totalDisplayClicks++;
	} else if (format === 'anchor') {
		state.anchorClicks++;
	} else if (format === 'interstitial') {
		state.interstitialClicks++;
	} else if (format === 'pause') {
		state.pauseClicks++;
	}
	if (cfg.debug) console.log('[PL-Ads] CLICK: ' + zoneId + ' (' + format + ')');
}

function initClickTracking() {
	window.addEventListener('blur', function() {
		setTimeout(function() {
			var active = document.activeElement;
			if (!active || active.tagName !== 'IFRAME') return;

			var zone = active.closest('.ad-zone');
			if (zone) {
				var zoneId = zone.dataset.zone || zone.id;
				recordAdClick(zoneId, 'display');
				return;
			}

			var parent = active.parentElement;
			while (parent && parent !== document.body) {
				if (parent.id && parent.id.indexOf('gpt') !== -1 && parent.style.position === 'fixed') {
					if (parent.style.bottom === '0px') {
						recordAdClick('anchor', 'anchor');
					} else {
						state.interstitialDismissed++;
					}
					return;
				}
				parent = parent.parentElement;
			}
		}, 50);
	});

	document.addEventListener('visibilitychange', function() {
		if (!document.hidden) return;
		if (state.interstitialShownAt && !state.interstitialClosedAt) {
			recordAdClick('interstitial', 'interstitial');
		}
	});
}

/* ================================================================
 * INIT
 * ================================================================ */

function init() {
	discoverZones();

	if (state.zones.length === 0 && !cfg.fmtInterstitial && !cfg.fmtAnchor) {
		if (cfg.debug) console.log('[PL-Ads] No zones or overlays — exiting');
		return;
	}

	// Start engagement gate monitoring.
	window.addEventListener('scroll', onScroll, { passive: true });
	startTimeTracking();

	// Observe zones — nav/sidebar activate immediately, content waits for gate.
	initZoneObserver();

	// Overlays load independently after 2s delay.
	initOverlays();

	// Click tracking.
	initClickTracking();

	// Send data on page unload.
	document.addEventListener('visibilitychange', function() {
		if (document.visibilityState === 'hidden') sendData();
	});
	window.addEventListener('pagehide', sendData);

	// Live monitor heartbeat.
	startHeartbeat();

	if (cfg.debug) {
		console.log('[PL-Ads] v4 Init', {
			dummy: cfg.dummy,
			maxAds: cfg.maxAds,
			gate: cfg.gateScrollPct + '% / ' + cfg.gateTimeSec + 's / ' + cfg.gateDirChanges + 'dirs',
			zones: state.zones.length,
			device: isMobile ? 'mobile' : 'desktop'
		});
		setInterval(function() {
			console.log('[PL-Ads]', {
				gate: state.gateOpen,
				ads: state.activeAds + '/' + cfg.maxAds,
				viewable: state.viewableCount,
				anchor: anchorShown ? 'firing' : 'off',
				topAnchor: topAnchorShown ? 'firing' : 'off',
				interstitial: state.interstitialShownAt ? 'fired' : 'off',
				pauseBanners: state.pauseBannersShown,
				refreshes: state.refreshCount,
				scroll: Math.round(state.scrollPct) + '%',
				time: Math.round(state.timeOnPage) + 's'
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
