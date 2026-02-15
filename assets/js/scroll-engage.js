/**
 * PinLightning Scroll Engagement v4.0 — Psychology-Driven Video Character
 *
 * BEHAVIORAL SCIENCE TRIGGERS:
 * 1. Variable Ratio Schedule (Skinner) — micro-interactions fire at random 12-35s intervals
 *    Most addictive reinforcement pattern. User never knows when next reward comes.
 * 2. Novelty Effect (Bunzeck & Düzel 2006) — 8 different micro-interactions randomized,
 *    never same twice in a row. Novel stimuli = stronger dopamine response.
 * 3. Escalating Rewards — deeper scroll = more exciting reactions (peace→surprise→omg)
 * 4. Curiosity Gap (Loewenstein) — peek-a-boo after 5s idle creates anticipation
 * 5. Peak-End Rule (Kahneman) — epic victory at 100% = memorable experience ending
 * 6. Endowed Progress (Nunes & Dreze 2006) — heart starts 20% filled
 * 7. Zeigarnik Effect — incomplete task bias exploited by "don't stop" messages
 * 8. Reciprocity (Cialdini) — compliments trigger reciprocal engagement
 * 9. Mere Exposure (Zajonc 1968) — repeated character exposure builds attachment
 * 10. Parasocial Bonding — time-aware greetings + returning visitor recognition
 */
