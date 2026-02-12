/**
 * Infinite scroll — loads random posts via REST API.
 *
 * Zero TBT: uses IntersectionObserver + requestIdleCallback.
 * Loaded with defer, initialised in idle time only.
 *
 * @package PinLightning
 * @since 1.0.0
 */
(function () {
	'use strict';

	var endpoint = '/wp-json/pinlightning/v1/random-posts';
	var container;
	var sentinel;
	var seenIds = [];
	var loading = false;
	var batchCount = 0;
	var maxBatches = 10;

	function init() {
		// Read current post ID from the data attribute.
		container = document.querySelector('.infinite-posts');
		if (!container) return;

		var currentId = container.getAttribute('data-current-post');
		if (currentId) seenIds.push(parseInt(currentId, 10));

		// Create sentinel element for IntersectionObserver.
		sentinel = document.createElement('div');
		sentinel.className = 'infinite-sentinel';
		container.appendChild(sentinel);

		var observer = new IntersectionObserver(function (entries) {
			if (entries[0].isIntersecting && !loading && batchCount < maxBatches) {
				loadMore();
			}
		}, { rootMargin: '600px' });

		observer.observe(sentinel);
	}

	function loadMore() {
		loading = true;
		batchCount++;

		var url = endpoint + '?exclude=' + seenIds.join(',');

		fetch(url)
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (!data.html) {
					loading = false;
					return;
				}

				// Track returned IDs.
				if (data.ids) {
					for (var i = 0; i < data.ids.length; i++) {
						seenIds.push(data.ids[i]);
					}
				}

				// Insert HTML before sentinel.
				var temp = document.createElement('div');
				temp.innerHTML = data.html;
				while (temp.firstChild) {
					container.insertBefore(temp.firstChild, sentinel);
				}

				loading = false;
			})
			.catch(function () {
				loading = false;
			});
	}

	// Initialise in idle time to avoid blocking main thread.
	if ('requestIdleCallback' in window) {
		requestIdleCallback(init);
	} else {
		setTimeout(init, 200);
	}
})();
