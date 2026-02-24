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
var MIN_PIXEL_SPACING    = L2.minPixelSpacing ? parseInt(L2.minPixelSpacing, 10) : 400;
var MAX_PIXEL_SPACING    = L2.maxPixelSpacing ? parseInt(L2.maxPixelSpacing, 10) : 1000;
var READER_TIME          = 2.5;   // seconds between ads for readers
var SCANNER_TIME         = 3.0;   // seconds between ads for scanners
var FAST_SCANNER_TIME    = 3.5;   // seconds between ads for fast-scanners

// Viewport ad density limits
var DESKTOP_MAX_IN_VIEW     = L2.desktopMaxInView ? parseInt(L2.desktopMaxInView, 10) : 2;
var MOBILE_MAX_IN_VIEW      = L2.mobileMaxInView ? parseInt(L2.mobileMaxInView, 10) : 1;
var MAX_AD_DENSITY_PERCENT  = L2.maxAdDensityPercent ? parseInt(L2.maxAdDensityPercent, 10) : 30;

// Pause detection
var PAUSE_VELOCITY       = 50;   // px/s — below this = paused
var PAUSE_THRESHOLD_MS   = L2.pauseThreshold ? parseInt(L2.pauseThreshold, 10) : 200;

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

// Velocity tracker
var _velocitySamples = [];  // last 10 velocity readings
var _lastSampleY     = 0;
var _lastSampleT     = 0;
var _velocity        = 0;   // smoothed velocity (px/s, signed: + = down)
var _speed           = 0;   // absolute velocity
var _peakSpeed       = 0;   // recent peak (for deceleration detection)
var _peakDecayT      = 0;   // timestamp of last peak update
var _isDecelerating  = false;
var _isPaused        = false;
var _pauseStartT     = 0;   // when current pause began

// Visitor classification
var _visitorType    = 'unknown';  // reader | scanner | fast-scanner
var _scrollSpeed    = 0;          // dashboard compat alias

// Scroll depth & direction
var _maxScrollDepth   = 0;
var _dirChanges       = 0;
var _lastScrollDir    = 0;    // 1=down, -1=up, 0=initial
var _scrollDirection  = 'down'; // current scroll direction: 'down' | 'up'

// Tab visibility tracking
var _hiddenTime      = 0;
var _hiddenSince     = 0;

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
 * Detects deceleration (speed drops >50% from recent peak) and
 * pause (velocity < 30px/s for PAUSE_THRESHOLD_MS).
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

		// Pause detection: velocity below threshold
		if (_speed < PAUSE_VELOCITY) {
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
	var content = document.querySelector('.single-content');
	if (!content) return null;

	var candidates = content.querySelectorAll('p');
	if (!candidates.length) return null;

	var scrollY   = window.pageYOffset || 0;
	var bestScore = -Infinity;
	var bestEl    = null;

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

	// Multi-size: let GPT auction pick highest-paying creative
	var sizes = IS_DESKTOP
		? [[336, 280], [300, 250], [300, 600], [250, 250], [300, 100]]
		: [[300, 250], [336, 280], [250, 250], [300, 100]];

	var sizeMapping = IS_DESKTOP
		? googletag.sizeMapping()
			.addSize([1025, 0], [[336, 280], [300, 250], [300, 600]])
			.addSize([768, 0],  [[336, 280], [300, 250], [300, 600]])
			.addSize([468, 0],  [[300, 250], [336, 280], [250, 250]])
			.addSize([320, 0],  [[300, 250], [250, 250]])
			.addSize([0, 0],    [[250, 250], [300, 100]])
			.build()
		: googletag.sizeMapping()
			.addSize([468, 0],  [[300, 250], [336, 280], [250, 250]])
			.addSize([320, 0],  [[300, 250], [250, 250]])
			.addSize([0, 0],    [[250, 250], [300, 100]])
			.build();

	var scrollY = window.pageYOffset || 0;
	var elRect  = container.getBoundingClientRect();
	var adY     = elRect.top + scrollY + elRect.height / 2;
	var adSpacing = getNearestAdDistance(adY);
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
		destroyed:         false
	};

	_dynamicSlots.push(record);

	// Define and display via GPT
	googletag.cmd.push(function() {
		var slot = googletag.defineSlot(
			SLOT_PATH + 'Ad.Plus-300x250',
			sizes,
			divId
		);
		if (slot) {
			slot.defineSizeMapping(sizeMapping);
			slot.addService(googletag.pubads());
			slot.setTargeting('refresh', 'true');
			slot.setTargeting('pos', 'dynamic');
			slot.setTargeting('strategy', injectionType || 'unknown');
			googletag.display(divId);
			record.slot = slot;
		}
	});

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
 * 6. VIEWPORT REFRESH (paused reading)
 *
 * When user pauses for >5s, refresh in-view dynamic ads.
 * - 30s minimum between refreshes (Google policy)
 * - Max 2 refreshes per slot
 * - Tab must be visible
 * ================================================================ */