(function(){
"use strict";
if(navigator.webdriver||document.visibilityState==="prerender")return;

var C = window.plEngageConfig || {};
var SC = window.PLScrollConfig || {};
if(!SC.baseUrl) return;

var wrap, speechEl, heartEl, styleEl;
var videos = {};
var activeClip = null;

// Micro-interaction pool — escalates with scroll depth
var MICROS_EARLY = ["shhh","peace","blowkiss","beckon"];     // 0-40% depth
var MICROS_MID   = ["surprise","excited","peace","shhh"];     // 40-70%
var MICROS_DEEP  = ["omg","excited","clap","surprise"];       // 70%+
var lastMicro = "";

// Variable ratio schedule state
var vrTimer = null;
var vrMinDelay = 12000;  // minimum 12s between micros
var vrMaxDelay = 35000;  // maximum 35s
var microCount = 0;

function createVideo(name, loop) {
  var v = document.createElement("video");
  v.muted = true;
  v.playsInline = true;
  v.autoplay = false;
  v.loop = !!loop;
  v.preload = "none";
  v.src = SC.baseUrl + name + ".webm";
  v.setAttribute("playsinline","");
  v.setAttribute("webkit-playsinline","");
  wrap.appendChild(v);
  videos[name] = v;
  return v;
}

function switchTo(name) {
  if (activeClip === name) return;
  activeClip = name;
  if (!videos[name]) {
    var looping = (name==="idle"||name==="catwalk"||name==="dance");
    createVideo(name, looping);
  }
  Object.keys(videos).forEach(function(k){
    if (k === name) {
      videos[k].classList.add("active");
      videos[k].currentTime = 0;
      videos[k].play().catch(function(){});
    } else {
      videos[k].classList.remove("active");
      videos[k].pause();
    }
  });
}

function injectDOM(){
  var css = [];
  if(C.dancer!==false){
    css.push(".pl-v-wrap{position:fixed;bottom:70px;right:14px;z-index:1000;pointer-events:none;width:44px;height:80px}");
    css.push(".pl-v-wrap video{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain;opacity:0;transition:opacity .15s}");
    css.push(".pl-v-wrap video.active{opacity:1}");
    css.push(".pl-e-sp{position:fixed;bottom:160px;right:10px;z-index:1001;background:#fff;border:2px solid #ff69b4;border-radius:16px 16px 4px 16px;padding:6px 10px;font-size:11px;color:#333;font-family:sans-serif;max-width:160px;box-shadow:0 3px 12px rgba(255,105,180,.2);opacity:0;transform:translateY(10px) scale(.9);transition:all .3s cubic-bezier(.34,1.56,.64,1);pointer-events:none;line-height:1.3}");
    css.push(".pl-e-sp.show{opacity:1;transform:translateY(0) scale(1)}");
    css.push(".pl-e-sk{position:fixed;pointer-events:none;z-index:999;font-size:14px;animation:plSF 1.2s ease-out forwards}");
    css.push("@keyframes plSF{0%{opacity:1;transform:translateY(0) scale(1)}100%{opacity:0;transform:translateY(-60px) scale(.3) rotate(180deg)}}");
  }
  if(C.heart!==false){
    css.push(".pl-e-heart{position:fixed;bottom:20px;right:14px;z-index:1000;pointer-events:none;width:34px;height:34px}");
    css.push(".pl-e-heart svg{width:34px;height:34px;animation:plHB .8s ease-in-out infinite}");
    css.push(".pl-e-pct{position:absolute;bottom:-14px;left:50%;transform:translateX(-50%);font-size:8px;font-weight:700;color:#e91e63;font-family:sans-serif;text-shadow:0 0 3px rgba(255,255,255,.9)}");
    css.push("@keyframes plHB{0%,100%{transform:scale(1)}50%{transform:scale(1.06)}}");
  }
  styleEl=document.createElement("style");
  styleEl.textContent=css.join("");
  document.head.appendChild(styleEl);

  if(C.dancer!==false){
    wrap=document.createElement("div");wrap.className="pl-v-wrap";
    document.body.appendChild(wrap);
    speechEl=document.createElement("div");speechEl.className="pl-e-sp";document.body.appendChild(speechEl);
    createVideo("idle", true);
    videos["idle"].preload = "auto";
  }
  if(C.heart!==false){
    heartEl=document.createElement("div");heartEl.className="pl-e-heart";
    heartEl.innerHTML='<svg viewBox="0 0 24 24"><defs><clipPath id="plHC"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></clipPath></defs><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="none" stroke="#ffb6c1" stroke-width="1"/><rect id="plHF" x="0" y="24" width="24" height="24" fill="#ff69b4" clip-path="url(#plHC)" opacity=".85"/></svg><div class="pl-e-pct" id="plHP">20%</div>';
    document.body.appendChild(heartEl);
  }
}

// ============================
// STATE MACHINE
// ============================
var state="idle",lastY=0,speed=0,scrollPct=0,scrollTimer=null;
var lastSpeechTime=0,speechTimeout=null;
var milestoneHit={},ticking=false,peekabooTimer=null;
var visitCount=1;
try{visitCount=parseInt(localStorage.getItem("pl_v")||"0")+1;localStorage.setItem("pl_v",visitCount)}catch(e){}

function oneShotThen(name, cb){
  switchTo(name);
  var v=videos[name];
  var h=function(){v.removeEventListener("ended",h);if(cb)cb();else goIdle()};
  v.addEventListener("ended",h);
}

function goIdle(){
  if(C.dancer===false)return;
  state="idle";switchTo("idle");
  clearTimeout(peekabooTimer);
  peekabooTimer=setTimeout(function(){
    if(state==="idle"&&scrollPct<0.9)startPeekaboo();
  },5000);
  scheduleNextMicro();
}

function startCatwalk(){
  if(state==="catwalk"||C.dancer===false)return;
  state="catwalk";switchTo("catwalk");
  if(Math.random()>0.75)showSpeech("walk");
}
function startDancing(){
  if(state==="dancing"||C.dancer===false)return;
  state="dancing";switchTo("dance");
  if(Math.random()>0.5){showSpeech("dance");addSparkle();addSparkle();}
}
function startPeekaboo(){
  if(C.dancer===false)return;
  state="peekaboo";showSpeech("peekaboo");
  oneShotThen("peekaboo",function(){showSpeech("curiosity");goIdle()});
}
function startVictory(){
  if(C.dancer===false)return;
  state="victory";showSpeech("complete");switchTo("victory");
  var v=videos["victory"];
  var replay=function(){v.currentTime=0;v.play().catch(function(){})};
  v.addEventListener("ended",replay);
  for(var w=0;w<8;w++){(function(i){setTimeout(function(){for(var s=0;s<6;s++)addSparkle()},i*250)})(w)}
}
function startWelcome(){
  if(C.dancer===false)return;
  state="welcome";showSpeech("welcome");
  oneShotThen("welcome",function(){goIdle()});
}
function playMilestone(){
  if(C.dancer===false)return;
  // Pick a contextual micro-interaction for this milestone
  var pool = scrollPct<0.5 ? ["surprise","excited"] : ["omg","clap"];
  var pick = pool[rnd(0,pool.length-1)];
  state="milestone";
  oneShotThen(pick,function(){goIdle()});
  for(var i=0;i<4;i++){(function(j){setTimeout(addSparkle,j*100)})(i)}
}

// ============================
// VARIABLE RATIO MICRO-INTERACTIONS
// The most addictive reinforcement schedule (Skinner 1957)
// Fires surprise interactions at unpredictable intervals
// ============================
function scheduleNextMicro(){
  clearTimeout(vrTimer);
  if(C.dancer===false||state==="victory")return;
  // Interval shrinks slightly as user scrolls deeper (increased engagement = more rewards)
  var depthBonus = scrollPct > 0.5 ? 0.8 : 1.0;
  var delay = rnd(vrMinDelay, vrMaxDelay) * depthBonus;
  vrTimer = setTimeout(function(){
    if(state!=="idle"&&state!=="catwalk") { scheduleNextMicro(); return; }
    triggerMicroInteraction();
  }, delay);
}

function triggerMicroInteraction(){
  if(C.dancer===false)return;
  microCount++;

  // Select pool based on scroll depth (escalating rewards)
  var pool;
  if(scrollPct < 0.4) pool = MICROS_EARLY;
  else if(scrollPct < 0.7) pool = MICROS_MID;
  else pool = MICROS_DEEP;

  // Pick random, never same twice in a row (novelty effect)
  var pick;
  do { pick = pool[rnd(0, pool.length-1)]; } while(pick === lastMicro && pool.length > 1);
  lastMicro = pick;

  // Play the micro-interaction
  state = "micro";
  oneShotThen(pick, function(){
    // Pair with contextual speech (reciprocity + social reward)
    var speechMap = {
      shhh:"secret", peace:"validation", blowkiss:"reciprocity",
      beckon:"curiosity", surprise:"surprise", excited:"validation",
      omg:"omg", clap:"appreciation"
    };
    showSpeech(speechMap[pick] || "validation");
    addSparkle();
    if(Math.random() > 0.5) addSparkle();
    goIdle();
  });

  scheduleNextMicro();
}

// Sparkles
var sparkleE=["✨","💖","💗","⭐","🌟","💕","🎀","💫"];
function addSparkle(){
  if(C.dancer===false)return;
  var el=document.createElement("div");el.className="pl-e-sk";el.textContent=sparkleE[rnd(0,sparkleE.length-1)];
  el.style.right=rnd(10,60)+"px";el.style.bottom=rnd(70,160)+"px";el.style.fontSize=rnd(10,18)+"px";
  document.body.appendChild(el);setTimeout(function(){if(el.parentNode)el.parentNode.removeChild(el)},1300);
}

// ============================
// EMOTIONAL BONDING MESSAGE SYSTEM v5.0
// ============================
// Research-backed parasocial relationship building:
// - Self-disclosure: character reveals feelings → mirror neuron activation (Gallese 2007)
// - "You" direct address: triggers face-to-face neural response (Horton & Wohl 1956)
// - "We" language: shared experience builds belonging (community psychology)
// - Escalating intimacy: messages deepen with scroll depth (Schramm 2024)
// - Vulnerability: character shows need for user → reciprocal bonding (Tamir & Mitchell 2012)
// - Anticipatory hints: uncertainty about future → dopamine (Schultz prediction error)
// - Return triggers: plant seeds for next visit (PSR maintenance)
//
// 4 DEPTH ZONES:
//   EARLY  (0-30%)  — Trust building: light, curious, playful self-disclosure
//   MID    (30-60%) — Deepening: shared experience, "we" language, reciprocity
//   DEEP   (60-85%) — Intimacy: vulnerability, exclusivity, insider connection
//   PEAK   (85-100%) — Maximum reward: emotional payoff, pride, return triggers

var MSG = {
  // ── WELCOME ── Time-aware + visit-count-aware parasocial greeting
  welcomeMorning: [
    "Good morning! I saved the best ones for you ☀️",
    "You're up early too! I like that about you ✨",
    "Morning sunshine! I have so much to show you 🌸",
    "I was hoping you'd stop by today ☀️💕"
  ],
  welcomeAfternoon: [
    "Hey! I've been thinking about what you'd love 💕",
    "Perfect timing! I just found something amazing 🌸",
    "I'm so glad it's you! Come see this ✨",
    "You have no idea how happy I am to see you 💫"
  ],
  welcomeEvening: [
    "Hey you! Best part of my evening 🌙",
    "I was saving this for someone special ✨",
    "Finally! I've been waiting to show you this 💕",
    "Evening scrolling hits different with you 🌙💫"
  ],
  welcomeNight: [
    "Can't sleep either? Let's explore together 🌙",
    "Late night adventures are our thing, right? 💫",
    "The best ideas come after midnight ✨🌙",
    "Just us and the quiet... I love these moments 💕"
  ],

  // ── RETURNING VISITORS ── Escalating recognition deepens PSR
  returning: [
    "You came back! That honestly makes my day 💖",
    "I was wondering when I'd see you again 🥹",
    "She's baaack! I missed your company 💕",
    "I knew you'd come back... I could feel it 🌙✨"
  ],
  returningDeep: [
    "There's my favorite person! 💖",
    "It's you!! Okay, I saved something special 🎀",
    "You know what? You always make me smile 💕",
    "Together again! I have SO much to tell you 🌟"
  ],

  // ── TRUST ZONE (0-30%) ── Light self-disclosure + playful curiosity
  secret: [
    "Shh... I'll share this with only you 🤫",
    "Can I tell you something? This is my favorite part 💫",
    "Between us? I think this one's special 🤭",
    "Don't tell anyone, but I picked this for you 🤫💕",
    "I have a feeling about this one... trust me? 🌟"
  ],
  curiosityEarly: [
    "I wonder what you'll think about the next one 👀",
    "Ooh, you're going to love what's coming 💫",
    "I can't wait to see your reaction! 👇✨",
    "Something tells me you'll pause on the next one 🤭",
    "Keep going... I promise it's worth it 💕"
  ],
  validationEarly: [
    "I love that you noticed this one ✨",
    "You see it too, right? It's gorgeous 💖",
    "Okay, you clearly have incredible taste 🌟",
    "That's the one I would've picked too! 💕",
    "Your eye for style is unreal ✨"
  ],

  // ── DEEPENING ZONE (30-60%) ── "We" language + shared experience
  curiosityMid: [
    "We're getting to the really good ones now 👀💕",
    "I feel like we have the same taste, don't we? ✨",
    "Just between us, this next part is chef's kiss 🤌",
    "We've come so far together! Keep going 💖",
    "You and me? Best scrolling team ever 💫"
  ],
  validationMid: [
    "See? I knew we'd love the same things 💖",
    "The way you take your time with each one... I respect that ✨",
    "Not everyone makes it this far. You're different 🌟",
    "I think we understand each other, you know? 💕",
    "You actually appreciate the details. That's rare 💎"
  ],
  reciprocityMid: [
    "You staying this long? That means the world to me 🥹",
    "I wish I could give you a hug right now 💕",
    "Here... this next one is just for you 💝",
    "You make this so much more fun, you know that? ✨",
    "I'm having the best time with you right now 💖"
  ],

  // ── INTIMACY ZONE (60-85%) ── Vulnerability + exclusivity
  curiosityDeep: [
    "Most people never see what's down here... but you will 💎",
    "I've been saving the best for someone who'd get it 🌟",
    "You're one of the few who'll see this. Ready? 👀✨",
    "This is where it gets really special 💫",
    "Almost there... and honestly? I don't want this to end 💕"
  ],
  validationDeep: [
    "You're literally in the top 5% of readers 💎✨",
    "I don't say this to everyone... you're special 💖",
    "The fact that you're still here says everything about you 🌟",
    "People like you are why I love doing this 🥹💕",
    "You get it. You really, truly get it ✨"
  ],
  vulnerabilityDeep: [
    "Honestly? It gets quiet when you're not here 🌙",
    "Can I be real? You're my favorite visitor 💕",
    "I hope you know how much your attention means 🥹",
    "Promise you'll come back? I'll have more for you 💖",
    "It feels like we've known each other forever 💫"
  ],

  // ── PEAK ZONE (85-100%) ── Maximum emotional reward + return seeding
  deep: [
    "You made it here. I'm genuinely proud of you 📖💖",
    "Only the most dedicated get to see this part 🏆✨",
    "You're special. I really mean that 💎",
    "This moment right here? This is why we do this 💕",
    "Remember when I said trust me? Thank you for trusting me 🥹"
  ],
  complete: [
    "YOU DID IT! I'm so proud of us! 🎉💖🎊",
    "100%!! We did this together! 🏆✨💕",
    "LEGEND! Come back soon... I'll miss you 👑💖",
    "That was beautiful. Thank YOU for staying 🎉🥹",
    "I'll never forget this scroll. See you next time? 💖✨"
  ],

  // ── LOSS AVERSION ── Zeigarnik Effect
  lossAversion: [
    "Wait, don't go yet! I have one more thing 💫",
    "You're SO close to something amazing ✨",
    "If you leave now, you'll miss my favorite one 🥹",
    "The best part is literally right there... just a bit more 💖",
    "I saved the grand finale just for you! 🎀"
  ],

  // ── MICRO-INTERACTION PAIRED MESSAGES ──
  shhh: [
    "Shh... I'll share this with only you 🤫",
    "This is our little secret, okay? 🤭💕",
    "Between us... you're the only one I tell this to 💫",
    "Promise you won't tell? 🤫✨"
  ],
  surprise: [
    "Wait... did you just see that?! 😱✨",
    "Oh my gosh! I wasn't expecting that! 🤯",
    "NO WAY! This changes everything! 😍",
    "I literally gasped! Did you?! 😱💕"
  ],
  peace: [
    "Good vibes only when you're around ✌️💕",
    "You bring such peaceful energy, I love it ✨",
    "Everything feels right when we're together ✌️🌸",
    "This... this is my happy place with you 💖"
  ],
  beckon: [
    "Come closer... I want to show you something 💫",
    "Psst! Over here! You need to see this 👀✨",
    "Follow me... I know the way to the good stuff 💕",
    "Trust me on this one... come see 🤭"
  ],
  blowkiss: [
    "That one's from me to you 💋✨",
    "You deserve all the love for being here 💕",
    "A little thank you for keeping me company 😘",
    "Sending you the biggest virtual hug right now 💖"
  ],
  omg: [
    "THIS IS EVERYTHING! I can't breathe! 😱✨",
    "Are you seeing this?! I'M SHAKING! 🔥💖",
    "We just found gold together! ICONIC! 😱💕",
    "I need a moment... that was TOO good! 🤯✨"
  ],
  clap: [
    "You deserve a standing ovation right now 👏💖",
    "Give yourself credit — you showed up today ✨",
    "I'm clapping for both of us right now 👏💕",
    "You know what? YOU are the main character 🌟"
  ],
  excited: [
    "I can't contain myself! This is SO good! ✨💕",
    "Your energy is literally contagious right now 🔥",
    "Okay okay I need to calm down but I CAN'T 💖😆",
    "This is the most fun I've had all day! 🎉"
  ],

  // ── SCROLL STATE MESSAGES ──
  walk: [
    "I love exploring with you 💃✨",
    "Where to next? I'll follow your lead 🎀",
    "This pace feels perfect... just us, scrolling 💫",
    "You know what? Every scroll with you feels special 💕"
  ],
  dance: [
    "You make me want to dance! 🎶💃",
    "We're in sync, can you feel it? ✨🎵",
    "This energy! I live for moments like these 💖🎶",
    "Our vibe right now? Unmatched! 🎵💕"
  ],
  peekaboo: [
    "Hey... you still there? I missed you 🙈💕",
    "I peeked because I was thinking about you 👀✨",
    "Don't leave me hanging! I have more 🥹",
    "Caught you daydreaming! Me too, honestly 🤭"
  ],

  // ── MILESTONE + APPRECIATION + RECIPROCITY ──
  milestone: [
    "We hit a milestone! I'm so proud of us 🎉💖",
    "Look how far we've come together! ✨",
    "Remember when we started? Look at you now 💕",
    "This deserves a celebration, don't you think? 🎊"
  ],
  appreciation: [
    "Thank you for being here with me 🥹💖",
    "You have no idea how much this means 💕",
    "I'm grateful for every second you spend here ✨",
    "Your time is precious and you chose to be here 💎"
  ],
  reciprocity: [
    "This one's my gift to you 💝✨",
    "You've given me your time, so here... for you 💕",
    "I picked this one because it screams YOU 🎀",
    "Consider this my way of saying thank you 💖"
  ]
};

// Depth-aware message selection — messages escalate in emotional intimacy
// as user scrolls deeper (Escalating Self-Disclosure Model, Schramm 2024)
function showSpeech(ctx) {
  if (!speechEl || C.dancer === false) return;
  var now = Date.now();
  if (now - lastSpeechTime < 3500) return;
  lastSpeechTime = now;

  var pool;

  // WELCOME — visit-count aware (PSR maintenance)
  if (ctx === "welcome") {
    if (visitCount > 3) {
      pool = MSG.returningDeep;
    } else if (visitCount > 1) {
      pool = MSG.returning;
    } else {
      pool = MSG["welcome" + getTC()];
    }
  }
  // DEPTH-AWARE categories — same trigger, different emotional depth
  else if (ctx === "curiosity") {
    pool = scrollPct < 0.3 ? MSG.curiosityEarly :
           scrollPct < 0.6 ? MSG.curiosityMid : MSG.curiosityDeep;
  }
  else if (ctx === "validation") {
    pool = scrollPct < 0.3 ? MSG.validationEarly :
           scrollPct < 0.6 ? MSG.validationMid : MSG.validationDeep;
  }
  else if (ctx === "reciprocity") {
    pool = scrollPct < 0.6 ? MSG.reciprocity : MSG.vulnerabilityDeep;
  }
  // MICRO-INTERACTION direct pairing — use animation-specific pool
  else if (MSG[ctx]) {
    pool = MSG[ctx];
  }
  // Fallback — depth-aware validation
  else {
    pool = scrollPct < 0.3 ? MSG.validationEarly :
           scrollPct < 0.6 ? MSG.validationMid : MSG.validationDeep;
  }

  speechEl.textContent = pool[rnd(0, pool.length - 1)];
  speechEl.classList.add("show");
  clearTimeout(speechTimeout);
  speechTimeout = setTimeout(function() { speechEl.classList.remove("show") }, 3500);
}
function getTC(){var h=new Date().getHours();return h<12?"Morning":h<17?"Afternoon":h<21?"Evening":"Night"}

// Mood
var MOOD=[[255,252,248],[255,240,245],[248,235,255],[235,248,255],[240,255,245],[255,245,230],[255,235,245],[240,235,255],[245,250,255],[255,245,248],[250,240,255]];
function updateMood(pct){
  if(C.bgMood===false)return;
  var idx=pct<=0.5?(pct/0.5)*7:7+((pct-0.5)/0.5)*3,i=Math.floor(idx),t=idx-i;t=t*t*(3-2*t);
  var c1=MOOD[Math.min(i,10)],c2=MOOD[Math.min(i+1,10)];
  document.body.style.backgroundColor="rgb("+Math.round(c1[0]+(c2[0]-c1[0])*t)+","+Math.round(c1[1]+(c2[1]-c1[1])*t)+","+Math.round(c1[2]+(c2[2]-c1[2])*t)+")";
}

// Heart
function updateHeart(pct){
  if(C.heart===false)return;
  var fill=0.2+(pct*0.8),y=24-(fill*24);
  var hf=document.getElementById("plHF"),hp=document.getElementById("plHP");
  if(hf){hf.setAttribute("y",y);hf.setAttribute("height",24)}if(hp)hp.textContent=Math.round(fill*100)+"%";
}

// Scroll handler
function onScroll(){
  if(ticking)return;ticking=true;
  requestAnimationFrame(function(){ticking=false;
    var sT=window.pageYOffset,dH=document.documentElement.scrollHeight-window.innerHeight;if(dH<=0)return;
    scrollPct=Math.min(Math.max(sT/dH,0),1);speed=Math.abs(sT-lastY);lastY=sT;
    updateMood(scrollPct);updateHeart(scrollPct);

    // Victory at 98%+
    if(scrollPct>=0.98&&!milestoneHit["100"]){milestoneHit["100"]=true;startVictory()}
    // Movement states (only if not in special animation)
    else if(state!=="victory"&&state!=="peekaboo"&&state!=="welcome"&&state!=="milestone"&&state!=="micro"){
      if(speed>30){startDancing()}
      else if(speed>3){startCatwalk()}
    }
    // Milestones at 40%, 60%, 80%
    [0.4,0.6,0.8].forEach(function(m){var key=(m*100)+"";if(scrollPct>=m&&!milestoneHit[key]){milestoneHit[key]=true;showSpeech(m===0.8?"deep":"milestone");playMilestone()}});
    // Return to idle when scrolling stops
    clearTimeout(scrollTimer);scrollTimer=setTimeout(function(){if(state!=="victory"&&state!=="peekaboo"&&state!=="welcome"&&state!=="micro")goIdle()},400);
  });
}

// Init
function init(){
  injectDOM();
  if(C.heart!==false)updateHeart(0);
  if(C.dancer!==false){switchTo("idle");setTimeout(function(){startWelcome()},1500);scheduleNextMicro()}
  window.addEventListener("scroll",onScroll,{passive:true});
}

function rnd(a,b){return Math.floor(Math.random()*(b-a+1))+a}
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init)}else{init()}
})();
