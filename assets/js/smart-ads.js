/**
 * PinLightning Ad System — Layer 2: Predictive Dynamic Ads
 *
 * Boots on first user interaction (invisible to Lighthouse).
 * Waits for Layer 1 (__initialAds) to be ready, then:
 * - 50ms velocity tracker with deceleration/pause detection
 * - Predictive injection: pause strategy + scroll prediction
 * - Viewport-aware refresh for paused reading
 * - Max 20 active slots with 1000px+ recycling
 *
 * Loaded post-window.load + 100ms — zero Lighthouse impact.
 *
 * @package PinLightning
 * @since   5.0.0
 */
;(function() {
'use strict';

/* ================================================================
 * CONFIG
 * ================================================================ */

var SLOT_PATH         = '/21849154601,22953639975/';
var IS_DESKTOP        = window.innerWidth >= 1025;
var DEBUG             = typeof plAds !== 'undefined' && plAds.debug;

// Optimizer config (Layer 2 tuning)
var L2  = (typeof plAds !== 'undefined' && plAds.layer2)  ? plAds.layer2  : {};
var VID = (typeof plAds !== 'undefined' && plAds.video)   ? plAds.video   : {};
var FMT = (typeof plAds !== 'undefined' && plAds.formats) ? plAds.formats : {};

// Slot limits
var MAX_DYNAMIC_SLOTS = L2.maxSlots ? parseInt(L2.maxSlots, 10) : 20;
var MAX_REFRESH_DYN   = 2;       // max refreshes per dynamic slot
var REFRESH_INTERVAL  = 30000;   // 30s minimum (Google policy)

// Timing
var VELOCITY_SAMPLE_MS = 50;     // velocity sampling interval
var ENGINE_LOOP_MS     = 100;    // predictive engine loop

// Recycling
var RECYCLE_DISTANCE   = 1000;   // px above viewport to recycle

// Speed-based spacing: spacing = speed × timeBetween, clamped [MIN, MAX]
var MIN_PIXEL_SPACING    = Math.max(400, L2.minPixelSpacing ? parseInt(L2.minPixelSpacing, 10) : 400);
var MAX_PIXEL_SPACING    = L2.maxPixelSpacing ? parseInt(L2.maxPixelSpacing, 10) : 1000;
var READER_TIME          = 2.5;   // seconds between ads for readers
var SCANNER_TIME         = 3.0;   // seconds between ads for scanners
var FAST_SCANNER_TIME    = 3.5;   // seconds between ads for fast-scanners

// Viewport ad density limits
var DESKTOP_MAX_IN_VIEW     = L2.desktopMaxInView ? parseInt(L2.desktopMaxInView, 10) : 2;
var MOBILE_MAX_IN_VIEW      = L2.mobileMaxInView ? parseInt(L2.mobileMaxInView, 10) : 1;
var MAX_AD_DENSITY_PERCENT  = L2.maxAdDensityPercent ? parseInt(L2.maxAdDensityPercent, 10) : 30;

// Pause detection
var PAUSE_VELOCITY       = 100;  // px/s — below this = paused (raised from 80, pause has 50% viewability)
var PAUSE_THRESHOLD_MS   = L2.pauseThreshold ? parseInt(L2.pauseThreshold, 10) : 120;

// Viewport refresh
var VP_REFRESH_DELAY = L2.viewportRefreshDelay ? parseInt(L2.viewportRefreshDelay, 10) : 3000;

// Predictive window (seconds to look ahead)
var PREDICTIVE_WINDOW = L2.predictiveWindow ? parseFloat(L2.predictiveWindow) : 1.0;

// Max speed for predictive injection (above this, ads flash by too fast to be viewable)
var PREDICTIVE_SPEED_CAP = L2.predictiveSpeedCap ? parseInt(L2.predictiveSpeedCap, 10) : 300;

// Visitor classification thresholds
var READER_SPEED_MAX  = L2.readerSpeed ? parseInt(L2.readerSpeed, 10) : 100;
var SCANNER_SPEED_MAX = L2.fastScannerSpeed ? parseInt(L2.fastScannerSpeed, 10) : 400;

/* ================================================================
 * STATE
 * ================================================================ */

var _dynamicSlots   = [];   // {divId, slot, el, anchorEl, injectedAt, viewable, refreshCount, lastRefresh, injectionType, injectionSpeed}
var _slotCounter    = 0;
var _totalSkips     = 0;    // fast-scroller ad skips (for analytics)
var _totalRetries   = 0;    // empty slot retry attempts (for analytics)
var _houseAdsShown  = 0;    // house ad backfills shown (max 2 per page)
var _activeStickyAd = null; // currently sticky ad div (only one at a time)

// Exit-intent interstitial
var _exitFired          = false;
var _exitTrigger        = '';   // 'visibility' | 'mouseleave' | 'beforeunload'
var _exitRecord         = null; // full tracking record (same shape as zone entries)

// Velocity tracker
var _velocitySamples = [];  // last 10 velocity readings
var _lastSampleY     = 0;
var _lastSampleT     = 0;
var _velocity        = 0;   // smoothed velocity (px/s, signed: + = down)
var _speed           = 0;   // absolute velocity
var _peakSpeed       = 0;   // recent peak (for deceleration detection)
var _peakDecayT      = 0;   // timestamp of last peak update
var _isDecelerating  = false;
var _isRapidDecel    = false;  // rapid deceleration (>150 to <100 in <300ms)
var _isPaused        = false;
var _pauseStartT     = 0;   // when current pause began

// Per-slot viewport visibility tracking (for viewport refresh)
var _slotViewStart   = {};   // divId → timestamp when slot became 50%+ visible

// Visitor classification
var _visitorType    = 'unknown';  // reader | scanner | fast-scanner
var _scrollSpeed    = 0;          // dashboard compat alias

// Scroll depth & direction
var _maxScrollDepth   = 0;
var _dirChanges       = 0;
var _lastScrollDir    = 0;    // 1=down, -1=up, 0=initial
var _scrollDirection  = 'down'; // current scroll direction: 'down' | 'up'

// Tab visibility tracking
var _tabVisible      = true;   // false when tab is hidden — gates engine + lazy render
var _hiddenTime      = 0;
var _hiddenSince     = 0;

// Engine loop interval (stored for tab visibility pause)
var _engineInterval  = null;

// Time tracking
var _timeOnPage     = 0;
var _sessionStart   = Date.now();

/* ================================================================
 * HELPERS
 * ================================================================ */

function log() {
	if (!DEBUG) return;
	var args = ['[SmartAds]'];
	for (var i = 0; i < arguments.length; i++) args.push(arguments[i]);
	console.log.apply(console, args);
}

/** Check if an overlay status means it rendered successfully. */
function oFilled(status) {
	return status === 'filled' || status === 'viewable';
}

/** Compute active time (tab visible) in seconds. */
function getActiveTime() {
	var hidden = _hiddenTime;
	if (_hiddenSince > 0) hidden += Date.now() - _hiddenSince;
	var total = (Date.now() - _sessionStart) / 1000;
	return Math.max(0, Math.round(total - hidden / 1000));
}

function pushEvent(name, data) {
	window.__plAdEvents = window.__plAdEvents || [];
	data.timestamp = Date.now();
	data.event     = name;
	window.__plAdEvents.push(data);
}

/** Get minimum spacing based on current scroll speed × time between ads.
 *  Faster scrollers get wider spacing (more time = more px before next ad). */
function getSpacing() {
	var timeBetween = SCANNER_TIME;
	if (_visitorType === 'reader') timeBetween = READER_TIME;
	else if (_visitorType === 'fast-scanner') timeBetween = FAST_SCANNER_TIME;
	var spacing = Math.round(_speed * timeBetween);
	return Math.max(MIN_PIXEL_SPACING, Math.min(MAX_PIXEL_SPACING, spacing));
}

/** Get directional spacing — 30% wider on scroll-up (users scan back too fast for viewability). */
function getDirectionalSpacing() {
	var base = getSpacing();
	return _scrollDirection === 'up' ? Math.round(base * 1.3) : base;
}

/** Get distance to nearest existing ad from a Y position. */
function getNearestAdDistance(targetY) {
	var scrollY = window.pageYOffset || 0;
	var minDist = 99999;

	if (window.__initialAds) {
		var zones = window.__initialAds.getExclusionZones();
		for (var z = 0; z < zones.length; z++) {
			var d = Math.abs(targetY - (zones[z].top + zones[z].bottom) / 2);
			if (d < minDist) minDist = d;
		}
	}

	for (var i = 0; i < _dynamicSlots.length; i++) {
		var rec = _dynamicSlots[i];
		if (rec.destroyed || !rec.el || !rec.el.parentNode) continue;
		var rect = rec.el.getBoundingClientRect();
		var d = Math.abs(targetY - (rect.top + scrollY + rect.height / 2));
		if (d < minDist) minDist = d;
	}

	return minDist;
}

/** Get current scroll percentage. */
function getScrollPercent() {
	var docH = document.documentElement.scrollHeight || 1;
	var y = window.pageYOffset || 0;
	return Math.round((y + window.innerHeight) / docH * 100);
}

/** Get viewport ad density: count ads visible + percentage of viewport height occupied by ads. */
function getViewportDensity() {
	var vpH = window.innerHeight;
	var adsInView = 0;
	var totalAdHeight = 0;

	// Layer 1 ads
	if (window.__initialAds) {
		var zones = window.__initialAds.getExclusionZones();
		for (var z = 0; z < zones.length; z++) {
			var zt = zones[z].top;
			var zb = zones[z].bottom;
			var visH = Math.max(0, Math.min(zb, vpH) - Math.max(zt, 0));
			if (visH > 10) {
				adsInView++;
				totalAdHeight += visH;
			}
		}
	}

	// Layer 2 dynamic ads
	for (var i = 0; i < _dynamicSlots.length; i++) {
		var rec = _dynamicSlots[i];
		if (rec.destroyed || !rec.el || !rec.el.parentNode) continue;
		var rect = rec.el.getBoundingClientRect();
		var visH = Math.max(0, Math.min(rect.bottom, vpH) - Math.max(rect.top, 0));
		if (visH > 10) {
			adsInView++;
			totalAdHeight += visH;
		}
	}

	return {
		adsInView: adsInView,
		adPercent: vpH > 0 ? Math.round(totalAdHeight / vpH * 100) : 0
	};
}

/* ================================================================
 * 1. VELOCITY TRACKER (50ms sampling)
 *
 * Tracks position, velocity, acceleration every 50ms.
 * Detects deceleration (speed drops >50% from recent peak),
 * rapid deceleration (>150 to <100 in <300ms), and
 * pause (velocity < 100px/s for PAUSE_THRESHOLD_MS).
 * Classifies visitor: reader (<100), scanner (<400), fast-scanner.
 * ================================================================ */

function sampleVelocity() {
	var now = Date.now();
	var y   = window.pageYOffset || 0;
	var dt  = (now - _lastSampleT) / 1000;

	if (dt > 0 && _lastSampleT > 0) {
		var rawVelocity = (y - _lastSampleY) / dt; // signed: + = scrolling down
		_velocitySamples.push(rawVelocity);
		if (_velocitySamples.length > 10) _velocitySamples.shift();

		// Weighted average (newer samples heavier)
		var total = 0, weight = 0;
		for (var i = 0; i < _velocitySamples.length; i++) {
			var w = i + 1;
			total += _velocitySamples[i] * w;
			weight += w;
		}
		_velocity = total / weight;
		_speed = Math.abs(_velocity);
		_scrollSpeed = Math.round(_speed);

		// Track direction changes + current direction
		var dir = y > _lastSampleY ? 1 : (y < _lastSampleY ? -1 : 0);
		if (dir !== 0 && dir !== _lastScrollDir && _lastScrollDir !== 0) _dirChanges++;
		if (dir !== 0) {
			_lastScrollDir = dir;
			_scrollDirection = dir > 0 ? 'down' : 'up';
		}

		// Peak speed tracking (decays after 500ms)
		if (_speed > _peakSpeed || (now - _peakDecayT) > 500) {
			_peakSpeed = _speed;
			_peakDecayT = now;
		}

		// Deceleration detection: speed dropped >50% from recent peak
		_isDecelerating = (_peakSpeed > 100 && _speed < _peakSpeed * 0.5 && _speed > PAUSE_VELOCITY);

		// Rapid deceleration: >150px/s to <100px/s within 300ms — user is clearly stopping.
		// Catches the moment just before a full pause for earlier injection.
		var prevSpeed = _velocitySamples.length >= 2
			? Math.abs(_velocitySamples[_velocitySamples.length - 2])
			: 0;
		_isRapidDecel = (prevSpeed > 150 && _speed < PAUSE_VELOCITY && dt < 0.3);

		// Pause detection: velocity below threshold
		if (_speed < PAUSE_VELOCITY || _isRapidDecel) {
			if (!_isPaused) {
				_isPaused = true;
				_pauseStartT = now;
			}
		} else {
			_isPaused = false;
			_pauseStartT = 0;
		}

		// Classify visitor
		if (_speed < READER_SPEED_MAX) {
			_visitorType = 'reader';
		} else if (_speed < SCANNER_SPEED_MAX) {
			_visitorType = 'scanner';
		} else {
			_visitorType = 'fast-scanner';
		}
	}

	_lastSampleY = y;
	_lastSampleT = now;
	_timeOnPage  = (now - _sessionStart) / 1000;

	// Track max scroll depth
	var docH = document.documentElement.scrollHeight || 1;
	var depthPct = Math.round((y + window.innerHeight) / docH * 100);
	if (depthPct > _maxScrollDepth) _maxScrollDepth = Math.min(depthPct, 100);
}

/* ================================================================
 * 2. SPACING GUARD
 *
 * Checks if injecting at a given Y position respects minimum
 * spacing from ALL existing ads (Layer 1 initial + Layer 2 dynamic).
 * Spacing is dynamic per visitor type.
 * ================================================================ */

function checkSpacing(targetY) {
	var spacing = getDirectionalSpacing();
	var scrollY = window.pageYOffset || 0;

	// Check Layer 1 initial ads
	if (window.__initialAds) {
		var zones = window.__initialAds.getExclusionZones();
		for (var z = 0; z < zones.length; z++) {
			var adCenter = (zones[z].top + zones[z].bottom) / 2;
			if (Math.abs(targetY - adCenter) < spacing) return false;
		}
	}

	// Check Layer 2 dynamic ads
	for (var d = 0; d < _dynamicSlots.length; d++) {
		var rec = _dynamicSlots[d];
		if (rec.destroyed) continue;
		var el = rec.el;
		if (!el || !el.parentNode) continue;
		var rect = el.getBoundingClientRect();
		var adY = rect.top + scrollY + rect.height / 2;
		if (Math.abs(targetY - adY) < spacing) return false;
	}

	return true;
}

/* ================================================================
 * 3. TARGET FINDERS
 *
 * Find the best paragraph to inject an ad after, near a target
 * Y position. Scores by proximity to target, content quality,
 * and spacing from existing ads.
 * ================================================================ */

function findTargetNear(targetY, searchRadius) {
	// Support multiple .single-content sections (original + auto-loaded posts)
	var contentSections = document.querySelectorAll('.single-content');
	if (!contentSections.length) return null;

	var scrollY   = window.pageYOffset || 0;
	var bestScore = -Infinity;
	var bestEl    = null;

	for (var s = 0; s < contentSections.length; s++) {
		var candidates = contentSections[s].querySelectorAll('p');

		for (var i = 0; i < candidates.length; i++) {
			var el   = candidates[i];
			var rect = el.getBoundingClientRect();
			var elY  = rect.top + scrollY + rect.height;

			// Must be within search radius of target
			var dist = Math.abs(elY - targetY);
			if (dist > searchRadius) continue;

			// Must pass spacing guard
			if (!checkSpacing(elY)) continue;

			// Score: closer to target = better (inverse distance)
			var score = searchRadius - dist;

			var prev = el.previousElementSibling;

			// Bonus: after images — #1 priority (natural content break, high attention)
			if (prev && (prev.tagName === 'IMG' || prev.tagName === 'FIGURE'
				|| (prev.classList && (prev.classList.contains('wp-block-image') || prev.classList.contains('wp-block-gallery')))
				|| (prev.querySelector && prev.querySelector('img')))) {
				score += 300;
			}

			// Bonus: after headings (section break)
			if (prev && /^H[2-4]$/.test(prev.tagName)) {
				score += 150;
			}

			// Bonus: paragraph has substantial text
			if (el.textContent.length > 80) {
				score += 50;
			}

			// Penalty: very short paragraph
			if (el.textContent.length < 20) {
				score -= 200;
			}

			if (score > bestScore) {
				bestScore = score;
				bestEl    = el;
			}
		}
	}

	return bestEl;
}

/** PAUSE TARGET: Find injection point at center of current viewport. */
function findPauseTarget() {
	var scrollY  = window.pageYOffset || 0;
	var vpCenter = scrollY + window.innerHeight / 2;
	return findTargetNear(vpCenter, window.innerHeight / 2);
}

/**
 * SCROLL TARGET: Find injection point at predicted scroll stop.
 * Works bidirectionally — uses signed velocity to predict where user will stop
 * whether scrolling down (velocity > 0) or up (velocity < 0).
 */
function findScrollTarget() {
	var scrollY = window.pageYOffset || 0;
	// Predict: signed velocity * window * 0.5 (deceleration halves distance)
	// Down: target is below viewport bottom; Up: target is above viewport top
	var predictedStop;
	if (_velocity > 0) {
		// Scrolling down — predict below current viewport bottom
		predictedStop = scrollY + window.innerHeight + (_velocity * PREDICTIVE_WINDOW * 0.5);
	} else {
		// Scrolling up — predict above current viewport top
		predictedStop = scrollY + (_velocity * PREDICTIVE_WINDOW * 0.5);
	}
	return findTargetNear(predictedStop, window.innerHeight);
}

/* ================================================================
 * 4. DYNAMIC AD INJECTION
 * ================================================================ */

/**
 * Viewport-aware size selection for dynamic ads.
 * Mobile (<768px): 300x250 only — single auction reduces latency by ~200ms.
 * Tablet (768-1024): 300x250 + 336x280 — modest multi-size.
 * Desktop (≥1025): full multi-size with 300x600 for higher RPM.
 */
function getDynamicAdSizes() {
	var w = window.innerWidth;
	var sizes, sizeMapping;

	if (w < 768) {
		// Mobile: single-size auction — faster fill, less latency
		sizes = [[300, 250]];
		sizeMapping = googletag.sizeMapping()
			.addSize([0, 0], [[300, 250]])
			.build();
	} else if (w < 1025) {
		// Tablet: modest multi-size
		sizes = [[300, 250], [336, 280]];
		sizeMapping = googletag.sizeMapping()
			.addSize([468, 0], [[300, 250], [336, 280]])
			.addSize([320, 0], [[300, 250]])
			.addSize([0, 0],   [[300, 250]])
			.build();
	} else {
		// Desktop: full multi-size for higher RPM
		sizes = [[336, 280], [300, 250], [300, 600], [250, 250]];
		sizeMapping = googletag.sizeMapping()
			.addSize([1025, 0], [[336, 280], [300, 250], [300, 600]])
			.addSize([768, 0],  [[336, 280], [300, 250], [300, 600]])
			.addSize([468, 0],  [[300, 250], [336, 280], [250, 250]])
			.addSize([320, 0],  [[300, 250], [250, 250]])
			.addSize([0, 0],    [[250, 250]])
			.build();
	}

	return { sizes: sizes, sizeMapping: sizeMapping };
}

/** Render a dynamic ad slot via GPT (called by lazy render observer or timeout). */
function renderSlot(record, sizes, sizeMapping) {
	if (record.destroyed || record.rendered) return;
	record.rendered = true;

	googletag.cmd.push(function() {
		var slot = googletag.defineSlot(
			SLOT_PATH + 'Ad.Plus-300x250',
			sizes,
			record.divId
		);
		if (slot) {
			slot.defineSizeMapping(sizeMapping);
			slot.addService(googletag.pubads());
			slot.setTargeting('refresh', 'true');
			slot.setTargeting('pos', 'dynamic');
			slot.setTargeting('strategy', record.injectionType || 'unknown');
			googletag.display(record.divId);
			googletag.pubads().refresh([slot]);
			record.slot = slot;
		}
	});

	// Record render request time for GPT response tracking
	record.renderRequestedAt = Date.now();

	// Patient timeout — only collapses if GPT never responds at all.
	// Real empties are handled by slotRenderEnded (showHouseAd on isEmpty).
	(function(rec) {
		rec._fillTimeout = setTimeout(function() {
			if (rec.destroyed || rec.filled) return;
			rec.el.style.minHeight = '0';
			rec.el.style.height = '0';
			rec.el.style.margin = '0';
			rec.destroyed = true;
			console.log('[SmartAds] GPT stuck:', rec.divId, '10s no response, collapsed');
			pushEvent('dynamic_ad_timeout', { divId: rec.divId, timeoutMs: 10000 });
			if (window.__plAdTracker) {
				window.__plAdTracker.track('creative_timeout', rec.divId, {
					slotType: 'dynamic', timeoutMs: 10000
				});
			}
		}, 10000);
	})(record);

	log('Rendered:', record.divId, 'type:', record.injectionType);
}

/** Observe a dynamic ad container for lazy rendering — triggers 200px before viewport. */
function observeForLazyRender(container, record, sizes, sizeMapping) {
	if (typeof IntersectionObserver === 'undefined') {
		renderSlot(record, sizes, sizeMapping);
		return;
	}

	var observer = new IntersectionObserver(function(entries) {
		if (entries[0].isIntersecting && !record.rendered) {
			// Don't render while tab is hidden — IO still fires for background tabs
			if (!_tabVisible) return;
			observer.disconnect();
			renderSlot(record, sizes, sizeMapping);
		}
	}, {
		rootMargin: '400px 0px'  // trigger 400px BEFORE entering viewport
	});

	observer.observe(container);
	record._lazyObserver = observer;
}

/**
 * Apply brief sticky behavior to a dynamic ad for viewability.
 * Only for fast-scrolling users (speed > 100px/s) scrolling down.
 * Holds the ad in viewport for 1.5s — just enough for the 1s viewability threshold.
 * Only one sticky ad at a time to avoid UX jank.
 */
function applyStickyBehavior(container) {
	if (typeof IntersectionObserver === 'undefined') return;

	var applied = false;
	var stickyObserver = new IntersectionObserver(function(entries) {
		if (entries[0].isIntersecting && !applied) {
			applied = true;
			stickyObserver.disconnect();

			// Clear previous sticky if any
			if (_activeStickyAd && _activeStickyAd !== container) {
				_activeStickyAd.style.position = '';
				_activeStickyAd.style.top = '';
				_activeStickyAd.style.transform = '';
				_activeStickyAd.style.zIndex = '';
			}

			// Apply sticky positioning
			container.style.position = 'sticky';
			container.style.top = '50%';
			container.style.transform = 'translateY(-50%)';
			container.style.zIndex = '10';
			_activeStickyAd = container;

			// Remove sticky after 1.5 seconds
			setTimeout(function() {
				container.style.position = '';
				container.style.top = '';
				container.style.transform = '';
				container.style.zIndex = '';
				if (_activeStickyAd === container) _activeStickyAd = null;
			}, 1500);
		}
	}, { threshold: 0.5 }); // trigger when 50% visible

	stickyObserver.observe(container);
}

/**
 * Last-chance refresh: refresh a dynamic ad as it exits the viewport top.
 * When user scrolls past an ad, refresh it so a new creative is ready
 * if the user scrolls back up. Increases total viewable impressions.
 * Guards: viewableEver, refreshCount < MAX, 30s cooldown, tab visible.
 */
function observeForLastChanceRefresh(record) {
	if (typeof IntersectionObserver === 'undefined') return;

	var observer = new IntersectionObserver(function(entries) {
		var entry = entries[0];
		// Only trigger when ad exits viewport (isIntersecting → false)
		if (entry.isIntersecting) return;

		// Only if exiting from the TOP (user scrolled past going down)
		if (entry.boundingClientRect.bottom >= 0) return;

		// Guards
		if (!_tabVisible) return;
		if (record.destroyed || !record.slot || !record.filled) return;
		if (record.refreshCount >= MAX_REFRESH_DYN) return;
		if (!record.viewableEver) return;
		var now = Date.now();
		var lastTime = record.lastRefresh || record.injectedAt;
		if (now - lastTime < REFRESH_INTERVAL) return;

		// Refresh this slot
		(function(r) {
			googletag.cmd.push(function() {
				if (r.slot) googletag.pubads().refresh([r.slot]);
			});
		})(record);

		record.refreshCount++;
		record.lastRefresh = now;
		record.viewable    = false;
		delete _slotViewStart[record.divId];

		log('Last-chance refresh:', record.divId, 'count:', record.refreshCount);
		pushEvent('dynamic_ad_refreshed', {
			divId:        record.divId,
			refreshCount: record.refreshCount,
			strategy:     'last_chance'
		});
		if (window.__plAdTracker) {
			window.__plAdTracker.track('last_chance_refresh', record.divId, {
				slotType:     'dynamic',
				refreshCount: record.refreshCount
			});
		}
	}, {
		threshold: 0  // fires when visibility crosses 0%
	});

	observer.observe(record.el);
	record._lastChanceObserver = observer;
}

/**
 * Show a house ad (email capture promo) in an empty dynamic slot.
 * Max 2 per page. Not counted in viewability calculations.
 */
function showHouseAd(rec) {
	if (_houseAdsShown >= 2) {
		// Max house ads reached — collapse instead
		rec.el.style.minHeight = '0';
		rec.el.style.margin    = '0';
		rec.el.style.overflow  = 'hidden';
		rec.filled = false;
		return;
	}

	var adDiv = rec.adDiv || document.getElementById(rec.divId);
	if (!adDiv) return;

	adDiv.innerHTML = '<a href="/free-pinterest-guide" ' +
		'class="pl-house-ad" ' +
		'style="display:block;text-align:center;padding:15px;' +
		'background:#FFF8E1;border:1px solid #FFD54F;border-radius:8px;text-decoration:none;">' +
		'<div style="font-weight:bold;font-size:14px;color:#333;">' +
		'Free Guide: How This Site Gets 5,000+ Pinterest Visitors</div>' +
		'<div style="font-size:12px;color:#666;margin-top:4px;">' +
		'Download the free PDF guide</div>' +
		'</a>';

	rec.el.style.minHeight = 'auto';
	rec.filled = false; // Don't count in viewability
	_houseAdsShown++;

	pushEvent('house_ad_shown', { divId: rec.divId });
	if (window.__plAdTracker) {
		window.__plAdTracker.track('house_ad_shown', rec.divId, { slotType: 'house' });
	}

	var link = adDiv.querySelector('.pl-house-ad');
	if (link) {
		link.addEventListener('click', function() {
			pushEvent('house_ad_click', { divId: rec.divId });
			if (window.__plAdTracker) {
				window.__plAdTracker.track('house_ad_click', rec.divId, { slotType: 'house' });
			}
		});
	}

	log('House ad shown:', rec.divId, 'total:', _houseAdsShown);
}

/**
 * Relocate a filled-but-missed ad near the user's current viewport.
 * Moves the existing DOM element (keeps GPT iframe alive) to a paragraph
 * near the viewport center, respecting spacing rules.
 */
function relocateFilledAd(rec) {
	if (rec.relocated || rec.destroyed) {
		console.log('[SmartAds] relocateFilledAd blocked:', rec.divId,
			'relocated:', rec.relocated, 'destroyed:', rec.destroyed);
		return;
	}
	console.log('[SmartAds] relocateFilledAd called for', rec.divId);

	var scrollY = window.pageYOffset || 0;
	var viewportMiddle = scrollY + (window.innerHeight * 0.6);

	// Search all content sections for a paragraph near viewport middle
	var containers = document.querySelectorAll('.single-content');
	var bestTarget = null;
	var bestDistance = Infinity;

	for (var c = 0; c < containers.length; c++) {
		var paragraphs = containers[c].querySelectorAll('p');
		for (var p = 0; p < paragraphs.length; p++) {
			var para = paragraphs[p];
			var paraY = para.getBoundingClientRect().top + scrollY;
			var distance = Math.abs(paraY - viewportMiddle);

			if (distance < bestDistance) {
				// Check spacing: don't place within 400px of another active ad
				var tooClose = false;
				for (var s = 0; s < _dynamicSlots.length; s++) {
					var other = _dynamicSlots[s];
					if (other.destroyed || other === rec) continue;
					if (!other.el || !other.el.parentNode) continue;
					var otherY = other.el.getBoundingClientRect().top + scrollY;
					if (Math.abs(paraY - otherY) < MIN_PIXEL_SPACING) {
						tooClose = true;
						break;
					}
				}

				// Also check Layer 1 exclusion zones
				if (!tooClose && window.__initialAds) {
					var zones = window.__initialAds.getExclusionZones();
					for (var z = 0; z < zones.length; z++) {
						var adCenter = (zones[z].top + zones[z].bottom) / 2;
						if (Math.abs(paraY - adCenter) < MIN_PIXEL_SPACING) {
							tooClose = true;
							break;
						}
					}
				}

				if (!tooClose) {
					bestDistance = distance;
					bestTarget = para;
				}
			}
		}
	}

	if (!bestTarget || bestDistance > 800) {
		console.log('[SmartAds] Relocate: no valid position for', rec.divId,
			'bestDistance:', Math.round(bestDistance),
			'bestTarget:', bestTarget ? 'found' : 'null');
		return;
	}

	// Store original position for analytics
	var oldY = rec.el.getBoundingClientRect().top + scrollY;

	// Detach from current position (do NOT clone — keeps GPT iframe alive)
	if (rec.el.parentNode) {
		rec.el.parentNode.removeChild(rec.el);
	}

	// Insert after the target paragraph
	bestTarget.parentNode.insertBefore(rec.el, bestTarget.nextSibling);

	var newY = rec.el.getBoundingClientRect().top + scrollY;

	// Update tracking
	rec.relocated = true;
	rec.relocatedFromY = Math.round(oldY);
	rec.relocatedToY = Math.round(newY);
	rec.relocatedAt = Date.now();

	// Reset viewability tracking for new position
	rec.viewable = false;
	delete _slotViewStart[rec.divId];

	console.log('[SmartAds] Relocated', rec.divId,
		'from Y:' + Math.round(oldY), 'to Y:' + Math.round(newY),
		'(' + Math.round(newY - oldY) + 'px moved)');
	pushEvent('dynamic_ad_relocated', {
		divId: rec.divId, fromY: Math.round(oldY),
		toY: Math.round(newY), distance: Math.round(newY - oldY)
	});
}

function injectDynamicAd(afterElement, injectionType) {
	_slotCounter++;
	var divId = 'smart-ad-' + _slotCounter;

	// Create container
	var container = document.createElement('div');
	container.className = 'pl-dynamic-ad';
	container.style.cssText = 'text-align:center;min-height:250px;margin:16px auto;overflow:hidden;clear:both';

	var adDiv = document.createElement('div');
	adDiv.id = divId;
	container.appendChild(adDiv);

	// Insert as NEXT SIBLING, never inside
	afterElement.parentNode.insertBefore(container, afterElement.nextSibling);

	// Viewport-aware sizes: mobile single-auction, desktop multi-size
	var adSizes     = getDynamicAdSizes();
	var sizes       = adSizes.sizes;
	var sizeMapping = adSizes.sizeMapping;

	var scrollY = window.pageYOffset || 0;
	var elRect  = container.getBoundingClientRect();
	var adY     = elRect.top + scrollY + elRect.height / 2;
	var adSpacing = getNearestAdDistance(adY);

	// Absolute minimum spacing guard — abort if actual placement is too close
	if (adSpacing < MIN_PIXEL_SPACING) {
		if (container.parentNode) container.parentNode.removeChild(container);
		_slotCounter--;
		log('ABORT: actual spacing', Math.round(adSpacing), '< MIN', MIN_PIXEL_SPACING);
		return null;
	}

	var vpBottom  = scrollY + window.innerHeight;
	var predDist  = (injectionType === 'predictive') ? Math.round(adY - vpBottom) : 0;

	var nearImage = false;
	var densityAtInject = getViewportDensity();

	var record = {
		divId:             divId,
		slot:              null,
		el:                container,
		adDiv:             adDiv,
		anchorEl:          afterElement,
		injectedAt:        Date.now(),
		injectedY:         scrollY,
		injectionType:     injectionType || 'unknown',
		injectionSpeed:    _scrollSpeed,
		scrollDirection:   _scrollDirection,
		nearImage:         nearImage,
		adsInViewport:     densityAtInject.adsInView,
		adDensityPercent:  densityAtInject.adPercent,
		adSpacing:         Math.round(adSpacing),
		predictedDistance: predDist,
		viewable:          false,
		viewableEver:      false,
		refreshCount:      0,
		lastRefresh:       0,
		renderedSize:      null,
		filled:            false,
		destroyed:         false,
		rendered:          false,
		retried:           false
	};

	_dynamicSlots.push(record);

	// Render strategy depends on scroll speed at injection time:
	// - Fast scrollers (>200px/s): 500ms timeout then proximity check — skip if user scrolled away
	// - Normal/slow scrollers: IO with 200px rootMargin — renders when approaching viewport
	if (_scrollSpeed > 200) {
		// Fast scroller — defer 500ms, then check if div is still near viewport
		(function(rec, sz, sm, ctr) {
			setTimeout(function() {
				if (rec.destroyed || rec.rendered) return;
				// Don't render while tab is hidden — defer until next engine tick
				if (!_tabVisible) return;
				var r = ctr.getBoundingClientRect();
				var vh = window.innerHeight;
				// Within 1.5x viewport height above/below = user slowed down or is nearby
				if (r.top > -vh && r.top < vh * 2.5) {
					renderSlot(rec, sz, sm);
				} else {
					// User scrolled far away — skip this ad, clean up
					rec.destroyed = true;
					if (ctr.parentNode) ctr.parentNode.removeChild(ctr);
					_totalSkips++;
					log('SKIPPED: fast scroll abandon', rec.divId, 'skips:', _totalSkips);
					pushEvent('dynamic_ad_skipped', {
						divId: rec.divId, reason: 'fast_scroll_abandon',
						speed: rec.injectionSpeed, totalSkips: _totalSkips
					});
					if (window.__plAdTracker) {
						window.__plAdTracker.track('skipped', rec.divId, {
							slotType: 'dynamic', reason: 'fast_scroll_abandon'
						});
					}
				}
			}, 500);
		})(record, sizes, sizeMapping, container);
	} else {
		// Normal speed — lazy render via IO (200px rootMargin gives ~500-1000ms lead time)
		observeForLazyRender(container, record, sizes, sizeMapping);
	}

	log('Injected:', divId, 'type:', injectionType, 'dir:', _scrollDirection, 'speed:', _scrollSpeed, 'spacing:', Math.round(adSpacing), 'visitor:', _visitorType);
	pushEvent('dynamic_ad_injected', {
		divId:             divId,
		speed:             _scrollSpeed,
		visitorType:       _visitorType,
		injectionType:     injectionType,
		scrollDirection:   _scrollDirection,
		nearImage:         nearImage,
		adsInViewport:     densityAtInject.adsInView,
		adDensityPercent:  densityAtInject.adPercent,
		slotCount:         _dynamicSlots.length,
		adSpacing:         Math.round(adSpacing),
		predictedDistance: predDist
	});
	if (window.__plAdTracker) {
		window.__plAdTracker.track('dynamic_inject', divId, {
			slotType:          'dynamic',
			injectionType:     injectionType,
			scrollDirection:   _scrollDirection,
			scrollSpeed:       _scrollSpeed,
			predictedDistance: predDist,
			adSpacing:         Math.round(adSpacing),
			nearImage:         nearImage,
			adsInViewport:     densityAtInject.adsInView,
			adDensityPercent:  densityAtInject.adPercent
		});
	}

	return record;
}

/* ================================================================
 * 5. SLOT RECYCLING (max 20, 1000px+ above viewport)
 * ================================================================ */

function recycleSlots() {
	var activeCount = 0;
	for (var i = 0; i < _dynamicSlots.length; i++) {
		if (!_dynamicSlots[i].destroyed) activeCount++;
	}

	if (activeCount <= MAX_DYNAMIC_SLOTS) return;

	// Find the oldest non-destroyed slot that's 1000px+ above the viewport
	for (var j = 0; j < _dynamicSlots.length; j++) {
		var rec = _dynamicSlots[j];
		if (rec.destroyed) continue;

		var rect = rec.el.getBoundingClientRect();
		if (rect.bottom < -RECYCLE_DISTANCE) {
			destroySlot(rec);
			log('Recycled slot:', rec.divId);
			pushEvent('slot_recycled', { divId: rec.divId });
			return;
		}
	}
}

function destroySlot(record) {
	record.destroyed = true;
	delete _slotViewStart[record.divId];
	if (record._lazyObserver) {
		record._lazyObserver.disconnect();
		record._lazyObserver = null;
	}
	if (record._lastChanceObserver) {
		record._lastChanceObserver.disconnect();
		record._lastChanceObserver = null;
	}

	googletag.cmd.push(function() {
		if (record.slot) {
			googletag.destroySlots([record.slot]);
			record.slot = null;
		}
	});

	// Remove DOM element
	if (record.el && record.el.parentNode) {
		record.el.parentNode.removeChild(record.el);
	}
}

/* ================================================================
 * 6. VIEWPORT REFRESH (per-slot visibility tracking)
 *
 * Tracks how long each dynamic ad has been 50%+ visible.
 * When visible for VP_REFRESH_DELAY (3s) continuously AND
 * user nearly stopped (< 20px/s), refresh it.
 * - 30s minimum between refreshes (Google policy)
 * - Max 2 refreshes per slot
 * - Tab must be visible
 * - Runs every engine tick (100ms) for accurate timing
 * ================================================================ */

function checkViewportRefresh() {
	if (document.hidden) return;

	var now = Date.now();
	var vpH = window.innerHeight;

	for (var i = 0; i < _dynamicSlots.length; i++) {
		var rec = _dynamicSlots[i];
		if (rec.destroyed || !rec.slot || !rec.filled) continue;
		if (rec.refreshCount >= MAX_REFRESH_DYN) continue;

		// Check timing (30s minimum between refreshes — Google policy)
		var lastTime = rec.lastRefresh || rec.injectedAt;
		if (now - lastTime < REFRESH_INTERVAL) continue;

		// Check if 50%+ visible in viewport
		var rect = rec.el.getBoundingClientRect();
		if (rect.height < 10) continue;
		var visibleH = Math.max(0, Math.min(rect.bottom, vpH) - Math.max(rect.top, 0));
		var ratio    = visibleH / rect.height;

		if (ratio >= 0.5) {
			// Track when this slot became continuously visible
			if (!_slotViewStart[rec.divId]) {
				_slotViewStart[rec.divId] = now;
			}

			// Must be continuously visible for VP_REFRESH_DELAY (3s)
			var viewDuration = now - _slotViewStart[rec.divId];
			if (viewDuration < VP_REFRESH_DELAY) continue;

			// User must be nearly stopped (< 20px/s) — more reliable than _isPaused
			if (_speed > 20) continue;

			// Density check: skip if 2+ other filled ads near viewport (±200px)
			var nearbyAds = 0;
			for (var j = 0; j < _dynamicSlots.length; j++) {
				if (j === i || _dynamicSlots[j].destroyed || !_dynamicSlots[j].filled) continue;
				var jRect = _dynamicSlots[j].el.getBoundingClientRect();
				if (jRect.top < vpH + 200 && jRect.bottom > -200) nearbyAds++;
			}
			if (nearbyAds >= 2) continue;

			// Refresh this slot (IIFE to capture rec in closure)
			(function(r) {
				googletag.cmd.push(function() {
					if (r.slot) googletag.pubads().refresh([r.slot]);
				});
			})(rec);

			rec.refreshCount++;
			rec.lastRefresh = now;
			rec.viewable    = false;
			// Reset visibility timer (new creative needs new viewability period)
			delete _slotViewStart[rec.divId];

			log('Viewport refresh:', rec.divId, 'count:', rec.refreshCount,
				'viewDuration:', Math.round(viewDuration / 1000) + 's');
			pushEvent('dynamic_ad_refreshed', {
				divId:        rec.divId,
				refreshCount: rec.refreshCount,
				strategy:     'viewport_refresh',
				viewDuration: viewDuration
			});
			if (window.__plAdTracker) {
				window.__plAdTracker.track('viewport_refresh', rec.divId, {
					slotType:        'dynamic',
					injectionType:   'viewport_refresh',
					scrollDirection: _scrollDirection,
					scrollSpeed:     _scrollSpeed
				});
			}
		} else {
			// Not visible — reset continuous visibility timer
			delete _slotViewStart[rec.divId];
		}
	}
}

/* ================================================================
 * 7. PREDICTIVE INJECTION ENGINE (100ms loop)
 *
 * Strategies evaluated every tick:
 * 1. PAUSE — user stopped scrolling → inject at viewport center
 * 1.5 SLOW SCROLL — sustained reading pace → inject ahead
 * 2. PREDICTIVE — user decelerating → inject at predicted stop
 * Note: viewport refresh runs independently at top of loop
 * ================================================================ */

function engineLoop() {
	// Tab hidden — skip all ad operations (prevents background waste)
	if (!_tabVisible) return;

	// Video check runs independently of display ad guards
	checkVideoInjection();

	// Viewport refresh runs every tick (tracks per-slot visibility independently)
	checkViewportRefresh();

	// Dynamic ads disabled in optimizer?
	var dynVal = FMT.dynamic;
	if (dynVal === false || dynVal === 'false' || dynVal === '' || dynVal === '0' || dynVal === 0) return;

	// Active slot count check
	var activeCount = 0;
	for (var i = 0; i < _dynamicSlots.length; i++) {
		if (!_dynamicSlots[i].destroyed) activeCount++;
	}

	// Viewport density guard — skip injection if too many ads already visible
	var density = getViewportDensity();
	var maxInView = IS_DESKTOP ? DESKTOP_MAX_IN_VIEW : MOBILE_MAX_IN_VIEW;
	if (density.adsInView >= maxInView || density.adPercent >= MAX_AD_DENSITY_PERCENT) {
		return;
	}

	// Strategy 1: PAUSE — user has stopped scrolling for PAUSE_THRESHOLD_MS
	if (activeCount < MAX_DYNAMIC_SLOTS && _isPaused && (Date.now() - _pauseStartT) >= PAUSE_THRESHOLD_MS) {
		var pauseTarget = findPauseTarget();
		if (pauseTarget) {
			recycleSlots();
			injectDynamicAd(pauseTarget, 'pause');
			return; // one injection per tick
		}
	}

	// Strategy 1.5: SLOW SCROLL — consistent reading pace (PAUSE_VELOCITY to 120px/s)
	// Users scrolling slowly and consistently should get ads even without a full pause.
	if (activeCount < MAX_DYNAMIC_SLOTS && _speed > PAUSE_VELOCITY && _speed <= 120
		&& !_isPaused && !_isDecelerating) {
		// Verify sustained slow speed: at least 4 of last 6 samples under 120px/s
		var slowCount = 0;
		var checkLen  = Math.min(_velocitySamples.length, 6);
		if (checkLen >= 6) {
			for (var ss = _velocitySamples.length - 6; ss < _velocitySamples.length; ss++) {
				if (Math.abs(_velocitySamples[ss]) <= 120) slowCount++;
			}
		}
		var sustainedSlow = (checkLen >= 6 && slowCount >= 4);
		log('SLOW CHECK: speed=' + Math.round(_speed) + ' sustained=' + sustainedSlow + ' (' + slowCount + '/6 under 120)');

		if (sustainedSlow) {
			var slowTarget = findScrollTarget();
			if (slowTarget) {
				recycleSlots();
				injectDynamicAd(slowTarget, 'slow_scroll');
				log('SLOW SCROLL inject at speed=' + Math.round(_speed));
				return;
			}
		}
	}

	// Strategy 2: PREDICTIVE — user is decelerating AND below speed cap
	// At 300px/s a 250px ad is visible for 0.83s — near viewable threshold (1s).
	// Above the cap, ads flash by too fast to ever be viewable.
	if (activeCount < MAX_DYNAMIC_SLOTS && _isDecelerating && _speed < PREDICTIVE_SPEED_CAP) {
		var scrollTarget = findScrollTarget();
		if (scrollTarget) {
			recycleSlots();
			injectDynamicAd(scrollTarget, 'predictive');
			return;
		}
	}

}

/* ================================================================
 * GPT EVENT HANDLERS (for dynamic slots)
 * ================================================================ */

function onDynamicSlotRenderEnded(event) {
	var divId = event.slot.getSlotElementId();

	// Only handle our dynamic slots
	var rec = null;
	for (var i = 0; i < _dynamicSlots.length; i++) {
		if (_dynamicSlots[i].divId === divId) {
			rec = _dynamicSlots[i];
			break;
		}
	}
	if (!rec) return;

	// Clear the 10s stuck timeout — GPT did respond
	if (rec._fillTimeout) {
		clearTimeout(rec._fillTimeout);
		rec._fillTimeout = null;
	}

	// Track GPT response time
	rec.gptResponseMs = rec.renderRequestedAt ? (Date.now() - rec.renderRequestedAt) : 0;

	if (event.isEmpty) {
		console.log('[SmartAds] GPT response:', rec.divId, 'EMPTY', rec.gptResponseMs + 'ms');
		if (!rec.retried) {
			// First empty — retry once after 3s with fallback sizes (300x250 only)
			rec.retried = true;
			rec.filled  = false;
			log('Dynamic empty:', divId, '— will retry in 3s');

			(function(r, evtSlot) {
				setTimeout(function() {
					if (r.destroyed) return;
					var el = r.el;
					if (!el || !el.parentNode) return;
					// Only retry if div is still near viewport
					var rect = el.getBoundingClientRect();
					var vh   = window.innerHeight;
					if (rect.top < -vh || rect.top > vh * 2) {
						el.style.minHeight = '0';
						el.style.margin    = '0';
						return;
					}
					googletag.cmd.push(function() {
						googletag.destroySlots([evtSlot]);
						var newSlot = googletag.defineSlot(
							SLOT_PATH + 'Ad.Plus-300x250',
							[[300, 250]],
							r.divId
						);
						if (newSlot) {
							newSlot.addService(googletag.pubads());
							newSlot.setTargeting('refresh', 'true');
							newSlot.setTargeting('pos', 'dynamic');
							newSlot.setTargeting('strategy', 'retry');
							googletag.display(r.divId);
							googletag.pubads().refresh([newSlot]);
							r.slot = newSlot;
						}
					});
					_totalRetries++;
					log('Retry:', r.divId);
					pushEvent('dynamic_ad_retry', { divId: r.divId });
					if (window.__plAdTracker) {
						window.__plAdTracker.track('retry', r.divId, { slotType: 'dynamic' });
					}
				}, 3000);
			})(rec, event.slot);

			return;
		}

		// Retry also empty — backfill with house ad (email capture promo)
		showHouseAd(rec);
		log('Dynamic empty after retry:', divId, '— house ad backfill');
		pushEvent('dynamic_ad_empty', { divId: divId, afterRetry: true, houseAd: _houseAdsShown > 0 });
		if (window.__plAdTracker) {
			window.__plAdTracker.track('empty', divId, { slotType: 'dynamic', afterRetry: true });
		}
		return;
	}

	// Resize container to match creative
	var size = event.size;
	if (size) {
		rec.el.style.minHeight = size[1] + 'px';
		rec.el.style.maxWidth  = size[0] + 'px';
		rec.renderedSize = size;
	}
	rec.filled = true;

	console.log('[SmartAds] GPT response:', rec.divId,
		'FILLED' + (size ? ' ' + size[0] + 'x' + size[1] : ''),
		rec.gptResponseMs + 'ms');

	// Relocate filled-but-missed ads: if user has scrolled past this ad,
	// move it near their current position to salvage the impression.
	var rect = rec.el.getBoundingClientRect();
	var isFarBelow = rect.top > window.innerHeight + 200;
	var isFarAbove = rect.bottom < -200;

	console.log('[SmartAds] Relocation check:', rec.divId,
		'rect.top:', Math.round(rect.top),
		'rect.bottom:', Math.round(rect.bottom),
		'viewport:', window.innerHeight,
		'farBelow:', isFarBelow,
		'farAbove:', isFarAbove,
		'scrollDir:', _scrollDirection,
		'gptMs:', rec.gptResponseMs);

	if ((isFarBelow || isFarAbove) && !rec.relocated) {
		// Don't relocate if user is scrolling toward the ad
		if (!(isFarBelow && _scrollDirection === 'up')) {
			relocateFilledAd(rec);
		}
	}

	// Delayed relocation recheck: if the ad filled near-viewport but the user
	// scrolled past it before it became viewable, relocate after 2s.
	if (!rec.relocated) {
		(function(r) {
			setTimeout(function() {
				if (r.destroyed || r.viewable || r.relocated) return;
				if (!r.el || !r.el.parentNode) return;
				var delayRect = r.el.getBoundingClientRect();
				var inView = delayRect.top < window.innerHeight && delayRect.bottom > 0;
				if (!inView) {
					console.log('[SmartAds] Delayed relocation for', r.divId,
						'rect.top:', Math.round(delayRect.top),
						'rect.bottom:', Math.round(delayRect.bottom));
					relocateFilledAd(r);
				}
			}, 2000);
		})(rec);
	}

	// Last-chance refresh: watch for this ad to exit viewport top
	observeForLastChanceRefresh(rec);

	// Brief sticky for fast-scrolling users: hold ad in viewport for 1.5s
	// Only when injection speed > 100px/s AND scrolling down (feels jarring on scroll-up)
	if (rec.injectionSpeed > 100 && rec.scrollDirection === 'down') {
		applyStickyBehavior(rec.el);
	}

	log('Dynamic filled:', divId, size);
	pushEvent('dynamic_ad_filled', {
		divId: divId, size: size, gptResponseMs: rec.gptResponseMs,
		relocated: rec.relocated || false
	});
	if (window.__plAdTracker) {
		window.__plAdTracker.track('impression', divId, {
			slotType: 'dynamic',
			creativeSize: size ? size[0] + 'x' + size[1] : '',
			gptResponseMs: rec.gptResponseMs || 0,
			relocated: rec.relocated ? 1 : 0
		});
	}
}

function onDynamicImpressionViewable(event) {
	var divId = event.slot.getSlotElementId();

	for (var i = 0; i < _dynamicSlots.length; i++) {
		if (_dynamicSlots[i].divId === divId) {
			var rec = _dynamicSlots[i];
			rec.viewable = true;
			rec.viewableEver = true;
			window.__plViewableCount = (window.__plViewableCount || 0) + 1;
			log('VIEWABLE: dynamic', divId, '__plViewableCount now=' + window.__plViewableCount);
			var ttv = Date.now() - rec.injectedAt;
			pushEvent('dynamic_ad_viewable', {
				divId:           divId,
				timeToViewable:  ttv,
				injectionType:   rec.injectionType,
				injectionSpeed:  rec.injectionSpeed,
				scrollDirection: rec.scrollDirection
			});
			if (window.__plAdTracker) {
				window.__plAdTracker.track('viewable', divId, {
					slotType:        'dynamic',
					injectionType:   rec.injectionType,
					scrollDirection: rec.scrollDirection,
					scrollSpeed:     rec.injectionSpeed,
					timeToViewable:  ttv
				});
			}
			break;
		}
	}
}

/* ================================================================
 * REAL-TIME DASHBOARD
 * ================================================================ */

function updateDashboard() {
	var activeSlots = 0;
	var viewableSlots = 0;
	var totalRefreshes = 0;
	var filledSlots = 0;
	var byStrategy = { pause: 0, slow_scroll: 0, predictive: 0 };

	for (var i = 0; i < _dynamicSlots.length; i++) {
		var s = _dynamicSlots[i];
		if (s.destroyed) continue;
		activeSlots++;
		if (s.viewable) viewableSlots++;
		if (s.filled) filledSlots++;
		totalRefreshes += s.refreshCount;
		if (s.injectionType && byStrategy[s.injectionType] !== undefined) {
			byStrategy[s.injectionType]++;
		}
	}

	var dashDensity = getViewportDensity();
	window.__plAdDashboard = window.__plAdDashboard || {};
	window.__plAdDashboard.layer2 = {
		activeSlots:     activeSlots,
		viewableSlots:   viewableSlots,
		filledSlots:     filledSlots,
		totalRefreshes:  totalRefreshes,
		totalInjected:   _dynamicSlots.length,
		scrollSpeed:     _scrollSpeed,
		scrollDirection: _scrollDirection,
		visitorType:     _visitorType,
		timeOnPage:      Math.round(_timeOnPage),
		isPaused:        _isPaused,
		isDecelerating:  _isDecelerating,
		spacing:         getDirectionalSpacing(),
		adsInViewport:   dashDensity.adsInView,
		adDensityPercent: dashDensity.adPercent,
		byStrategy:      byStrategy
	};
}

/* ================================================================
 * VIDEO OUTSTREAM AD (Ad.Plus InPage Player)
 * ================================================================ */

/**
 * Inject the Ad.Plus video outstream player once per session.
 * Only a single video ad per page is allowed (Ad.Plus policy).
 * The player handles sticky/floating, display passback, and sizing.
 */
function checkVideoInjection() {
	// One video per page — ever
	if (window.__plVideoInjected) return;

	// Only on single posts — use first .single-content (original article)
	var content = document.querySelector('.single-content');
	if (!content) return;

	// Video disabled in optimizer
	var vidEnabled = VID.enabled;
	if (vidEnabled === false || vidEnabled === 'false' || vidEnabled === '' || vidEnabled === '0' || vidEnabled === 0) return;

	// Only for engaged visitors (configurable)
	var allowedVisitor = VID.allowedVisitor || 'reader,scanner';
	if (allowedVisitor.indexOf(_visitorType) === -1) return;

	// Must have scrolled at least N% of page
	var minScroll = VID.minScroll ? parseInt(VID.minScroll, 10) : 40;
	var docH      = document.documentElement.scrollHeight || 1;
	var scrollY   = window.pageYOffset || 0;
	var scrollPct = Math.round((scrollY + window.innerHeight) / docH * 100);
	if (scrollPct < minScroll) return;

	// At least N dynamic content ads must have filled
	var minFilled = VID.minFilledAds ? parseInt(VID.minFilledAds, 10) : 2;
	var filledCount = 0;
	for (var i = 0; i < _dynamicSlots.length; i++) {
		if (_dynamicSlots[i].filled) filledCount++;
	}
	if (filledCount < minFilled) return;

	// Find injection point: paragraph 6-8 deep in content
	var paragraphs = content.querySelectorAll('p');
	var targetPara = null;
	for (var p = 7; p >= 5; p--) {
		if (paragraphs[p]) { targetPara = paragraphs[p]; break; }
	}
	if (!targetPara) return;

	// Mark injected BEFORE async work to prevent race conditions
	window.__plVideoInjected = true;

	// Create container
	var container = document.createElement('div');
	container.className = 'pl-video-ad';
	container.id = 'video-ad-1';
	container.style.cssText = 'margin:20px auto;text-align:center;max-width:640px';
	targetPara.parentNode.insertBefore(container, targetPara.nextSibling);

	// Load Ad.Plus player script
	var playerScript = document.createElement('script');
	playerScript.async = true;
	playerScript.src = 'https://cdn.ad.plus/player/adplus.js';
	document.head.appendChild(playerScript);

	// Initialize player
	var initScript = document.createElement('script');
	initScript.textContent = '(function(){' +
		'var s=document.getElementById("video-ad-1");' +
		'(playerPro=window.playerPro||[]).push({' +
			'id:"z2I717k6zq5b",' +
			'after:s,' +
			'appParams:{' +
				'"C_NETWORK_CODE":"22953639975",' +
				'"C_WEBSITE":"cheerlives.com"' +
			'}' +
		'});' +
	'})();';
	document.body.appendChild(initScript);

	log('Video ad injected for', _visitorType, 'at', scrollPct + '%');
	pushEvent('video_ad_injected', {
		visitorType: _visitorType,
		scrollPct:   scrollPct,
		filledAds:   filledCount
	});
	if (window.__plAdTracker) {
		window.__plAdTracker.track('video_inject', 'video-ad-1', { slotType: 'video' });
	}
}

/* ================================================================
 * ANALYTICS BEACON
 * ================================================================ */

function sendBeacon() {
	if (typeof plAds === 'undefined' || !plAds.record) return;

	// Compute totals from dynamic slots
	var totalViewable  = 0;
	var totalFilled    = 0;
	var totalEmpty     = 0;
	var totalRefreshes = 0;
	var zones = [];
	for (var i = 0; i < _dynamicSlots.length; i++) {
		var s = _dynamicSlots[i];
		if (s.viewableEver) totalViewable++;
		if (s.filled) totalFilled++;
		if (!s.filled && !s.destroyed) totalEmpty++;
		totalRefreshes += s.refreshCount;
		zones.push({
			divId:             s.divId,
			filled:            s.filled,
			viewable:          s.viewableEver,
			refreshCount:      s.refreshCount,
			destroyed:         s.destroyed,
			renderedSize:      s.renderedSize,
			injectionType:     s.injectionType,
			injectionSpeed:    s.injectionSpeed,
			scrollDirection:   s.scrollDirection || 'down',
			nearImage:         s.nearImage || false,
			adSpacing:         s.adSpacing || 0,
			predictedDistance: s.predictedDistance || 0,
			gptResponseMs:     s.gptResponseMs || 0,
			relocated:         s.relocated || false,
			relocatedFromY:    s.relocatedFromY || 0,
			relocatedToY:      s.relocatedToY || 0
		});
	}

	// Include exit interstitial as a zone entry if it fired.
	// Totals NOT incremented here — interstitial already counted via Layer 1 slotMap.
	if (_exitRecord) {
		zones.push({
			divId:             _exitRecord.divId,
			filled:            _exitRecord.filled,
			viewable:          _exitRecord.viewableEver,
			refreshCount:      0,
			destroyed:         false,
			renderedSize:      _exitRecord.renderedSize,
			injectionType:     _exitRecord.injectionType,
			injectionSpeed:    0,
			scrollDirection:   'n/a',
			nearImage:         false,
			adSpacing:         0,
			predictedDistance: 0,
			trigger:           _exitRecord.trigger,
			sessionSeconds:    _exitRecord.sessionSeconds
		});
	}

	// Gather Layer 1 data — slotMap for counts, __plOverlayStatus for overlay state
	var slotMap = (window.__initialAds && window.__initialAds.getSlotMap) ? window.__initialAds.getSlotMap() : {};
	var OVS = window.__plOverlayStatus || {};
	var anchorStatus       = OVS.bottomAnchor || 'off';
	var interstitialStatus = OVS.interstitial || 'off';
	var topAnchorStatus    = OVS.topAnchor || 'off';
	var anchorFilled       = oFilled(OVS.bottomAnchor);
	var intFilled          = oFilled(OVS.interstitial);
	var topFilled          = oFilled(OVS.topAnchor);
	var leftFilled         = oFilled(OVS.leftRail);
	var rightFilled        = oFilled(OVS.rightRail);

	// Count Layer 1 totals
	var l1Filled = 0, l1Empty = 0, l1Viewable = 0, l1Refreshes = 0;
	var l1keys = Object.keys(slotMap);
	for (var k = 0; k < l1keys.length; k++) {
		var info = slotMap[l1keys[k]];
		if (info.renderedSize) l1Filled++; else l1Empty++;
		if (info.viewable) l1Viewable++;
		l1Refreshes += info.refreshCount || 0;
	}

	var totalRequested = l1keys.length + _dynamicSlots.length;
	var allFilled      = l1Filled + totalFilled;
	var sharedViewable = window.__plViewableCount || 0;
	var allViewable    = Math.max(l1Viewable + totalViewable, sharedViewable);
	var allRefreshes   = l1Refreshes + totalRefreshes;
	var fillRate       = totalRequested > 0 ? Math.round((allFilled / totalRequested) * 100) : 0;
	var viewRate       = allFilled > 0 ? Math.round((allViewable / allFilled) * 100) : 0;

	// Session ID from Layer 1 tracker
	var sid = (window.__plAdTracker && window.__plAdTracker.sessionId) ? window.__plAdTracker.sessionId : '';

	var payload = {
		layer:           2,
		session:         true,
		sid:             sid,
		postId:          plAds.postId || 0,
		postSlug:        plAds.postSlug || '',
		postTitle:       plAds.postTitle || '',
		device:          IS_DESKTOP ? 'desktop' : (window.innerWidth >= 768 ? 'tablet' : 'mobile'),
		viewportW:       window.innerWidth,
		viewportH:       window.innerHeight,
		timeOnPage:      Math.round(_timeOnPage),
		activeTime:      getActiveTime(),
		maxDepth:        _maxScrollDepth,
		scrollPct:       _maxScrollDepth,
		scrollSpeed:     _scrollSpeed,
		scrollPattern:   _visitorType,
		dirChanges:      _dirChanges,
		visitorType:     _visitorType,
		referrer:        document.referrer || '',
		language:        (navigator.language || navigator.userLanguage || ''),
		// Injection totals
		totalInjected:       _dynamicSlots.length,
		viewportAdsInjected: _dynamicSlots.length,
		totalViewable:       allViewable,
		viewabilityRate:     viewRate,
		// Fill tracking
		totalRequested:  totalRequested,
		totalFilled:     allFilled,
		totalEmpty:      l1Empty + totalEmpty,
		fillRate:        fillRate,
		// Overlay status
		anchorStatus:        anchorStatus,
		interstitialStatus:  interstitialStatus,
		topAnchorStatus:     topAnchorStatus,
		anchorFilled:        anchorFilled,
		interstitialFilled:  intFilled,
		topAnchorFilled:     topFilled,
		leftSideRailFilled:  leftFilled,
		rightSideRailFilled: rightFilled,
		// Overlay viewability
		anchorImpressions: slotMap['__anchor'] ? (slotMap['__anchor'].refreshCount + 1) : 0,
		anchorViewable:    slotMap['__anchor'] && slotMap['__anchor'].viewable ? 1 : 0,
		interstitialViewable: slotMap['__interstitial'] && slotMap['__interstitial'].viewable ? 1 : 0,
		// Refresh + video + pause
		totalRefreshes:      allRefreshes,
		pauseBannersInjected: slotMap['pause-ad-1'] && slotMap['pause-ad-1'].renderedSize ? 1 : 0,
		videoInjected:       !!window.__plVideoInjected,
		totalSkips:          _totalSkips,
		totalRetries:        _totalRetries,
		// Exit-intent interstitial
		exitInterstitialFired:   _exitFired,
		exitInterstitialTrigger: _exitTrigger || '',
		// Image taps
		imageTaps:           window._imageTaps || [],
		// Dynamic slot detail
		zones:           zones
	};

	var json = JSON.stringify(payload);
	if (navigator.sendBeacon && plAds.recordEndpoint) {
		navigator.sendBeacon(plAds.recordEndpoint, new Blob([json], { type: 'application/json' }));
	}
}

/* ================================================================
 * HEARTBEAT — real-time session data for admin Live Sessions
 * ================================================================ */

var _heartbeatInterval = null;

function sendHeartbeat() {
	if (typeof plAds === 'undefined' || !plAds.heartbeatEndpoint) return;

	// Compute same totals as sendBeacon
	var totalViewable = 0, totalFilled = 0, totalRefreshes = 0;
	for (var i = 0; i < _dynamicSlots.length; i++) {
		if (_dynamicSlots[i].viewableEver) totalViewable++;
		if (_dynamicSlots[i].filled) totalFilled++;
		totalRefreshes += _dynamicSlots[i].refreshCount;
	}

	var slotMap = (window.__initialAds && window.__initialAds.getSlotMap) ? window.__initialAds.getSlotMap() : {};
	var l1Filled = 0, l1Viewable = 0, l1Refreshes = 0;
	var l1keys = Object.keys(slotMap);
	for (var k = 0; k < l1keys.length; k++) {
		var info = slotMap[l1keys[k]];
		if (info.renderedSize) l1Filled++;
		if (info.viewable) l1Viewable++;
		l1Refreshes += info.refreshCount || 0;
	}

	var allFilled   = l1Filled + totalFilled;
	var hbSharedViewable = window.__plViewableCount || 0;
	var allViewable = Math.max(l1Viewable + totalViewable, hbSharedViewable);
	var totalReq    = l1keys.length + _dynamicSlots.length;
	var fillRate    = totalReq > 0 ? Math.round((allFilled / totalReq) * 100) : 0;
	var viewRate    = allFilled > 0 ? Math.round((allViewable / allFilled) * 100) : 0;

	log('HEARTBEAT viewable: __plViewableCount=' + hbSharedViewable,
		'l1Viewable=' + l1Viewable, 'dynViewable=' + totalViewable,
		'allViewable=' + allViewable, 'allFilled=' + allFilled);

	var sid = (window.__plAdTracker && window.__plAdTracker.sessionId) ? window.__plAdTracker.sessionId : '';

	var data = {
		sid:             sid,
		postId:          plAds.postId || 0,
		postSlug:        plAds.postSlug || '',
		postTitle:       plAds.postTitle || '',
		device:          IS_DESKTOP ? 'desktop' : (window.innerWidth >= 768 ? 'tablet' : 'mobile'),
		viewportW:       window.innerWidth,
		viewportH:       window.innerHeight,
		timeOnPage:      Math.round(_timeOnPage),
		activeTime:      getActiveTime(),
		maxDepth:        _maxScrollDepth,
		scrollPct:       _maxScrollDepth,
		scrollSpeed:     _scrollSpeed,
		scrollPattern:   _visitorType,
		dirChanges:      _dirChanges,
		referrer:        document.referrer || '',
		language:        (navigator.language || navigator.userLanguage || ''),
		// Totals
		totalInjected:       _dynamicSlots.length,
		viewportAdsInjected: _dynamicSlots.length,
		totalViewable:       allViewable,
		viewabilityRate:     viewRate,
		totalRequested:      totalReq,
		totalFilled:         allFilled,
		fillRate:            fillRate,
		totalRefreshes:      l1Refreshes + totalRefreshes,
		// Overlay status — event-driven from __plOverlayStatus (set by initial-ads.js GPT handlers)
		anchorStatus:        (window.__plOverlayStatus || {}).bottomAnchor || 'off',
		interstitialStatus:  (window.__plOverlayStatus || {}).interstitial || 'off',
		topAnchorStatus:     (window.__plOverlayStatus || {}).topAnchor || 'off',
		anchorFilled:        oFilled((window.__plOverlayStatus || {}).bottomAnchor),
		interstitialFilled:  oFilled((window.__plOverlayStatus || {}).interstitial),
		topAnchorFilled:     oFilled((window.__plOverlayStatus || {}).topAnchor),
		leftSideRailFilled:  oFilled((window.__plOverlayStatus || {}).leftRail),
		rightSideRailFilled: oFilled((window.__plOverlayStatus || {}).rightRail),
		pauseBannersInjected: slotMap['pause-ad-1'] && slotMap['pause-ad-1'].renderedSize ? 1 : 0,
		videoInjected:       !!window.__plVideoInjected,
		totalSkips:          _totalSkips,
		totalRetries:        _totalRetries,
		// Exit-intent interstitial
		exitInterstitialFired:   _exitFired,
		exitInterstitialTrigger: _exitTrigger || '',
		// Image taps
		imageTapCount:       (window._imageTaps || []).length,
	};

	// Build zones array for per-ad detail
	var zones = [];
	for (var d = 0; d < _dynamicSlots.length; d++) {
		var ds = _dynamicSlots[d];
		if (ds.destroyed) continue;
		zones.push({
			zoneId:            ds.divId,
			slot:              'Ad.Plus-300x250',
			size:              ds.renderedSize ? ds.renderedSize[0] + 'x' + ds.renderedSize[1] : '',
			viewable:          ds.viewableEver,
			filled:            ds.filled,
			refreshCount:      ds.refreshCount,
			injectionType:     ds.injectionType,
			injectionSpeed:    ds.injectionSpeed,
			scrollDirection:   ds.scrollDirection || 'down',
			adSpacing:         ds.adSpacing || 0,
			gptResponseMs:     ds.gptResponseMs || 0,
			relocated:         ds.relocated || false,
			relocatedFromY:    ds.relocatedFromY || 0,
			relocatedToY:      ds.relocatedToY || 0
		});
	}

	// Include exit interstitial as a zone entry if it fired
	if (_exitRecord) {
		zones.push({
			zoneId:        _exitRecord.divId,
			slot:          'exit-interstitial',
			size:          _exitRecord.renderedSize ? _exitRecord.renderedSize[0] + 'x' + _exitRecord.renderedSize[1] : '',
			viewable:      _exitRecord.viewableEver,
			filled:        _exitRecord.filled,
			refreshCount:  0,
			injectionType: _exitRecord.injectionType,
			injectionSpeed: 0,
			scrollDirection: 'n/a',
			adSpacing:     0
		});
	}

	data.zones = zones;

	// Use fetch (not sendBeacon) so we can send credentials for CORS
	fetch(plAds.heartbeatEndpoint, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': plAds.nonce
		},
		body: JSON.stringify(data),
		credentials: 'same-origin',
		keepalive: true
	}).catch(function() {});
}

