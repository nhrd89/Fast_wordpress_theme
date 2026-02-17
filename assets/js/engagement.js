/**
 * PinLightning Engagement Architecture v1.0
 *
 * Deferred script — all interaction systems for listicle engagement.
 * Uses event delegation, IntersectionObserver, and scroll-gated loading.
 *
 * @package PinLightning
 */
;(function() {
"use strict";

// Config from wp_localize_script
var C = window.ebConfig || {};
var TOTAL = C.totalItems || 15;
var itemTitles = C.itemTitles || [];
var itemPins = C.itemPins || [];
var CATEGORY = C.category || 'hairstyle';
var POST_ID = C.postId || 0;
var NEXT_POST = C.nextPost || { title: '', url: '', img: '' };
var AI_TIP_TEXT = C.aiTip || '';
var EMAIL_ENDPOINT = C.emailEndpoint || '';
var FEATURES = C.features || {};

// State
var seenItems = new Set();
var favItems = new Set();
var collectedItems = new Set();
var totalCollectibles = 0;
var pollVoted = false;
var quizAnswered = false;
var emailCaptured = false;
var blurRevealed = false;
var milestonesFired = {};
var lastScrollY = 0;
var lastScrollTime = 0;
var exitShown = false;
var streakCount = 0;

// DOM refs
var $progressFill = document.getElementById('ebProgressFill');
var $counter = document.getElementById('ebCounter');
var $countNum = document.getElementById('ebCountNum');
var $live = document.getElementById('ebLive');
var $liveCount = document.getElementById('ebLiveCount');
var $counterLive = document.getElementById('ebCounterLive');
var $counterLiveNum = document.getElementById('ebCounterLiveNum');
var $pills = document.getElementById('ebPills');
var $streak = document.getElementById('ebStreak');
var $speedWarn = document.getElementById('ebSpeedWarn');
var $milestone = document.getElementById('ebMilestone');
var $milestoneEmoji = document.getElementById('ebMilestoneEmoji');
var $milestoneText = document.getElementById('ebMilestoneText');
var $milestoneSub = document.getElementById('ebMilestoneSub');
var $achievement = document.getElementById('ebAchievement');
var $collectCounter = document.getElementById('ebCollectCounter');
var $collectNum = document.getElementById('ebCollectNum');
var $nextBar = document.getElementById('ebNextBar');
var $exit = document.getElementById('ebExit');
var $exitFavCount = document.getElementById('ebExitFavCount');
var $aiTip = document.getElementById('ebAiTip');
var $aiTipText = document.getElementById('ebAiTipText');
var $favSummary = document.getElementById('ebFavSummary');
var $favGrid = document.getElementById('ebFavGrid');

// Count collectibles on page
document.querySelectorAll('.eb-collectible').forEach(function() { totalCollectibles++; });

// === GA4 Event Tracking ===
function ebTrack(event, params) {
	if (typeof gtag === 'function') {
		gtag('event', 'eb_' + event, Object.assign({
			post_id: POST_ID,
			category: CATEGORY
		}, params || {}));
	}
}

// === Simulated Live Count ===
function updateLiveCount() {
	var base = 30 + Math.floor(Math.random() * 40);
	if ($liveCount) $liveCount.textContent = base;
	if ($counterLiveNum) $counterLiveNum.textContent = base;
	// Sync header stats (mobile)
	document.querySelectorAll('.pl-header-stats .eb-counter-live').forEach(function(el) {
		el.textContent = base;
	});
}

// === Progress Bar ===
function updateProgress() {
	var pct = Math.round((seenItems.size / TOTAL) * 100);
	if ($progressFill) $progressFill.style.width = pct + '%';
	if ($countNum) $countNum.textContent = seenItems.size;
	// Sync header stats (mobile)
	document.querySelectorAll('.pl-header-stats .eb-collect-num').forEach(function(el) {
		el.textContent = seenItems.size;
	});

	// Show counter after first item seen
	if (seenItems.size > 0 && $counter) {
		$counter.classList.add('show');
	}

	// Show live activity
	if (seenItems.size >= 2 && $live) {
		$live.classList.add('show');
	}
	if (seenItems.size >= 2 && $counterLive) {
		$counterLive.style.display = 'inline-flex';
	}
}

// === Jump Pill Highlighting ===
function updatePills(activeIdx) {
	if (!$pills) return;
	var pills = $pills.querySelectorAll('.eb-pill');
	pills.forEach(function(p) {
		var idx = parseInt(p.getAttribute('data-item'), 10);
		p.classList.toggle('active', idx === activeIdx);
	});
}

// === Milestone System ===
function checkMilestones() {
	var pct = Math.round((seenItems.size / TOTAL) * 100);
	var milestones = C.milestones || [];

	milestones.forEach(function(m) {
		if (pct >= m.at && !milestonesFired[m.at]) {
			milestonesFired[m.at] = true;
			showMilestone(m.emoji, m.text, m.sub);
			ebTrack('milestone_hit', { milestone: m.at });
		}
	});

	// Achievement at 100%
	if (pct >= 100 && $achievement) {
		// Disabled: achievement popup interrupts scrolling experience
		// setTimeout(function() {
		// 	$achievement.classList.add('show');
		// 	ebTrack('achievement_earned', { type: 'style_expert' });
		// 	setTimeout(function() { $achievement.classList.remove('show'); }, 5000);
		// }, 2000);
		ebTrack('achievement_earned', { type: 'style_expert' });
	}
}

function showMilestone(emoji, text, sub) {
	if (!$milestone) return;
	$milestoneEmoji.textContent = emoji;
	$milestoneText.textContent = text;
	$milestoneSub.textContent = sub;
	// Disabled: milestone popup interrupts scrolling experience
	// $milestone.classList.add('show');
	// setTimeout(function() { $milestone.classList.remove('show'); }, 3000);
}

// === AI Tip Unlock ===
function checkAiTip() {
	// Unlock after 2 of 3 signals: poll voted, quiz answered, 50%+ items seen
	var signals = 0;
	if (pollVoted) signals++;
	if (quizAnswered) signals++;
	if (seenItems.size >= Math.ceil(TOTAL / 2)) signals++;

	if (signals >= 2 && $aiTip && !$aiTip.classList.contains('unlocked')) {
		$aiTip.classList.add('unlocked');
		if ($aiTipText) {
			$aiTipText.textContent = AI_TIP_TEXT || 'Based on your picks, try asking for "face-framing layers with curtain bangs" — it combines the elements you\'re drawn to most.';
		}
		ebTrack('ai_tip_unlocked', { signals: signals });
	}
}

// === Next Article Bar ===
function checkNextBar() {
	if (!NEXT_POST || !NEXT_POST.url || !$nextBar) return;
	var pct = Math.round((seenItems.size / TOTAL) * 100);
	if (pct >= 80) {
		$nextBar.classList.add('show');
	}
}

// === Speed Warning ===
function checkScrollSpeed() {
	var now = Date.now();
	var dy = Math.abs(window.scrollY - lastScrollY);
	var dt = now - lastScrollTime;
	if (dt > 0 && dt < 200) {
		var speed = dy / dt * 1000; // px/s
		if (speed > 2000 && $speedWarn && seenItems.size > 2 && seenItems.size < TOTAL - 2) {
			// Disabled: speed warning popup interrupts scrolling experience
			// $speedWarn.classList.add('show');
			ebTrack('speed_warning', { speed: Math.round(speed) });
			// setTimeout(function() { $speedWarn.classList.remove('show'); }, 2500);
		}
	}
	lastScrollY = window.scrollY;
	lastScrollTime = now;
}

// === Exit Intent Detection ===
function checkExitIntent() {
	if (exitShown || favItems.size === 0) return;
	// Detect rapid scroll up from deep position
	var scrollPct = window.scrollY / (document.documentElement.scrollHeight - window.innerHeight);
	if (scrollPct < 0.3 && seenItems.size > TOTAL * 0.5) {
		showExitIntent();
	}
}

function showExitIntent() {
	if (exitShown || !$exit) return;
	exitShown = true;
	if ($exitFavCount) $exitFavCount.textContent = favItems.size;
	$exit.classList.add('show');
	ebTrack('exit_intent_shown', { fav_count: favItems.size });
}

// === Favorites Summary ===
function updateFavSummary() {
	if (!$favSummary || !$favGrid) return;
	if (favItems.size === 0) {
		$favSummary.classList.remove('show');
		return;
	}
	$favSummary.classList.add('show');
	$favGrid.innerHTML = '';
	favItems.forEach(function(idx) {
		if (itemPins[idx]) {
			var img = document.createElement('img');
			img.src = itemPins[idx];
			img.alt = itemTitles[idx] || ('Look ' + (idx + 1));
			img.loading = 'lazy';
			$favGrid.appendChild(img);
		}
	});
}

// === Reading Streak ===
function checkStreak() {
	try {
		var key = 'eb_streak';
		var data = JSON.parse(localStorage.getItem(key) || '{}');
		var today = new Date().toDateString();
		var yesterday = new Date(Date.now() - 86400000).toDateString();

		if (data.lastDate === today) {
			streakCount = data.count || 1;
		} else if (data.lastDate === yesterday) {
			streakCount = (data.count || 0) + 1;
		} else {
			streakCount = 1;
		}

		localStorage.setItem(key, JSON.stringify({ lastDate: today, count: streakCount }));

		if (streakCount > 1 && $streak) {
			$streak.innerHTML = '\u{1F525} ' + streakCount + '-day reading streak!';
			$streak.classList.add('show');
		}
	} catch (e) { /* localStorage unavailable */ }
}

// === Event Delegation ===
document.addEventListener('click', function(e) {
	var target = e.target.closest('[data-eb-action]');
	if (!target) return;

	var action = target.dataset.ebAction;

	if (action === 'vote') handleVote(target);
	else if (action === 'quiz') handleQuiz(target);
	else if (action === 'fav') handleFav(target);
	else if (action === 'collect') handleCollect(target);
	else if (action === 'reveal') handleReveal(target);
	else if (action === 'jump') handleJump(target);
	else if (action === 'email') handleEmail(target);
	else if (action === 'exit-close') handleExitClose();
	else if (action === 'next') handleNext(target);
	else if (action === 'pin-all') handlePinAll();
});

function handleVote(btn) {
	if (pollVoted) return;
	pollVoted = true;
	var poll = btn.closest('.eb-poll');
	if (!poll) return;

	// Highlight selected
	poll.querySelectorAll('.eb-poll-opt').forEach(function(opt) {
		opt.classList.remove('voted');
		opt.style.pointerEvents = 'none';
	});
	btn.classList.add('voted');

	// Animate bars
	poll.querySelectorAll('.eb-poll-fill').forEach(function(fill) {
		var pct = fill.getAttribute('data-pct');
		setTimeout(function() { fill.style.width = pct + '%'; }, 100);
	});

	// Show result
	var result = poll.querySelector('.eb-poll-result');
	if (result) {
		setTimeout(function() { result.style.display = 'block'; }, 800);
	}

	ebTrack('poll_vote', { choice: btn.dataset.vote });
	checkAiTip();
}

function handleQuiz(btn) {
	if (quizAnswered) return;
	quizAnswered = true;
	var quiz = btn.closest('.eb-quiz');
	if (!quiz) return;

	quiz.querySelectorAll('.eb-quiz-opt').forEach(function(opt) {
		opt.style.pointerEvents = 'none';
	});
	btn.classList.add('selected');

	var result = quiz.querySelector('.eb-quiz-result');
	if (result) {
		result.textContent = 'You are: ' + (btn.dataset.result || 'Unique!');
		setTimeout(function() { result.style.display = 'block'; }, 400);
	}

	ebTrack('quiz_answer', { style: btn.dataset.style });
	checkAiTip();
}

function handleFav(btn) {
	var idx = parseInt(btn.dataset.idx, 10);
	if (favItems.has(idx)) {
		favItems.delete(idx);
		btn.classList.remove('faved');
	} else {
		favItems.add(idx);
		btn.classList.add('faved');
	}
	updateFavSummary();
	ebTrack('fav_toggle', { idx: idx, total_favs: favItems.size });
}

function handleCollect(el) {
	if (el.classList.contains('collected')) return;
	el.classList.add('collected');
	collectedItems.add(el.dataset.emoji);

	if ($collectCounter) $collectCounter.classList.add('show');
	if ($collectNum) $collectNum.textContent = collectedItems.size;

	ebTrack('collectible_found', { emoji: el.dataset.emoji, total: collectedItems.size });
}

function handleReveal(el) {
	var wrap = el.closest('.eb-reveal-blur');
	if (wrap) {
		wrap.classList.add('revealed');
		blurRevealed = true;
		ebTrack('blur_revealed', {});
	}
}

function handleJump(btn) {
	var target = parseInt(btn.dataset.jump, 10);
	var el = document.getElementById('item-' + target);
	if (el) {
		el.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}
}

function handleEmail(btn) {
	var wrap = btn.closest('.eb-email') || btn.closest('.eb-exit');
	if (!wrap) return;
	var input = wrap.querySelector('.eb-email-input');

	// For exit intent email button without input
	if (!input && wrap.classList.contains('eb-exit')) {
		// Convert exit to email capture form
		var exitText = wrap.querySelector('.eb-exit-text');
		if (exitText && !emailCaptured) {
			exitText.innerHTML = '<input type="email" class="eb-email-input" placeholder="Your best email..." style="padding:6px 12px;border:2px solid #f9a8d4;border-radius:20px;font-size:13px;width:200px;outline:none">';
			btn.textContent = 'Subscribe';
			btn.dataset.ebAction = 'email';
		}
		return;
	}

	if (!input || !input.value || input.value.indexOf('@') === -1) return;

	var email = input.value.trim();
	emailCaptured = true;

	// Send to unified email leads REST API
	if (C.emailEndpoint) {
		var source = wrap.classList.contains('eb-exit') ? 'post_exit_intent' : 'post_inline';
		fetch(C.emailEndpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				email: email,
				source: source,
				source_detail: C.category || '',
				page_url: window.location.href,
				page_title: C.postTitle || document.title,
				post_id: C.postId || 0,
				device: window.innerWidth < 768 ? 'mobile' : (window.innerWidth < 1024 ? 'tablet' : 'desktop'),
				viewport_width: window.innerWidth,
				language: navigator.language || '',
				referrer: document.referrer || ''
			}),
			credentials: 'same-origin'
		}).catch(function() {});
	}

	// Show success UI regardless (don't wait for response)
	wrap.classList.add('captured');
	var inner = wrap.querySelector('.eb-email-inner');
	if (inner) {
		inner.innerHTML = '<div class="eb-email-success">\u{1F389} Check your inbox! Your bonus looks are on the way.</div>';
	}
	if (wrap.classList.contains('eb-exit')) {
		var exitText2 = wrap.querySelector('.eb-exit-text');
		if (exitText2) exitText2.innerHTML = '\u{1F389} You\'re subscribed! Check your inbox.';
	}

	ebTrack('email_capture', { source: wrap.dataset.eb || 'inline', fav_count: favItems.size });
}

