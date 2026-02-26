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
 * Features:
 *   - Two-phase inline injection (above-fold immediate, IO-triggered below-fold)
 *   - Anchor (bottom) overlay ad with close button, once per session
 *   - Interstitial overlay ad with auto-close, once per session
 *   - Format rotation per position (leaderboard / rectangle / banner)
 *   - MutationObserver for dynamically loaded content (category load-more)
 *   - Per-page-type spacing rules (homepage fixed, category aggressive)
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
	var DUMMY       = CFG.dummy;
	var PAGE_TYPE   = CFG.pageType || 'unknown';
	var HOST        = window.location.hostname.replace(/\./g, '_');

	// ── Spacing: category gets tighter spacing ──
	var SPACING;
	if ( PAGE_TYPE === 'category' ) {
		SPACING = IS_DESKTOP ? 300 : 250;
	} else {
		SPACING = IS_DESKTOP ? (CFG.desktopSpacing || 600) : (CFG.mobileSpacing || 400);
	}

	// ── Format / size maps ──
	var FORMATS = {
		leaderboard: { desktop: [[970, 250], [728, 90]], mobile: [[320, 100], [320, 50]] },
		rectangle:   { desktop: [[336, 280], [300, 250]], mobile: [[300, 250]] },
		banner:      { desktop: [[728, 90]],              mobile: [[320, 50]] }
	};

	// Category format rotation: top=leaderboard, then rect/banner alternation.
	var CAT_FORMAT_SEQ = ['leaderboard', 'rectangle', 'banner', 'rectangle'];

	// ── State ──
	var _slots        = [];
	var _slotCounter  = 0;
	var _gptReady     = false;
	var _viewStarts   = {};
	var _filledCount  = 0;
	var _emptyCount   = 0;
	var _phaseIO      = null;   // IntersectionObserver for phase 2

	// ── Public API ──
	window.__plPageAds = {
		getSlots: function() { return _slots; },
		getConfig: function() { return CFG; },
		rescan: function() { scanNewAnchors(); }
	};

	// ── Helpers ──
	function log() {
		if ( typeof console !== 'undefined' && console.log ) {
			var args = [LOG_PREFIX];
			for ( var i = 0; i < arguments.length; i++ ) args.push( arguments[i] );
			console.log.apply( console, args );
		}
	}

	function getSizesForFormat( fmt ) {
		var f = FORMATS[fmt] || FORMATS.rectangle;
		return IS_DESKTOP ? f.desktop : f.mobile;
	}

	function getFormatForPosition( idx ) {
		// idx is 0-based position in category sequence
		if ( PAGE_TYPE === 'category' ) {
			return idx < CAT_FORMAT_SEQ.length ? CAT_FORMAT_SEQ[idx] : (idx % 2 === 0 ? 'rectangle' : 'banner');
		}
		// Homepage / static: read from data-format attribute or default
		return 'rectangle';
	}

	/**
	 * Build GPT slot name with _pg_ infix.
	 */
	function buildSlotName( fmt ) {
		return SLOT_PATH + HOST + '_pg_' + fmt;
	}

	/**
	 * Check spacing between a candidate Y and all existing inline slots.
	 */
	function checkSpacing( candidateY ) {
		for ( var i = 0; i < _slots.length; i++ ) {
			var rec = _slots[i];
			if ( !rec.el || rec.isOverlay ) continue;
			var rect = rec.el.getBoundingClientRect();
			var slotY = rect.top + window.pageYOffset;
			if ( Math.abs( candidateY - slotY ) < SPACING ) return false;
		}
		return true;
	}

	function inlineSlotCount() {
		var c = 0;
		for ( var i = 0; i < _slots.length; i++ ) {
			if ( !_slots[i].isOverlay ) c++;
		}
		return c;
	}

	// ══════════════════════════════════════════════════════════════
	// GPT SETUP
	// ══════════════════════════════════════════════════════════════
	function initGPT() {
		if ( DUMMY ) { _gptReady = true; return; }

		window.googletag = window.googletag || { cmd: [] };

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
			log('GPT initialized');

			googletag.pubads().addEventListener('impressionViewable', function(e) {
				var id = e.slot.getSlotElementId();
				for ( var i = 0; i < _slots.length; i++ ) {
					if ( _slots[i].divId === id ) {
						_slots[i].viewable = true;
						_slots[i].viewableEver = true;
						sendEvent('viewable', _slots[i]);
						log('Event: viewable | slot:', _slots[i].slotName, '| format:', _slots[i].adFormat);
						break;
					}
				}
			});

			googletag.pubads().addEventListener('slotRenderEnded', function(e) {
				var id = e.slot.getSlotElementId();
				for ( var i = 0; i < _slots.length; i++ ) {
					if ( _slots[i].divId === id ) {
						_slots[i].filled = !e.isEmpty;
						if ( e.size && e.size.length === 2 ) {
							_slots[i].renderedSize = e.size[0] + 'x' + e.size[1];
						}
						if ( e.isEmpty ) { _emptyCount++; } else { _filledCount++; }
						sendEvent( e.isEmpty ? 'empty' : 'filled', _slots[i] );
						log('Event:', e.isEmpty ? 'empty' : 'filled', '| slot:', _slots[i].slotName, '| format:', _slots[i].adFormat);
						logStats();
						break;
					}
				}
			});
		});
	}

	function logStats() {
		var total = _filledCount + _emptyCount;
		if ( total > 0 ) {
			log('Stats:', _filledCount, 'filled,', _emptyCount, 'empty,', Math.round(_filledCount / total * 100) + '% fill rate');
		}
	}

	// ══════════════════════════════════════════════════════════════
	// INLINE AD RENDERING
	// ══════════════════════════════════════════════════════════════
	function renderSlot( anchorEl ) {
		if ( inlineSlotCount() >= MAX_SLOTS ) {
			log('Max inline slots reached:', MAX_SLOTS);
			return;
		}

		// Read format from data attribute or determine by position index.
		var fmt = anchorEl.getAttribute('data-format') || getFormatForPosition( inlineSlotCount() );
		var sizes = getSizesForFormat( fmt );
		var primarySize = sizes[0];

		var anchorRect = anchorEl.getBoundingClientRect();
		var anchorY = anchorRect.top + window.pageYOffset;

		// Homepage: skip spacing check (fixed positions).
		if ( PAGE_TYPE !== 'homepage' && !checkSpacing( anchorY ) ) {
			log('Spacing violation at Y:', anchorY);
			return;
		}

		_slotCounter++;
		var divId = 'pl-page-ad-' + _slotCounter;
		var slotName = anchorEl.getAttribute('data-slot') || ('pos-' + _slotCounter);

		var container = document.createElement('div');
		container.className = 'pl-page-ad-slot';
		container.id = divId;
		container.style.cssText = 'min-height:' + primarySize[1] + 'px;display:flex;align-items:center;justify-content:center;margin:20px auto;max-width:' + primarySize[0] + 'px;';

		var record = {
			divId: divId,
			slotName: slotName,
			adFormat: fmt,
			slot: null,
			el: container,
			isOverlay: false,
			filled: false,
			viewable: false,
			viewableEver: false,
			renderedSize: null,
			pageType: PAGE_TYPE,
			device: IS_DESKTOP ? 'desktop' : 'mobile',
			viewportWidth: window.innerWidth,
			injectedAt: Date.now()
		};

		if ( DUMMY ) {
			var colors = { leaderboard: ['#d5e8f5','#b3d1ef','#2980b9'], rectangle: ['#e8d5f5','#d1b3ef','#7b3fa0'], banner: ['#d5f5e8','#b3efcd','#27ae60'] };
			var c = colors[fmt] || colors.rectangle;
			container.style.cssText = 'width:' + primarySize[0] + 'px;height:' + primarySize[1] + 'px;'
				+ 'background:linear-gradient(135deg,' + c[0] + ',' + c[1] + ');'
				+ 'border:2px dashed ' + c[2] + ';border-radius:8px;'
				+ 'display:flex;align-items:center;justify-content:center;'
				+ 'font:600 13px/1 system-ui;color:' + c[2] + ';margin:20px auto;';
			container.textContent = fmt.charAt(0).toUpperCase() + fmt.slice(1) + ' (' + primarySize[0] + 'x' + primarySize[1] + ')';
			record.filled = true;
			record.renderedSize = primarySize[0] + 'x' + primarySize[1];
			_filledCount++;
			log('Dummy slot:', divId, fmt, primarySize[0] + 'x' + primarySize[1]);
		}

		anchorEl.appendChild( container );
		_slots.push( record );

		if ( !DUMMY && _gptReady ) {
			googletag.cmd.push(function() {
				var gptName = buildSlotName( fmt );
				var gptSlot = googletag.defineSlot( gptName, sizes, divId );
				if ( gptSlot ) {
					gptSlot.addService( googletag.pubads() );
					googletag.display( divId );
					googletag.pubads().refresh([ gptSlot ]);
					record.slot = gptSlot;
					sendEvent('impression', record);
					log('Event: impression | slot:', slotName, '| format:', fmt, '| page:', PAGE_TYPE);
				}
			});
		}

		anchorEl.setAttribute('data-rendered', '1');
	}

	// ══════════════════════════════════════════════════════════════
	// ANCHOR (BOTTOM) OVERLAY AD
	// ══════════════════════════════════════════════════════════════
	function initAnchorAd() {
		if ( !CFG.fmtAnchor ) return;
		if ( sessionStorage.getItem('pl_pg_anchor_shown') ) { log('Anchor: skipped (session)'); return; }

		sessionStorage.setItem('pl_pg_anchor_shown', '1');
		var divId = 'pl-page-anchor-ad';

		if ( DUMMY ) {
			var bar = document.createElement('div');
			bar.id = divId;
			bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:9998;'
				+ 'height:60px;background:linear-gradient(135deg,#fde68a,#f59e0b);'
				+ 'border-top:2px dashed #d97706;display:flex;align-items:center;justify-content:center;'
				+ 'font:600 14px/1 system-ui;color:#92400e;';
			bar.innerHTML = 'Anchor Ad (page) <button style="position:absolute;right:12px;top:8px;background:none;border:none;font-size:20px;cursor:pointer;color:#92400e" onclick="this.parentNode.remove()">&times;</button>';
			document.body.appendChild(bar);
			_slots.push({ divId: divId, slotName: 'anchor', adFormat: 'anchor', isOverlay: true, filled: true, viewable: false, viewableEver: false, renderedSize: 'anchor', pageType: PAGE_TYPE, device: IS_DESKTOP ? 'desktop' : 'mobile', viewportWidth: window.innerWidth, injectedAt: Date.now() });
			log('Dummy anchor ad shown');
			return;
		}

		if ( !_gptReady ) return;
		googletag.cmd.push(function() {
			var slot = googletag.defineOutOfPageSlot(
				SLOT_PATH + HOST + '_pg_anchor',
				googletag.enums.OutOfPageFormat.BOTTOM_ANCHOR
			);
			if ( slot ) {
				slot.addService( googletag.pubads() );
				_slots.push({ divId: divId, slotName: 'anchor', adFormat: 'anchor', slot: slot, isOverlay: true, filled: false, viewable: false, viewableEver: false, renderedSize: null, pageType: PAGE_TYPE, device: IS_DESKTOP ? 'desktop' : 'mobile', viewportWidth: window.innerWidth, injectedAt: Date.now() });
				sendEvent('impression', _slots[_slots.length - 1]);
				log('Anchor slot defined');
			}
		});
	}

	// ══════════════════════════════════════════════════════════════
	// INTERSTITIAL OVERLAY AD
	// ══════════════════════════════════════════════════════════════
	function initInterstitialAd() {
		if ( !CFG.fmtInterstitial ) return;
		if ( PAGE_TYPE === 'page' ) return; // static pages excluded
		if ( sessionStorage.getItem('pl_pg_interstitial_shown') ) { log('Interstitial: skipped (session)'); return; }

		sessionStorage.setItem('pl_pg_interstitial_shown', '1');
		var divId = 'pl-page-interstitial-ad';

		if ( DUMMY ) {
			setTimeout(function() {
				var overlay = document.createElement('div');
				overlay.id = divId;
				overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.7);'
					+ 'display:flex;align-items:center;justify-content:center;';
				var box = document.createElement('div');
				box.style.cssText = 'width:320px;height:480px;background:linear-gradient(135deg,#fecaca,#fca5a5);'
					+ 'border:3px dashed #dc2626;border-radius:12px;display:flex;flex-direction:column;'
					+ 'align-items:center;justify-content:center;font:600 16px/1.4 system-ui;color:#991b1b;position:relative;';
				box.innerHTML = 'Interstitial Ad (page)<br><small>Auto-closes in 10s</small>'
					+ '<button style="position:absolute;top:8px;right:12px;background:none;border:none;font-size:24px;cursor:pointer;color:#991b1b" onclick="this.closest(\'[id=pl-page-interstitial-ad]\').remove()">&times;</button>';
				overlay.appendChild(box);
				overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });
				document.body.appendChild(overlay);
				setTimeout(function() { if (overlay.parentNode) overlay.remove(); }, 10000);
				_slots.push({ divId: divId, slotName: 'interstitial', adFormat: 'interstitial', isOverlay: true, filled: true, viewable: false, viewableEver: false, renderedSize: 'interstitial', pageType: PAGE_TYPE, device: IS_DESKTOP ? 'desktop' : 'mobile', viewportWidth: window.innerWidth, injectedAt: Date.now() });
				log('Dummy interstitial shown');
			}, 3000);
			return;
		}

		if ( !_gptReady ) return;
		setTimeout(function() {
			googletag.cmd.push(function() {
				var slot = googletag.defineOutOfPageSlot(
					SLOT_PATH + HOST + '_pg_interstitial',
					googletag.enums.OutOfPageFormat.INTERSTITIAL
				);
				if ( slot ) {
					slot.addService( googletag.pubads() );
					_slots.push({ divId: divId, slotName: 'interstitial', adFormat: 'interstitial', slot: slot, isOverlay: true, filled: false, viewable: false, viewableEver: false, renderedSize: null, pageType: PAGE_TYPE, device: IS_DESKTOP ? 'desktop' : 'mobile', viewportWidth: window.innerWidth, injectedAt: Date.now() });
					sendEvent('impression', _slots[_slots.length - 1]);
					log('Interstitial slot defined');
				}
			});
		}, 3000);
	}

	// ══════════════════════════════════════════════════════════════
	// EVENT REPORTING
	// ══════════════════════════════════════════════════════════════
	function sendEvent( event, record ) {
		if ( !CFG.eventEndpoint ) return;

		var payload = {
			event: event,
			pageType: PAGE_TYPE,
			slotId: record.slotName || record.divId,
			adFormat: record.adFormat || '',
			domain: window.location.hostname,
			device: IS_DESKTOP ? 'desktop' : 'mobile',
			viewportWidth: window.innerWidth,
			renderedSize: record.renderedSize || '',
			url: window.location.pathname,
			timestamp: new Date().toISOString()
		};

		try {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', CFG.eventEndpoint, true);
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.setRequestHeader('X-WP-Nonce', CFG.nonce || '');
			xhr.send( JSON.stringify(payload) );
		} catch(e) {}
	}

	// ══════════════════════════════════════════════════════════════
	// PHASE 1: ABOVE-FOLD IMMEDIATE RENDER
	// ══════════════════════════════════════════════════════════════
	function phase1() {
		var anchors = document.querySelectorAll('.pl-page-ad-anchor:not([data-rendered])');
		var viewportH = window.innerHeight;

		for ( var i = 0; i < anchors.length; i++ ) {
			var rect = anchors[i].getBoundingClientRect();
			if ( rect.top < viewportH + 100 ) {
				renderSlot( anchors[i] );
			}
		}
		log('Phase 1 complete, slots:', inlineSlotCount());
	}

	// ══════════════════════════════════════════════════════════════
	// PHASE 2: IO-TRIGGERED BELOW-FOLD RENDER
	// ══════════════════════════════════════════════════════════════
	function phase2() {
		var lookahead = PAGE_TYPE === 'category' ? '400px 0px' : '600px 0px';

		if ( typeof IntersectionObserver === 'undefined' ) {
			var anchors = document.querySelectorAll('.pl-page-ad-anchor:not([data-rendered])');
			for ( var i = 0; i < anchors.length; i++ ) renderSlot( anchors[i] );
			return;
		}

		_phaseIO = new IntersectionObserver(function( entries ) {
			for ( var i = 0; i < entries.length; i++ ) {
				if ( entries[i].isIntersecting ) {
					var anchor = entries[i].target;
					_phaseIO.unobserve( anchor );
					if ( !anchor.getAttribute('data-rendered') ) {
						renderSlot( anchor );
					}
				}
			}
		}, { rootMargin: lookahead, threshold: 0 });

		var anchors = document.querySelectorAll('.pl-page-ad-anchor:not([data-rendered])');
		for ( var i = 0; i < anchors.length; i++ ) {
			_phaseIO.observe( anchors[i] );
		}
		log('Phase 2 observing', anchors.length, 'anchors (rootMargin:', lookahead + ')');
	}

	// ══════════════════════════════════════════════════════════════
	// MUTATION OBSERVER — detect dynamically loaded posts
	// ══════════════════════════════════════════════════════════════
	function scanNewAnchors() {
		if ( !_phaseIO ) return;
		var fresh = document.querySelectorAll('.pl-page-ad-anchor:not([data-rendered])');
		for ( var i = 0; i < fresh.length; i++ ) {
			_phaseIO.observe( fresh[i] );
		}
		if ( fresh.length ) log('Rescan: found', fresh.length, 'new anchors');
	}

	function initMutationObserver() {
		if ( typeof MutationObserver === 'undefined' ) return;

		// Observe the main content area for new child nodes (load more).
		var targets = document.querySelectorAll('.pl-cat-grid, main, #primary, .site-main, .cb-grid-4, .ee-grid-3, .pl-post-grid');
		if ( !targets.length ) return;

		var mo = new MutationObserver(function( mutations ) {
			var hasNew = false;
			for ( var i = 0; i < mutations.length; i++ ) {
				if ( mutations[i].addedNodes.length ) { hasNew = true; break; }
			}
			if ( hasNew ) {
				setTimeout(scanNewAnchors, 100);
			}
		});

		for ( var i = 0; i < targets.length; i++ ) {
			mo.observe( targets[i], { childList: true, subtree: true });
		}
		log('MutationObserver attached to', targets.length, 'containers');
	}

	// ══════════════════════════════════════════════════════════════
	// VIEWABILITY TRACKING (50% visible for 1s)
	// ══════════════════════════════════════════════════════════════
	function initViewabilityTracking() {
		if ( typeof IntersectionObserver === 'undefined' || DUMMY ) return;

		var viewIO = new IntersectionObserver(function( entries ) {
			var now = Date.now();
			for ( var i = 0; i < entries.length; i++ ) {
				var id = entries[i].target.id;
				if ( entries[i].isIntersecting && entries[i].intersectionRatio >= 0.5 ) {
					if ( !_viewStarts[id] ) _viewStarts[id] = now;
				} else {
					delete _viewStarts[id];
				}
			}
		}, { threshold: [0, 0.5, 1.0] });

		setInterval(function() {
			var now = Date.now();
			for ( var id in _viewStarts ) {
				if ( now - _viewStarts[id] >= 1000 ) {
					for ( var j = 0; j < _slots.length; j++ ) {
						if ( _slots[j].divId === id && !_slots[j].viewableEver ) {
							_slots[j].viewable = true;
							_slots[j].viewableEver = true;
							sendEvent('viewable', _slots[j]);
							var dwell = ((now - _viewStarts[id]) / 1000).toFixed(1);
							log('Event: viewable | slot:', _slots[j].slotName, '| dwell:', dwell + 's');
						}
					}
					delete _viewStarts[id];
				}
			}
		}, 500);

		var origPush = _slots.push;
		_slots.push = function( record ) {
			var result = origPush.apply( _slots, arguments );
			if ( record.el && !record.isOverlay ) viewIO.observe( record.el );
			return result;
		};
	}

	// ══════════════════════════════════════════════════════════════
	// BEACON ON PAGE HIDE
	// ══════════════════════════════════════════════════════════════
	function initBeacon() {
		function sendBeacon() {
			if ( !CFG.eventEndpoint ) return;
			var filled = 0, viewable = 0, overlays = 0;
			for ( var i = 0; i < _slots.length; i++ ) {
				if ( _slots[i].filled ) filled++;
				if ( _slots[i].viewableEver ) viewable++;
				if ( _slots[i].isOverlay ) overlays++;
			}
			var data = {
				event: 'beacon',
				pageType: PAGE_TYPE,
				domain: window.location.hostname,
				slotId: 'session',
				adFormat: 'session',
				url: window.location.pathname,
				device: IS_DESKTOP ? 'desktop' : 'mobile',
				viewportWidth: window.innerWidth,
				totalSlots: _slots.length,
				inlineSlots: _slots.length - overlays,
				overlaySlots: overlays,
				filled: filled,
				viewable: viewable,
				empty: _emptyCount,
				dummy: DUMMY
			};
			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( CFG.eventEndpoint, new Blob([ JSON.stringify(data) ], { type: 'application/json' }) );
			}
		}
		document.addEventListener('visibilitychange', function() { if ( document.hidden ) sendBeacon(); });
		window.addEventListener('pagehide', sendBeacon);
	}

	// ══════════════════════════════════════════════════════════════
	// BOOT
	// ══════════════════════════════════════════════════════════════
	function boot() {
		log('Booting — pageType:', PAGE_TYPE, 'maxSlots:', MAX_SLOTS, 'dummy:', DUMMY, 'spacing:', SPACING, 'desktop:', IS_DESKTOP);

		initGPT();
		initViewabilityTracking();
		initBeacon();

		setTimeout(function() {
			phase1();
			phase2();
			initAnchorAd();
			initInterstitialAd();
			initMutationObserver();
		}, DUMMY ? 0 : 200);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