function startHeartbeat() {
	// Always start heartbeat — server handles load (lightweight transient storage)
	// Only send every 5s to minimize network overhead
	if (_heartbeatInterval) return;
	_heartbeatInterval = setInterval(sendHeartbeat, 5000);
	// Send first heartbeat immediately
	sendHeartbeat();
	log('Heartbeat started (5s interval)');
}

/* ================================================================
 * INIT — called after Layer 1 is ready
 * ================================================================ */

function init() {
	log('Layer 2 init — waiting for Layer 1');

	// Wait for Layer 1 (__initialAds) to be ready
	function onLayer1Ready() {
		log('Layer 1 ready — starting predictive injection engine');

		// Register our event listeners on the shared GPT instance
		googletag.cmd.push(function() {
			googletag.pubads().addEventListener('slotRenderEnded', onDynamicSlotRenderEnded);
			googletag.pubads().addEventListener('impressionViewable', onDynamicImpressionViewable);
		});

		// Start velocity tracker (50ms) and predictive engine (100ms)
		setInterval(sampleVelocity, VELOCITY_SAMPLE_MS);
		_engineInterval = setInterval(engineLoop, ENGINE_LOOP_MS);

		// Start heartbeat for live sessions dashboard
		startHeartbeat();

		// Passive scroll listener for immediate velocity updates
		window.addEventListener('scroll', sampleVelocity, { passive: true });

		// Update dashboard every 5s
		setInterval(updateDashboard, 5000);

		// Track tab visibility for active time + pause ad operations
		document.addEventListener('visibilitychange', function() {
			if (document.visibilityState === 'hidden') {
				_tabVisible  = false;
				_hiddenSince = Date.now();
				sendBeacon();
			} else {
				_tabVisible = true;
				if (_hiddenSince > 0) {
					_hiddenTime += Date.now() - _hiddenSince;
					_hiddenSince = 0;
				}
				// Reset per-slot viewport visibility timestamps — stale from hidden period.
				// Don't refresh existing ads on return; let natural viewport refresh logic re-detect.
				_slotViewStart = {};
			}
		});
		window.addEventListener('pagehide', sendBeacon);

		pushEvent('layer2_started', { timestamp: Date.now() });
	}

	if (window.__initialAds && window.__initialAds.ready) {
		onLayer1Ready();
	} else if (window.__initialAds) {
		window.__initialAds.onReady(onLayer1Ready);
	} else {
		// Polling fallback — Layer 1 script may not have loaded yet
		var pollCount = 0;
		var poll = setInterval(function() {
			pollCount++;
			if (window.__initialAds && window.__initialAds.ready) {
				clearInterval(poll);
				onLayer1Ready();
			} else if (pollCount > 60) {
				// 30s timeout — Layer 1 never loaded, start without it
				clearInterval(poll);
				log('Layer 1 timeout — starting without it');
				// Load GPT ourselves as fallback
				if (typeof googletag === 'undefined' || !googletag.cmd) {
					window.googletag = window.googletag || { cmd: [] };
					var s = document.createElement('script');
					s.src = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
					s.async = true;
					document.head.appendChild(s);
				}
				googletag.cmd.push(function() {
					googletag.pubads().enableSingleRequest();
					googletag.pubads().collapseEmptyDivs();
					googletag.pubads().addEventListener('slotRenderEnded', onDynamicSlotRenderEnded);
					googletag.pubads().addEventListener('impressionViewable', onDynamicImpressionViewable);
					googletag.enableServices();
				});
				setInterval(sampleVelocity, VELOCITY_SAMPLE_MS);
				_engineInterval = setInterval(engineLoop, ENGINE_LOOP_MS);

				// Start heartbeat for live sessions dashboard
				startHeartbeat();

				// Passive scroll listener for immediate velocity updates
				window.addEventListener('scroll', sampleVelocity, { passive: true });

				setInterval(updateDashboard, 5000);
				document.addEventListener('visibilitychange', function() {
					if (document.visibilityState === 'hidden') {
						_tabVisible  = false;
						_hiddenSince = Date.now();
						sendBeacon();
					} else {
						_tabVisible = true;
						if (_hiddenSince > 0) {
							_hiddenTime += Date.now() - _hiddenSince;
							_hiddenSince = 0;
						}
						_slotViewStart = {};
					}
				});
				window.addEventListener('pagehide', sendBeacon);
			}
		}, 500);
	}
}