function handleExitClose() {
	if ($exit) $exit.classList.remove('show');
}

function handleNext(btn) {
	var url = btn.dataset.url;
	if (url) {
		ebTrack('next_article_clicked', { url: url });
		window.location.href = url;
	}
}

function handlePinAll() {
	// Open Pinterest bulk save (individual pins)
	favItems.forEach(function(idx) {
		if (itemPins[idx]) {
			var pinUrl = 'https://www.pinterest.com/pin/create/button/'
				+ '?url=' + encodeURIComponent(window.location.href)
				+ '&media=' + encodeURIComponent(itemPins[idx])
				+ '&description=' + encodeURIComponent(itemTitles[idx] || document.title);
			window.open(pinUrl, '_blank', 'noopener');
		}
	});
}

// === Intersection Observer — Item Tracking + Animation Gating ===
function initObserver() {
	if (!('IntersectionObserver' in window)) {
		return;
	}

	// Observer 1: counting/tracking — triggers early, one-shot per item
	var seenIO = new IntersectionObserver(function(entries) {
		entries.forEach(function(entry) {
			if (entry.isIntersecting) {
				var el = entry.target;
				var idx = parseInt(el.getAttribute('data-item'), 10);
				if (!seenItems.has(idx)) {
					seenItems.add(idx);
					updateProgress();
					updatePills(idx);
					checkMilestones();
					checkNextBar();
					checkAiTip();
					ebTrack('item_seen', { item: idx, total_seen: seenItems.size });
				}
				seenIO.unobserve(el);
			}
		});
	}, { rootMargin: '0px 0px 100px 0px', threshold: 0.15 });

	// Observer 2: animation gating — toggles .in-view for shimmer/Ken Burns
	var animIO = new IntersectionObserver(function(entries) {
		entries.forEach(function(entry) {
			if (entry.isIntersecting) {
				entry.target.classList.add('in-view');
			} else {
				entry.target.classList.remove('in-view');
			}
		});
	}, { rootMargin: '0px', threshold: 0.8 });

	document.querySelectorAll('.eb-item').forEach(function(el) {
		seenIO.observe(el);
		animIO.observe(el);
	});
}

