/**
 * Infinite scroll — loads full articles at 70% read trigger.
 *
 * Zero TBT: uses IntersectionObserver + requestIdleCallback.
 * Loads ONE full post at a time when user reaches 70% of current article.
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
	var maxBatches = 5;

	// REST endpoint URL passed from PHP via wp_localize_script.
	var endpoint = (typeof plInfinite !== 'undefined' && plInfinite.endpoint)
		? plInfinite.endpoint
		: '/wp-json/pinlightning/v1/random-posts';

	// Get current post ID from the main article (not related-post cards).
	var articleEl = document.querySelector('article.single-article');

	if (!articleEl) {
		// Fallback: try any article with an ID.
		articleEl = document.querySelector('article[id^="post-"]');
	}

	if (articleEl) {
		loadedIds.push(parseInt(articleEl.id.replace('post-', ''), 10));
	}

	function init() {
		container = document.createElement('div');
		container.className = 'infinite-posts';

		var mainContent = document.querySelector('.site-main') || document.querySelector('main');

		if (!mainContent) return;
		mainContent.appendChild(container);

		// Start observing the original article at 70%.
		observeLastArticle();

		// Fallback: scroll-based trigger for browsers where IntersectionObserver
		// may not fire on absolutely-positioned markers (some mobile browsers).
		var scrollTimer = null;
		window.addEventListener('scroll', function () {
			if (scrollTimer) return;
			scrollTimer = setTimeout(function () {
				scrollTimer = null;
				if (loading || batchCount >= maxBatches) return;

				var articles = document.querySelectorAll('article.single-article, .infinite-post');
				var lastArticle = articles[articles.length - 1];
				if (!lastArticle) return;

				var rect = lastArticle.getBoundingClientRect();
				var articleHeight = rect.height;
				var scrolledPast = -rect.top;
				var percentRead = scrolledPast / articleHeight;

				if (percentRead >= 0.7) {
					loadMore();
				}
			}, 500);
		}, { passive: true });
	}

	function observeLastArticle() {
		// Find the last article (main or infinite-loaded — excludes related-post cards).
		var articles = document.querySelectorAll('article.single-article, .infinite-post');

		var lastArticle = articles[articles.length - 1];
		if (!lastArticle) return;

		// Create a marker at 70% height of the last article.
		var marker = document.createElement('div');
		marker.className = 'infinite-trigger';
		marker.style.cssText = 'height:1px;position:absolute;left:0;width:1px;pointer-events:none;';
		lastArticle.style.position = 'relative';

		function positionMarker() {
			marker.style.top = (lastArticle.scrollHeight * 0.7) + 'px';
		}
		positionMarker();
		lastArticle.appendChild(marker);

		// Reposition after images load (article height changes).
		var images = lastArticle.querySelectorAll('img');
		for (var i = 0; i < images.length; i++) {
			if (!images[i].complete) {
				images[i].addEventListener('load', positionMarker);
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

					// Divider between posts.
					var divider = document.createElement('hr');
					divider.className = 'infinite-divider';
					container.appendChild(divider);

					// Insert the full post HTML.
					var wrapper = document.createElement('div');
					wrapper.innerHTML = post.html;
					while (wrapper.firstChild) {
						container.appendChild(wrapper.firstChild);
					}
				});

				loading = false;

				// Observe the newly loaded article at 70% for the next batch.
				observeLastArticle();
			})
			.catch(function () {
				loading = false;
			});
	}

	// Only init on single views (body has 'single' class on all single post types).
	if (document.body.classList.contains('single')) {
		if ('requestIdleCallback' in window) {
			requestIdleCallback(init);
		} else {
			setTimeout(init, 200);
		}
	}
})();
