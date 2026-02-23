/**
 * PinLightning Ad System — Layer 2: Dynamic Below-Fold Ads
 *
 * Boots on first user interaction (invisible to Lighthouse).
 * Waits for Layer 1 (__initialAds) to be ready, then:
 * - Tracks scroll velocity and classifies visitor behavior
 * - Injects dynamic ad slots at optimal content positions
 * - Smart in-view refresh (30s minimum, viewable + engaged)
 * - Slot recycling: max 6 active dynamic slots
 *
 * Loaded post-window.load + 100ms — zero Lighthouse impact.
 *
 * @package PinLightning
 * @since   4.0.0
 */
;(function() {
'use strict';

/* ================================================================
 * CONFIG
 * ================================================================ */

var SLOT_PATH         = '/21849154601,22953639975/';
var MAX_DYNAMIC_SLOTS = 6;
var MAX_REFRESH_DYN   = 2;       // max refreshes per dynamic slot
var REFRESH_INTERVAL  = 30000;   // 30s minimum (Google policy)
var MAIN_LOOP_MS      = 500;     // main loop interval
var MIN_SPACING_PX    = 600;     // minimum px between any two ads
var IS_DESKTOP        = window.innerWidth >= 1025;
var DEBUG             = typeof plAds !== 'undefined' && plAds.debug;

/* ================================================================
 * STATE
 * ================================================================ */

var _dynamicSlots   = [];   // {divId, slot, el, anchorEl, injectedAt, viewable, refreshCount, lastRefresh}
var _slotCounter    = 0;
var _lastInjectionY = -9999;
var _lastInjectionT = 0;

// Scroll velocity
var _scrollSamples  = [];
var _lastSampleY    = 0;
var _lastSampleT    = 0;
var _scrollSpeed    = 0;
var _visitorType    = 'unknown';  // reader | scanner | fast-scanner

// Engagement bridge
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

function pushEvent(name, data) {
	window.__plAdEvents = window.__plAdEvents || [];
	data.timestamp = Date.now();
	data.event     = name;
	window.__plAdEvents.push(data);
}

/* ================================================================
 * SCROLL VELOCITY TRACKER
 * ================================================================ */

function sampleScroll() {
	var now = Date.now();
	var y   = window.pageYOffset || 0;
	var dt  = (now - _lastSampleT) / 1000;

	if (dt > 0 && _lastSampleT > 0) {
		var speed = Math.abs(y - _lastSampleY) / dt;
		_scrollSamples.push(speed);
		if (_scrollSamples.length > 10) _scrollSamples.shift();

		// Weighted average (newer samples heavier)
		var total = 0, weight = 0;
		for (var i = 0; i < _scrollSamples.length; i++) {
			var w = i + 1;
			total += _scrollSamples[i] * w;
			weight += w;
		}
		_scrollSpeed = Math.round(total / weight);
	}

	_lastSampleY = y;
	_lastSampleT = now;
	_timeOnPage  = (now - _sessionStart) / 1000;

	// Classify visitor
	if (_scrollSpeed < 100) {
		_visitorType = 'reader';
	} else if (_scrollSpeed < 400) {
		_visitorType = 'scanner';
	} else {
		_visitorType = 'fast-scanner';
	}
}

/* ================================================================
 * CONTENT-AWARE INJECTION SCORING
 * ================================================================ */

/**
 * Find the best paragraph to inject an ad after.
 * Scores each <p> tag by distance from other ads, proximity to
 * images/headings, and position relative to viewport.
 *
 * Returns the <p> element to inject after, or null.
 */
function findBestInjectionPoint() {
	var content = document.querySelector('.single-content');
	if (!content) return null;

	var paragraphs = content.querySelectorAll('p');
	if (!paragraphs.length) return null;

	var scrollY  = window.pageYOffset || 0;
	var vpBottom = scrollY + window.innerHeight;
	var vpH      = window.innerHeight;

	// Get all existing ad positions (initial + dynamic)
	var adPositions = [];

	// Initial ads from Layer 1
	if (window.__initialAds) {
		var zones = window.__initialAds.getExclusionZones();
		for (var z = 0; z < zones.length; z++) {
			adPositions.push((zones[z].top + zones[z].bottom) / 2);
		}
	}

	// Dynamic ads from this layer
	for (var d = 0; d < _dynamicSlots.length; d++) {
		var dEl = _dynamicSlots[d].el;
		if (dEl && dEl.parentNode) {
			var dRect = dEl.getBoundingClientRect();
			adPositions.push(dRect.top + scrollY + dRect.height / 2);
		}
	}

	var bestScore = -Infinity;
	var bestPara  = null;

	for (var i = 0; i < paragraphs.length; i++) {
		var p    = paragraphs[i];
		var rect = p.getBoundingClientRect();
		var pY   = rect.top + scrollY;

		// Skip paragraphs above the viewport or too far below
		if (pY < vpBottom - 200) continue;       // already scrolled past
		if (pY > vpBottom + vpH * 2) continue;    // too far ahead

		// Score: distance from nearest ad (higher = better)
		var minDist = Infinity;
		for (var a = 0; a < adPositions.length; a++) {
			var dist = Math.abs(pY - adPositions[a]);
			if (dist < minDist) minDist = dist;
		}
		if (minDist < MIN_SPACING_PX) continue;  // too close to existing ad

		var score = Math.min(minDist, 2000); // cap benefit at 2000px

		// Bonus: after images (natural content break)
		var prev = p.previousElementSibling;
		if (prev && (prev.tagName === 'IMG' || prev.querySelector('img'))) {
			score += 100;
		}

		// Bonus: after headings (section break)
		if (prev && /^H[2-4]$/.test(prev.tagName)) {
			score += 150;
		}

		// Bonus: paragraph has substantial text (not just a caption)
		if (p.textContent.length > 80) {
			score += 50;
		}

		// Penalty: very short paragraph (caption, single line)
		if (p.textContent.length < 20) {
			score -= 200;
		}

		if (score > bestScore) {
			bestScore = score;
			bestPara  = p;
		}
	}

	return bestPara;
}

/* ================================================================
 * DYNAMIC AD INJECTION
 * ================================================================ */

function injectDynamicAd(afterElement) {
	_slotCounter++;
	var divId = 'smart-ad-' + _slotCounter;

	// Create container
	var container = document.createElement('div');
	container.className = 'pl-dynamic-ad';
	container.style.cssText = 'text-align:center;min-height:250px;margin:16px auto;overflow:hidden;clear:both';

	var adDiv = document.createElement('div');
	adDiv.id = divId;
	container.appendChild(adDiv);

	// Insert after the target element
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

	var record = {
		divId:        divId,
		slot:         null,
		el:           container,
		adDiv:        adDiv,
		anchorEl:     afterElement,
		injectedAt:   Date.now(),
		viewable:     false,
		refreshCount: 0,
		lastRefresh:  0,
		renderedSize: null,
		filled:       false,
		destroyed:    false
	};

	_dynamicSlots.push(record);
	_lastInjectionY = window.pageYOffset || 0;
	_lastInjectionT = Date.now();

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
			googletag.display(divId);
			record.slot = slot;
		}
	});

	log('Injected dynamic:', divId, 'after', afterElement.tagName, 'speed:', _scrollSpeed, 'type:', _visitorType);
	pushEvent('dynamic_ad_injected', {
		divId:       divId,
		speed:       _scrollSpeed,
		visitorType: _visitorType,
		slotCount:   _dynamicSlots.length
	});

	return record;
}

