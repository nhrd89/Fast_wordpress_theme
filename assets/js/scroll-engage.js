(function(){
  'use strict';

  // Config from WordPress Customizer (all booleans)
  var C = window.plEngageConfig || {};

  // =============================================
  // SHARED STATE
  // =============================================
  var scrollPct = 0, displayPct = 0, scrollSpeed = 0, lastScrollY = 0;
  var isScrolling = false, scrollTimeout, lastMicroMilestone = 2, lastMajorMilestone = 0;

  // =============================================
  // FEATURE 1: HEART PROGRESS (C.heart)
  // =============================================
  var heartEl, heartFill, heartPercent, streakFlame;
  var streakStart = 0, streakActive = false, streakRAF;

  function initHeart() {
    if (!C.heart) return;
    var html = '<div class="pl-heart-progress" id="plHeartProgress">' +
      '<svg viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg">' +
        '<defs><clipPath id="plHeartClip"><path d="M25 44 C25 44,5 30,5 18 C5 10,12 5,18 5 C22 5,25 8,25 12 C25 8,28 5,32 5 C38 5,45 10,45 18 C45 30,25 44,25 44Z"/></clipPath>' +
        '<linearGradient id="plHeartGrad" x1="0" y1="1" x2="0" y2="0">' +
          '<stop offset="0%" stop-color="#e91e63" id="plGradStop1"/>' +
          '<stop offset="50%" stop-color="#f06292" id="plGradStop2"/>' +
          '<stop offset="100%" stop-color="#f48fb1" id="plGradStop3"/></linearGradient></defs>' +
        '<path class="pl-heart-bg" d="M25 44 C25 44,5 30,5 18 C5 10,12 5,18 5 C22 5,25 8,25 12 C25 8,28 5,32 5 C38 5,45 10,45 18 C45 30,25 44,25 44Z"/>' +
        '<g clip-path="url(#plHeartClip)"><rect id="plHeartFillRect" x="0" y="40" width="50" height="50" fill="url(#plHeartGrad)"/></g>' +
        '<path class="pl-heart-outline" d="M25 44 C25 44,5 30,5 18 C5 10,12 5,18 5 C22 5,25 8,25 12 C25 8,28 5,32 5 C38 5,45 10,45 18 C45 30,25 44,25 44Z"/></svg>' +
      '<div class="pl-heart-percent" id="plHeartPercent">20%</div>' +
      '<div class="pl-streak-flame" id="plStreakFlame">\uD83D\uDD25</div></div>';
    document.body.insertAdjacentHTML('beforeend', html);
    heartEl = document.getElementById('plHeartProgress');
    heartFill = document.getElementById('plHeartFillRect');
    heartPercent = document.getElementById('plHeartPercent');
    streakFlame = document.getElementById('plStreakFlame');
  }

  function updateHeart() {
    if (!C.heart || !heartFill) return;
    // Map 0-1 scroll to 20-100% display
    displayPct = 0.2 + (scrollPct * 0.8);
    var y = 50 - (displayPct * 50);
    heartFill.setAttribute('y', y);
    heartPercent.textContent = Math.round(displayPct * 100) + '%';

    // Heartbeat while scrolling
    if (scrollSpeed > 2) {
      heartEl.classList.add('pl-heartbeat');
    }

    // Glow intensification
    var glowStrength, glowSpread;
    if (displayPct < 0.4) { glowStrength = 0.3; glowSpread = 12; }
    else if (displayPct < 0.65) { glowStrength = 0.45; glowSpread = 16; }
    else if (displayPct < 0.9) { glowStrength = 0.6; glowSpread = 22; }
    else { glowStrength = 0.8; glowSpread = 28; }
    heartEl.style.filter = 'drop-shadow(0 4px ' + glowSpread + 'px rgba(233,30,99,' + glowStrength + '))';

    // Color shift
    var s1, s2, s3;
    if (displayPct < 0.5) { s1='#e91e63'; s2='#f06292'; s3='#f48fb1'; }
    else if (displayPct < 0.8) { s1='#d81b60'; s2='#e91e63'; s3='#ec407a'; }
    else { s1='#e91e63'; s2='#f06292'; s3='#ff9800'; }
    document.getElementById('plGradStop1').setAttribute('stop-color', s1);
    document.getElementById('plGradStop2').setAttribute('stop-color', s2);
    document.getElementById('plGradStop3').setAttribute('stop-color', s3);

    // Micro milestones every 10%
    var micro = Math.floor(displayPct * 10);
    if (micro > lastMicroMilestone && micro > 2) {
      lastMicroMilestone = micro;
      var isMajor = (micro === 3 || micro === 5 || micro === 8 || micro === 10);
      heartEl.classList.remove('pl-heart-pulse', 'pl-heart-mini-pulse');
      void heartEl.offsetWidth;
      heartEl.classList.add(isMajor ? 'pl-heart-pulse' : 'pl-heart-mini-pulse');
      burstHearts(isMajor ? 6 : 3);
      if (isMajor) dancerReact('major', micro);
      else dancerReact('minor', micro);
      if (C.collectibles) popCollectible();
    }

    // 100% celebration
    if (displayPct >= 0.99 && lastMajorMilestone < 10) {
      lastMajorMilestone = 10;
      celebrate100();
    }
  }

  function burstHearts(count) {
    if (!heartEl) return;
    var rect = heartEl.getBoundingClientRect();
    var cx = rect.left + rect.width / 2, cy = rect.top + rect.height / 2;
    var emojis = ['\uD83D\uDC95','\uD83D\uDC97','\uD83D\uDC96','\uD83E\uDE77','\u2764\uFE0F','\uD83D\uDC98'];
    for (var i = 0; i < count; i++) {
      var h = document.createElement('div');
      h.className = 'pl-mini-heart';
      h.textContent = emojis[i % 6];
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

  function celebrate100() {
    burstHearts(12);
    // Confetti
    var confetti = ['\uD83C\uDF89','\u2728','\uD83D\uDC96','\uD83C\uDF1F','\uD83C\uDF8A','\uD83E\uDE77','\u2B50','\uD83D\uDCAB'];
    var rect = heartEl.getBoundingClientRect();
    var cx = rect.left + rect.width / 2, cy = rect.top + rect.height / 2;
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
      c.style.animationDelay = (i * 0.06) + 's';
      document.body.appendChild(c);
      (function(el){ setTimeout(function(){ el.remove(); }, 1500); })(c);
    }
    heartEl.classList.add('pl-golden');
    setTimeout(function(){ heartEl.classList.remove('pl-golden'); }, 4000);
    if (C.dancer) {
      var d = document.getElementById('plDancer');
      if (d) d.className = 'pl-dancer pl-dancing-3' + (d.dataset.extras || '');
      showSpeech('milestone100');
    }
    if (C.achievements) unlockAchievement('deep_diver', 'Deep Diver \uD83E\uDD3F', 'Read 100% of the article!');
  }

  // Streak tracker
  function updateStreak() {
    if (!C.heart || !streakFlame) return;
    if (isScrolling) {
      if (!streakActive) { streakStart = Date.now(); streakActive = true; }
      var elapsed = (Date.now() - streakStart) / 1000;
      if (elapsed > 3) {
        streakFlame.classList.add('active');
        var scale = Math.min(1 + (elapsed - 3) * 0.06, 1.6);
        streakFlame.style.transform = 'scale(' + scale + ')';
      }
    } else {
      streakActive = false;
      streakFlame.classList.remove('active');
    }
    streakRAF = requestAnimationFrame(updateStreak);
  }

  // =============================================
  // FEATURE 2: DANCING GIRL (C.dancer)
  // =============================================
  var dancer, dancerSpeech, dancerContainer;
  var danceFrame = 0, danceInterval;
  var speechTimeout;

  var speeches = {
    idle: ['Hey! \uD83C\uDF38', 'Read more~ \uD83D\uDC95', 'Cute! \u2728', 'Hehe \uD83C\uDF80'],
    milestone25: ['Yay! \uD83C\uDF38', 'Nice~ \uD83D\uDCAB', 'Go go! \uD83C\uDF1F'],
    milestone50: ['Halfway! \uD83C\uDF8A', 'Amazing~ \uD83D\uDC96', 'Woo! \u2728'],
    milestone75: ['Almost! \uD83D\uDE0D', 'So close~ \uD83D\uDC97', 'Wow! \uD83C\uDF1F'],
    milestone100: ['Yaaay! \uD83C\uDFC6', 'You did it! \uD83D\uDC96', '\uD83C\uDF89\uD83C\uDF89\uD83C\uDF89'],
    micro: ['\u2728', '!\uD83D\uDC95', '~\uD83C\uDF1F', 'Ooh!\uD83C\uDF80']
  };

  function initDancer() {
    if (!C.dancer) return;
    var html = '<div class="pl-dancer-container" id="plDancerContainer">' +
      '<div class="pl-dancer idle" id="plDancer">' +
        '<div class="pl-dancer-speech" id="plDancerSpeech"></div>' +
        '<div class="pl-dancer-body">' +
          '<div class="pl-dancer-head"><div class="pl-dancer-hair-left"></div><div class="pl-dancer-hair-right"></div>' +
            '<div class="pl-dancer-eyes"><div class="pl-dancer-eye"></div><div class="pl-dancer-eye"></div></div>' +
            '<div class="pl-dancer-smile"></div></div>' +
          '<div class="pl-dancer-arm-left"><div class="pl-dancer-hand"></div></div>' +
          '<div class="pl-dancer-arm-right"><div class="pl-dancer-hand"></div></div>' +
          '<div class="pl-dancer-dress"></div>' +
          '<div class="pl-dancer-leg-left"><div class="pl-dancer-shoe"></div></div>' +
          '<div class="pl-dancer-leg-right"><div class="pl-dancer-shoe"></div></div>' +
          '<div class="pl-dancer-bow" id="plDancerBow" style="display:none"></div>' +
          '<div class="pl-dancer-wand" id="plDancerWand" style="display:none"></div>' +
          '<div class="pl-dancer-crown" id="plDancerCrown" style="display:none"></div>' +
        '</div>' +
      '</div>' +
    '</div>';
    document.body.insertAdjacentHTML('beforeend', html);
    dancer = document.getElementById('plDancer');
    dancerSpeech = document.getElementById('plDancerSpeech');
    dancerContainer = document.getElementById('plDancerContainer');
    setTimeout(function(){ showSpeech('idle'); }, 3000);
  }

  function showSpeech(category) {
    if (!dancerSpeech) return;
    var options = speeches[category];
    if (!options) return;
    dancerSpeech.textContent = options[Math.floor(Math.random() * options.length)];
    dancerSpeech.classList.add('show');
    clearTimeout(speechTimeout);
    speechTimeout = setTimeout(function(){ dancerSpeech.classList.remove('show'); }, 2500);
  }

  function startDancing() {
    if (danceInterval || !dancer) return;
    danceInterval = setInterval(function(){
      danceFrame = (danceFrame % 3) + 1;
      dancer.className = 'pl-dancer pl-dancing-' + danceFrame + (dancer.dataset.extras || '');
      if (Math.random() > 0.5) addSparkle();
    }, 200);
  }

  function stopDancing() {
    if (danceInterval) { clearInterval(danceInterval); danceInterval = null; }
    if (dancer) dancer.className = 'pl-dancer idle' + (dancer.dataset.extras || '');
  }

  function addSparkle() {
    if (!dancerContainer) return;
    var s = document.createElement('div');
    s.className = 'pl-dancer-sparkle';
    s.textContent = ['\u2728','\u2B50','\uD83D\uDCAB','\uD83E\uDE77'][Math.floor(Math.random() * 4)];
    s.style.left = (Math.random() * 60) + 'px';
    s.style.top = (Math.random() * 40) + 'px';
    s.style.animation = 'plSparkle 0.6s ease forwards';
    dancerContainer.appendChild(s);
    setTimeout(function(){ s.remove(); }, 700);
  }

  function dancerReact(type, milestone) {
    if (!C.dancer || !dancer) return;
    if (type === 'major') {
      dancer.className = 'pl-dancer pl-dancing-3' + (dancer.dataset.extras || '');
      var keys = { 3: 'milestone25', 5: 'milestone50', 8: 'milestone75', 10: 'milestone100' };
      showSpeech(keys[milestone] || 'idle');
      setTimeout(function(){ if (!isScrolling) stopDancing(); }, 800);
    } else {
      var pose = Math.ceil(Math.random() * 3);
      dancer.className = 'pl-dancer pl-dancing-' + pose + (dancer.dataset.extras || '');
      if (Math.random() > 0.5) showSpeech('micro');
      setTimeout(function(){ if (!isScrolling) stopDancing(); }, 500);
    }
  }

  // =============================================
  // FEATURE 3: COMBO COUNTER (C.combo)
  // =============================================
  var comboEl, comboCount = 0, comboTimer;

  function initCombo() {
    if (!C.combo) return;
    var html = '<div class="pl-combo" id="plCombo">' +
      '<span class="pl-combo-multiplier" id="plComboMultiplier">2x</span>' +
      '<span class="pl-combo-label">COMBO</span></div>';
    document.body.insertAdjacentHTML('beforeend', html);
    comboEl = document.getElementById('plCombo');
  }

  function updateCombo() {
    if (!C.combo || !comboEl) return;
    if (scrollSpeed > 3) {
      comboCount++;
      if (comboCount > 10) {
        var multiplier = Math.min(Math.floor(comboCount / 10) + 1, 10);
        document.getElementById('plComboMultiplier').textContent = multiplier + 'x';
        comboEl.classList.add('active');
        if (multiplier >= 5) comboEl.classList.add('hot');
        else comboEl.classList.remove('hot');
        if (multiplier >= 8) comboEl.classList.add('fire');
        else comboEl.classList.remove('fire');
      }
    }
    clearTimeout(comboTimer);
    comboTimer = setTimeout(function(){
      comboCount = 0;
      comboEl.classList.remove('active', 'hot', 'fire');
    }, 800);
  }

  // =============================================
  // FEATURE 4: FLOATING COLLECTIBLES (C.collectibles)
  // =============================================
  var collectCount = 0, collectCounterEl;
  var collectiblePositions = [];

  function initCollectibles() {
    if (!C.collectibles) return;
    var html = '<div class="pl-collect-counter" id="plCollectCounter">' +
      '<span class="pl-collect-icon">\uD83D\uDC8E</span>' +
      '<span class="pl-collect-num" id="plCollectNum">0</span></div>';
    document.body.insertAdjacentHTML('beforeend', html);
    collectCounterEl = document.getElementById('plCollectCounter');
    var pageHeight = document.documentElement.scrollHeight;
    var numCollectibles = Math.min(Math.floor(pageHeight / 800), 20);
    for (var i = 0; i < numCollectibles; i++) {
      var pct = 0.08 + (i / numCollectibles) * 0.85;
      collectiblePositions.push({
        pct: pct,
        collected: false,
        emoji: ['\uD83D\uDC8E','\u2B50','\uD83C\uDF1F','\uD83E\uDE77','\uD83D\uDC96'][i % 5]
      });
    }
  }

  function updateCollectibles() {
    if (!C.collectibles) return;
    collectiblePositions.forEach(function(item) {
      if (!item.collected && scrollPct >= item.pct - 0.01 && scrollPct <= item.pct + 0.02) {
        item.collected = true;
        collectCount++;
        document.getElementById('plCollectNum').textContent = collectCount;
        popCollectibleAt(item);
        if (collectCount % 5 === 0) {
          collectCounterEl.classList.remove('pl-collect-pulse');
          void collectCounterEl.offsetWidth;
          collectCounterEl.classList.add('pl-collect-pulse');
        }
      }
    });
  }

  function popCollectible() {
    // Visual pop triggered by heart milestones — find closest uncollected
    var closest = null, closestDist = 1;
    collectiblePositions.forEach(function(item) {
      if (!item.collected) {
        var d = Math.abs(scrollPct - item.pct);
        if (d < closestDist) { closestDist = d; closest = item; }
      }
    });
    if (closest && closestDist < 0.05) {
      closest.collected = true;
      collectCount++;
      document.getElementById('plCollectNum').textContent = collectCount;
      popCollectibleAt(closest);
    }
  }

  function popCollectibleAt(item) {
    var x = 40 + Math.random() * (window.innerWidth - 80);
    var yPos = window.innerHeight * 0.4 + (Math.random() - 0.5) * 100;
    var el = document.createElement('div');
    el.className = 'pl-collectible-pop';
    el.textContent = item.emoji;
    el.style.left = x + 'px';
    el.style.top = yPos + 'px';
    el.style.position = 'fixed';
    document.body.appendChild(el);
    var counterRect = collectCounterEl.getBoundingClientRect();
    var endX = counterRect.left + counterRect.width / 2;
    var endY = counterRect.top + counterRect.height / 2;
    el.style.setProperty('--endX', (endX - x) + 'px');
    el.style.setProperty('--endY', (endY - yPos) + 'px');
    el.style.animation = 'plCollectFly 0.6s cubic-bezier(0.34,1.56,0.64,1) forwards';
    setTimeout(function(){ el.remove(); }, 700);
  }

  // =============================================
  // FEATURE 5: ACHIEVEMENTS (C.achievements)
  // =============================================
  var unlockedAchievements = {};
  var achievementQueue = [];
  var showingAchievement = false;

  function initAchievements() {
    if (!C.achievements) return;
    var html = '<div class="pl-achievement-container" id="plAchievementContainer"></div>';
    document.body.insertAdjacentHTML('beforeend', html);
  }

  function checkAchievements() {
    if (!C.achievements) return;
    if (displayPct >= 0.5 && !unlockedAchievements.speed_reader) {
      var elapsed = (Date.now() - pageLoadTime) / 1000;
      if (elapsed < 30) unlockAchievement('speed_reader', 'Speed Reader \uD83C\uDFC3', 'Reached 50% in under 30 seconds!');
    }
    if (displayPct >= 0.8 && !unlockedAchievements.deep_diver) {
      unlockAchievement('deep_diver', 'Deep Diver \uD83E\uDD3F', 'Explored 80% of the article!');
    }
    var hour = new Date().getHours();
    if ((hour >= 22 || hour < 5) && displayPct > 0.3 && !unlockedAchievements.night_owl) {
      unlockAchievement('night_owl', 'Night Owl \uD83E\uDD89', 'Late night reading session!');
    }
    if (C.collectibles && collectCount >= 10 && !unlockedAchievements.collector) {
      unlockAchievement('collector', 'Gem Collector \uD83D\uDC8E', 'Collected 10 gems!');
    }
    if (C.combo && comboCount >= 50 && !unlockedAchievements.combo_master) {
      unlockAchievement('combo_master', 'Combo Master \uD83D\uDD25', 'Hit a 5x scroll combo!');
    }
  }

  function unlockAchievement(id, title, desc) {
    if (unlockedAchievements[id]) return;
    unlockedAchievements[id] = true;
    achievementQueue.push({ title: title, desc: desc });
    if (!showingAchievement) showNextAchievement();
  }

  function showNextAchievement() {
    if (achievementQueue.length === 0) { showingAchievement = false; return; }
    showingAchievement = true;
    var ach = achievementQueue.shift();
    var container = document.getElementById('plAchievementContainer');
    var el = document.createElement('div');
    el.className = 'pl-achievement';
    el.innerHTML = '<div class="pl-achievement-icon">\uD83C\uDFC6</div>' +
      '<div class="pl-achievement-text"><div class="pl-achievement-title">' + ach.title + '</div>' +
      '<div class="pl-achievement-desc">' + ach.desc + '</div></div>';
    container.appendChild(el);
    requestAnimationFrame(function(){ el.classList.add('show'); });
    setTimeout(function(){
      el.classList.remove('show');
      setTimeout(function(){ el.remove(); showNextAchievement(); }, 400);
    }, 3000);
  }

  // =============================================
  // FEATURE 6: IMAGE SHIMMER (C.shimmer)
  // =============================================
  function initShimmer() {
    if (!C.shimmer) return;
    var images = document.querySelectorAll('.single-content img, .entry-content img, article img');
    var shimmerObserver = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          var img = entry.target;
          if (!img.dataset.shimmerDone) {
            img.dataset.shimmerDone = '1';
            img.classList.add('pl-shimmer-reveal');
            setTimeout(function(){ img.classList.remove('pl-shimmer-reveal'); }, 1000);
          }
          shimmerObserver.unobserve(img);
        }
      });
    }, { threshold: 0.2 });
    images.forEach(function(img) { shimmerObserver.observe(img); });
  }

  // =============================================
  // FEATURE 7: BACKGROUND MOOD (C.bgMood)
  // =============================================
  function updateBgMood() {
    if (!C.bgMood) return;
    // Relaxing spa-like color journey as you scroll
    var colors = [
      {r:255, g:253, b:250}, // 0% warm ivory
      {r:255, g:245, b:247}, // 15% barely-there blush
      {r:250, g:243, b:255}, // 30% soft lavender mist
      {r:243, g:250, b:255}, // 45% sky blue whisper
      {r:245, g:255, b:250}, // 60% mint fresh
      {r:255, g:248, b:240}, // 75% peach warmth
      {r:255, g:243, b:248}, // 90% rose petal
      {r:253, g:245, b:255}  // 100% dreamy lilac
    ];
    var idx = scrollPct * (colors.length - 1);
    var i = Math.floor(idx);
    var t = idx - i;
    var c1 = colors[Math.min(i, colors.length - 1)];
    var c2 = colors[Math.min(i + 1, colors.length - 1)];
    // Smooth cubic interpolation
    t = t * t * (3 - 2 * t);
    var r = Math.round(c1.r + (c2.r - c1.r) * t);
    var g = Math.round(c1.g + (c2.g - c1.g) * t);
    var b = Math.round(c1.b + (c2.b - c1.b) * t);
    document.body.style.backgroundColor = 'rgb(' + r + ',' + g + ',' + b + ')';
  }

  // =============================================
  // FEATURE 8: SECTION ANIMATIONS (C.sectionAnim)
  // =============================================
  function initSectionAnim() {
    if (!C.sectionAnim) return;
    var headings = document.querySelectorAll('h2, h3');
    var sectionObserver = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting && !entry.target.dataset.animated) {
          entry.target.dataset.animated = '1';
          entry.target.classList.add('pl-section-bounce');
          sectionObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.3, rootMargin: '0px 0px -30px 0px' });
    headings.forEach(function(h) {
      if (/^#?\d/.test(h.textContent.trim())) {
        h.classList.add('pl-section-heading');
        sectionObserver.observe(h);
      }
    });
  }

  // =============================================
  // FEATURE 9: READER STATS (C.readerStats)
  // =============================================
  var readerStatsShown = false;

  function checkReaderStats() {
    if (!C.readerStats || readerStatsShown) return;
    if (displayPct >= 0.55) {
      readerStatsShown = true;
      var pct = Math.floor(8 + Math.random() * 7);
      var el = document.createElement('div');
      el.className = 'pl-reader-stat';
      el.innerHTML = '\u2728 Only <strong>' + pct + '%</strong> of visitors read this far. You\'re one of the curious ones!';
      document.body.appendChild(el);
      setTimeout(function(){ el.classList.add('show'); }, 100);
      setTimeout(function(){
        el.classList.remove('show');
        setTimeout(function(){ el.remove(); }, 500);
      }, 5000);
    }
  }

  // =============================================
  // FEATURE 10: DANCER EVOLUTION (C.dancerEvolve)
  // =============================================
  function updateDancerEvolution() {
    if (!C.dancerEvolve || !C.dancer || !dancer) return;
    var extras = '';
    if (displayPct >= 0.35) {
      var bow = document.getElementById('plDancerBow');
      if (bow && bow.style.display === 'none') { bow.style.display = 'block'; addSparkle(); addSparkle(); }
      extras += ' pl-has-bow';
    }
    if (displayPct >= 0.65) {
      var wand = document.getElementById('plDancerWand');
      if (wand && wand.style.display === 'none') { wand.style.display = 'block'; addSparkle(); addSparkle(); addSparkle(); }
      extras += ' pl-has-wand';
    }
    if (displayPct >= 0.92) {
      var crown = document.getElementById('plDancerCrown');
      if (crown && crown.style.display === 'none') { crown.style.display = 'block'; addSparkle(); addSparkle(); addSparkle(); addSparkle(); showSpeech('milestone100'); }
      extras += ' pl-has-crown';
    }
    dancer.dataset.extras = extras;
  }

  // =============================================
  // MAIN SCROLL HANDLER
  // =============================================
  var pageLoadTime = Date.now();

  function onScroll() {
    var scrollTop = window.scrollY;
    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
    scrollPct = Math.min(Math.max(scrollTop / docHeight, 0), 1);
    scrollSpeed = Math.abs(scrollTop - lastScrollY);
    lastScrollY = scrollTop;
    isScrolling = true;

    updateHeart();
    if (C.dancer && scrollSpeed > 5) startDancing();
    updateCombo();
    updateCollectibles();
    updateBgMood();
    checkReaderStats();
    updateDancerEvolution();
    checkAchievements();

    clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(function(){
      isScrolling = false;
      if (C.dancer) stopDancing();
      if (C.heart && heartEl) heartEl.classList.remove('pl-heartbeat');
      if (Math.random() > 0.7 && scrollPct > 0.1 && scrollPct < 0.9 && C.dancer) {
        showSpeech('idle');
      }
    }, 300);
  }

  // =============================================
  // INIT
  // =============================================
  function init() {
    initHeart();
    initDancer();
    initCombo();
    initCollectibles();
    initAchievements();
    initShimmer();
    initSectionAnim();
    window.addEventListener('scroll', onScroll, { passive: true });
    if (C.heart) streakRAF = requestAnimationFrame(updateStreak);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
