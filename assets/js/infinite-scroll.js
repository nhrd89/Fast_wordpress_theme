/**
 * Next-Post Auto-Loader — loads the next full article at 70% read.
 *
 * Zero TBT: uses IntersectionObserver + requestIdleCallback.
 * Loads ONE full post at a time when user reaches 70% of current article.
 * Notifies smart-ads.js (Layer 2) via window.SmartAds.rescanAnchors()
 * so dynamic ads are injected into the new content.
 *
 * REST endpoint: /pinlightning/v1/random-posts (same-category random post)
 * Response HTML already wraps content in .single-content class,
 * making it discoverable by smart-ads.js querySelectorAll('.single-content').
 *
 * @package PinLightning
 * @since 1.0.0
 */
(function () {
	'use strict';

	var loading = false;
	var loadedIds = [];
	var container = null;
	var batchCount = 0;
	var maxBatches = 3;   // max 3 auto-loaded posts per session

	// REST endpoint URL passed from PHP via wp_localize_script.
	var endpoint = (typeof plInfinite !== 'undefined' && plInfinite.endpoint)
		? plInfinite.endpoint
		: '/wp-json/pinlightning/v1/random-posts';

	// Auto-load enabled flag (PHP toggle, defaults to true when script is enqueued).
	var autoLoadEnabled = (typeof plInfinite !== 'undefined' && plInfinite.autoLoad !== undefined)
		? (plInfinite.autoLoad === '1' || plInfinite.autoLoad === true)
		: true;

	// Get current post ID from the main article.
	var articleEl = document.querySelector('article.single-article');
	if (!articleEl) {
		articleEl = document.querySelector('article[id^="post-"]');
	}
	if (articleEl) {
		loadedIds.push(parseInt(articleEl.id.replace('post-', ''), 10));
	}

	function init() {
		if (!autoLoadEnabled) return;

		container = document.createElement('div');
		container.className = 'infinite-posts';

		var mainContent = document.querySelector('.site-main') || document.querySelector('main');
		if (!mainContent) return;
		mainContent.appendChild(container);

		// Start observing the original article at 70%.
		observeLastArticle();
	}

	function observeLastArticle() {
		var articles = document.querySelectorAll('article.single-article, .infinite-post');
		var lastArticle = articles[articles.length - 1];
		if (!lastArticle) return;

		// READ geometry FIRST — before any DOM writes to avoid forced reflow.
		var initialHeight = lastArticle.scrollHeight;

		// WRITE — create marker and set position.
		var marker = document.createElement('div');
		marker.className = 'infinite-trigger';
		marker.style.cssText = 'height:1px;position:absolute;left:0;width:1px;pointer-events:none;top:' + (initialHeight * 0.7) + 'px';
		lastArticle.style.position = 'relative';
		lastArticle.appendChild(marker);

		// Reposition after images load (article height changes).
		var repositionPending = false;
		function repositionMarker() {
			if (repositionPending) return;
			repositionPending = true;
			requestAnimationFrame(function() {
				repositionPending = false;
				marker.style.top = (lastArticle.scrollHeight * 0.7) + 'px';
			});
		}
		var images = lastArticle.querySelectorAll('img');
		for (var i = 0; i < images.length; i++) {
			if (!images[i].complete) {
				images[i].addEventListener('load', repositionMarker);
			}
		}

		var observer = new IntersectionObserver(function (entries) {
			if (entries[0].isIntersecting && !loading && batchCount < maxBatches) {
				observer.disconnect();
				marker.remove();
				loadMore();
			}
		});

		observer.observe(marker);
	}

	function loadMore() {
		loading = true;
		batchCount++;

		var sep = endpoint.indexOf('?') === -1 ? '?' : '&';
		var url = endpoint + sep + 'per_page=1&exclude=' + loadedIds.join(',');

		fetch(url)
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data.posts || !data.posts.length) {
					loading = false;
					return;
				}

				data.posts.forEach(function (post) {
					loadedIds.push(post.id);

					// Visual separator between posts.
					var divider = document.createElement('hr');
					divider.className = 'infinite-divider';
					divider.style.cssText = 'border:none;border-top:3px solid #eee;margin:40px auto;max-width:600px';
					container.appendChild(divider);

					// Insert the full post HTML.
					var wrapper = document.createElement('div');
					wrapper.className = 'infinite-post-wrapper';
					wrapper.setAttribute('data-post-id', post.id);
					wrapper.innerHTML = post.html;
					container.appendChild(wrapper);

					// Notify smart-ads.js Layer 2 that new content was added.
					// The REST response wraps content in .single-content,
					// so querySelectorAll('.single-content') now finds it.
					if (window.SmartAds && typeof window.SmartAds.rescanAnchors === 'function') {
						window.SmartAds.rescanAnchors();
					}

					// Initialize lazy-loading for new images.
					var newImages = wrapper.querySelectorAll('img[loading="lazy"]');
					// Images with loading="lazy" are handled natively by the browser.
					// Force any src-less images with data-src to load.
					newImages.forEach(function(img) {
						if (img.dataset.src && !img.src) {
							img.src = img.dataset.src;
						}
					});
				});

				loading = false;

				// Expose loaded post IDs for external consumers.
				window.__plLoadedPosts = loadedIds.slice();

				// Observe the newly loaded article at 70% for the next batch.
				if (batchCount < maxBatches) {
					observeLastArticle();
				}
			})
			.catch(function () {
				loading = false;
			});
	}

	// Only init on single views (body has 'single' class).
	if (document.body.classList.contains('single')) {
		if ('requestIdleCallback' in window) {
			requestIdleCallback(init);
		} else {
			setTimeout(init, 200);
		}
	}

	// Expose for engagement.js smooth-scroll integration.
	window.__plNextPostLoader = {
		getLoadedIds: function() { return loadedIds.slice(); },
		getContainer: function() { return container; }
	};
})();
