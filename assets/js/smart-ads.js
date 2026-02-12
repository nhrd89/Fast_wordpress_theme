/**
 * PinLightning Smart Ad Engine — Phase 1
 *
 * Systems:
 * 1. Smart Injector — IntersectionObserver-based ad injection
 * 2. Viewability Tracker — Per-ad IAB viewability measurement
 * 3. Debug Overlay — Real-time metrics visualization
 */
(function() {
	'use strict';

	// ========================================
	// CONFIG (merged with PHP-provided config)
	// ========================================
	var C = {
		dummyMode: true,
		debug: true,
		maxAds: 8,
		viewableThreshold: 0.5,
		viewableTimeMs: 1000,
		maxScrollSpeed: 800,
		minReadTimeMs: 2000
	};

	// Merge PHP config
	if (window.plAdsConfig) {
		for (var key in window.plAdsConfig) {
			if (window.plAdsConfig.hasOwnProperty(key)) {
				C[key] = window.plAdsConfig[key];
			}
		}
	}

	// ========================================
	// STATE
	// ========================================
	var S = {
		scrollSpeed: 0,
		scrollDir: 'down',
		lastY: 0,
		lastT: 0,
		reading: false,
		depth: 0,
		loadTime: Date.now(),
		injected: 0,
		zones: {},      // zoneId -> { el, visible, since, totalMs, sessionMs, impressions, ratio }
		debugEl: null
	};

	// ========================================
	// DUMMY AD SPECS
	// ========================================
	var SPECS = {
		'300x250': { w: 300, h: 250, label: '300\u00d7250', bg: '#f0f4ff', bc: '#4a90d9' },
		'728x90':  { w: 728, h: 90,  label: '728\u00d790',  bg: '#fff4f0', bc: '#d94a4a' },
		'320x100': { w: 320, h: 100, label: '320\u00d7100', bg: '#f0fff4', bc: '#4ad94a' },
		'300x600': { w: 300, h: 600, label: '300\u00d7600', bg: '#fff0f8', bc: '#d94a90' }
	};

	// ========================================
	// 1. SCROLL SPEED TRACKER
	// ========================================
	function trackScroll() {
		var timer = null;
		window.addEventListener('scroll', function() {
			var now = Date.now();
			var y = window.scrollY;
			var dt = now - S.lastT;
			if (dt > 0) {
				S.scrollSpeed = (Math.abs(y - S.lastY) / dt) * 1000;
				S.scrollDir = y > S.lastY ? 'down' : 'up';
				S.reading = S.scrollSpeed < C.maxScrollSpeed;
			}
			var dh = document.documentElement.scrollHeight - window.innerHeight;
			if (dh > 0) S.depth = (y / dh) * 100;
			S.lastY = y;
			S.lastT = now;
			if (timer) clearTimeout(timer);
			timer = setTimeout(function() { S.scrollSpeed = 0; S.reading = true; }, 150);
		}, { passive: true });
	}

	// ========================================
	// 2. SMART INJECTOR
	// ========================================
	function initInjector() {
		var zones = document.querySelectorAll('.ad-zone[data-injected="false"]');
		if (!zones.length) return;

		var obs = new IntersectionObserver(function(entries) {
			entries.forEach(function(entry) {
				if (!entry.isIntersecting) return;

				var el = entry.target;
				if (el.getAttribute('data-injected') === 'true') return;

				// Check conditions
				var elapsed = Date.now() - S.loadTime;
				if (elapsed < C.minReadTimeMs) return;       // Too early
				if (!S.reading && S.scrollSpeed > 0) return; // Scrolling too fast
				if (S.injected >= C.maxAds) return;           // Max reached

				// Inject
				inject(el);
				obs.unobserve(el);
			});
		}, {
			rootMargin: '300px 0px',  // Start loading 300px before visible
			threshold: [0.1]
		});

		zones.forEach(function(z) { obs.observe(z); });

		// Re-check zones that weren't injected due to speed
		// (user might have slowed down)
		setInterval(function() {
			if (!S.reading) return;
			document.querySelectorAll('.ad-zone[data-injected="false"]').forEach(function(z) {
				var rect = z.getBoundingClientRect();
				var inView = rect.top < window.innerHeight + 300 && rect.bottom > -300;
				if (inView && S.injected < C.maxAds && (Date.now() - S.loadTime) >= C.minReadTimeMs) {
					inject(z);
					obs.unobserve(z);
				}
			});
		}, 1000);
	}

	function inject(el) {
		var zoneId = el.getAttribute('data-zone-id');
		var size = el.getAttribute('data-ad-size');

		el.setAttribute('data-injected', 'true');
		S.injected++;

		// Resolve responsive size
		var mobile = window.innerWidth < 768;
		var resolvedSize = size;
		if (size === 'responsive') {
			resolvedSize = mobile ? '320x100' : '728x90';
		}

		if (C.dummyMode) {
			injectDummy(el, zoneId, resolvedSize);
		} else {
			injectReal(el, zoneId, resolvedSize);
		}

		// Smooth expand animation (prevent CLS)
		el.style.overflow = 'hidden';
		el.style.maxHeight = '0';
		el.style.transition = 'max-height 0.4s ease, margin 0.3s ease';

		requestAnimationFrame(function() {
			var spec = SPECS[resolvedSize];
			var targetH = spec ? spec.h + 20 : 270;
			el.style.maxHeight = targetH + 'px';
			el.style.margin = '1.5rem 0';
		});

		el.classList.add('ad-zone-active');

		// Start viewability tracking
		trackViewability(el, zoneId);
	}

	function injectDummy(el, zoneId, size) {
		var spec = SPECS[size] || SPECS['300x250'];
		var mobile = window.innerWidth < 768;
		var w = mobile ? Math.min(spec.w, window.innerWidth - 32) : spec.w;
		var h = (w < spec.w) ? Math.round((w / spec.w) * spec.h) : spec.h;

		el.innerHTML =
			'<div class="pl-dummy-ad" style="' +
				'width:' + w + 'px;height:' + h + 'px;' +
				'background:' + spec.bg + ';border:2px dashed ' + spec.bc + ';' +
				'display:flex;align-items:center;justify-content:center;' +
				'flex-direction:column;gap:4px;margin:0 auto;border-radius:8px;' +
				'font-family:system-ui,sans-serif;position:relative;">' +
				'<div style="font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:1px;">Advertisement</div>' +
				'<div style="font-weight:700;color:' + spec.bc + ';font-size:14px;">' + spec.label + '</div>' +
				'<div style="font-size:10px;color:#888;">' + zoneId + '</div>' +
				'<div class="pl-ad-badge" id="vb-' + zoneId + '" ' +
					'style="position:absolute;bottom:4px;right:4px;padding:2px 6px;' +
					'border-radius:4px;font-size:10px;background:#eee;color:#666;">0.0s</div>' +
			'</div>';
	}

	function injectReal(el, zoneId, size) {
		// Phase 2: AdPlus GPT integration
		// Will load GPT once, define slots per zone, and display
	}

	// ========================================
	// 3. VIEWABILITY TRACKER
	// ========================================
	function trackViewability(el, zoneId) {
		S.zones[zoneId] = {
			el: el,
			visible: false,
			since: null,
			totalMs: 0,
			sessionMs: 0,
			impressions: 0,
			ratio: 0
		};

		var vObs = new IntersectionObserver(function(entries) {
			var e = entries[0];
			var z = S.zones[zoneId];
			z.ratio = e.intersectionRatio;

			if (e.intersectionRatio >= C.viewableThreshold) {
				if (!z.visible) {
					z.visible = true;
					z.since = Date.now();
				}
			} else {
				if (z.visible && z.since) {
					var dur = Date.now() - z.since;
					z.totalMs += dur;
					if (dur >= C.viewableTimeMs) z.impressions++;
					z.visible = false;
					z.since = null;
				}
			}
		}, { threshold: [0, 0.1, 0.25, 0.5, 0.75, 1.0] });

		vObs.observe(el);
	}

	// Update session timers + badges every 200ms
	function tickViewability() {
		var ids = Object.keys(S.zones);
		ids.forEach(function(id) {
			var z = S.zones[id];
			z.sessionMs = (z.visible && z.since) ? (Date.now() - z.since) : 0;

			var total = z.totalMs + z.sessionMs;
			var badge = document.getElementById('vb-' + id);
			if (badge) {
				badge.textContent = (total / 1000).toFixed(1) + 's';
				if (total >= C.viewableTimeMs) {
					badge.style.background = '#4caf50';
					badge.style.color = '#fff';
				} else if (z.visible) {
					badge.style.background = '#ff9800';
					badge.style.color = '#fff';
				} else {
					badge.style.background = '#eee';
					badge.style.color = '#666';
				}
			}
		});
	}

	// ========================================
	// 4. DEBUG OVERLAY
	// ========================================
	function initDebug() {
		if (!C.debug) return;

		var el = document.createElement('div');
		el.id = 'pl-ad-debug';
		el.style.cssText =
			'position:fixed;bottom:60px;left:8px;z-index:9999;' +
			'background:rgba(0,0,0,0.88);color:#0f0;font-family:monospace;' +
			'font-size:10px;padding:8px 10px;border-radius:8px;' +
			'max-width:260px;pointer-events:none;line-height:1.5;' +
			'backdrop-filter:blur(4px);';
		document.body.appendChild(el);
		S.debugEl = el;
	}

	function tickDebug() {
		if (!S.debugEl) return;

		var lines = [
			'<b style="color:#ff0">PinLightning Ads</b>',
			Math.round(S.scrollSpeed) + 'px/s ' + (S.reading ? 'reading' : 'fast') + ' ' + S.scrollDir,
			'Depth: ' + Math.round(S.depth) + '% | Ads: ' + S.injected + '/' + C.maxAds,
			'---'
		];

		var totalViewable = 0;
		var totalAds = 0;

		Object.keys(S.zones).forEach(function(id) {
			var z = S.zones[id];
			var total = z.totalMs + z.sessionMs;
			var pct = Math.round(z.ratio * 100);
			var icon = z.visible ? '[on]' : '[--]';
			totalAds++;
			if (total >= C.viewableTimeMs) totalViewable++;

			lines.push(
				icon + ' ' + id + ': ' +
				(total / 1000).toFixed(1) + 's ' +
				'(' + pct + '%) ' +
				'x' + z.impressions
			);
		});

		if (totalAds > 0) {
			var viewRate = Math.round((totalViewable / totalAds) * 100);
			lines.splice(3, 0, 'Viewability: ' + viewRate + '% (' + totalViewable + '/' + totalAds + ')');
		}

		S.debugEl.innerHTML = lines.join('<br>');
	}

	// ========================================
	// MAIN TICK (200ms interval)
	// ========================================
	function tick() {
		tickViewability();
		tickDebug();
	}

	// ========================================
	// INIT
	// ========================================
	function init() {
		trackScroll();
		initInjector();
		initDebug();
		setInterval(tick, 200);
	}

	// PinLightning performance pattern
	if (document.body.classList.contains('single')) {
		if ('requestIdleCallback' in window) {
			requestIdleCallback(init);
		} else {
			setTimeout(init, 200);
		}
	}
})();
