/**
 * PinLightning Page Ads — Non-Post Ad Injection
 *
 * Completely isolated from post ads (smart-ads.js / initial-ads.js).
 * Runs on homepages, category archives, and static pages only.
 *
 * Config: window.plPageAds (set by page-ad-engine.php)
 * CSS class prefix: .pl-page-ad
 * GPT slot ID prefix: pl-page-ad-
 * Global: window.__plPageAds
 *
 * Two-phase injection:
 *   Phase 1 (DOMContentLoaded): above-fold anchor ads rendered immediately
 *   Phase 2 (scroll): below-fold anchors rendered via IntersectionObserver (600px lookahead)
 *
 * @package PinLightning
 */
(function() {
	'use strict';

	// ── Runtime bail-out: NEVER run on single posts ──
	if ( document.querySelector('.single-post') || window.plAds ) {
		return;
	}

	var CFG = window.plPageAds;
	if ( !CFG ) {
		return;
	}

	var LOG_PREFIX  = '[PageAds]';
	var IS_DESKTOP  = window.innerWidth >= 1025;
	var SLOT_PATH   = CFG.slotPrefix || '/21849154601,22953639975/';
	var MAX_SLOTS   = CFG.maxSlots || 3;
	var SPACING     = IS_DESKTOP ? (CFG.desktopSpacing || 600) : (CFG.mobileSpacing || 400);
	var DUMMY       = CFG.dummy;
	var PAGE_TYPE   = CFG.pageType || 'unknown';

	// ── Size maps ──
	var DESKTOP_SIZES = [[336, 280], [300, 250], [300, 600], [250, 250]];
	var MOBILE_SIZES  = [[300, 250]];

	// ── State ──
	var _slots       = [];     // { divId, slot, el, filled, viewable, viewableEver, renderedSize }
	var _slotCounter = 0;
	var _gptReady    = false;
	var _viewStarts  = {};     // divId → timestamp of continuous 50% visibility

	// ── Public API ──
	window.__plPageAds = {
		getSlots: function() { return _slots; },
		getConfig: function() { return CFG; }
	};

	// ── Helpers ──
	function log() {
		if ( typeof console !== 'undefined' && console.log ) {
			var args = [LOG_PREFIX];
			for ( var i = 0; i < arguments.length; i++ ) {
				args.push( arguments[i] );
			}
			console.log.apply( console, args );
		}
	}

	function getSizes() {
		return IS_DESKTOP ? DESKTOP_SIZES : MOBILE_SIZES;
	}

	/**
	 * Build the slot name with _pg_ infix for isolation.
	 * Example: /21849154601,22953639975/site_pg_300x250
	 */
	function buildSlotName( width, height ) {
		var host = window.location.hostname.replace(/\./g, '_');
		return SLOT_PATH + host + '_pg_' + width + 'x' + height;
	}

	/**
	 * Check spacing between a candidate Y position and all existing slots.
	 */
	function checkSpacing( candidateY ) {
		for ( var i = 0; i < _slots.length; i++ ) {
			var slotEl = _slots[i].el;
			if ( !slotEl ) continue;
			var rect = slotEl.getBoundingClientRect();
			var slotY = rect.top + window.pageYOffset;
			if ( Math.abs( candidateY - slotY ) < SPACING ) {
				return false;
			}
		}
		return true;
	}

	// ── GPT Setup ──
	function initGPT() {
		if ( DUMMY ) {
			_gptReady = true;
			return;
		}

		window.googletag = window.googletag || { cmd: [] };

		// Load GPT if not already loaded.
		if ( !document.querySelector('script[src*="googletagservices"]') && !document.querySelector('script[src*="securepubads"]') ) {
			var s = document.createElement('script');
			s.src = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
			s.async = true;
			document.head.appendChild(s);
		}

		googletag.cmd.push(function() {
			googletag.pubads().enableSingleRequest();
			googletag.pubads().collapseEmptyDivs();
			googletag.enableServices();
			_gptReady = true;
			log('GPT initialized for page ads');

			// Viewability listener.
			googletag.pubads().addEventListener('impressionViewable', function(e) {
				var slotId = e.slot.getSlotElementId();
				for ( var i = 0; i < _slots.length; i++ ) {
					if ( _slots[i].divId === slotId ) {
						_slots[i].viewable = true;
						_slots[i].viewableEver = true;
						sendEvent('viewable', _slots[i]);
						log('Viewable:', slotId);
						break;
					}
				}
			});

			// Fill tracking.
			googletag.pubads().addEventListener('slotRenderEnded', function(e) {
				var slotId = e.slot.getSlotElementId();
				for ( var i = 0; i < _slots.length; i++ ) {
					if ( _slots[i].divId === slotId ) {
						_slots[i].filled = !e.isEmpty;
						if ( e.size && e.size.length === 2 ) {
							_slots[i].renderedSize = e.size[0] + 'x' + e.size[1];
						}
						sendEvent( e.isEmpty ? 'empty' : 'filled', _slots[i] );
						log( e.isEmpty ? 'Empty:' : 'Filled:', slotId, _slots[i].renderedSize );
						break;
					}
				}
			});

			// Overlays.
			if ( CFG.fmtAnchor ) {
				var anchorSlot = googletag.defineOutOfPageSlot(
					SLOT_PATH + 'Ad.Plus-Anchor',
					googletag.enums.OutOfPageFormat.BOTTOM_ANCHOR
				);
				if ( anchorSlot ) {
					anchorSlot.addService( googletag.pubads() );
					log('Anchor slot defined');
				}
			}
		});
	}

	// ── Render a single ad into an anchor div ──
	function renderSlot( anchorEl ) {
		if ( _slots.length >= MAX_SLOTS ) {
			log('Max slots reached:', MAX_SLOTS);
			return;
		}

		var anchorRect = anchorEl.getBoundingClientRect();
		var anchorY    = anchorRect.top + window.pageYOffset;

		if ( !checkSpacing( anchorY ) ) {
			log('Spacing violation at Y:', anchorY);
			return;
		}

		_slotCounter++;
		var divId = 'pl-page-ad-' + _slotCounter;
		var sizes = getSizes();

		// Create container.
		var container = document.createElement('div');
		container.className = 'pl-page-ad-slot';
		container.id = divId;
		container.style.cssText = 'min-height:250px;display:flex;align-items:center;justify-content:center;margin:20px auto;max-width:' + sizes[0][0] + 'px;';

		var record = {
			divId: divId,
			slot: null,
			el: container,
			filled: false,
			viewable: false,
			viewableEver: false,
			renderedSize: null,
			pageType: PAGE_TYPE,
			injectedAt: Date.now()
		};

		if ( DUMMY ) {
			// Dummy mode: colored placeholder.
			var size = sizes[0];
			container.style.cssText = 'width:' + size[0] + 'px;height:' + size[1] + 'px;'
				+ 'background:linear-gradient(135deg,#e8d5f5,#d5e8f5);'
				+ 'border:2px dashed #9b59b6;border-radius:8px;'
				+ 'display:flex;align-items:center;justify-content:center;'
				+ 'font:600 14px/1 system-ui;color:#7b3fa0;margin:20px auto;';
			container.textContent = 'Page Ad ' + _slotCounter + ' (' + size[0] + 'x' + size[1] + ')';
			record.filled = true;
			record.renderedSize = size[0] + 'x' + size[1];
			log('Dummy slot:', divId);
		}

		anchorEl.appendChild( container );
		_slots.push( record );

		if ( !DUMMY && _gptReady ) {
			googletag.cmd.push(function() {
				var slotName = buildSlotName( sizes[0][0], sizes[0][1] );
				var gptSlot = googletag.defineSlot( slotName, sizes, divId );
				if ( gptSlot ) {
					gptSlot.addService( googletag.pubads() );
					googletag.display( divId );
					googletag.pubads().refresh([ gptSlot ]);
					record.slot = gptSlot;
					sendEvent('impression', record);
					log('Rendered:', divId, slotName);
				}
			});
		}

		// Mark anchor as processed.
		anchorEl.setAttribute('data-rendered', '1');
	}

	// ── Event reporting ──
	function sendEvent( event, record ) {
		if ( !CFG.eventEndpoint ) return;

		var payload = {
			event: event,
			pageType: PAGE_TYPE,
			slotId: record.divId,
			domain: window.location.hostname,
			renderedSize: record.renderedSize || '',
			url: window.location.pathname
		};

		try {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', CFG.eventEndpoint, true);
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.setRequestHeader('X-WP-Nonce', CFG.nonce || '');
			xhr.send( JSON.stringify(payload) );
		} catch(e) {
			// Silently fail.
		}
	}

	// ── Phase 1: Above-fold immediate render ──
	function phase1() {
		var anchors = document.querySelectorAll('.pl-page-ad-anchor');
		var viewportH = window.innerHeight;

		for ( var i = 0; i < anchors.length; i++ ) {
			if ( anchors[i].getAttribute('data-rendered') ) continue;

			var rect = anchors[i].getBoundingClientRect();
			// Above fold = top is within viewport.
			if ( rect.top < viewportH + 100 ) {
				renderSlot( anchors[i] );
			}
		}

		log('Phase 1 complete, slots:', _slots.length);
	}

	// ── Phase 2: Scroll-triggered below-fold render (IO with 600px lookahead) ──
	function phase2() {
		if ( typeof IntersectionObserver === 'undefined' ) {
			// Fallback: render all remaining anchors.
			var anchors = document.querySelectorAll('.pl-page-ad-anchor:not([data-rendered])');
			for ( var i = 0; i < anchors.length; i++ ) {
				renderSlot( anchors[i] );
			}
			return;
		}

		var observer = new IntersectionObserver(function( entries ) {
			for ( var i = 0; i < entries.length; i++ ) {
				if ( entries[i].isIntersecting ) {
					var anchor = entries[i].target;
					observer.unobserve( anchor );
					if ( !anchor.getAttribute('data-rendered') ) {
						renderSlot( anchor );
					}
				}
			}
		}, {
			rootMargin: '600px 0px',
			threshold: 0
		});

		var anchors = document.querySelectorAll('.pl-page-ad-anchor:not([data-rendered])');
		for ( var i = 0; i < anchors.length; i++ ) {
			observer.observe( anchors[i] );
		}

		log('Phase 2 observing', anchors.length, 'anchors');
	}

	// ── Viewability tracking (50% visible for 1s) ──
	function initViewabilityTracking() {
		if ( typeof IntersectionObserver === 'undefined' || DUMMY ) {
			return;
		}

		var viewIO = new IntersectionObserver(function( entries ) {
			var now = Date.now();
			for ( var i = 0; i < entries.length; i++ ) {
				var divId = entries[i].target.id;
				if ( entries[i].isIntersecting && entries[i].intersectionRatio >= 0.5 ) {
					if ( !_viewStarts[divId] ) {
						_viewStarts[divId] = now;
					}
				} else {
					delete _viewStarts[divId];
				}
			}
		}, {
			threshold: [0, 0.5, 1.0]
		});

		// Check viewability dwell every 500ms.
		setInterval(function() {
			var now = Date.now();
			for ( var divId in _viewStarts ) {
				if ( now - _viewStarts[divId] >= 1000 ) {
					// 1s dwell achieved — mark viewable.
					for ( var j = 0; j < _slots.length; j++ ) {
						if ( _slots[j].divId === divId && !_slots[j].viewableEver ) {
							_slots[j].viewable = true;
							_slots[j].viewableEver = true;
							sendEvent('viewable', _slots[j]);
							log('Viewable (dwell):', divId);
						}
					}
					delete _viewStarts[divId];
				}
			}
		}, 500);

		// Observe new slots when they're created.
		var origPush = _slots.push;
		_slots.push = function( record ) {
			var result = origPush.apply( _slots, arguments );
			if ( record.el ) {
				viewIO.observe( record.el );
			}
			return result;
		};
	}

	// ── Beacon on page hide ──
	function initBeacon() {
		function sendBeacon() {
			if ( !CFG.eventEndpoint ) return;

			var filled = 0, viewable = 0;
			for ( var i = 0; i < _slots.length; i++ ) {
				if ( _slots[i].filled ) filled++;
				if ( _slots[i].viewableEver ) viewable++;
			}

			var data = {
				event: 'beacon',
				pageType: PAGE_TYPE,
				domain: window.location.hostname,
				slotId: 'session',
				url: window.location.pathname,
				totalSlots: _slots.length,
				filled: filled,
				viewable: viewable,
				dummy: DUMMY
			};

			if ( navigator.sendBeacon ) {
				navigator.sendBeacon(
					CFG.eventEndpoint,
					new Blob([ JSON.stringify(data) ], { type: 'application/json' })
				);
			}
		}

		document.addEventListener('visibilitychange', function() {
			if ( document.hidden ) sendBeacon();
		});
		window.addEventListener('pagehide', sendBeacon);
	}

	// ── Boot sequence ──
	function boot() {
		log('Booting — pageType:', PAGE_TYPE, 'maxSlots:', MAX_SLOTS, 'dummy:', DUMMY, 'spacing:', SPACING);

		initGPT();
		initViewabilityTracking();
		initBeacon();

		// Small delay to ensure GPT script has loaded.
		setTimeout(function() {
			phase1();
			phase2();
		}, DUMMY ? 0 : 200);
	}

	// Start when DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