/* ================================================================
 * EXIT-INTENT INTERSTITIAL
 *
 * Fires the interstitial ad slot when user shows exit signals:
 * - Mobile: tab switch / back button (visibilitychange)
 * - Desktop: mouse leaves viewport toward top (mouseleave)
 * - Both: page unload (beforeunload)
 * Requires min 15s session + interstitial slot defined by Layer 1.
 * ================================================================ */

var EXIT_MIN_SESSION_S = 15;

function tryExitInterstitial(trigger) {
	if (_exitFired) return;

	// Check config
	var exitEnabled = (typeof plAds !== 'undefined' && plAds.exitInterstitial !== undefined)
		? plAds.exitInterstitial
		: true;
	if (!exitEnabled) return;

	// Minimum engagement time
	var sessionSeconds = (Date.now() - _sessionStart) / 1000;
	if (sessionSeconds < EXIT_MIN_SESSION_S) return;

	// Ensure GPT is loaded
	if (typeof googletag === 'undefined' || !googletag.apiReady) return;

	_exitFired = true;
	_exitTrigger = trigger;

	// Create tracking record (same fields used by beacon/heartbeat zones).
	// NOT pushed to _dynamicSlots (no DOM element — would crash engine loop).
	_exitRecord = {
		divId:           'exit-interstitial',
		injectedAt:      Date.now(),
		injectionType:   'exit_intent',
		injectionSpeed:  0,
		scrollDirection: 'n/a',
		nearImage:       false,
		adSpacing:       0,
		predictedDistance: 0,
		trigger:         trigger,
		sessionSeconds:  Math.round(sessionSeconds),
		filled:          false,
		viewable:        false,
		viewableEver:    false,
		refreshCount:    0,
		renderedSize:    null,
		destroyed:       false
	};

	// Check Layer 1 interstitial status before deciding strategy.
	// GPT interstitials don't show immediately — GPT decides when.
	// If still 'pending', DON'T destroy it (it hasn't had a chance to show).
	// Instead, nudge GPT to show it now via refresh().
	var overlayStatus = window.__plOverlayStatus || {};
	var interstitialStatus = overlayStatus.interstitial || 'off';

	googletag.cmd.push(function() {
		var slotMap = (window.__initialAds && window.__initialAds.getSlotMap)
			? window.__initialAds.getSlotMap() : {};
		var existing = slotMap['__interstitial'];

		if (interstitialStatus === 'pending' && existing && existing.slot) {
			// GPT hasn't shown the interstitial yet — don't destroy it.
			// Refresh to nudge GPT into showing it now as exit trigger.
			log('Exit intent: Layer 1 interstitial still pending, refreshing instead of destroying');
			googletag.pubads().refresh([existing.slot]);
			// Layer 1's existing slotRenderEnded/impressionViewable handlers will track this.
			// We still mark exit as fired to prevent double-trigger.
			pushEvent('exit_interstitial_fired_refresh', {
				trigger: trigger,
				sessionSeconds: Math.round(sessionSeconds)
			});
			return;
		}

		// Layer 1 interstitial already showed (filled/viewable/empty) or doesn't exist.
		// Safe to destroy and create a NEW exit-intent interstitial slot.
		if (existing && existing.slot) {
			googletag.destroySlots([existing.slot]);
			log('Destroyed Layer 1 interstitial (status:', interstitialStatus + ') to free format');
		}

		// Define fresh interstitial slot
		var exitSlot = googletag.defineOutOfPageSlot(
			SLOT_PATH + 'Ad.Plus-Interstitial',
			googletag.enums.OutOfPageFormat.INTERSTITIAL
		);
		if (!exitSlot) {
			log('Exit interstitial: defineOutOfPageSlot returned null');
			_exitRecord.filled = false;
			return;
		}
		exitSlot.addService(googletag.pubads());

		// Listen for render result on the NEW slot
		googletag.pubads().addEventListener('slotRenderEnded', function(event) {
			if (event.slot !== exitSlot || !_exitRecord) return;
			_exitRecord.filled = !event.isEmpty;
			if (event.size) {
				_exitRecord.renderedSize = event.size;
			}
			log('Exit interstitial result:',
				_exitRecord.filled ? 'FILLED ' + (event.size ? event.size[0] + 'x' + event.size[1] : '') : 'EMPTY');
			pushEvent(_exitRecord.filled ? 'exit_interstitial_filled' : 'exit_interstitial_empty', {
				trigger: trigger,
				size: event.size ? event.size[0] + 'x' + event.size[1] : 'empty'
			});
		});

		// Listen for viewability on the NEW slot
		googletag.pubads().addEventListener('impressionViewable', function(event) {
			if (event.slot !== exitSlot || !_exitRecord) return;
			_exitRecord.viewable = true;
			_exitRecord.viewableEver = true;
			window.__plViewableCount = (window.__plViewableCount || 0) + 1;
			log('Exit interstitial VIEWABLE');
			pushEvent('exit_interstitial_viewable', { trigger: trigger });
		});

		// Display triggers the auction immediately
		googletag.display(exitSlot);
	});

	console.log('[SmartAds] Exit interstitial fired:', trigger,
		'after', Math.round(sessionSeconds) + 's');
	pushEvent('exit_interstitial_fired', {
		trigger: trigger,
		sessionSeconds: Math.round(sessionSeconds)
	});
}

