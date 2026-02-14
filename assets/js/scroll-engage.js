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
            '<stop offset="0%" stop-color="#e91e63"/>' +
            '<stop offset="50%" stop-color="#f06292"/>' +
            '<stop offset="100%" stop-color="#f48fb1"/>' +
          '</linearGradient>' +
        '</defs>' +
        '<path class="pl-heart-bg" d="M25 44 C25 44, 5 30, 5 18 C5 10, 12 5, 18 5 C22 5, 25 8, 25 12 C25 8, 28 5, 32 5 C38 5, 45 10, 45 18 C45 30, 25 44, 25 44Z"/>' +
        '<g clip-path="url(#plHeartClip)">' +
          '<rect id="plHeartFillRect" x="0" y="50" width="50" height="50" fill="url(#plHeartGrad)"/>' +
        '</g>' +
        '<path class="pl-heart-outline" d="M25 44 C25 44, 5 30, 5 18 C5 10, 12 5, 18 5 C22 5, 25 8, 25 12 C25 8, 28 5, 32 5 C38 5, 45 10, 45 18 C45 30, 25 44, 25 44Z"/>' +
      '</svg>' +
      '<div class="pl-heart-percent" id="plHeartPercent">0%</div>' +
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
  var lastMilestone = 0;

  function updateHeart(scrollPct) {
    var fillRect = document.getElementById('plHeartFillRect');
    var percentEl = document.getElementById('plHeartPercent');
    var heartEl = document.getElementById('plHeartProgress');
    if (!fillRect) return;

    var y = 50 - (scrollPct * 50);
    fillRect.setAttribute('y', y);
    percentEl.textContent = Math.round(scrollPct * 100) + '%';

    var milestone = Math.floor(scrollPct * 4);
    if (milestone > lastMilestone && milestone > 0) {
      lastMilestone = milestone;
      heartEl.classList.remove('pl-heart-pulse');
      void heartEl.offsetWidth;
      heartEl.classList.add('pl-heart-pulse');
      burstHearts(heartEl);
    }
  }

  function burstHearts(heartEl) {
    var rect = heartEl.getBoundingClientRect();
    var cx = rect.left + rect.width / 2;
    var cy = rect.top + rect.height / 2;
    var emojis = ['\uD83D\uDC95','\uD83D\uDC97','\uD83D\uDC96','\uD83E\uDE77','\u2764\uFE0F','\uD83D\uDC98'];

    for (var i = 0; i < 6; i++) {
      var h = document.createElement('div');
      h.className = 'pl-mini-heart';
      h.textContent = emojis[i];
      h.style.left = cx + 'px';
      h.style.top = cy + 'px';
      var angle = (i / 6) * Math.PI * 2 + Math.random() * 0.5;
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

  var speeches = {
    idle: ['Scroll more! \uD83C\uDF80', 'Keep reading! \uD83D\uDCD6', 'So pretty! \u2728', 'Love this! \uD83D\uDC95'],
    milestone25: ['25%! Nice! \uD83C\uDF38', 'Quarter way! \uD83D\uDCAA', 'Keep going! \uD83C\uDF89'],
    milestone50: ['Halfway! \uD83C\uDF8A', "You're amazing! \uD83D\uDC96", 'So engaged! \u2728'],
    milestone75: ['Almost there! \uD83D\uDE80', '75%! Wow! \uD83C\uDF1F', 'Love it! \uD83D\uDC97'],
    milestone100: ['You did it! \uD83C\uDFC6', 'Full heart! \uD83D\uDC96', 'Amazing! \uD83C\uDF89']
  };

  function showSpeech(category) {
    var el = document.getElementById('plDancerSpeech');
    if (!el) return;
    var options = speeches[category];
    el.textContent = options[Math.floor(Math.random() * options.length)];
    el.classList.add('show');
    clearTimeout(speechTimeout);
    speechTimeout = setTimeout(function(){ el.classList.remove('show'); }, 2500);
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
  // SCROLL HANDLER
  // =============================================
  function onScroll() {
    var scrollTop = window.scrollY;
    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
    var scrollPct = Math.min(Math.max(scrollTop / docHeight, 0), 1);

    scrollSpeed = Math.abs(scrollTop - lastScrollY);
    lastScrollY = scrollTop;

    updateHeart(scrollPct);

    clearTimeout(scrollTimeout);
    if (scrollSpeed > 5) startDancing();

    scrollTimeout = setTimeout(function(){
      stopDancing();
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