// === Scroll Handler (throttled) ===
var scrollTicking = false;
function onScroll() {
	if (scrollTicking) return;
	scrollTicking = true;
	requestAnimationFrame(function() {
		checkScrollSpeed();
		checkExitIntent();
		scrollTicking = false;
	});
}

// === Skeleton Scroll Gate ===
function initScrollGate() {
	// Wait 200ms to skip any synthetic scroll events
	setTimeout(function() {
		var fired = false;
		function onFirstRealScroll() {
			if (fired) return;
			fired = true;
			window.removeEventListener('scroll', onFirstRealScroll, true);
			window.removeEventListener('pointerdown', onFirstRealScroll, true);
			window.removeEventListener('touchstart', onFirstRealScroll, true);

			if ('requestIdleCallback' in window) {
				requestIdleCallback(function() {
					initSkeletons();
				});
			} else {
				setTimeout(function() {
					initSkeletons();
				}, 50);
			}
		}
		window.addEventListener('scroll', onFirstRealScroll, { once: true, passive: true, capture: true });
		window.addEventListener('pointerdown', onFirstRealScroll, { once: true, passive: true, capture: true });
		window.addEventListener('touchstart', onFirstRealScroll, { once: true, passive: true, capture: true });
	}, 200);
}

function initSkeletons() {
	if (FEATURES.skeletons === false) return;

	var skelCSS = document.createElement('style');
	skelCSS.textContent = [
		'.eb-skeleton{background:#f0f0f0;border-radius:8px;overflow:hidden;position:relative}',
		'.eb-skeleton::after{content:"";position:absolute;top:0;left:0;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.4),transparent);transform:translateX(-100%);animation:ebSkelShimmer 1.5s infinite}',
		'@keyframes ebSkelShimmer{0%{transform:translateX(-100%)}100%{transform:translateX(200%)}}'
	].join('');
	document.head.appendChild(skelCSS);

	// Add skeleton loading to below-fold items that haven't loaded images yet
	if ('IntersectionObserver' in window) {
		var skelIO = new IntersectionObserver(function(entries) {
			entries.forEach(function(entry) {
				if (entry.isIntersecting) {
					// Remove skeleton once in view
					entry.target.querySelectorAll('.eb-skeleton').forEach(function(skel) {
						skel.classList.remove('eb-skeleton');
					});
					skelIO.unobserve(entry.target);
				}
			});
		}, { rootMargin: '600px 0px' });

		document.querySelectorAll('.eb-item').forEach(function(el) {
			if (!el.classList.contains('eb-visible')) {
				skelIO.observe(el);
			}
		});
	}
}

// === Init ===
function init() {
	// Populate header stats total (mobile)
	document.querySelectorAll('.pl-header-stats .eb-collect-total').forEach(function(el) {
		el.textContent = TOTAL;
	});

	updateLiveCount();
	setInterval(updateLiveCount, 30000);

	initObserver();
	checkStreak();

	window.addEventListener('scroll', onScroll, { passive: true });

	// Scroll gate for skeletons
	initScrollGate();
}

// Run on DOMContentLoaded or immediately if already loaded
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}

})();