function initExitIntent() {
	// Mobile: tab switch / back button
	document.addEventListener('visibilitychange', function() {
		if (document.hidden) tryExitInterstitial('visibility');
	});

	// Desktop: mouse leaves window toward top (close/back button area)
	document.documentElement.addEventListener('mouseleave', function(e) {
		if (e.clientY < 10) tryExitInterstitial('mouseleave');
	});

	// Both: page unload
	window.addEventListener('beforeunload', function() {
		tryExitInterstitial('beforeunload');
	});
}

/* ================================================================
 * BOOT — first user interaction trigger
 * ================================================================ */

var _booted = false;
function bootOnce() {
	if (_booted) return;
	_booted = true;
	window.removeEventListener('scroll', bootOnce);
	window.removeEventListener('click', bootOnce);
	window.removeEventListener('touchstart', bootOnce);

	// Small delay to let interaction complete smoothly
	setTimeout(function() {
		if (typeof requestIdleCallback !== 'undefined') {
			requestIdleCallback(init);
		} else {
			setTimeout(init, 50);
		}
	}, 100);
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', function() {
		window.addEventListener('scroll', bootOnce, { passive: true, once: true });
		window.addEventListener('click', bootOnce, { once: true });
		window.addEventListener('touchstart', bootOnce, { passive: true, once: true });
	});
} else {
	window.addEventListener('scroll', bootOnce, { passive: true, once: true });
	window.addEventListener('click', bootOnce, { once: true });
	window.addEventListener('touchstart', bootOnce, { passive: true, once: true });
}