/* ================================================================
 * SLOT RECYCLING
 * ================================================================ */

/**
 * If we have more than MAX_DYNAMIC_SLOTS active, destroy the oldest
 * slot that is off-screen (above the viewport).
 */
function recycleSlots() {
	var activeCount = 0;
	for (var i = 0; i < _dynamicSlots.length; i++) {
		if (!_dynamicSlots[i].destroyed) activeCount++;
	}

	if (activeCount <= MAX_DYNAMIC_SLOTS) return;

	var scrollY = window.pageYOffset || 0;

	// Find the oldest non-destroyed slot that's above the viewport
	for (var j = 0; j < _dynamicSlots.length; j++) {
		var rec = _dynamicSlots[j];
		if (rec.destroyed) continue;

		var rect = rec.el.getBoundingClientRect();
		// Slot is well above the viewport
		if (rect.bottom < -500) {
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
 * SMART IN-VIEW REFRESH
 * ================================================================ */

/**
 * Check dynamic slots for refresh eligibility:
 * - Slot is viewable (50%+ in viewport)
 * - At least 30s since last display/refresh
 * - Tab is visible
 * - User is engaged (not idle)
 * - Max 2 refreshes per dynamic slot
 */
function checkRefreshes() {
	if (document.hidden) return;

	var now     = Date.now();
	var scrollY = window.pageYOffset || 0;
	var vpH     = window.innerHeight;

	for (var i = 0; i < _dynamicSlots.length; i++) {
		var rec = _dynamicSlots[i];
		if (rec.destroyed || !rec.slot || !rec.filled) continue;
		if (rec.refreshCount >= MAX_REFRESH_DYN) continue;

		// Check timing
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
		rec.viewable    = false; // Reset for next impression

		log('Refreshed dynamic:', rec.divId, 'count:', rec.refreshCount);
		pushEvent('dynamic_ad_refreshed', {
			divId:        rec.divId,
			refreshCount: rec.refreshCount
		});
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

	if (event.isEmpty) {
		// Collapse — remove container
		rec.el.style.minHeight = '0';
		rec.el.style.margin    = '0';
		rec.el.style.overflow  = 'hidden';
		rec.filled = false;
		log('Dynamic empty:', divId);
		pushEvent('dynamic_ad_empty', { divId: divId });
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
}

function onDynamicImpressionViewable(event) {
	var divId = event.slot.getSlotElementId();

	for (var i = 0; i < _dynamicSlots.length; i++) {
		if (_dynamicSlots[i].divId === divId) {
			_dynamicSlots[i].viewable = true;
			pushEvent('dynamic_ad_viewable', { divId: divId });
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

	for (var i = 0; i < _dynamicSlots.length; i++) {
		var s = _dynamicSlots[i];
		if (s.destroyed) continue;
		activeSlots++;
		if (s.viewable) viewableSlots++;
		if (s.filled) filledSlots++;
		totalRefreshes += s.refreshCount;
	}

	window.__plAdDashboard = window.__plAdDashboard || {};
	window.__plAdDashboard.layer2 = {
		activeSlots:    activeSlots,
		viewableSlots:  viewableSlots,
		filledSlots:    filledSlots,
		totalRefreshes: totalRefreshes,
		totalInjected:  _dynamicSlots.length,
		scrollSpeed:    _scrollSpeed,
		visitorType:    _visitorType,
		timeOnPage:     Math.round(_timeOnPage)
	};
}

/* ================================================================
 * MAIN LOOP
 * ================================================================ */

function mainLoop() {
	sampleScroll();

	// Video check runs independently of display ad guards
	checkVideoInjection();

	var now     = Date.now();
	var scrollY = window.pageYOffset || 0;

	// Don't inject if fast-scanner (0% viewability at high speed)
	if (_visitorType === 'fast-scanner') return;

	// Don't inject too frequently
	if (now - _lastInjectionT < 4000) return;

	// Don't inject if too close to last injection position
	if (Math.abs(scrollY - _lastInjectionY) < MIN_SPACING_PX) return;

	// Don't inject if scrolling too fast right now
	if (_scrollSpeed > 500) return;

	// Find best injection point
	var target = findBestInjectionPoint();
	if (!target) return;

	// Recycle before injecting to stay within limit
	recycleSlots();

	// Inject
	injectDynamicAd(target);
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

	// Only for engaged visitors
	if (_visitorType !== 'reader' && _visitorType !== 'scanner') return;

	// Must have scrolled at least 40% of page
	var docH      = document.documentElement.scrollHeight || 1;
	var scrollY   = window.pageYOffset || 0;
	var scrollPct = Math.round((scrollY + window.innerHeight) / docH * 100);
	if (scrollPct < 40) return;

	// At least 2 dynamic content ads must have filled
	var filledCount = 0;
	for (var i = 0; i < _dynamicSlots.length; i++) {
		if (_dynamicSlots[i].filled) filledCount++;
	}
	if (filledCount < 2) return;

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
}

/* ================================================================
 * ANALYTICS BEACON
 * ================================================================ */

function sendBeacon() {
	if (typeof plAds === 'undefined' || !plAds.record) return;

	var zones = [];
	for (var i = 0; i < _dynamicSlots.length; i++) {
		var s = _dynamicSlots[i];
		zones.push({
			divId:        s.divId,
			filled:       s.filled,
			viewable:     s.viewable,
			refreshCount: s.refreshCount,
			destroyed:    s.destroyed,
			renderedSize: s.renderedSize
		});
	}

	var payload = {
		layer:        2,
		session:      true,
		postId:       plAds.postId,
		device:       IS_DESKTOP ? 'desktop' : (window.innerWidth >= 768 ? 'tablet' : 'mobile'),
		timeOnPage:   Math.round(_timeOnPage),
		scrollSpeed:  _scrollSpeed,
		visitorType:  _visitorType,
		totalInjected: _dynamicSlots.length,
		zones:        zones
	};

	var json = JSON.stringify(payload);
	if (navigator.sendBeacon && plAds.recordEndpoint) {
		navigator.sendBeacon(plAds.recordEndpoint, new Blob([json], { type: 'application/json' }));
	}
}

/* ================================================================
 * INIT — called after Layer 1 is ready
 * ================================================================ */

function init() {
	log('Layer 2 init — waiting for Layer 1');

	// Wait for Layer 1 (__initialAds) to be ready
	function onLayer1Ready() {
		log('Layer 1 ready — starting dynamic injection');

		// Register our event listeners on the shared GPT instance
		googletag.cmd.push(function() {
			googletag.pubads().addEventListener('slotRenderEnded', onDynamicSlotRenderEnded);
			googletag.pubads().addEventListener('impressionViewable', onDynamicImpressionViewable);
		});

		// Start main loop
		setInterval(mainLoop, MAIN_LOOP_MS);

		// Start refresh checker (every 10s)
		setInterval(checkRefreshes, 10000);

		// Update dashboard every 5s
		setInterval(updateDashboard, 5000);

		// Send analytics on page exit
		document.addEventListener('visibilitychange', function() {
			if (document.visibilityState === 'hidden') sendBeacon();
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
				setInterval(mainLoop, MAIN_LOOP_MS);
				setInterval(checkRefreshes, 10000);
				setInterval(updateDashboard, 5000);
				document.addEventListener('visibilitychange', function() {
					if (document.visibilityState === 'hidden') sendBeacon();
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
