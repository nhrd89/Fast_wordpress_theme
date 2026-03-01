/**
 * PinLightning Ad System — Layer 1: Initial Viewport Ads
 *
 * Loads GPT after window.load (via postload handler), defines all
 * initial-viewport slots in a single SRA batch, displays them,
 * and sets up overlay refresh via impressionViewable.
 *
 * Exposes window.__initialAds API for Layer 2 (smart-ads.js).
 *
 * Loaded post-window.load + 100ms — invisible to Lighthouse.
 */
;(function() {
'use strict';

/* ================================================================
 * CONFIG
 * ================================================================ */

var SLOT_PATH  = (typeof plAds !== 'undefined' && plAds.slotPrefix) ? plAds.slotPrefix : '/21849154601,22953639975/';
var GPT_URL    = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
var DEBUG      = typeof plAds !== 'undefined' && plAds.debug;
var IS_DESKTOP = window.innerWidth >= 1025;
var FMT        = (typeof plAds !== 'undefined' && plAds.formats) ? plAds.formats : {};
var REF_CFG    = (typeof plAds !== 'undefined' && plAds.refresh) ? plAds.refresh : {};
var VW_CFG     = (typeof plAds !== 'undefined' && plAds.viewability) ? plAds.viewability : {};

/* ================================================================
 * STATE
 * ================================================================ */

var _readyCallbacks = [];
var _slotMap        = {};  // divId → {slot, type, refreshCount, lastRefresh, maxRefresh, renderedSize, viewable}
var _overlaySlots   = {};

// Module-scope overlay slot references (FIX A: accessible by setInterval, event handlers)
var _anchorSlot     = null;
var _leftRailSlot   = null;
var _rightRailSlot  = null;
var _interstitialSlot = null;

// Shared viewable counter (FIX D: read by smart-ads.js heartbeat/beacon)
window.__plViewableCount = 0;

// Overlay status tracking — event-driven, read by smart-ads.js heartbeat/beacon.
// States: 'off' (format disabled) → 'pending' (slot defined, waiting for GPT) →
//         'empty' (slotRenderEnded isEmpty) | 'filled' (rendered) → 'viewable' (impressionViewable)
window.__plOverlayStatus = {
	interstitial: 'off',
	bottomAnchor: 'off',
	topAnchor:    'off',   // Removed — kept for beacon/heartbeat compat
	leftRail:     'off',
	rightRail:    'off'
};
var _overlayDivMap = {
	'__interstitial': 'interstitial',
	'__anchor':       'bottomAnchor',
	'__leftRail':     'leftRail',
	'__rightRail':    'rightRail'
};

// Event tracking
var _bootTime   = Date.now();
var _sessionId  = 'pl_' + Math.random().toString(36).substr(2, 12);
var _device     = window.innerWidth >= 1025 ? 'desktop' : (window.innerWidth >= 768 ? 'tablet' : 'mobile');
var _pageType   = document.body && document.body.classList.contains('single') ? 'single' : (document.body && document.body.classList.contains('home') ? 'home' : 'archive');
var _unitMap    = {};
var _eventBatch = [];

/* ================================================================
 * PUBLIC API — consumed by smart-ads.js (Layer 2)
 * ================================================================ */

window.__initialAds = {
	ready:    false,
	gptReady: false,

	/**
	 * Register callback for when all initial slots are defined.
	 */
	onReady: function(cb) {
		if (window.__initialAds.ready) { cb(); }
		else { _readyCallbacks.push(cb); }
	},

	/**
	 * Return exclusion zones (250px buffer around each initial ad).
	 * Used by Layer 2 to avoid injecting too close.
	 */
	getExclusionZones: function() {
		var zones  = [];
		var ids    = Object.keys(_slotMap);
		var scrollY = window.pageYOffset || 0;
		for (var i = 0; i < ids.length; i++) {
			var el = document.getElementById(ids[i]);
			if (!el) continue;
			var rect = el.getBoundingClientRect();
			zones.push({
				top:    rect.top  + scrollY - 250,
				bottom: rect.bottom + scrollY + 250
			});
		}
		return zones;
	},

	/**
	 * Smart-refresh a specific slot (checks viewability first).
	 */
	refreshSlot: function(divId) {
		var info = _slotMap[divId];
		if (!info || !info.slot) return;
		if (info.maxRefresh !== -1 && info.refreshCount >= info.maxRefresh) return;
		googletag.cmd.push(function() {
			googletag.pubads().refresh([info.slot]);
		});
		info.lastRefresh = Date.now();
		info.refreshCount++;
	},

	getSlotMap: function() { return _slotMap; }
};

/* ================================================================
 * HELPERS
 * ================================================================ */

function log() {
	if (!DEBUG) return;
	var args = ['[InitialAds]'];
	for (var i = 0; i < arguments.length; i++) args.push(arguments[i]);
	console.log.apply(console, args);
}

function pushEvent(name, data) {
	window.__plAdEvents = window.__plAdEvents || [];
	data.timestamp = Date.now();
	data.event     = name;
	window.__plAdEvents.push(data);
}

function fmtOn(key) {
	var v = FMT[key];
	if (v === undefined || v === null) return true;
	return !!v && v !== 'false' && v !== '0';
}

function refMax(key, fallback) {
	var cfg = REF_CFG[key];
	if (!cfg) return fallback;
	if (!cfg.enabled || cfg.enabled === 'false' || cfg.enabled === '0') return 0;
	return cfg.max !== undefined ? parseInt(cfg.max, 10) : fallback;
}

function refDelay(slotType) {
	var map = { initial:'initial', sidebar:'sidebar', pause:'pause',
	            anchor:'anchor', sideRail:'sideRails' };
	var cfg = REF_CFG[map[slotType] || slotType];
	if (!cfg) return (slotType === 'anchor' || slotType === 'sideRail') ? 30000 : 45000;
	if (!cfg.enabled || cfg.enabled === 'false' || cfg.enabled === '0') return 0;
	return cfg.delay ? parseInt(cfg.delay, 10) * 1000 : 30000;
}

function trackAdEvent(eventType, slotId, extra) {
	extra = extra || {};
	var info = _slotMap[slotId] || {};
	var evt = {
		e:  eventType,
		s:  slotId,
		u:  _unitMap[slotId] || '',
		t:  info.type || extra.slotType || '',
		cs: extra.creativeSize || (info.renderedSize ? info.renderedSize[0] + 'x' + info.renderedSize[1] : ''),
		rc: info.refreshCount || 0,
		ts: Date.now()
	};
	// Pass through injection analytics fields if present
	if (extra.injectionType)               evt.it  = extra.injectionType;
	if (extra.scrollSpeed !== undefined)    evt.ss  = Math.round(extra.scrollSpeed);
	if (extra.predictedDistance !== undefined) evt.pd = Math.round(extra.predictedDistance);
	if (extra.adSpacing !== undefined)      evt.sp  = Math.round(extra.adSpacing);
	if (extra.timeToViewable !== undefined) evt.ttv = Math.round(extra.timeToViewable);
	if (extra.scrollDirection)             evt.sd  = extra.scrollDirection;
	if (extra.nearImage)                   evt.ni  = 1;
	if (extra.adsInViewport !== undefined) evt.aiv = extra.adsInViewport;
	if (extra.adDensityPercent !== undefined) evt.adp = extra.adDensityPercent;
	if (extra.gptResponseMs !== undefined) evt.grm = Math.round(extra.gptResponseMs);
	if (extra.relocated)                   evt.rel = 1;
	_eventBatch.push(evt);
}

function flushEvents() {
	if (!_eventBatch.length) return;
	if (typeof plAds === 'undefined' || !plAds.ajaxUrl) return;

	var scrollY = window.pageYOffset || 0;
	var docH    = document.documentElement.scrollHeight || 1;
	var vt      = window.__plAdDashboard && window.__plAdDashboard.layer2
	            ? window.__plAdDashboard.layer2.visitorType : 'unknown';
	var payload = JSON.stringify({
		sid:         _sessionId,
		device:      _device,
		pageType:    _pageType,
		postId:      plAds.postId || 0,
		scrollPct:   Math.round((scrollY + window.innerHeight) / docH * 100),
		timeOnPage:  Math.round((Date.now() - _bootTime) / 1000),
		visitorType: vt,
		events:      _eventBatch
	});

	if (navigator.sendBeacon) {
		navigator.sendBeacon(
			plAds.ajaxUrl + '?action=pl_ad_event',
			new Blob([payload], { type: 'application/json' })
		);
	}
	_eventBatch = [];
}

window.__plAdTracker = {
	track:     trackAdEvent,
	flush:     flushEvents,
	sessionId: _sessionId,
	device:    _device,
	pageType:  _pageType
};

/* ================================================================
 * BOOT
 * ================================================================ */

function boot() {
	if (typeof plAds === 'undefined') {
		log('plAds not found — ads disabled');
		return;
	}
	// Check for ad containers on this page.
	if (!document.getElementById('initial-ad-1')) {
		log('No ad containers found — ads disabled');
		return;
	}
	console.log('[InitialAds] Format toggles:', JSON.stringify(FMT));
	log('Booting — loading GPT');
	loadGPT();
}

function loadGPT() {
	window.googletag = window.googletag || { cmd: [] };

	// If GPT is already loaded (e.g. by a cached old script), skip the <script> tag
	// and go straight to slot definitions.
	if (window.googletag && window.googletag.apiReady) {
		log('GPT already loaded — reusing');
		initSlots();
		return;
	}

	var s   = document.createElement('script');
	s.src   = GPT_URL;
	s.async = true;
	s.crossOrigin = 'anonymous';
	s.onload = function() {
		log('GPT loaded');
		initSlots();
	};
	s.onerror = function() {
		log('GPT failed to load');
	};
	document.head.appendChild(s);
}

/* ================================================================
 * SLOT DEFINITIONS
 * ================================================================ */

function initSlots() {
	googletag.cmd.push(function() {

		/* --- Size Mappings --- */

		// In-content slot 1 (after para 2): medium formats only, no 300x600 (too tall between paragraphs)
		var contentSizeMap1 = googletag.sizeMapping()
			.addSize([1025, 0], [[336, 280], [300, 250]])
			.addSize([768, 0],  [[336, 280], [300, 250]])
			.addSize([468, 0],  [[300, 250], [336, 280]])
			.addSize([320, 0],  [[300, 250]])
			.addSize([0, 0],    [[300, 250], [300, 100]])
			.build();

		// In-content slot 2 (after para 4): includes 300x600 — user engaged, taller ad acceptable
		var contentSizeMap2 = googletag.sizeMapping()
			.addSize([1025, 0], [[336, 280], [300, 250], [300, 600]])
			.addSize([768, 0],  [[336, 280], [300, 250], [300, 600]])
			.addSize([468, 0],  [[300, 250], [336, 280]])
			.addSize([320, 0],  [[300, 250]])
			.addSize([0, 0],    [[300, 250], [300, 100]])
			.build();

		/* --- GPT Global Config --- */

		// If a stale cached script already initialized GPT, destroy its slots first.
		var existingSlots = googletag.pubads().getSlots ? googletag.pubads().getSlots() : [];
		if (existingSlots.length) {
			log('Destroying', existingSlots.length, 'stale slots from cached script');
			googletag.destroySlots(existingSlots);
		}

		googletag.pubads().enableSingleRequest();   // SRA: one HTTP request for all initial slots
		googletag.pubads().collapseEmptyDivs();     // Collapse unfilled containers

		/* --- Overlay Slots (all devices) --- */

		// Interstitial (guard: plAds.formats.interstitial)
		if (fmtOn('interstitial')) {
			var interstitial = googletag.defineOutOfPageSlot(
				SLOT_PATH + 'Ad.Plus-Interstitial',
				googletag.enums.OutOfPageFormat.INTERSTITIAL
			);
			if (interstitial) {
				interstitial.addService(googletag.pubads());
				_overlaySlots.interstitial = interstitial;
				_interstitialSlot = interstitial;
				_slotMap['__interstitial'] = {
					slot: interstitial, type: 'interstitial',
					refreshCount: 0, lastRefresh: 0, maxRefresh: 0,
					renderedSize: null, viewable: false
				};
				window.__plOverlayStatus.interstitial = 'pending';
			}
		} else { log('Interstitial SKIPPED — disabled'); }

		// Bottom Anchor (guard: plAds.formats.anchor)
		if (fmtOn('anchor')) {
			var anchor = googletag.defineOutOfPageSlot(
				SLOT_PATH + 'Ad.Plus-Anchor',
				googletag.enums.OutOfPageFormat.BOTTOM_ANCHOR
			);
			if (anchor) {
				anchor.addService(googletag.pubads());
				_overlaySlots.anchor = anchor;
				_anchorSlot = anchor;
				_slotMap['__anchor'] = {
					slot: anchor, type: 'anchor',
					refreshCount: 0, lastRefresh: 0, maxRefresh: refMax('anchor', -1),
					renderedSize: null, viewable: false
				};
				window.__plOverlayStatus.bottomAnchor = 'pending';
			}
		} else { log('Anchor SKIPPED — disabled'); }

		// Top Anchor — REMOVED: "Format already created on the page" conflict with bottom anchor
		// + GPT does not support TOP_ANCHOR on most pages/devices. Zero demand in 27 sessions.

		// Side Rails (guard: plAds.formats.sideRails)
		if (IS_DESKTOP && fmtOn('sideRails')) {
			var leftRail = googletag.defineOutOfPageSlot(
				SLOT_PATH + 'Ad.Plus-Side-Anchor',
				googletag.enums.OutOfPageFormat.LEFT_SIDE_RAIL
			);
			if (leftRail) {
				leftRail.addService(googletag.pubads());
				_overlaySlots.leftRail = leftRail;
				_leftRailSlot = leftRail;
				_slotMap['__leftRail'] = {
					slot: leftRail, type: 'sideRail',
					refreshCount: 0, lastRefresh: 0, maxRefresh: refMax('sideRails', -1),
					renderedSize: null, viewable: false
				};
				window.__plOverlayStatus.leftRail = 'pending';
			}

			var rightRail = googletag.defineOutOfPageSlot(
				SLOT_PATH + 'Ad.Plus-Side-Anchor',
				googletag.enums.OutOfPageFormat.RIGHT_SIDE_RAIL
			);
			if (rightRail) {
				rightRail.addService(googletag.pubads());
				_overlaySlots.rightRail = rightRail;
				_rightRailSlot = rightRail;
				_slotMap['__rightRail'] = {
					slot: rightRail, type: 'sideRail',
					refreshCount: 0, lastRefresh: 0, maxRefresh: refMax('sideRails', -1),
					renderedSize: null, viewable: false
				};
				window.__plOverlayStatus.rightRail = 'pending';
			}
		}

		/* --- Initial In-Content Slots (only on single posts) --- */

		// Slot 1 — after paragraph 2 (medium formats, no tall)
		if (fmtOn('initial1') && document.getElementById('initial-ad-1')) {
			var slot1 = googletag.defineSlot(
				SLOT_PATH + 'Ad.Plus-336x280',
				[[336, 280], [300, 250], [300, 100]],
				'initial-ad-1'
			);
			if (slot1) {
				slot1.defineSizeMapping(contentSizeMap1);
				slot1.addService(googletag.pubads());
				slot1.setTargeting('refresh', 'true');
				slot1.setTargeting('pos', 'atf');
				_slotMap['initial-ad-1'] = {
					slot: slot1, type: 'initial',
					refreshCount: 0, lastRefresh: 0, maxRefresh: refMax('initial', 3),
					renderedSize: null, viewable: false
				};
			}
		}

		// Slot 2 — after paragraph 4 (taller formats allowed)
		if (fmtOn('initial2') && document.getElementById('initial-ad-2')) {
			var slot2 = googletag.defineSlot(
				SLOT_PATH + 'Ad.Plus-336x280',
				[[336, 280], [300, 250], [300, 600], [300, 100]],
				'initial-ad-2'
			);
			if (slot2) {
				slot2.defineSizeMapping(contentSizeMap2);
				slot2.addService(googletag.pubads());
				slot2.setTargeting('refresh', 'true');
				slot2.setTargeting('pos', 'atf');
				_slotMap['initial-ad-2'] = {
					slot: slot2, type: 'initial',
					refreshCount: 0, lastRefresh: 0, maxRefresh: refMax('initial', 3),
					renderedSize: null, viewable: false
				};
			}
		}

		/* --- Sidebar Slots (desktop only) --- */
		/* Sidebar widened to 348px with 24px padding = 300px content area.
		   300x250, 300x600, 200x200, 160x600 all fit. */

		if (IS_DESKTOP) {

			// Primary sidebar: tall + medium formats — maximum auction competition
			var sidebarSizeMap1 = googletag.sizeMapping()
				.addSize([1025, 0], [[300, 600], [300, 250], [200, 200], [160, 600]])
				.addSize([0, 0],    [])  // Hide on mobile/tablet
				.build();

			if (fmtOn('sidebar1') && document.getElementById('300x600-1')) {
				var sb1 = googletag.defineSlot(
					SLOT_PATH + 'Ad.Plus-300x600',
					[[300, 600], [300, 250], [200, 200], [160, 600]],
					'300x600-1'
				);
				if (sb1) {
					sb1.defineSizeMapping(sidebarSizeMap1);
					sb1.addService(googletag.pubads());
					sb1.setTargeting('refresh', 'true');
					sb1.setTargeting('pos', 'sidebar');
					_slotMap['300x600-1'] = {
						slot: sb1, type: 'sidebar',
						refreshCount: 0, lastRefresh: 0, maxRefresh: refMax('sidebar', 3),
						renderedSize: null, viewable: false
					};
				}
			}

			// Secondary sidebar: medium formats (no 300x600 — two tall ads would overwhelm)
			var sidebarSizeMap2 = googletag.sizeMapping()
				.addSize([1025, 0], [[300, 250], [200, 200]])
				.addSize([0, 0],    [])  // Hide on mobile/tablet
				.build();

			if (fmtOn('sidebar2') && document.getElementById('300x250-sidebar')) {
				var sb2 = googletag.defineSlot(
					SLOT_PATH + 'Ad.Plus-300x250',
					[[300, 250], [200, 200]],
					'300x250-sidebar'
				);
				if (sb2) {
					sb2.defineSizeMapping(sidebarSizeMap2);
					sb2.addService(googletag.pubads());
					sb2.setTargeting('refresh', 'true');
					sb2.setTargeting('pos', 'sidebar');
					_slotMap['300x250-sidebar'] = {
						slot: sb2, type: 'sidebar',
						refreshCount: 0, lastRefresh: 0, maxRefresh: refMax('sidebar', 3),
						renderedSize: null, viewable: false
					};
				}
			}
		}

		/* --- Pause Banner Slot (single posts only) --- */
		/* GPT contentPause: ad appears when user stops scrolling.
		   min-height:0 — no space reserved until activation. */

		if (fmtOn('pause') && document.getElementById('pause-ad-1')) {
			var pauseSlot = googletag.defineSlot(
				SLOT_PATH + 'Ad.Plus-Pause-300x250',
				[300, 250],
				'pause-ad-1'
			);
			if (pauseSlot) {
				pauseSlot.addService(googletag.pubads());
				pauseSlot.setConfig({ contentPause: true });
				pauseSlot.setTargeting('pos', 'pause');
				_slotMap['pause-ad-1'] = {
					slot: pauseSlot, type: 'pause',
					refreshCount: 0, lastRefresh: 0, maxRefresh: refMax('pause', 2),
					renderedSize: null, viewable: false
				};
			}
		}

		/* --- Unit Map for Event Tracking --- */
		_unitMap['__interstitial']  = 'Ad.Plus-Interstitial';
		_unitMap['__anchor']        = 'Ad.Plus-Anchor';
		_unitMap['__leftRail']      = 'Ad.Plus-Side-Anchor';
		_unitMap['__rightRail']     = 'Ad.Plus-Side-Anchor';
		_unitMap['initial-ad-1']    = 'Ad.Plus-336x280';
		_unitMap['initial-ad-2']    = 'Ad.Plus-336x280';
		_unitMap['300x600-1']       = 'Ad.Plus-300x600';
		_unitMap['300x250-sidebar'] = 'Ad.Plus-300x250';
		_unitMap['pause-ad-1']      = 'Ad.Plus-Pause-300x250';

		/* --- Event Listeners --- */

		// Container resize on fill / collapse on empty
		googletag.pubads().addEventListener('slotRenderEnded', onSlotRenderEnded);

		// Viewability-based refresh scheduling
		googletag.pubads().addEventListener('impressionViewable', onImpressionViewable);

		/* --- Enable & Display --- */

		googletag.enableServices();

		// Display all display slots (overlays are auto-displayed by GPT)
		// Mobile (<768px): delay initial-ad-1 by 2s — gives user time to engage
		// before the above-fold ad loads (GPT takes ~1s, user scrolls past by then)
		var isMobile = window.innerWidth < 768;
		var displayIds = isMobile
			? ['initial-ad-2', 'pause-ad-1']
			: ['initial-ad-1', 'initial-ad-2', 'pause-ad-1'];
		if (IS_DESKTOP) {
			displayIds.push('300x600-1', '300x250-sidebar');
		}
		for (var i = 0; i < displayIds.length; i++) {
			if (document.getElementById(displayIds[i])) {
				googletag.display(displayIds[i]);
			}
		}

		// Delayed display for initial-ad-1 on mobile — 2s lets user start engaging
		if (isMobile && document.getElementById('initial-ad-1')) {
			setTimeout(function() {
				googletag.cmd.push(function() {
					googletag.display('initial-ad-1');
					// SRA already fetched the creative; explicit refresh ensures it renders
					var ad1Info = _slotMap['initial-ad-1'];
					if (ad1Info && ad1Info.slot) {
						googletag.pubads().refresh([ad1Info.slot]);
					}
				});
				log('Mobile initial-ad-1 delayed display (2s)');
			}, 2000);
		}

		// Mark ready
		window.__initialAds.ready    = true;
		window.__initialAds.gptReady = true;

		var slotCount = Object.keys(_slotMap).length;
		log('All initial slots defined and displayed:', slotCount);
		pushEvent('initial_ads_loaded', { slotCount: slotCount });

		// Fire ready callbacks
		for (var j = 0; j < _readyCallbacks.length; j++) {
			try { _readyCallbacks[j](); } catch (e) { /* swallow */ }
		}
		_readyCallbacks = [];

		// Affiliate top banner — after GPT slots defined, shown on first scroll
		initAffiliateTopBanner();

		// Start event flush: every 10s + on page hide
		setInterval(flushEvents, 10000);
		document.addEventListener('visibilitychange', function() {
			if (document.visibilityState === 'hidden') flushEvents();
		});
		window.addEventListener('pagehide', flushEvents);

		// Overlay refresh via setInterval (replaces impressionViewable for overlays).
		// Uses module-scope slot refs — accessible from setInterval closures.
		var overlayDefs = [
			{ key: 'anchor',    slotRef: _anchorSlot,    divId: '__anchor',    statusKey: 'bottomAnchor' },
			{ key: 'leftRail',  slotRef: _leftRailSlot,  divId: '__leftRail',  statusKey: 'leftRail' },
			{ key: 'rightRail', slotRef: _rightRailSlot, divId: '__rightRail', statusKey: 'rightRail' }
		];
		for (var oi = 0; oi < overlayDefs.length; oi++) {
			(function(def) {
				if (!def.slotRef) return;
				var oInfo = _slotMap[def.divId];
				if (!oInfo) return;
				var oDelay = refDelay(oInfo.type);
				if (oDelay === 0) {
					log('Overlay refresh DISABLED:', def.key);
					return;
				}
				setInterval(function() {
					if (document.hidden) return;
					if (oInfo.maxRefresh !== -1 && oInfo.refreshCount >= oInfo.maxRefresh) return;
					var oStatus = window.__plOverlayStatus[def.statusKey];
					if (oStatus !== 'filled' && oStatus !== 'viewable') return;
					googletag.pubads().refresh([def.slotRef]);
					oInfo.refreshCount++;
					oInfo.lastRefresh = Date.now();
					trackAdEvent('refresh', def.divId);
					pushEvent('ad_refreshed', { divId: def.divId, refreshCount: oInfo.refreshCount, type: oInfo.type });
					console.log('[InitialAds] Overlay refresh:', def.key, '#' + oInfo.refreshCount);
				}, oDelay);
				console.log('[InitialAds]', def.key, 'setInterval REGISTERED, every', oDelay / 1000 + 's');
			})(overlayDefs[oi]);
		}
	});
}

/* ================================================================
 * GPT EVENT HANDLERS
 * ================================================================ */

function onSlotRenderEnded(event) {
	var divId     = event.slot.getSlotElementId();
	console.log('[InitialAds] slotRenderEnded:', {
		divId: divId, isEmpty: event.isEmpty, size: event.size,
		unitPath: event.slot.getAdUnitPath()
	});

	// Update overlay status — check by slot reference (out-of-page slots have auto-generated divIds)
	var statusVal = event.isEmpty ? 'empty' : 'filled';
	if (_overlayDivMap[divId]) {
		window.__plOverlayStatus[_overlayDivMap[divId]] = statusVal;
	}
	if (event.slot === _anchorSlot)       window.__plOverlayStatus.bottomAnchor = statusVal;
	if (event.slot === _leftRailSlot)     window.__plOverlayStatus.leftRail     = statusVal;
	if (event.slot === _rightRailSlot)    window.__plOverlayStatus.rightRail    = statusVal;
	if (event.slot === _interstitialSlot) window.__plOverlayStatus.interstitial = statusVal;

	var el        = document.getElementById(divId);
	if (!el) return;
	var container = el.parentElement;

	if (event.isEmpty) {
		// Collapse — shrink container to 0
		if (container && (container.classList.contains('pl-initial-ad') || container.classList.contains('pl-sidebar-ad') || container.classList.contains('pl-pause-ad'))) {
			container.style.minHeight = '0';
			container.style.margin    = '0';
			container.style.overflow  = 'hidden';
		}
		log('Empty:', divId);
		pushEvent('ad_empty', { divId: divId, isInitial: true });
		trackAdEvent('empty', divId);
		return;
	}

	// Resize container: only GROW, never shrink below original min-height.
	// initial-ad-1 starts at 280px, initial-ad-2 at 250px — shrinking causes CLS.
	var size = event.size;
	if (size && container && container.classList.contains('pl-initial-ad')) {
		var origMin = parseInt(container.style.minHeight, 10) || 250;
		if (size[1] > origMin) {
			container.style.minHeight = size[1] + 'px';
		}
		container.style.maxWidth = size[0] + 'px';
	}
	// Sidebar containers: same logic — only grow
	if (size && container && container.classList.contains('pl-sidebar-ad')) {
		var origSbMin = parseInt(container.style.minHeight, 10) || 250;
		if (size[1] > origSbMin) {
			container.style.minHeight = size[1] + 'px';
		}
	}
	if (_slotMap[divId]) {
		_slotMap[divId].renderedSize = size;
	}

	// FIX 6: Sidebar viewability check — if sidebar ad is not visible within 5s
	// of rendering, collapse it to save the impression for a refresh later
	var info = _slotMap[divId];
	if (info && info.type === 'sidebar') {
		(function(sidebarDivId, sidebarInfo) {
			setTimeout(function() {
				var sEl = document.getElementById(sidebarDivId);
				if (!sEl) return;
				var sRect = sEl.getBoundingClientRect();
				var vpH   = window.innerHeight;
				var visH  = Math.max(0, Math.min(sRect.bottom, vpH) - Math.max(sRect.top, 0));
				if (visH < 10) {
					// Sidebar ad not visible after 5s — collapse
					var sCont = sEl.parentElement;
					if (sCont && sCont.classList.contains('pl-sidebar-ad')) {
						sCont.style.minHeight = '0';
						sCont.style.height    = '0';
						sCont.style.overflow  = 'hidden';
					}
					log('Sidebar collapsed (not visible after 5s):', sidebarDivId);
					pushEvent('sidebar_collapsed_invisible', { divId: sidebarDivId });
					trackAdEvent('sidebar_collapse', sidebarDivId, { reason: 'not_visible_5s' });
				}
			}, 5000);
		})(divId, info);
	}

	log('Filled:', divId, size);
	pushEvent('ad_filled', { divId: divId, size: size, isInitial: true });
	trackAdEvent('impression', divId, { creativeSize: size ? size[0] + 'x' + size[1] : '' });
}

function onImpressionViewable(event) {
	var slot  = event.slot;
	var divId = slot.getSlotElementId();
	console.log('[InitialAds] impressionViewable:', {
		divId: divId, unitPath: slot.getAdUnitPath()
	});

	// Update overlay status by slot reference (out-of-page divIds are auto-generated)
	if (_overlayDivMap[divId])            window.__plOverlayStatus[_overlayDivMap[divId]] = 'viewable';
	if (slot === _anchorSlot)             window.__plOverlayStatus.bottomAnchor = 'viewable';
	if (slot === _leftRailSlot)           window.__plOverlayStatus.leftRail     = 'viewable';
	if (slot === _rightRailSlot)          window.__plOverlayStatus.rightRail    = 'viewable';
	if (slot === _interstitialSlot)       window.__plOverlayStatus.interstitial = 'viewable';

	var info  = _slotMap[divId];
	if (!info) {
		// Out-of-page slot — find info by slot reference
		var keys = Object.keys(_slotMap);
		for (var si = 0; si < keys.length; si++) {
			if (_slotMap[keys[si]].slot === slot) { info = _slotMap[keys[si]]; divId = keys[si]; break; }
		}
	}
	if (!info) return; // Dynamic slots handled by smart-ads.js onDynamicImpressionViewable

	info.viewable = true;

	// Shared viewable counter — Layer 1 only (Layer 2 increments in smart-ads.js)
	window.__plViewableCount = (window.__plViewableCount || 0) + 1;
	console.log('[InitialAds] VIEWABLE:', divId, 'type:', info.type,
		'__plViewableCount now=' + window.__plViewableCount);
	log('impressionViewable fired:', divId, 'type:', info.type,
		'refreshCount:', info.refreshCount, '/', info.maxRefresh);
	pushEvent('ad_viewable', { divId: divId, type: info.type });
	trackAdEvent('viewable', divId);

	// Overlays use setInterval for refresh — skip impressionViewable scheduling
	var isOverlay = (info.type === 'interstitial' || info.type === 'anchor' || info.type === 'sideRail');
	if (isOverlay) {
		console.log('[InitialAds] Skipping impressionViewable refresh for overlay:', divId);
		return;
	}

	// Schedule refresh based on format type
	// maxRefresh: -1 = unlimited, 0 = never, N = cap
	if (info.maxRefresh === 0) {
		log('Refresh SKIPPED:', divId, '— interstitial (maxRefresh=0)');
		return;
	}
	if (info.maxRefresh !== -1 && info.refreshCount >= info.maxRefresh) {
		log('Refresh SKIPPED:', divId, '— max reached (' + info.refreshCount + '/' + info.maxRefresh + ')');
		return;
	}

	// Refresh delay from optimizer config (per-format)
	var delay = refDelay(info.type);
	if (delay === 0) {
		log('Refresh SKIPPED:', divId, '— disabled for', info.type);
		return;
	}
	log('Refresh SCHEDULED:', divId, '— ' + (delay / 1000) + 's timer started',
		'(will be attempt #' + (info.refreshCount + 1) + ')');

	setTimeout(function() {
		// Double-check: tab visible, user engaged
		if (VW_CFG.skipTabHidden !== 'false' && VW_CFG.skipTabHidden !== false && document.hidden) {
			log('Refresh ABORTED:', divId, '— tab hidden');
			trackAdEvent('refresh_skip', divId);
			return;
		}

		// Viewport check for display slots (overlays already filtered above)
		var doViewportCheck = VW_CFG.viewportCheck !== 'false' && VW_CFG.viewportCheck !== false;
		if (doViewportCheck) {
			var refreshEl = document.getElementById(divId);
			if (refreshEl) {
				var rRect = refreshEl.getBoundingClientRect();
				var vpH   = window.innerHeight;
				if (rRect.height > 0) {
					// 200px margin above/below viewport, 10% default threshold
					var vpTop    = -200;
					var vpBtm    = vpH + 200;
					var visH     = Math.max(0, Math.min(rRect.bottom, vpBtm) - Math.max(rRect.top, vpTop));
					var minVis   = VW_CFG.minVisibility ? parseFloat(VW_CFG.minVisibility) / 100 : 0.1;
					var visRatio = visH / rRect.height;
					if (visRatio < minVis) {
						log('Refresh SKIP:', divId,
							'vis=' + Math.round(visRatio * 100) + '% < min=' + Math.round(minVis * 100) + '%',
							'(top:' + Math.round(rRect.top) + ' btm:' + Math.round(rRect.bottom) + ' vpH:' + vpH + ')');
						trackAdEvent('refresh_skip', divId);
						return;
					}
					log('Refresh viewport OK:', divId, Math.round(visRatio * 100) + '% visible');
				}
			}
		}

		log('Refresh EXECUTING:', divId, 'type:', info.type);
		googletag.cmd.push(function() {
			googletag.pubads().refresh([slot]);
		});
		info.refreshCount++;
		info.lastRefresh = Date.now();

		log('Refresh DONE:', divId, 'count:', info.refreshCount, '/', info.maxRefresh);
		pushEvent('ad_refreshed', {
			divId:        divId,
			refreshCount: info.refreshCount,
			type:         info.type
		});
		trackAdEvent('refresh', divId);
	}, delay);
}

/* ================================================================
 * AFFILIATE CREATIVE SYSTEM
 *
 * 9 copy variants (A1-A3 dark, B1-B3 light, C1-C3 banner) with
 * multi-armed bandit rotation. Creatives defined here so both
 * initial-ads.js (top banner) and smart-ads.js (backfill) share
 * the same variant pool and tracking.
 * ================================================================ */

/** Build affiliate URL with variant tracking for cross-site analytics. */
function getAffUrl(variantId) {
	if (typeof plAds === 'undefined' || !plAds.affiliate) return '#';
	var url = plAds.affiliate.url;
	if (!plAds.affiliate.isDirect) {
		url += (url.indexOf('?') > -1 ? '&' : '?') + 'v=' + variantId;
	}
	return url;
}

/** Return full HTML string for a given creative variant. */
window.__plAffiliateCreative = function(variantId) {
	var url = getAffUrl(variantId);

	// Shared styles
	var darkBase = 'background:linear-gradient(135deg,#0f0f23 0%,#1a1a3e 100%);border-radius:14px;padding:22px;text-align:center;max-width:336px;margin:0 auto;cursor:pointer;font-family:-apple-system,system-ui,sans-serif;border:1px solid rgba(255,107,53,0.2);transition:transform 0.2s,box-shadow 0.2s;';
	var lightBase = 'background:#f0fff4;border:2px solid #00D4AA;border-radius:12px;padding:18px;text-align:center;max-width:300px;margin:0 auto;cursor:pointer;font-family:-apple-system,system-ui,sans-serif;transition:transform 0.2s,border-color 0.2s;';
	var bannerBase = 'background:linear-gradient(90deg,#0f0f23,#1a1a3e);padding:10px 16px;display:flex;align-items:center;justify-content:center;gap:14px;cursor:pointer;font-family:-apple-system,system-ui,sans-serif;';
	var badgeGlow = 'animation:plGlow 1.5s infinite;';
	var ctaPulse = 'animation:plPulse 2s infinite;';
	var ctaPulseTeal = 'animation:plPulseTeal 2s infinite;';
	var footer = '<div style="color:#555;font-size:10px;margin-top:10px">Sponsored</div>';

	var variants = {
		'A1': '<a href="' + url + '" class="pl-affiliate-link" rel="sponsored" target="_blank" style="text-decoration:none;display:block;' + darkBase + '">' +
			'<div class="pl-aff-badge" style="color:#FF6B35;margin-bottom:10px;' + badgeGlow + '">\uD83D\uDD25 FREE FOR 7 DAYS</div>' +
			'<div style="color:#fff;font-size:19px;font-weight:800;line-height:1.3;margin-bottom:8px">Turn Your Pinterest Hobby Into <span style="color:#00D4AA">$3K/Month</span></div>' +
			'<div style="color:#a8a8c0;font-size:13px;margin-bottom:14px">170,000+ students already earning from pins</div>' +
			'<div class="pl-aff-cta" style="background:#FF6B35;color:#fff;border-radius:10px;padding:11px 28px;font-size:15px;' + ctaPulse + '">Start Earning Free \u2192</div>' +
			footer + '</a>',

		'A2': '<a href="' + url + '" class="pl-affiliate-link" rel="sponsored" target="_blank" style="text-decoration:none;display:block;' + darkBase + '">' +
			'<div class="pl-aff-badge" style="color:#00D4AA;margin-bottom:10px;' + badgeGlow + '">\u26A1 TRENDING NOW</div>' +
			'<div style="color:#fff;font-size:19px;font-weight:800;line-height:1.3;margin-bottom:8px">Why <span style="color:#FF6B35">170K People</span> Quit Their 9-5 Using Pinterest</div>' +
			'<div style="color:#a8a8c0;font-size:13px;margin-bottom:14px">The #1 rated Pinterest income course</div>' +
			'<div class="pl-aff-cta" style="background:#00D4AA;color:#0f0f23;border-radius:10px;padding:11px 28px;font-size:15px;' + ctaPulseTeal + '">See How They Did It \u2192</div>' +
			footer + '</a>',

		'A3': '<a href="' + url + '" class="pl-affiliate-link" rel="sponsored" target="_blank" style="text-decoration:none;display:block;' + darkBase + '">' +
			'<div class="pl-aff-badge" style="color:#FF6B35;margin-bottom:10px;' + badgeGlow + '">\uD83D\uDCCC YOU\'RE ALREADY HALFWAY THERE</div>' +
			'<div style="color:#fff;font-size:19px;font-weight:800;line-height:1.3;margin-bottom:8px">You Scroll Pinterest Daily \u2014 Why Not <span style="color:#00D4AA">Get Paid</span>?</div>' +
			'<div style="color:#a8a8c0;font-size:13px;margin-bottom:14px">Free class \u00B7 No experience needed</div>' +
			'<div class="pl-aff-cta" style="background:linear-gradient(90deg,#FF6B35,#ff8f65);color:#fff;border-radius:10px;padding:11px 28px;font-size:15px;' + ctaPulse + '">Show Me How \u2192</div>' +
			footer + '</a>',

		'B1': '<a href="' + url + '" class="pl-affiliate-link" rel="sponsored" target="_blank" style="text-decoration:none;display:block;' + lightBase + '">' +
			'<div style="color:#0f0f23;font-size:16px;font-weight:700;margin-bottom:8px">\uD83D\uDCCC You Love Pinterest. It Can <span style="color:#FF6B35">Love You Back</span>.</div>' +
			'<div style="color:#555;font-size:13px;margin-bottom:12px">Free class \u2192 <b style="color:#00D4AA">$3K/month</b> from pins</div>' +
			'<div style="color:#FF6B35;font-weight:700;font-size:15px">Start Free \u2192</div>' +
			'<div style="color:#999;font-size:10px;margin-top:10px">Sponsored</div></a>',

		'B2': '<a href="' + url + '" class="pl-affiliate-link" rel="sponsored" target="_blank" style="text-decoration:none;display:block;' + lightBase + '">' +
			'<div style="color:#0f0f23;font-size:16px;font-weight:700;margin-bottom:8px">\uD83D\uDCCC What If Every Pin You Saved <span style="color:#00D4AA">Made You Money</span>?</div>' +
			'<div style="color:#555;font-size:13px;margin-bottom:12px">170K students \u00B7 Free to start</div>' +
			'<div style="color:#00D4AA;font-weight:700;font-size:15px">Learn How \u2192</div>' +
			'<div style="color:#999;font-size:10px;margin-top:10px">Sponsored</div></a>',

		'B3': '<a href="' + url + '" class="pl-affiliate-link" rel="sponsored" target="_blank" style="text-decoration:none;display:block;' + lightBase + '">' +
			'<div style="color:#0f0f23;font-size:16px;font-weight:700;margin-bottom:8px">\uD83D\uDCCC Your Pinterest Addiction Could <span style="color:#FF6B35">Pay Your Rent</span></div>' +
			'<div style="color:#555;font-size:13px;margin-bottom:12px">Free 7-day class \u00B7 No credit card</div>' +
			'<div class="pl-aff-cta" style="background:#FF6B35;color:#fff;border-radius:8px;padding:8px 20px;font-size:14px;' + ctaPulse + '">Try It Free \u2192</div>' +
			'<div style="color:#999;font-size:10px;margin-top:10px">Sponsored</div></a>',

		'C1': '<a href="' + url + '" class="pl-affiliate-link" rel="sponsored" target="_blank" style="text-decoration:none;' + bannerBase + '">' +
			'<span style="color:#fff;font-size:13px">\uD83D\uDD25 You browse Pinterest daily \u2014 learn to earn <span style="color:#00D4AA;font-weight:700">$3K/month</span> from it</span>' +
			'<span class="pl-aff-cta" style="background:#FF6B35;color:#fff;border-radius:6px;padding:5px 14px;font-size:12px;white-space:nowrap;' + ctaPulse + '">Start Free \u2192</span></a>',

		'C2': '<a href="' + url + '" class="pl-affiliate-link" rel="sponsored" target="_blank" style="text-decoration:none;' + bannerBase + '">' +
			'<span style="color:#fff;font-size:13px">\u26A1 170,000 people turned Pinterest into income. <span style="color:#FF6B35;font-weight:700">You\'re next</span>.</span>' +
			'<span class="pl-aff-cta" style="background:#00D4AA;color:#0f0f23;border-radius:6px;padding:5px 14px;font-size:12px;white-space:nowrap;' + ctaPulseTeal + '">Free Class \u2192</span></a>',

		'C3': '<a href="' + url + '" class="pl-affiliate-link" rel="sponsored" target="_blank" style="text-decoration:none;' + bannerBase + '">' +
			'<span style="color:#fff;font-size:13px">\uD83D\uDCCC Stop scrolling. <span style="color:#00D4AA;font-weight:700">Start earning</span>. Free Pinterest income class.</span>' +
			'<span class="pl-aff-cta" style="background:linear-gradient(90deg,#FF6B35,#ff8f65);color:#fff;border-radius:6px;padding:5px 14px;font-size:12px;white-space:nowrap;' + ctaPulse + '">Try Free \u2192</span></a>'
	};

	return variants[variantId] || variants['A1'];
};

/* ================================================================
 * SMART ROTATION — Multi-Armed Bandit
 *
 * Weighted random selection that learns from clicks.
 * Variants with higher CTR get served more often after 10+ impressions.
 * All localStorage access wrapped in try/catch for privacy mode.
 * ================================================================ */

function _affGetStats() {
	try {
		var raw = localStorage.getItem('pl_aff_stats');
		if (raw) return JSON.parse(raw);
	} catch (e) {}
	return {
		A1:{impressions:0,clicks:0}, A2:{impressions:0,clicks:0}, A3:{impressions:0,clicks:0},
		B1:{impressions:0,clicks:0}, B2:{impressions:0,clicks:0}, B3:{impressions:0,clicks:0},
		C1:{impressions:0,clicks:0}, C2:{impressions:0,clicks:0}, C3:{impressions:0,clicks:0}
	};
}

function _affSaveStats(stats) {
	try { localStorage.setItem('pl_aff_stats', JSON.stringify(stats)); } catch (e) {}
}

/**
 * Pick a variant using weighted random (Thompson-sampling-lite).
 * type: 'dark' | 'light' | 'banner'
 */
window.__plAffPickVariant = function(type) {
	var candidates = { dark: ['A1','A2','A3'], light: ['B1','B2','B3'], banner: ['C1','C2','C3'] };
	var pool = candidates[type] || candidates.dark;
	var stats = _affGetStats();
	var scores = [];
	var totalScore = 0;

	for (var i = 0; i < pool.length; i++) {
		var v = pool[i];
		var s = stats[v] || { impressions: 0, clicks: 0 };
		var score;
		if (s.impressions < 10) {
			score = 1.0; // Explore: not enough data
		} else {
			score = (s.clicks / s.impressions) + 0.1; // Exploit + exploration bonus
		}
		scores.push(score);
		totalScore += score;
	}

	// Weighted random selection
	var r = Math.random() * totalScore;
	var cumulative = 0;
	for (var j = 0; j < pool.length; j++) {
		cumulative += scores[j];
		if (r <= cumulative) return pool[j];
	}
	return pool[pool.length - 1];
};

window.__plAffTrackImpression = function(variantId) {
	var stats = _affGetStats();
	if (!stats[variantId]) stats[variantId] = { impressions: 0, clicks: 0 };
	stats[variantId].impressions++;
	_affSaveStats(stats);
};

window.__plAffTrackClick = function(variantId) {
	var stats = _affGetStats();
	if (!stats[variantId]) stats[variantId] = { impressions: 0, clicks: 0 };
	stats[variantId].clicks++;
	_affSaveStats(stats);
};

/* ================================================================
 * AFFILIATE TOP BANNER — shown on first scroll
 *
 * Zero layout shift on load (hidden off-screen). Bouncers never see it.
 * Dismiss persisted in sessionStorage. Shows once per session.
 * ================================================================ */

function initAffiliateTopBanner() {
	if (typeof plAds === 'undefined' || !plAds.affiliate) return;
	if (!plAds.affiliate.enabled || !plAds.affiliate.topBanner) return;

	try {
		if (sessionStorage.getItem('pl_aff_top_dismissed')) return;
	} catch (e) {}

	var bannerVariant = window.__plAffPickVariant('banner');

	var topBar = document.createElement('div');
	topBar.id = 'pl-affiliate-top';
	topBar.style.cssText = 'position:fixed;top:0;left:0;width:100%;z-index:99999;transform:translateY(-100%);transition:transform 0.3s ease;';
	topBar.innerHTML = window.__plAffiliateCreative(bannerVariant) +
		'<button id="pl-aff-top-close" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#888;font-size:18px;cursor:pointer;padding:4px 8px;z-index:1;">\u2715</button>';
	document.body.appendChild(topBar);

	var topBarShowTime = 0;

	function showTopBanner() {
		topBar.style.transform = 'translateY(0)';
		document.body.style.marginTop = topBar.offsetHeight + 'px';
		document.body.style.transition = 'margin-top 0.3s ease';
		topBarShowTime = Date.now();
		window.__plAffTrackImpression(bannerVariant);
		trackAdEvent('affiliate_impression', 'top-affiliate', {
			injectionType: 'top_banner',
			creativeSize: 'aff-' + bannerVariant
		});
	}

	window.addEventListener('scroll', function onFirstScroll() {
		showTopBanner();
		window.removeEventListener('scroll', onFirstScroll);
	}, { once: true, passive: true });

	// Close button
	document.getElementById('pl-aff-top-close').addEventListener('click', function(e) {
		e.preventDefault();
		e.stopPropagation();
		topBar.style.transform = 'translateY(-100%)';
		document.body.style.marginTop = '0';
		try { sessionStorage.setItem('pl_aff_top_dismissed', '1'); } catch (ex) {}
		trackAdEvent('affiliate_dismiss', 'top-affiliate', {
			injectionType: 'top_banner',
			creativeSize: 'aff-' + bannerVariant,
			timeToViewable: topBarShowTime ? Math.round((Date.now() - topBarShowTime) / 1000) : 0
		});
	});

	// Click tracking on the affiliate link
	var affLink = topBar.querySelector('.pl-affiliate-link');
	if (affLink) {
		affLink.addEventListener('click', function() {
			window.__plAffTrackClick(bannerVariant);
			trackAdEvent('affiliate_click', 'top-affiliate', {
				injectionType: 'top_banner',
				creativeSize: 'aff-' + bannerVariant
			});
			// sendBeacon for reliability before navigation
			if (typeof flushEvents === 'function') flushEvents();
		});
	}
}

/* ================================================================
 * ENTRY POINT
 * ================================================================ */

// This script is loaded post-window.load+100ms by the theme's postload handler.
// By the time it executes, readyState is already 'complete'.
if (document.readyState === 'complete') {
	setTimeout(boot, 100);
} else {
	window.addEventListener('load', function() {
		setTimeout(boot, 100);
	});
}

})();