// Safety net: boot after 15s even without interaction
setTimeout(function() {
	if (!_booted) bootOnce();
}, 15000);

// Exit-intent runs immediately — doesn't wait for user interaction gate.
// Layer 1 has already defined the interstitial slot by this point
// (smart-ads.js loads post-window.load + 100ms, after initial-ads.js).
initExitIntent();

/* ================================================================
 * PUBLIC API — window.SmartAds
 *
 * Exposes rescanAnchors() so next-post-loader can notify Layer 2
 * that new .single-content sections have been appended to the DOM.
 * The engine loop already picks up new paragraphs via querySelectorAll,
 * but rescan forces an immediate spacing recalculation.
 * ================================================================ */

/**
 * Notify Layer 2 that new content has been added to the page.
 *
 * Puts the engine back into the same state it was in when the original
 * post first started getting ads:
 * - Aggressively recycles (destroys) all slots that are above the current
 *   viewport, freeing activeCount budget and removing spacing blockers
 * - Resets house ad counter so new content gets its own quota
 * - Clears per-slot viewport visibility timestamps (stale from old content)
 *
 * The engine loop (setInterval) is ALWAYS running — it never stops.
 * The blockers were: activeCount near MAX_DYNAMIC_SLOTS (20) and
 * checkSpacing() rejecting new positions due to old slot Y positions.
 * Recycling old above-viewport slots fixes both.
 */