function checkViewportRefresh() {
	if (document.hidden) return;
	if (!_isPaused) return;

	var pauseDuration = Date.now() - _pauseStartT;
	if (pauseDuration < VP_REFRESH_DELAY) return;

	var now = Date.now();
	var vpH = window.innerHeight;

	for (var i = 0; i < _dynamicSlots.length; i++) {
		var rec = _dynamicSlots[i];
		if (rec.destroyed || !rec.slot || !rec.filled) continue;
		if (rec.refreshCount >= MAX_REFRESH_DYN) continue;

		// Check timing (30s minimum)
		var lastTime = rec.lastRefresh || rec.injectedAt;
		if (now - lastTime < REFRESH_INTERVAL) continue;

		// Check viewability (50%+ in viewport)
		var rect = rec.el.getBoundingClientRect();
		if (rect.height < 10) continue;
		var visibleH = Math.max(0, Math.min(rect.bottom, vpH) - Math.max(rect.top, 0));
		var ratio    = visibleH / rect.height;
		if (ratio < 0.5) continue;

		// Refresh this slot
		googletag.cmd.push(function() {
			var slot = rec.slot;
			if (slot) googletag.pubads().refresh([slot]);
		});

		rec.refreshCount++;
		rec.lastRefresh = now;
		rec.viewable    = false;

		log('Viewport refresh:', rec.divId, 'count:', rec.refreshCount, 'pause:', Math.round(pauseDuration / 1000) + 's');
		pushEvent('dynamic_ad_refreshed', {
			divId:        rec.divId,
			refreshCount: rec.refreshCount,
			strategy:     'viewport_refresh',
			viewDuration: pauseDuration
		});
		if (window.__plAdTracker) {
			window.__plAdTracker.track('refresh', rec.divId, {
				slotType:        'dynamic',
				injectionType:   'viewport_refresh',
				scrollDirection: _scrollDirection,
				scrollSpeed:     _scrollSpeed
			});
		}
	}
}

/* ================================================================
 * 7. PREDICTIVE INJECTION ENGINE (100ms loop)
 *
 * Three strategies evaluated every tick:
 * 1. PAUSE — user stopped scrolling → inject at viewport center
 * 2. PREDICTIVE — user decelerating → inject at predicted stop
 * 3. VIEWPORT REFRESH — user paused >5s → refresh in-view ads
 * ================================================================ */

function engineLoop() {
	// Video check runs independently of display ad guards
	checkVideoInjection();

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
		// Too dense — skip to viewport refresh only
		checkViewportRefresh();
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

	// Strategy 3: VIEWPORT REFRESH — user paused long enough, refresh in-view ads
	checkViewportRefresh();
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

	if (event.isEmpty) {
		// Collapse — remove container
		rec.el.style.minHeight = '0';
		rec.el.style.margin    = '0';
		rec.el.style.overflow  = 'hidden';
		rec.filled = false;
		log('Dynamic empty:', divId);
		pushEvent('dynamic_ad_empty', { divId: divId });
		if (window.__plAdTracker) {
			window.__plAdTracker.track('empty', divId, { slotType: 'dynamic' });
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

	log('Dynamic filled:', divId, size);
	pushEvent('dynamic_ad_filled', { divId: divId, size: size });
	if (window.__plAdTracker) {
		window.__plAdTracker.track('impression', divId, { slotType: 'dynamic', creativeSize: size ? size[0] + 'x' + size[1] : '' });
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

	// Only on single posts
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
			predictedDistance: s.predictedDistance || 0
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
		videoInjected:       !!window.__plVideoInjected
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
			adSpacing:         ds.adSpacing || 0
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
		setInterval(engineLoop, ENGINE_LOOP_MS);

		// Start heartbeat for live sessions dashboard
		startHeartbeat();

		// Passive scroll listener for immediate velocity updates
		window.addEventListener('scroll', sampleVelocity, { passive: true });

		// Update dashboard every 5s
		setInterval(updateDashboard, 5000);

		// Track tab visibility for active time
		document.addEventListener('visibilitychange', function() {
			if (document.visibilityState === 'hidden') {
				_hiddenSince = Date.now();
				sendBeacon();
			} else {
				if (_hiddenSince > 0) {
					_hiddenTime += Date.now() - _hiddenSince;
					_hiddenSince = 0;
				}
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
				setInterval(engineLoop, ENGINE_LOOP_MS);

				// Start heartbeat for live sessions dashboard
				startHeartbeat();

				// Passive scroll listener for immediate velocity updates
				window.addEventListener('scroll', sampleVelocity, { passive: true });

				setInterval(updateDashboard, 5000);
				document.addEventListener('visibilitychange', function() {
					if (document.visibilityState === 'hidden') {
						_hiddenSince = Date.now();
						sendBeacon();
					} else {
						if (_hiddenSince > 0) {
							_hiddenTime += Date.now() - _hiddenSince;
							_hiddenSince = 0;
						}
					}
				});
				window.addEventListener('pagehide', sendBeacon);
			}
		}, 500);
	}
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

})();
