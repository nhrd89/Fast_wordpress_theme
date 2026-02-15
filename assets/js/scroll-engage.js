/**
 * PinLightning Scroll Engagement v5.1 — Psychology-Driven Video Character + Interaction Tracking
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
    css.push(".pl-v-wrap{position:fixed;bottom:70px;right:14px;z-index:1000;pointer-events:auto;cursor:pointer;width:44px;height:80px}");
    css.push(".pl-v-wrap video{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain;opacity:0;transition:opacity .15s}");
    css.push(".pl-v-wrap video.active{opacity:1}");
    css.push(".pl-e-sp{position:fixed;bottom:160px;right:10px;z-index:1001;background:#fff;border:2px solid #ff69b4;border-radius:16px 16px 4px 16px;padding:6px 10px;font-size:11px;color:#333;font-family:sans-serif;max-width:160px;box-shadow:0 3px 12px rgba(255,105,180,.2);opacity:0;transform:translateY(10px) scale(.9);transition:all .3s cubic-bezier(.34,1.56,.64,1);pointer-events:none;line-height:1.3}");
    css.push(".pl-e-sp.show{opacity:1;transform:translateY(0) scale(1)}");
    css.push(".pl-e-sk{position:fixed;pointer-events:none;z-index:999;font-size:14px;animation:plSF 1.2s ease-out forwards}");
    css.push("@keyframes plSF{0%{opacity:1;transform:translateY(0) scale(1)}100%{opacity:0;transform:translateY(-60px) scale(.3) rotate(180deg)}}");
    css.push(".pl-chat-wrap{position:fixed;bottom:100px;right:14px;width:260px;z-index:1002;display:none;flex-direction:column;align-items:flex-end;gap:6px;pointer-events:none}");
    css.push(".pl-chat-wrap.show{display:flex}");
    css.push(".pl-chat-scroll{display:flex;flex-direction:column;gap:6px;max-height:55vh;overflow-y:auto;overflow-x:hidden;width:100%;padding:4px 2px;scroll-behavior:smooth;scrollbar-width:none;-ms-overflow-style:none;pointer-events:auto}");
    css.push(".pl-chat-scroll::-webkit-scrollbar{display:none}");
    css.push(".pl-chat-msg{position:relative;max-width:90%;padding:9px 13px;font-size:13px;line-height:1.45;word-wrap:break-word;animation:plMsgFloat .3s ease;pointer-events:auto;background:#fff;border:2px solid #f9a8d4;border-radius:16px;color:#4a1942;box-shadow:0 2px 8px rgba(244,114,182,.1);flex-shrink:0}");
    css.push(".pl-chat-msg::after{content:'';position:absolute;bottom:-6px;width:9px;height:9px;background:#fff;border-bottom:2px solid #f9a8d4;border-right:2px solid #f9a8d4;transform:rotate(45deg)}");
    css.push("@keyframes plMsgFloat{from{opacity:0;transform:translateY(8px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}");
    css.push(".pl-chat-msg.ai{align-self:flex-start;border-radius:16px 16px 16px 4px}");
    css.push(".pl-chat-msg.ai::after{left:12px;border-right:2px solid #f9a8d4;border-bottom:2px solid #f9a8d4;border-left:none;border-top:none}");
    css.push(".pl-chat-msg.user{align-self:flex-end;border-radius:16px 16px 4px 16px;background:#fff5f7}");
    css.push(".pl-chat-msg.user::after{right:12px;left:auto;border-left:2px solid #f9a8d4;border-bottom:2px solid #f9a8d4;border-right:none;border-top:none}");
    css.push(".pl-chat-msg.typing{align-self:flex-start;border-radius:16px 16px 16px 4px;background:#fff;border:2px solid #f9a8d4}");
    css.push(".pl-chat-msg.typing::after{left:12px;border-right:2px solid #f9a8d4;border-bottom:2px solid #f9a8d4;border-left:none;border-top:none}");
    css.push(".pl-chat-dots{display:inline-flex;gap:4px;padding:2px 0}");
    css.push(".pl-chat-dots span{width:6px;height:6px;border-radius:50%;background:#f472b6;animation:plDotBounce .5s infinite alternate}");
    css.push(".pl-chat-dots span:nth-child(2){animation-delay:.15s}");
    css.push(".pl-chat-dots span:nth-child(3){animation-delay:.3s}");
    css.push("@keyframes plDotBounce{from{opacity:.3;transform:translateY(0)}to{opacity:1;transform:translateY(-3px)}}");
    css.push(".pl-chat-bar{display:flex;gap:6px;align-items:center;width:100%;pointer-events:auto;margin-top:4px}");
    css.push(".pl-chat-input{flex:1;border:2px solid #f9a8d4;border-radius:18px;padding:7px 12px;font-size:12.5px;outline:none;font-family:-apple-system,BlinkMacSystemFont,sans-serif;resize:none;max-height:44px;overflow-y:auto;background:#fff;color:#4a1942;box-shadow:0 2px 8px rgba(244,114,182,.1)}");
    css.push(".pl-chat-input:focus{border-color:#ec4899;box-shadow:0 0 0 2px rgba(236,72,153,.12)}");
    css.push(".pl-chat-input::placeholder{color:#e8a0bf}");
    css.push(".pl-chat-send{background:linear-gradient(135deg,#f472b6,#ec4899);color:#fff;border:none;border-radius:50%;width:30px;height:30px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s;box-shadow:0 2px 6px rgba(236,72,153,.2)}");
    css.push(".pl-chat-send:hover{background:linear-gradient(135deg,#ec4899,#db2777);transform:scale(1.05)}");
    css.push(".pl-chat-send:disabled{background:#fce7f3;color:#f9a8d4;cursor:not-allowed;box-shadow:none;transform:none}");
    css.push(".pl-chat-x{background:none;border:none;color:#f472b6;font-size:16px;cursor:pointer;padding:0 4px;line-height:1;opacity:.7;transition:opacity .2s;flex-shrink:0}");
    css.push(".pl-chat-x:hover{opacity:1}");
    css.push(".pl-chat-ended{align-self:center;font-size:11px;color:#9d174d;padding:4px 10px;background:rgba(255,245,248,.9);border-radius:10px;border:1px solid #f9a8d4}");
    css.push("@media(max-width:480px){.pl-chat-wrap{right:8px;left:8px;width:auto;bottom:90px}}");
  }
  if(C.heart!==false){
    css.push(".pl-e-heart{position:fixed;bottom:20px;right:14px;z-index:1000;pointer-events:auto;cursor:pointer;width:34px;height:34px}");
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

  // Chat — floating speech bubbles
  var chatHtml = '<div class="pl-chat-wrap" id="plChatWrap">'
    + '<div class="pl-chat-scroll" id="plChatBody"></div>'
    + '<div class="pl-chat-bar">'
    + '<button class="pl-chat-x" id="plChatClose">\u2715</button>'
    + '<input class="pl-chat-input" id="plChatInput" placeholder="Type a message..." maxlength="500" />'
    + '<button class="pl-chat-send" id="plChatSend" disabled>'
    + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>'
    + '</button>'
    + '</div>'
    + '</div>';
  document.body.insertAdjacentHTML('beforeend', chatHtml);

  chatPanel = document.getElementById('plChatWrap');
  chatBody = document.getElementById('plChatBody');
  chatInput = document.getElementById('plChatInput');
}

// ============================
// STATE MACHINE
// ============================
var state="idle",lastY=0,speed=0,scrollPct=0,scrollTimer=null;
var lastSpeechTime=0,speechTimeout=null;
var milestoneHit={},ticking=false,peekabooTimer=null;
var visitCount=1;
try{visitCount=parseInt(localStorage.getItem("pl_v")||"0")+1;localStorage.setItem("pl_v",visitCount)}catch(e){}

// ============================
// INTERACTION TRACKING v5.1
// ============================
var charTaps = [];           // timestamps of character taps
var heartTaps = [];          // timestamps of heart taps
var charTapCount = 0;
var heartTapCount = 0;
var rapidTapCount = 0;       // taps within 2s window
var lastTapTime = 0;
var firstCharTapMs = 0;
var firstHeartTapMs = 0;
var imageTaps = [];
var imageTapCount = 0;
var sessionStart = Date.now();

// === AI CHAT STATE ===
var chatOpen = false;
var chatSessionId = 0;
var chatVisitorId = '';
var chatMessages = [];
var chatLoading = false;
var chatEnded = false;
var chatMinimized = false;
var chatPanel = null;
var chatInput = null;
var chatBody = null;
var chatStartTime = 0;
var chatImageContext = null; // stores info about tapped image
var tappedImages = [];       // log of all images tapped this session


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

  // ── TAP INTERACTION MESSAGES ──
  charTapEarly: [
    "You tapped me! I like the attention 💕",
    "Hey! That tickles! 🤭✨",
    "Ooh you noticed me! Hi! 👋💖",
    "You're interactive! I love that about you 💫"
  ],
  charTapEscalated: [
    "Okay okay I see you! Can't stop tapping? 😆💕",
    "You really like me, don't you? 🥰✨",
    "We're definitely besties now 💖💖",
    "All this tapping... are you trying to tell me something? 🤭💫",
    "I'm blushing! Stop! ...okay don't stop 😳💕"
  ],
  heartTap: [
    "You tapped my heart! Now I'm yours 💖",
    "Feel that? Our hearts are in sync 💕✨",
    "A heart tap! You really care 🥹💖",
    "Every tap fills it with more love 💗✨"
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

// Chat-aware speech gate — suppress bubbles while chat panel is visible
function canShowSpeech() {
  return !chatOpen || chatMinimized;
}

// Depth-aware message selection — messages escalate in emotional intimacy
// as user scrolls deeper (Escalating Self-Disclosure Model, Schramm 2024)
function showSpeech(ctx) {
  if (!speechEl || C.dancer === false) return;
  if (!canShowSpeech()) return;
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
    // Don't process scroll tracking when chat input is focused
    if (document.activeElement && document.activeElement.classList.contains('pl-chat-input')) return;
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

// ============================
// AI CHAT FUNCTIONS
// ============================

// === CHAT: Open panel and start session ===
function openChat() {
  if (!window.__plChat || !window.__plChat.enabled) return;

  // Resume minimized chat — just reshow panel, don't create new session
  if (chatMinimized && chatSessionId) {
    chatMinimized = false;
    chatPanel.classList.add('show');
    if (window.innerWidth < 768) {
      if (wrap) wrap.style.display = 'none';
      if (heartEl) heartEl.style.display = 'none';
    }
    chatInput.focus();
    chatBody.scrollTop = chatBody.scrollHeight;
    // If resumed via image tap, send the image context as a special message
    if (chatImageContext) {
      var imgNote = '[Visitor just tapped image ' + chatImageContext.position + ': "' + (chatImageContext.alt || chatImageContext.caption || chatImageContext.section || 'an image') + '" at ' + chatImageContext.depth + '% depth]';
      fetch(window.__plChat.endpoint + '/message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          sid: chatSessionId,
          msg: imgNote,
          sd: Math.round(scrollPct * 100),
          isImageTap: true
        })
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.msg) addMessage('ai', data.msg);
      });
      chatImageContext = null;
    }
    return;
  }
  if (chatOpen) return;

  chatOpen = true;
  chatStartTime = Date.now();
  chatPanel.classList.add('show');

  // On mobile, hide character + heart (bottom sheet overlay)
  if (window.innerWidth < 768) {
    if (wrap) wrap.style.display = 'none';
    if (heartEl) heartEl.style.display = 'none';
  }
  if (speechEl) speechEl.classList.remove('show');
  clearTimeout(speechTimeout);

  // Show typing indicator
  addTypingIndicator();

  // Get visitor timezone info
  var tz = '';
  try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch(e) {}

  // Get visitor ID from localStorage
  var vn = 1;
  try {
    chatVisitorId = localStorage.getItem('pl_chat_vid') || '';
    vn = parseInt(localStorage.getItem('pl_v') || '1');
  } catch(e) {}

  // Start session via API
  var payload = {
    vid: chatVisitorId,
    pid: window.__plChat.pid,
    pt: window.__plChat.pt,
    pc: window.__plChat.pc || '',
    dev: window.innerWidth < 768 ? 'mobile' : (window.innerWidth < 1024 ? 'tablet' : 'desktop'),
    ref: document.referrer || '',
    tz: tz,
    lh: new Date().getHours(),
    vn: vn,
    sd: Math.round(scrollPct * 100),
    ss: 0,
    sp: '',
    ps: '',
    ct: charTapCount || 0,
    ht: heartTapCount || 0,
    img: chatImageContext ? chatImageContext.src : '',
    imgAlt: chatImageContext ? chatImageContext.alt : '',
    imgCaption: chatImageContext ? (chatImageContext.caption || chatImageContext.title || '') : '',
    imgSection: chatImageContext ? chatImageContext.section : '',
    imgPos: chatImageContext ? chatImageContext.position : '',
    imgDepth: chatImageContext ? chatImageContext.depth : 0,
    ti: JSON.stringify(tappedImages.slice(-10))
  };

  fetch(window.__plChat.endpoint + '/start', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    removeTypingIndicator();
    if (data.error) {
      addMessage('ai', 'Oops! I\'m taking a quick nap. Try again later!');
      return;
    }
    chatSessionId = data.sid;
    chatVisitorId = data.vid;
    try { localStorage.setItem('pl_chat_vid', data.vid); } catch(e) {}
    addMessage('ai', data.msg);
    chatImageContext = null; // clear after use
    chatInput.focus();
    document.getElementById('plChatSend').disabled = false;
  })
  .catch(function() {
    removeTypingIndicator();
    addMessage('ai', 'Hmm, something went wrong. Try tapping me again!');
  });
}

// === CHAT: Close panel (minimize — session stays alive) ===
function closeChat() {
  if (!chatOpen) return;
  chatMinimized = true;
  chatPanel.classList.remove('show');
  // Restore character + heart visibility
  if (wrap) wrap.style.display = '';
  if (heartEl) heartEl.style.display = '';
}

// === CHAT: Send message ===
function sendMessage() {
  if (chatLoading || chatEnded) return;
  var msg = chatInput.value.trim();
  if (!msg) return;

  chatInput.value = '';
  addMessage('user', msg);
  chatLoading = true;
  document.getElementById('plChatSend').disabled = true;
  addTypingIndicator();

  fetch(window.__plChat.endpoint + '/message', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      sid: chatSessionId,
      msg: msg,
      sd: Math.round(scrollPct * 100)
    })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    removeTypingIndicator();
    chatLoading = false;

    if (data.error) {
      addMessage('ai', 'Oops, my brain glitched! Try again?');
      document.getElementById('plChatSend').disabled = false;
      return;
    }

    addMessage('ai', data.msg);
    document.getElementById('plChatSend').disabled = false;
    chatInput.focus();

    if (data.ended) {
      chatEnded = true;
      chatInput.disabled = true;
      document.getElementById('plChatSend').disabled = true;
      chatBody.insertAdjacentHTML('beforeend',
        '<div class="pl-chat-ended">Come back anytime! \uD83D\uDC95</div>');
    }
  })
  .catch(function() {
    removeTypingIndicator();
    chatLoading = false;
    addMessage('ai', 'Connection lost! Try again?');
    document.getElementById('plChatSend').disabled = false;
  });
}

// === CHAT: Add message bubble ===
function addMessage(role, text) {
  var cls = role === 'ai' ? 'ai' : 'user';
  var div = document.createElement('div');
  div.className = 'pl-chat-msg ' + cls;
  div.textContent = text;
  chatBody.appendChild(div);
  requestAnimationFrame(function() {
    chatBody.scrollTop = chatBody.scrollHeight;
  });
  chatMessages.push({ role: role, text: text });
}

// === CHAT: Typing indicator ===
function addTypingIndicator() {
  var div = document.createElement('div');
  div.className = 'pl-chat-msg typing';
  div.id = 'plChatTyping';
  div.innerHTML = '<div class="pl-chat-dots"><span></span><span></span><span></span></div>';
  chatBody.appendChild(div);
  requestAnimationFrame(function() {
    chatBody.scrollTop = chatBody.scrollHeight;
  });
}

function removeTypingIndicator() {
  var el = document.getElementById('plChatTyping');
  if (el) el.parentNode.removeChild(el);
}

// Init
function init(){
  injectDOM();
  if(C.heart!==false)updateHeart(0);
  if(C.dancer!==false){switchTo("idle");setTimeout(function(){startWelcome()},1500);scheduleNextMicro()}
  window.addEventListener("scroll",onScroll,{passive:true});

  // ── Character tap interaction ──
  if(wrap && C.dancer!==false){
    var onCharTap = function(e){
      e.preventDefault();
      var now = Date.now();
      charTapCount++;
      charTaps.push(now);

      // Rapid tap detection (multiple taps within 2s)
      if(now - lastTapTime < 2000) { rapidTapCount++; } else { rapidTapCount = 1; }
      lastTapTime = now;

      // React with a random micro-interaction
      if(state!=="victory"){
        var tapMicros = ["shhh","peace","blowkiss","surprise","excited"];
        var pick = tapMicros[rnd(0, tapMicros.length-1)];
        state = "micro";
        oneShotThen(pick, function(){ goIdle(); });
      }

      // Escalating messages based on tap count
      if(charTapCount <= 4){
        showSpeech("charTapEarly");
      } else {
        showSpeech("charTapEscalated");
      }

      // Visual feedback — sparkle burst
      for(var i=0;i<3;i++) addSparkle();

      // Open chat on first character tap
      if ((!chatOpen || chatMinimized) && window.__plChat && window.__plChat.enabled) {
        if (window.innerWidth < 768 && window.__plChat && !window.__plChat.showOnMobile) {
          // Mobile chat disabled
        } else {
          setTimeout(openChat, 600); // slight delay to see the micro-interaction first
        }
      }

      // First tap timing
      if(!firstCharTapMs) firstCharTapMs = Date.now() - sessionStart;

      // Expose to tracker plugin v3.1
      window.__plt = window.__plt || {};
      window.__plt.ct = charTapCount;
      window.__plt.ht = heartTapCount;
      window.__plt.cft = firstCharTapMs;
      window.__plt.hft = firstHeartTapMs || 0;
      window.__plt.rm = rapidTapCount;
      window.__plt.tl = charTaps.slice(0, 50).map(function(t){
        return {t:t,d:Math.round(scrollPct*100),a:activeClip||"none",sb:speechEl&&speechEl.classList.contains("show")?1:0,tgt:"c"};
      });
      window.__plt.it = imageTapCount;
      window.__plt.itl = imageTaps.slice(0, 30);
      window.__plt.ift = imageTaps.length > 0 ? imageTaps[0].time : 0;
    };
    wrap.addEventListener("click", onCharTap);
    wrap.addEventListener("touchend", function(e){ e.preventDefault(); onCharTap(e); });
  }

  // ── Heart tap interaction ──
  if(heartEl && C.heart!==false){
    var onHeartTap = function(e){
      e.preventDefault();
      heartTapCount++;
      heartTaps.push(Date.now());

      // Pulse animation
      var svg = heartEl.querySelector("svg");
      if(svg){
        svg.style.transition = "transform .15s";
        svg.style.transform = "scale(1.4)";
        setTimeout(function(){ svg.style.transform = "scale(1)"; }, 150);
      }

      // Heart tap message
      showSpeech("heartTap");

      // Open chat on first heart tap
      if ((!chatOpen || chatMinimized) && window.__plChat && window.__plChat.enabled) {
        if (window.innerWidth < 768 && window.__plChat && !window.__plChat.showOnMobile) {
          // Mobile chat disabled
        } else {
          setTimeout(openChat, 400);
        }
      }

      // First tap timing
      if(!firstHeartTapMs) firstHeartTapMs = Date.now() - sessionStart;

      // Expose to tracker plugin v3.1
      window.__plt = window.__plt || {};
      window.__plt.ct = charTapCount;
      window.__plt.ht = heartTapCount;
      window.__plt.cft = firstCharTapMs || 0;
      window.__plt.hft = firstHeartTapMs;
      window.__plt.rm = rapidTapCount;
      window.__plt.tl = heartTaps.slice(0, 50).map(function(t){
        return {t:t,d:Math.round(scrollPct*100),a:activeClip||"none",sb:speechEl&&speechEl.classList.contains("show")?1:0,tgt:"h"};
      });
      window.__plt.it = imageTapCount;
      window.__plt.itl = imageTaps.slice(0, 30);
      window.__plt.ift = imageTaps.length > 0 ? imageTaps[0].time : 0;
    };
    heartEl.addEventListener("click", onHeartTap);
    heartEl.addEventListener("touchend", function(e){ e.preventDefault(); onHeartTap(e); });
  }

  // === CHAT EVENT LISTENERS ===
  document.getElementById('plChatClose').addEventListener('click', closeChat);

  document.getElementById('plChatSend').addEventListener('click', sendMessage);

  document.getElementById('plChatInput').addEventListener('keydown', function(e) {
    e.stopPropagation();
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  // Enable send button when input has text
  document.getElementById('plChatInput').addEventListener('input', function() {
    document.getElementById('plChatSend').disabled = !this.value.trim() || chatLoading || chatEnded;
  });

  // Prevent page scroll while interacting with chat
  document.getElementById('plChatInput').addEventListener('focus', function() {
    document.body.style.overflow = '';
  });
  // Prevent chat scroll from bubbling to page
  document.getElementById('plChatBody').addEventListener('scroll', function(e) {
    e.stopPropagation();
  });
  // Prevent touch events on chat from scrolling the page
  document.getElementById('plChatWrap').addEventListener('touchmove', function(e) {
    e.stopPropagation();
  });

  // === IMAGE TAP → OPEN CHAT WITH IMAGE CONTEXT ===
  var postImages = document.querySelectorAll('.entry-content img, .post-content img, article img, .wp-block-image img');
  postImages.forEach(function(img, index) {
    // Skip tiny images (icons, spacers) and the character image
    if (img.width < 100 || img.height < 100) return;
    if (img.closest('.pl-v-wrap')) return;

    // Add visual hint that images are tappable when chat is enabled
    if (window.__plChat && window.__plChat.enabled) {
      img.style.cursor = 'pointer';
      img.style.transition = 'box-shadow .2s ease';
      img.addEventListener('mouseenter', function() {
        this.style.boxShadow = '0 0 0 3px #f9a8d4';
      });
      img.addEventListener('mouseleave', function() {
        this.style.boxShadow = '';
      });
    }

    img.addEventListener('click', function(e) {
      if (!window.__plChat || !window.__plChat.enabled) return;
      // Mobile check
      if (window.innerWidth < 768 && window.__plChat && !window.__plChat.showOnMobile) return;

      // Get image context
      var imgSrc = this.src || '';
      var imgAlt = this.alt || '';
      var imgTitle = this.title || '';

      // Get nearby heading or caption
      var caption = '';
      var figcaption = this.closest('figure') ? this.closest('figure').querySelector('figcaption') : null;
      if (figcaption) caption = figcaption.textContent.trim();

      // Get the closest heading above this image
      var nearestHeading = '';
      var el = this.parentElement;
      while (el && el !== document.body) {
        var prev = el.previousElementSibling;
        while (prev) {
          if (prev.matches && prev.matches('h1,h2,h3,h4,h5,h6')) {
            nearestHeading = prev.textContent.trim();
            break;
          }
          prev = prev.previousElementSibling;
        }
        if (nearestHeading) break;
        el = el.parentElement;
      }

      // Calculate image position in article
      var allImgs = document.querySelectorAll('.entry-content img, .post-content img, article img');
      var imgIndex = 0;
      var totalImgs = 0;
      allImgs.forEach(function(im) {
        if (im.width >= 100 && im.height >= 100 && !im.closest('.pl-v-wrap')) {
          totalImgs++;
          if (im === img) imgIndex = totalImgs;
        }
      });

      // Get depth of this image
      var rect = this.getBoundingClientRect();
      var imgScrollDepth = Math.round(((window.scrollY + rect.top) / document.documentElement.scrollHeight) * 100);

      // Track image tap for analytics
      imageTapCount++;
      imageTaps.push({
        time: Date.now() - sessionStart,
        index: imgIndex,
        total: totalImgs,
        depth: imgScrollDepth,
        alt: (imgAlt || '').substring(0, 80),
        section: (nearestHeading || '').substring(0, 80),
        caption: (caption || '').substring(0, 80),
        openedChat: !!(window.__plChat && window.__plChat.enabled)
      });

      // Expose to tracker plugin
      window.__plt = window.__plt || {};
      window.__plt.it = imageTapCount;
      window.__plt.itl = imageTaps.slice(0, 30);
      window.__plt.ift = imageTaps.length > 0 ? imageTaps[0].time : 0;

      chatImageContext = {
        src: imgSrc,
        alt: imgAlt,
        title: imgTitle,
        caption: caption,
        section: nearestHeading,
        position: imgIndex + ' of ' + totalImgs,
        depth: imgScrollDepth
      };

      tappedImages.push({
        time: Date.now(),
        index: imgIndex,
        section: nearestHeading,
        depth: imgScrollDepth
      });

      // Add a quick visual feedback — pink pulse on the image
      this.style.boxShadow = '0 0 0 4px #f472b6';
      var self = this;
      setTimeout(function() { self.style.boxShadow = ''; }, 400);

      // Open chat with image context
      setTimeout(function() {
        openChat();
      }, 300);
    });
  });

  // End chat session on page leave
  window.addEventListener('pagehide', function() {
    if ((chatOpen || chatMinimized) && chatSessionId) {
      var dur = Math.round((Date.now() - chatStartTime) / 1000);
      navigator.sendBeacon(
        window.__plChat.endpoint + '/end',
        new Blob([JSON.stringify({ sid: chatSessionId, dur: dur, sd: Math.round(scrollPct * 100) })],
          { type: 'application/json' })
      );
    }
  });

}

function rnd(a,b){return Math.floor(Math.random()*(b-a+1))+a}
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init)}else{init()}
})();