function rescanAnchors() {
	var scrollY = window.pageYOffset || 0;
	var recycled = 0;

	// Aggressively recycle ALL slots above the current viewport.
	// Normal recycleSlots() only recycles one at a time and only when
	// count > MAX_DYNAMIC_SLOTS. Here we destroy ALL above-viewport slots
	// so the new post gets a fresh budget identical to post 1.
	for (var i = 0; i < _dynamicSlots.length; i++) {
		var rec = _dynamicSlots[i];
		if (rec.destroyed) continue;
		if (!rec.el || !rec.el.parentNode) continue;

		var rect = rec.el.getBoundingClientRect();
		// Destroy any slot whose bottom is above the viewport top
		if (rect.bottom < 0) {
			destroySlot(rec);
			recycled++;
		}
	}

	// Reset house ad counter: new content gets its own quota (max 2)
	_houseAdsShown = 0;

	// Clear stale viewport visibility timestamps from old content slots
	_slotViewStart = {};

	// Count remaining active slots for the log
	var activeAfter = 0;
	for (var j = 0; j < _dynamicSlots.length; j++) {
		if (!_dynamicSlots[j].destroyed) activeAfter++;
	}

	console.log('[SmartAds] rescan: recycled ' + recycled + ' old slots, ' +
		activeAfter + ' active (of ' + MAX_DYNAMIC_SLOTS + ' max), ' +
		'house ads reset, engine running. Content sections: ' +
		document.querySelectorAll('.single-content').length);
}

window.SmartAds = {
	rescanAnchors: rescanAnchors
};

})();
