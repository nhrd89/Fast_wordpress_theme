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

var SLOT_PATH  = '/21849154601,22953639975/';
var GPT_URL    = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
var DEBUG      = typeof plAds !== 'undefined' && plAds.debug;
var IS_DESKTOP = window.innerWidth >= 1025;

/* ================================================================
 * STATE
 * ================================================================ */

var _readyCallbacks = [];
var _slotMap        = {};  // divId → {slot, type, refreshCount, lastRefresh, maxRefresh, renderedSize, viewable}
var _overlaySlots   = {};

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

/* ================================================================
 * BOOT
 * ================================================================ */

function boot() {
	if (typeof plAds === 'undefined') {
		log('plAds not found — ads disabled');
		return;
	}
	if (!document.getElementById('initial-ad-1')) {
		log('No initial ad containers — not a single post or ads disabled');
		return;
	}
	log('Booting — loading GPT');
	loadGPT();
}

function loadGPT() {
	window.googletag = window.googletag || { cmd: [] };

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

		// In-content slot 1: includes 970x250 on desktop
		var contentSizeMap1 = googletag.sizeMapping()
			.addSize([1025, 0], [[336, 280], [300, 250], [970, 250], [300, 600]])
			.addSize([768, 0],  [[336, 280], [300, 250], [300, 600]])
			.addSize([468, 0],  [[300, 250], [336, 280], [250, 250]])
			.addSize([320, 0],  [[300, 250], [250, 250]])
			.addSize([0, 0],    [[250, 250], [300, 100]])
			.build();

		// In-content slot 2: no 970x250 (too aggressive for 2nd slot)
		var contentSizeMap2 = googletag.sizeMapping()
			.addSize([1025, 0], [[336, 280], [300, 250], [300, 600]])
			.addSize([768, 0],  [[336, 280], [300, 250], [300, 600]])
			.addSize([468, 0],  [[300, 250], [336, 280], [250, 250]])
			.addSize([320, 0],  [[300, 250], [250, 250]])
			.addSize([0, 0],    [[250, 250], [300, 100]])
			.build();

		/* --- GPT Global Config --- */

		googletag.pubads().enableSingleRequest();   // SRA: one HTTP request for all initial slots
		googletag.pubads().collapseEmptyDivs();     // Collapse unfilled containers

		/* --- Overlay Slots (all devices) --- */

		// Interstitial
		var interstitial = googletag.defineOutOfPageSlot(
			SLOT_PATH + 'Ad.Plus-Interstitial',
			googletag.enums.OutOfPageFormat.INTERSTITIAL
		);
		if (interstitial) {
			interstitial.addService(googletag.pubads());
			_overlaySlots.interstitial = interstitial;
			_slotMap['__interstitial'] = {
				slot: interstitial, type: 'interstitial',
				refreshCount: 0, lastRefresh: 0, maxRefresh: 0,
				renderedSize: null, viewable: false
			};
		}

		// Bottom Anchor
		var anchor = googletag.defineOutOfPageSlot(
			SLOT_PATH + 'Ad.Plus-Anchor',
			googletag.enums.OutOfPageFormat.BOTTOM_ANCHOR
		);
		if (anchor) {
			anchor.addService(googletag.pubads());
			_overlaySlots.anchor = anchor;
			_slotMap['__anchor'] = {
				slot: anchor, type: 'anchor',
				refreshCount: 0, lastRefresh: 0, maxRefresh: -1,
				renderedSize: null, viewable: false
			};
		}

		// Side Rails (desktop only)
		if (IS_DESKTOP) {
			var leftRail = googletag.defineOutOfPageSlot(
				SLOT_PATH + 'Ad.Plus-Side-Anchor',
				googletag.enums.OutOfPageFormat.LEFT_SIDE_RAIL
			);
			if (leftRail) {
				leftRail.addService(googletag.pubads());
				_overlaySlots.leftRail = leftRail;
				_slotMap['__leftRail'] = {
					slot: leftRail, type: 'sideRail',
					refreshCount: 0, lastRefresh: 0, maxRefresh: -1,
					renderedSize: null, viewable: false
				};
			}

			var rightRail = googletag.defineOutOfPageSlot(
				SLOT_PATH + 'Ad.Plus-Side-Anchor',
				googletag.enums.OutOfPageFormat.RIGHT_SIDE_RAIL
			);
			if (rightRail) {
				rightRail.addService(googletag.pubads());
				_overlaySlots.rightRail = rightRail;
				_slotMap['__rightRail'] = {
					slot: rightRail, type: 'sideRail',
					refreshCount: 0, lastRefresh: 0, maxRefresh: -1,
					renderedSize: null, viewable: false
				};
			}
		}

		/* --- Initial In-Content Slots --- */

		// Slot 1 — before paragraph 1
		var slot1 = googletag.defineSlot(
			SLOT_PATH + 'Ad.Plus-300x250',
			[[336, 280], [300, 250], [970, 250], [300, 600], [250, 250], [300, 100]],
			'initial-ad-1'
		);
		if (slot1) {
			slot1.defineSizeMapping(contentSizeMap1);
			slot1.addService(googletag.pubads());
			slot1.setTargeting('refresh', 'true');
			slot1.setTargeting('pos', 'atf');
			_slotMap['initial-ad-1'] = {
				slot: slot1, type: 'initial',
				refreshCount: 0, lastRefresh: 0, maxRefresh: 3,
				renderedSize: null, viewable: false
			};
		}

		// Slot 2 — after paragraph 2
		var slot2 = googletag.defineSlot(
			SLOT_PATH + 'Ad.Plus-300x250',
			[[336, 280], [300, 250], [300, 600], [250, 250], [300, 100]],
			'initial-ad-2'
		);
		if (slot2) {
			slot2.defineSizeMapping(contentSizeMap2);
			slot2.addService(googletag.pubads());
			slot2.setTargeting('refresh', 'true');
			slot2.setTargeting('pos', 'atf');
			_slotMap['initial-ad-2'] = {
				slot: slot2, type: 'initial',
				refreshCount: 0, lastRefresh: 0, maxRefresh: 3,
				renderedSize: null, viewable: false
			};
		}

		/* --- Sidebar Slots (desktop only) --- */

		if (IS_DESKTOP) {
			if (document.getElementById('300x600-1')) {
				var sb1 = googletag.defineSlot(
					SLOT_PATH + 'Ad.Plus-300x250',
					[[300, 600], [300, 250], [160, 600], [120, 600]],
					'300x600-1'
				);
				if (sb1) {
					sb1.addService(googletag.pubads());
					sb1.setTargeting('refresh', 'true');
					sb1.setTargeting('pos', 'sidebar');
					_slotMap['300x600-1'] = {
						slot: sb1, type: 'sidebar',
						refreshCount: 0, lastRefresh: 0, maxRefresh: 3,
						renderedSize: null, viewable: false
					};
				}
			}

			if (document.getElementById('300x250-sidebar')) {
				var sb2 = googletag.defineSlot(
					SLOT_PATH + 'Ad.Plus-300x250',
					[[300, 250], [250, 250], [300, 100]],
					'300x250-sidebar'
				);
				if (sb2) {
					sb2.addService(googletag.pubads());
					sb2.setTargeting('refresh', 'true');
					sb2.setTargeting('pos', 'sidebar');
					_slotMap['300x250-sidebar'] = {
						slot: sb2, type: 'sidebar',
						refreshCount: 0, lastRefresh: 0, maxRefresh: 3,
						renderedSize: null, viewable: false
					};
				}
			}
		}

		/* --- Event Listeners --- */

		// Container resize on fill / collapse on empty
		googletag.pubads().addEventListener('slotRenderEnded', onSlotRenderEnded);

		// Viewability-based refresh scheduling
		googletag.pubads().addEventListener('impressionViewable', onImpressionViewable);

		/* --- Enable & Display --- */

		googletag.enableServices();

		// Display all display slots (overlays are auto-displayed by GPT)
		var displayIds = ['initial-ad-1', 'initial-ad-2', '300x600-1', '300x250-sidebar'];
		for (var i = 0; i < displayIds.length; i++) {
			if (document.getElementById(displayIds[i])) {
				googletag.display(displayIds[i]);
			}
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
	});
}

