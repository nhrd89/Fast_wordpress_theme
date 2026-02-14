(function(){
  'use strict';

  // =============================================
  // INJECT HTML (no PHP template changes needed)
  // =============================================
  function injectElements() {
    // Heart progress indicator (bottom-right)
    var heartHTML = '<div class="pl-heart-progress" id="plHeartProgress">' +
      '<svg viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg">' +
        '<defs>' +
          '<clipPath id="plHeartClip">' +
            '<path d="M25 44 C25 44, 5 30, 5 18 C5 10, 12 5, 18 5 C22 5, 25 8, 25 12 C25 8, 28 5, 32 5 C38 5, 45 10, 45 18 C45 30, 25 44, 25 44Z"/>' +
          '</clipPath>' +
          '<linearGradient id="plHeartGrad" x1="0" y1="1" x2="0" y2="0">' +
            '<stop id="plGradStop0" offset="0%" stop-color="#e91e63"/>' +
            '<stop id="plGradStop1" offset="50%" stop-color="#f06292"/>' +
            '<stop id="plGradStop2" offset="100%" stop-color="#f48fb1"/>' +
          '</linearGradient>' +
        '</defs>' +
        '<path class="pl-heart-bg" d="M25 44 C25 44, 5 30, 5 18 C5 10, 12 5, 18 5 C22 5, 25 8, 25 12 C25 8, 28 5, 32 5 C38 5, 45 10, 45 18 C45 30, 25 44, 25 44Z"/>' +
        '<g clip-path="url(#plHeartClip)">' +
          '<rect id="plHeartFillRect" x="0" y="40" width="50" height="50" fill="url(#plHeartGrad)"/>' +
        '</g>' +
        '<path class="pl-heart-outline" d="M25 44 C25 44, 5 30, 5 18 C5 10, 12 5, 18 5 C22 5, 25 8, 25 12 C25 8, 28 5, 32 5 C38 5, 45 10, 45 18 C45 30, 25 44, 25 44Z"/>' +
      '</svg>' +
      '<div class="pl-heart-percent" id="plHeartPercent">20%</div>' +
      '<div class="pl-streak-flame" id="plStreakFlame">\uD83D\uDD25</div>' +
    '</div>';

    // Dancing girl (bottom-left)
    var dancerHTML = '<div class="pl-dancer-container" id="plDancerContainer">' +
      '<div class="pl-dancer idle" id="plDancer">' +
        '<div class="pl-dancer-speech" id="plDancerSpeech">Keep going! \uD83D\uDC95</div>' +
        '<div class="pl-dancer-body">' +
          '<div class="pl-dancer-head">' +
            '<div class="pl-dancer-hair-left"></div>' +
            '<div class="pl-dancer-hair-right"></div>' +
            '<div class="pl-dancer-eyes">' +
              '<div class="pl-dancer-eye"></div>' +
              '<div class="pl-dancer-eye"></div>' +
            '</div>' +
            '<div class="pl-dancer-smile"></div>' +
          '</div>' +
          '<div class="pl-dancer-arm-left"><div class="pl-dancer-hand"></div></div>' +
          '<div class="pl-dancer-arm-right"><div class="pl-dancer-hand"></div></div>' +
          '<div class="pl-dancer-dress"></div>' +
          '<div class="pl-dancer-leg-left"><div class="pl-dancer-shoe"></div></div>' +
          '<div class="pl-dancer-leg-right"><div class="pl-dancer-shoe"></div></div>' +
        '</div>' +
      '</div>' +
    '</div>';

    document.body.insertAdjacentHTML('beforeend', heartHTML);
    document.body.insertAdjacentHTML('beforeend', dancerHTML);
  }

  // =============================================
  // HEART PROGRESS
  // =============================================
  var PRE_FILL = 0.2; // 20% head start
  var lastMicroMilestone = 2; // start at 2 (already passed 10% and 20% from pre-fill)
  var lastMajorMilestone = 0;
  var completed = false;

  // Color shift stops: pink → deeper rose → rose gold
  var colorStops = [
    { pct: 0,   s0: '#e91e63', s1: '#f06292', s2: '#f48fb1' },
    { pct: 0.3, s0: '#d81b60', s1: '#ec407a', s2: '#f06292' },
    { pct: 0.6, s0: '#c2185b', s1: '#e91e63', s2: '#ec407a' },
    { pct: 1,   s0: '#b71c1c', s1: '#d32f2f', s2: '#e57373' }
  ];

  function lerpColor(a, b, t) {
    var ah = parseInt(a.slice(1), 16), bh = parseInt(b.slice(1), 16);
    var ar = (ah >> 16) & 0xff, ag = (ah >> 8) & 0xff, ab = ah & 0xff;
    var br = (bh >> 16) & 0xff, bg = (bh >> 8) & 0xff, bb = bh & 0xff;
    var rr = Math.round(ar + (br - ar) * t);
    var rg = Math.round(ag + (bg - ag) * t);
    var rb = Math.round(ab + (bb - ab) * t);
    return '#' + ((1 << 24) + (rr << 16) + (rg << 8) + rb).toString(16).slice(1);
  }

  function getColorStops(pct) {
    var i = 0;
    for (; i < colorStops.length - 1; i++) {
      if (pct <= colorStops[i + 1].pct) break;
    }
    var a = colorStops[i], b = colorStops[Math.min(i + 1, colorStops.length - 1)];
    var range = b.pct - a.pct;
    var t = range > 0 ? (pct - a.pct) / range : 0;
    return {
      s0: lerpColor(a.s0, b.s0, t),
      s1: lerpColor(a.s1, b.s1, t),
      s2: lerpColor(a.s2, b.s2, t)
    };
  }

  function updateHeart(scrollPct) {
    var fillRect = document.getElementById('plHeartFillRect');
    var percentEl = document.getElementById('plHeartPercent');
    var heartEl = document.getElementById('plHeartProgress');
    if (!fillRect || completed) return;

    // Map scroll 0-1 to display 20%-100% (pre-fill effect)
    var displayPct = PRE_FILL + scrollPct * (1 - PRE_FILL);
    var y = 50 - (displayPct * 50);
    fillRect.setAttribute('y', y);
    percentEl.textContent = Math.round(displayPct * 100) + '%';

    // Color shift
    var colors = getColorStops(displayPct);
    var s0 = document.getElementById('plGradStop0');
    var s1 = document.getElementById('plGradStop1');
    var s2 = document.getElementById('plGradStop2');
    if (s0) { s0.setAttribute('stop-color', colors.s0); }
    if (s1) { s1.setAttribute('stop-color', colors.s1); }
    if (s2) { s2.setAttribute('stop-color', colors.s2); }

    // Glow intensification
    var glowStrength = 0.3 + displayPct * 0.5;
    var glowSpread = 12 + displayPct * 16;
    heartEl.style.filter = 'drop-shadow(0 4px ' + glowSpread + 'px rgba(233,30,99,' + glowStrength.toFixed(2) + '))';

    // Micro-milestones every 10%
    var microMilestone = Math.floor(displayPct * 10);
    if (microMilestone > lastMicroMilestone && microMilestone > 0) {
      lastMicroMilestone = microMilestone;

      // Check if it's a major milestone (25/50/75/100)
      var isMajor = (microMilestone === 3 || microMilestone === 5 || microMilestone === 8 || microMilestone === 10);
      // Note: 30%≈25%, 50%=50%, 80%≈75%, 100%=100% in display space

      if (isMajor) {
        // Big celebration
        heartEl.classList.remove('pl-heart-pulse');
        void heartEl.offsetWidth;
        heartEl.classList.add('pl-heart-pulse');
        burstHearts(heartEl, 6);
        dancerReact('major');
      } else {
        // Mini celebration
        heartEl.classList.remove('pl-heart-mini-pulse');
        void heartEl.offsetWidth;
        heartEl.classList.add('pl-heart-mini-pulse');
        burstHearts(heartEl, 3);
        dancerReact('minor');
      }
    }

    // 100% completion!
    if (scrollPct >= 0.99 && !completed) {
      completed = true;
      celebrate100(heartEl);
    }
  }

  function burstHearts(heartEl, count) {
    var rect = heartEl.getBoundingClientRect();
    var cx = rect.left + rect.width / 2;
    var cy = rect.top + rect.height / 2;
    var emojis = ['\uD83D\uDC95','\uD83D\uDC97','\uD83D\uDC96','\uD83E\uDE77','\u2764\uFE0F','\uD83D\uDC98'];

    for (var i = 0; i < count; i++) {
      var h = document.createElement('div');
      h.className = 'pl-mini-heart';
      h.textContent = emojis[i % emojis.length];
      h.style.left = cx + 'px';
      h.style.top = cy + 'px';
      var angle = (i / count) * Math.PI * 2 + Math.random() * 0.5;
      var dist = 40 + Math.random() * 50;
      h.style.setProperty('--dx', Math.cos(angle) * dist + 'px');
      h.style.setProperty('--dy', (Math.sin(angle) * dist - 30) + 'px');
      h.style.setProperty('--rot', (Math.random() * 360) + 'deg');
      h.style.animation = 'plFloatHeart 0.8s cubic-bezier(0.16,1,0.3,1) forwards';
      h.style.animationDelay = (i * 0.05) + 's';
      document.body.appendChild(h);
      (function(el){ setTimeout(function(){ el.remove(); }, 1000); })(h);
    }
  }

  function celebrate100(heartEl) {
    // Big 12-heart burst
    burstHearts(heartEl, 12);

    // Golden state
    heartEl.classList.add('pl-golden');
    heartEl.classList.remove('pl-heart-pulse');
    void heartEl.offsetWidth;
    heartEl.classList.add('pl-heart-pulse');

    // Confetti burst
    var rect = heartEl.getBoundingClientRect();
    var cx = rect.left + rect.width / 2;
    var cy = rect.top + rect.height / 2;
    var confetti = ['\uD83C\uDF89','\uD83C\uDF8A','\u2B50','\uD83C\uDFC6','\uD83D\uDCAB','\uD83C\uDF1F','\uD83E\uDE77','\u2728'];
    for (var i = 0; i < 8; i++) {
      var c = document.createElement('div');
      c.className = 'pl-mini-heart';
      c.textContent = confetti[i];
      c.style.left = cx + 'px';
      c.style.top = cy + 'px';
      c.style.fontSize = '20px';
      var angle = (i / 8) * Math.PI * 2;
      var dist = 60 + Math.random() * 40;
      c.style.setProperty('--dx', Math.cos(angle) * dist + 'px');
      c.style.setProperty('--dy', (Math.sin(angle) * dist - 40) + 'px');
      c.style.setProperty('--rot', (Math.random() * 720) + 'deg');
      c.style.animation = 'plFloatHeart 1.2s cubic-bezier(0.16,1,0.3,1) forwards';
      c.style.animationDelay = (i * 0.04) + 's';
      document.body.appendChild(c);
      (function(el){ setTimeout(function(){ el.remove(); }, 1500); })(c);
    }

    // Dancer jump + speech
    showSpeech('milestone100');
    var dancer = document.getElementById('plDancer');
    if (dancer) {
      dancer.className = 'pl-dancer pl-dancing-3';
      setTimeout(function(){ if (!completed) return; dancer.className = 'pl-dancer idle'; }, 2000);
    }

    // Remove golden after 3 seconds
    setTimeout(function(){ heartEl.classList.remove('pl-golden'); }, 3000);
  }

  // =============================================
  // DANCING GIRL
  // =============================================
  var danceFrame = 0;
  var danceInterval = null;
  var speechTimeout;
  var lastScrollY = 0;
  var scrollSpeed = 0;
  var lastMilestoneForDancer = -1;
  var scrollTimeout;

  // Streak tracking
  var streakStart = 0;
  var streakActive = false;
  var streakRaf = 0;

  var speeches = {
    idle: ['Scroll more! \uD83C\uDF80', 'Keep reading! \uD83D\uDCD6', 'So pretty! \u2728', 'Love this! \uD83D\uDC95'],
    milestone25: ['25%! Nice! \uD83C\uDF38', 'Quarter way! \uD83D\uDCAA', 'Keep going! \uD83C\uDF89'],
    milestone50: ['Halfway! \uD83C\uDF8A', "You're amazing! \uD83D\uDC96", 'So engaged! \u2728'],
    milestone75: ['Almost there! \uD83D\uDE80', '75%! Wow! \uD83C\uDF1F', 'Love it! \uD83D\uDC97'],
    milestone100: ['You did it! \uD83C\uDFC6', 'Full heart! \uD83D\uDC96', 'Amazing! \uD83C\uDF89'],
    micro: ['Nice! \u2728', 'Yay! \uD83D\uDC95', 'Go go! \uD83C\uDF1F', 'Woo! \uD83C\uDF80']
  };

  function showSpeech(category) {
    var el = document.getElementById('plDancerSpeech');
    if (!el) return;
    var options = speeches[category];
    if (!options) return;
    el.textContent = options[Math.floor(Math.random() * options.length)];
    el.classList.add('show');
    clearTimeout(speechTimeout);
    speechTimeout = setTimeout(function(){ el.classList.remove('show'); }, 2500);
  }

  function dancerReact(type) {
    var dancer = document.getElementById('plDancer');
    if (!dancer) return;
    if (type === 'major') {
      // Jump + speech at major milestones
      dancer.className = 'pl-dancer pl-dancing-3';
      var pct = lastMicroMilestone * 10;
      if (pct >= 100) showSpeech('milestone100');
      else if (pct >= 75) showSpeech('milestone75');
      else if (pct >= 50) showSpeech('milestone50');
      else showSpeech('milestone25');
      setTimeout(function(){ dancer.className = 'pl-dancer idle'; }, 800);
    } else {
      // Quick pose at minor milestones
      var pose = Math.floor(Math.random() * 2) + 1;
      dancer.className = 'pl-dancer pl-dancing-' + pose;
      if (Math.random() > 0.5) showSpeech('micro');
      setTimeout(function(){ dancer.className = 'pl-dancer idle'; }, 400);
    }
  }

  function startDancing() {
    if (danceInterval) return;
    var dancer = document.getElementById('plDancer');
    danceInterval = setInterval(function(){
      danceFrame = (danceFrame % 3) + 1;
      dancer.className = 'pl-dancer pl-dancing-' + danceFrame;
      if (Math.random() > 0.5) addSparkle();
    }, 200);
  }

  function stopDancing() {
    if (danceInterval) { clearInterval(danceInterval); danceInterval = null; }
    var dancer = document.getElementById('plDancer');
    if (dancer) dancer.className = 'pl-dancer idle';
  }

  function addSparkle() {
    var container = document.getElementById('plDancerContainer');
    if (!container) return;
    var s = document.createElement('div');
    s.className = 'pl-dancer-sparkle';
    s.textContent = ['\u2728','\u2B50','\uD83D\uDCAB','\uD83E\uDE77'][Math.floor(Math.random()*4)];
    s.style.left = (Math.random() * 60) + 'px';
    s.style.top = (Math.random() * 40) + 'px';
    s.style.animation = 'plSparkle 0.6s ease forwards';
    container.appendChild(s);
    setTimeout(function(){ s.remove(); }, 700);
  }

  // =============================================
  // SCROLL STREAK
  // =============================================
  function updateStreak() {
    var flame = document.getElementById('plStreakFlame');
    if (!flame) return;

    if (!streakActive) {
      flame.classList.remove('show');
      return;
    }

    var elapsed = (Date.now() - streakStart) / 1000;
    if (elapsed >= 3) {
      flame.classList.add('show');
      // Scale grows with streak duration (max at 10s)
      var scale = 1 + Math.min((elapsed - 3) / 7, 1) * 0.6;
      flame.style.transform = 'scale(' + scale.toFixed(2) + ')';
    }

    streakRaf = requestAnimationFrame(updateStreak);
  }

  // =============================================
  // SCROLL HANDLER
  // =============================================
  function onScroll() {
    var scrollTop = window.scrollY;
    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
    var scrollPct = Math.min(Math.max(scrollTop / docHeight, 0), 1);

    scrollSpeed = Math.abs(scrollTop - lastScrollY);
    lastScrollY = scrollTop;

    // Heartbeat while scrolling
    var heartEl = document.getElementById('plHeartProgress');
    if (heartEl && scrollSpeed > 2) {
      heartEl.classList.add('pl-heartbeat');
    }

    updateHeart(scrollPct);

    // Streak tracking
    if (scrollSpeed > 5) {
      if (!streakActive) {
        streakActive = true;
        streakStart = Date.now();
        cancelAnimationFrame(streakRaf);
        streakRaf = requestAnimationFrame(updateStreak);
      }
    }

    clearTimeout(scrollTimeout);
    if (scrollSpeed > 5) startDancing();

    scrollTimeout = setTimeout(function(){
      stopDancing();
      // Stop heartbeat
      if (heartEl) heartEl.classList.remove('pl-heartbeat');
      // End streak
      streakActive = false;
      cancelAnimationFrame(streakRaf);
      var flame = document.getElementById('plStreakFlame');
      if (flame) { flame.classList.remove('show'); flame.style.transform = ''; }

      if (Math.random() > 0.7 && scrollPct > 0.1 && scrollPct < 0.9) {
        showSpeech('idle');
      }
    }, 300);

    var currentMilestone = Math.floor(scrollPct * 4);
    if (currentMilestone > lastMilestoneForDancer) {
      lastMilestoneForDancer = currentMilestone;
      var keys = ['milestone25','milestone50','milestone75','milestone100'];
      if (currentMilestone > 0 && currentMilestone <= 4) showSpeech(keys[currentMilestone-1]);
    }
  }

  // =============================================
  // INIT
  // =============================================
  function init() {
    injectElements();
    window.addEventListener('scroll', onScroll, { passive: true });
    setTimeout(function(){ showSpeech('idle'); }, 3000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
