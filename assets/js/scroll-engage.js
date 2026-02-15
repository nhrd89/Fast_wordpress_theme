/**
 * PinLightning Scroll Engagement v3.0 — Video Character System
 * WebM VP9 with alpha transparency for crystal clear quality
 * Lazy loads clips on demand, hardware decoded, zero main thread
 */
(function(){
"use strict";
if(navigator.webdriver||document.visibilityState==="prerender")return;

var C = window.plEngageConfig || {};
var SC = window.PLScrollConfig || {};
if(!SC.baseUrl) return;

// Video elements
var wrap, speechEl, streakEl, heartEl, styleEl;
var videos = {};
var activeClip = null;

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
    var looping = (name==="idle"||name==="walk"||name==="dance");
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

// Inject DOM
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
    css.push(".pl-e-str{position:fixed;bottom:160px;right:56px;z-index:998;font-family:sans-serif;font-size:13px;font-weight:700;color:#ff4500;text-shadow:0 1px 4px rgba(255,69,0,.3);opacity:0;transition:opacity .3s;pointer-events:none}");
    css.push(".pl-e-str.show{opacity:1}");
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
    streakEl=document.createElement("div");streakEl.className="pl-e-str";document.body.appendChild(streakEl);
    // Preload idle immediately
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
var streakCount=0,streakTimer=null,lastCardY=0,lastSpeechTime=0,speechTimeout=null;
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
}

function startWalking(){
  if(state==="walking"||C.dancer===false)return;
  state="walking";switchTo("walk");
  if(Math.random()>0.7)showSpeech("walk");
}
function startDancing(){
  if(state==="dancing"||C.dancer===false)return;
  state="dancing";switchTo("dance");
  if(Math.random()>0.5)showSpeech("dance");
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
  state="milestone";oneShotThen("dance",function(){goIdle()});
  for(var i=0;i<4;i++){(function(j){setTimeout(addSparkle,j*100)})(i)}
}

// Sparkles
var sparkleE=["✨","💖","💗","⭐","🌟","💕","🎀","💫"],lastSpkT=0,nextSpkD=rnd(8e3,22e3);
function addSparkle(){
  if(C.dancer===false)return;
  var el=document.createElement("div");el.className="pl-e-sk";el.textContent=sparkleE[rnd(0,sparkleE.length-1)];
  el.style.right=rnd(10,60)+"px";el.style.bottom=rnd(70,160)+"px";el.style.fontSize=rnd(10,18)+"px";
  document.body.appendChild(el);setTimeout(function(){if(el.parentNode)el.parentNode.removeChild(el)},1300);
}
function checkSparkle(){
  if(C.dancer===false)return;
  var now=Date.now();if(now-lastSpkT>nextSpkD&&scrollPct>0.05){lastSpkT=now;nextSpkD=rnd(8e3,22e3);
  var roll=Math.random();if(roll<0.2){for(var i=0;i<5;i++)(function(j){setTimeout(addSparkle,j*80)})(i);showSpeech("validation")}
  else if(roll<0.45){addSparkle();addSparkle()}else{addSparkle()}}
}

// Speech
var MSG={
  welcomeMorning:["Good morning gorgeous! ☀️","Rise & shine! ✨"],
  welcomeAfternoon:["Hey beautiful! 💕","So glad you're here! 🌸"],
  welcomeEvening:["Evening vibes! 🌙","Perfect night to scroll! ✨"],
  welcomeNight:["Late night scrolling? Same! 💫","Can't sleep? Me neither! 🌙"],
  returning:["You came back! 💖","Missed you! 🎀","She's baaack! 💕"],
  curiosity:["Wait till you see what's next! 👀","The best one is coming... 👇"],
  validation:["Great taste! 💖","Amazing style sense! ✨","Slay queen! 💅"],
  lossAversion:["Don't stop now! 💪","So close! Keep going! 🔥"],
  streak:["You're on fire! 🔥🔥🔥","Unstoppable! 💪✨"],
  milestone:["Look at you go! 🎉","You're dedicated! 💖"],
  deep:["True reader! 📖✨","Top 5% of readers! 🏆"],
  complete:["YOU DID IT! 🎉🎊💖","100%!! AMAZING! 🏆✨🎀"],
  walk:["Love your scrolling style! 💃","Where are we going? 🎀"],
  dance:["Let's gooo! 🎶💃","We're vibing! ✨🎵"],
  peekaboo:["Peek-a-boo! 🙈","I see you! 👀💕"]
};
function showSpeech(ctx){
  if(!speechEl||C.dancer===false)return;var now=Date.now();if(now-lastSpeechTime<4e3)return;lastSpeechTime=now;
  var pool;if(ctx==="welcome"){pool=visitCount>1?MSG.returning:MSG["welcome"+getTC()]}else{pool=MSG[ctx]||MSG.validation}
  speechEl.textContent=pool[rnd(0,pool.length-1)];speechEl.classList.add("show");
  clearTimeout(speechTimeout);speechTimeout=setTimeout(function(){speechEl.classList.remove("show")},3500);
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

// Streak
function updateStreak(){
  if(C.dancer===false||!streakEl)return;
  streakCount++;if(streakCount>=3){streakEl.textContent="🔥 "+streakCount+" streak!";streakEl.classList.add("show");
  if(streakCount===5||streakCount===10){showSpeech("streak");for(var i=0;i<3;i++)(function(j){setTimeout(addSparkle,j*100)})(i)}}
  clearTimeout(streakTimer);streakTimer=setTimeout(function(){if(streakCount>=3&&scrollPct<0.9)showSpeech("lossAversion");streakCount=0;streakEl.classList.remove("show")},5e3);
}

// Scroll
function onScroll(){
  if(ticking)return;ticking=true;
  requestAnimationFrame(function(){ticking=false;
    var sT=window.pageYOffset,dH=document.documentElement.scrollHeight-window.innerHeight;if(dH<=0)return;
    scrollPct=Math.min(Math.max(sT/dH,0),1);speed=Math.abs(sT-lastY);lastY=sT;
    updateMood(scrollPct);updateHeart(scrollPct);checkSparkle();
    var cards=Math.floor(scrollPct*15);if(cards>lastCardY){lastCardY=cards;updateStreak()}
    if(scrollPct>=0.98&&!milestoneHit["100"]){milestoneHit["100"]=true;startVictory()}
    else if(state!=="victory"&&state!=="peekaboo"&&state!=="welcome"&&state!=="milestone"){
      if(speed>30){startDancing()}else if(speed>3){startWalking()}
    }
    [0.4,0.6,0.8].forEach(function(m){var key=(m*100)+"";if(scrollPct>=m&&!milestoneHit[key]){milestoneHit[key]=true;showSpeech(m===0.8?"deep":"milestone");playMilestone()}});
    clearTimeout(scrollTimer);scrollTimer=setTimeout(function(){if(state!=="victory"&&state!=="peekaboo"&&state!=="welcome")goIdle()},400);
  });
}

// Init
function init(){
  injectDOM();
  if(C.heart!==false)updateHeart(0);
  if(C.dancer!==false){switchTo("idle");setTimeout(function(){startWelcome()},1500)}
  window.addEventListener("scroll",onScroll,{passive:true});
}

function rnd(a,b){return Math.floor(Math.random()*(b-a+1))+a}

// Start when DOM ready
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init)}else{init()}
})();