/* ================================================================
 * GPT EVENT HANDLERS
 * ================================================================ */

function onSlotRenderEnded(event) {
	var divId     = event.slot.getSlotElementId();
	var el        = document.getElementById(divId);
	if (!el) return;
	var container = el.parentElement;

	if (event.isEmpty) {
		// Collapse — shrink container to 0
		if (container && (container.classList.contains('pl-initial-ad') || container.classList.contains('pl-sidebar-ad'))) {
			container.style.minHeight = '0';
			container.style.margin    = '0';
			container.style.overflow  = 'hidden';
		}
		log('Empty:', divId);
		pushEvent('ad_empty', { divId: divId, isInitial: true });
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

	log('Filled:', divId, size);
	pushEvent('ad_filled', { divId: divId, size: size, isInitial: true });
}

function onImpressionViewable(event) {
	var slot  = event.slot;
	var divId = slot.getSlotElementId();
	var info  = _slotMap[divId];
	if (!info) return;

	info.viewable = true;
	pushEvent('ad_viewable', { divId: divId, type: info.type });

	// Schedule refresh based on format type
	// maxRefresh: -1 = unlimited (overlays), 0 = never (interstitial), N = cap
	if (info.maxRefresh === 0) return; // Interstitial — no refresh
	if (info.maxRefresh !== -1 && info.refreshCount >= info.maxRefresh) return;

	// Overlay = 30s, initial in-content = 45s, sidebar = 45s
	var delay = (info.type === 'anchor' || info.type === 'sideRail') ? 30000 : 45000;

	setTimeout(function() {
		// Double-check: tab visible, user engaged
		if (document.hidden) return;

		googletag.cmd.push(function() {
			googletag.pubads().refresh([slot]);
		});
		info.refreshCount++;
		info.lastRefresh = Date.now();

		log('Refreshed:', divId, 'count:', info.refreshCount, 'type:', info.type);
		pushEvent('ad_refreshed', {
			divId:        divId,
			refreshCount: info.refreshCount,
			type:         info.type
		});
	}, delay);
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
