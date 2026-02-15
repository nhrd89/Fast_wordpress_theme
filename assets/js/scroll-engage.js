/**
 * PinLightning Scroll Engagement v2.0 — Real Photo Sprite System
 * Reads plEngageConfig from Customizer + PLScrollConfig for sprite URL
 * Zero CWV: passive scroll + rAF, fixed-position DOM, defer loaded
 */
(function(){
"use strict";
if(navigator.webdriver||document.visibilityState==="prerender")return;

var C = window.plEngageConfig || {};
var SC = window.PLScrollConfig || {};
if(!SC.spriteUrl) return;

var CELL=57,TOTAL=45,DISP_H=42,SPR_H=100;
var SCALE=DISP_H/SPR_H,DC=Math.round(CELL*SCALE),BGS=Math.round(CELL*TOTAL*SCALE);

var SEQ={
walk:[41,42,43,44,33,34,35,36],
dance:[37,38,39,40,29,30,31,32,40,39,38,37],
welcome:[0,33,34,0,35,36,0,0],
peekaboo:[1,2,3,4,5,6,7,8,9,10],
victory:[11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32],
milestone:[37,38,39,40,41,42,0],
idle:[0,0,1,0,0,2,0,3,0,0,41,0,0,33,0,0,42,0,0,34,0,0,4,0,0]
};

var spriteReady=false,spriteImg=new Image();
spriteImg.onload=function(){spriteReady=true;init()};
spriteImg.onerror=function(){};
spriteImg.src=SC.spriteUrl;

var styleEl,charEl,spriteEl,heartEl,speechEl,streakEl;

function injectDOM(){
var css=[];
if(C.dancer!==false){
css.push(".pl-e-char{position:fixed;bottom:52px;right:12px;z-index:1000;pointer-events:none}");
css.push(".pl-e-spr{width:"+DC+"px;height:"+DISP_H+"px;background-repeat:no-repeat;background-size:"+BGS+"px "+DISP_H+"px;filter:drop-shadow(0 1px 3px rgba(0,0,0,.15));image-rendering:-webkit-optimize-contrast}");
css.push(".pl-e-sp{position:fixed;bottom:100px;right:8px;z-index:1001;background:#fff;border:2px solid #ff69b4;border-radius:16px 16px 4px 16px;padding:6px 10px;font-size:11px;color:#333;font-family:sans-serif;max-width:160px;box-shadow:0 3px 12px rgba(255,105,180,.2);opacity:0;transform:translateY(10px) scale(.9);transition:all .3s cubic-bezier(.34,1.56,.64,1);pointer-events:none;line-height:1.3}");
css.push(".pl-e-sp.show{opacity:1;transform:translateY(0) scale(1)}");
css.push(".pl-e-sk{position:fixed;pointer-events:none;z-index:999;font-size:14px;animation:plSF 1.2s ease-out forwards}");
css.push("@keyframes plSF{0%{opacity:1;transform:translateY(0) scale(1)}100%{opacity:0;transform:translateY(-60px) scale(.3) rotate(180deg)}}");
css.push(".pl-e-str{position:fixed;bottom:100px;right:50px;z-index:998;font-family:sans-serif;font-size:13px;font-weight:700;color:#ff4500;text-shadow:0 1px 4px rgba(255,69,0,.3);opacity:0;transition:opacity .3s;pointer-events:none}");
css.push(".pl-e-str.show{opacity:1}");
}
if(C.heart!==false){
css.push(".pl-e-heart{position:fixed;bottom:10px;right:12px;z-index:1000;pointer-events:none;width:34px;height:34px}");
css.push(".pl-e-heart svg{width:34px;height:34px;animation:plHB .8s ease-in-out infinite}");
css.push(".pl-e-pct{position:absolute;bottom:-14px;left:50%;transform:translateX(-50%);font-size:8px;font-weight:700;color:#e91e63;font-family:sans-serif;text-shadow:0 0 3px rgba(255,255,255,.9)}");
css.push("@keyframes plHB{0%,100%{transform:scale(1)}50%{transform:scale(1.06)}}");
}
styleEl=document.createElement("style");
styleEl.textContent=css.join("");
document.head.appendChild(styleEl);

if(C.dancer!==false){
charEl=document.createElement("div");charEl.className="pl-e-char";
spriteEl=document.createElement("div");spriteEl.className="pl-e-spr";
spriteEl.style.backgroundImage="url("+SC.spriteUrl+")";
charEl.appendChild(spriteEl);document.body.appendChild(charEl);
speechEl=document.createElement("div");speechEl.className="pl-e-sp";document.body.appendChild(speechEl);
streakEl=document.createElement("div");streakEl.className="pl-e-str";document.body.appendChild(streakEl);
}

if(C.heart!==false){
heartEl=document.createElement("div");heartEl.className="pl-e-heart";
heartEl.innerHTML='<svg viewBox="0 0 24 24"><defs><clipPath id="plHC"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></clipPath></defs><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="none" stroke="#ffb6c1" stroke-width="1"/><rect id="plHF" x="0" y="24" width="24" height="24" fill="#ff69b4" clip-path="url(#plHC)" opacity=".85"/></svg><div class="pl-e-pct" id="plHP">20%</div>';
document.body.appendChild(heartEl);
}
}

function setFrame(n){if(!spriteEl)return;spriteEl.style.backgroundPosition=(-n*DC)+"px bottom"}

var state="idle",lastY=0,speed=0,scrollPct=0,scrollTimer=null,activeTimer=null,seqIdx=0;
var streakCount=0,streakTimer=null,lastCardY=0,lastSpeechTime=0,speechTimeout=null;
var milestoneHit={},idleStartTime=0,ticking=false;
var visitCount=1;
try{visitCount=parseInt(localStorage.getItem("pl_v")||"0")+1;localStorage.setItem("pl_v",visitCount)}catch(e){}

function clearActive(){if(activeTimer){clearInterval(activeTimer);activeTimer=null}}

function startWalking(){
if(state==="walking"||C.dancer===false)return;clearActive();state="walking";seqIdx=0;setFrame(SEQ.walk[0]);
activeTimer=setInterval(function(){seqIdx=(seqIdx+1)%SEQ.walk.length;setFrame(SEQ.walk[seqIdx])},250);
if(Math.random()>0.7)showSpeech("walk");
}
function startDancing(){
if(state==="dancing"||C.dancer===false)return;clearActive();state="dancing";seqIdx=0;setFrame(SEQ.dance[0]);
activeTimer=setInterval(function(){seqIdx=(seqIdx+1)%SEQ.dance.length;setFrame(SEQ.dance[seqIdx]);if(Math.random()>0.6)addSparkle()},150);
if(Math.random()>0.5)showSpeech("dance");
}
function startIdle(){
if(C.dancer===false)return;
clearActive();state="idle";idleStartTime=Date.now();seqIdx=0;setFrame(0);
activeTimer=setInterval(function(){seqIdx=(seqIdx+1)%SEQ.idle.length;setFrame(SEQ.idle[seqIdx]);
var dur=(Date.now()-idleStartTime)/1000;if(dur>5&&dur<5.15&&scrollPct<0.9)startPeekaboo()},600);
}
function startPeekaboo(){
if(C.dancer===false)return;
clearActive();state="peekaboo";seqIdx=0;setFrame(SEQ.peekaboo[0]);
activeTimer=setInterval(function(){seqIdx++;if(seqIdx>=SEQ.peekaboo.length){showSpeech("curiosity");startIdle();return}setFrame(SEQ.peekaboo[seqIdx])},300);
showSpeech("peekaboo");
}
function startVictory(){
if(C.dancer===false)return;
clearActive();state="victory";seqIdx=0;setFrame(SEQ.victory[0]);showSpeech("complete");
activeTimer=setInterval(function(){seqIdx=(seqIdx+1)%SEQ.victory.length;setFrame(SEQ.victory[seqIdx]);addSparkle();if(Math.random()>0.5)addSparkle()},180);
for(var w=0;w<8;w++){(function(i){setTimeout(function(){for(var s=0;s<8;s++)addSparkle()},i*250)})(w)}
}
function startWelcome(){
if(C.dancer===false)return;
state="welcome";seqIdx=0;setFrame(SEQ.welcome[0]);
activeTimer=setInterval(function(){seqIdx++;if(seqIdx>=SEQ.welcome.length){startIdle();return}setFrame(SEQ.welcome[seqIdx])},350);
}
function playMilestone(){
if(C.dancer===false)return;
clearActive();state="milestone";seqIdx=0;setFrame(SEQ.milestone[0]);
activeTimer=setInterval(function(){seqIdx++;if(seqIdx>=SEQ.milestone.length){startIdle();return}setFrame(SEQ.milestone[seqIdx])},250);
for(var i=0;i<4;i++){(function(j){setTimeout(addSparkle,j*100)})(i)}
}

var sparkleE=["✨","💖","💗","⭐","🌟","💕","🎀","💫"],lastSpkT=0,nextSpkD=rnd(8e3,22e3);
function addSparkle(){
if(C.dancer===false)return;
var el=document.createElement("div");el.className="pl-e-sk";el.textContent=sparkleE[rnd(0,sparkleE.length-1)];
el.style.right=rnd(8,60)+"px";el.style.bottom=rnd(50,130)+"px";el.style.fontSize=rnd(10,18)+"px";
document.body.appendChild(el);setTimeout(function(){if(el.parentNode)el.parentNode.removeChild(el)},1300);
}
function checkSparkle(){
if(C.dancer===false)return;
var now=Date.now();if(now-lastSpkT>nextSpkD&&scrollPct>0.05){lastSpkT=now;nextSpkD=rnd(8e3,22e3);
var roll=Math.random();if(roll<0.2){for(var i=0;i<5;i++)(function(j){setTimeout(addSparkle,j*80)})(i);showSpeech("validation")}
else if(roll<0.45){addSparkle();addSparkle()}else{addSparkle()}}
}

var MSG={
welcomeMorning:["Good morning gorgeous! ☀️","Rise & shine, beautiful! ✨"],
welcomeAfternoon:["Hey beautiful! 💕","So glad you're here! 🌸"],
welcomeEvening:["Evening vibes! 🌙","Perfect night to scroll! ✨"],
welcomeNight:["Late night scrolling? Same! 💫","Can't sleep? Me neither! 🌙"],
returning:["You came back! 💖","Missed you! Welcome back! 🎀","She's baaack! 💕"],
reciprocity:["You look amazing today! 💖","Love your taste! ✨","You have incredible style! 🌟"],
curiosity:["Wait till you see what's next! 👀","The best one is coming... 👇"],
fomo:["Everyone's loving this one! 🔥","This is going viral! 💕"],
lossAversion:["Don't stop now! 💪","So close! Keep going! 🔥"],
validation:["Great taste! 💖","Amazing style sense! ✨","Slay queen! 💅"],
anticipation:["Something special coming... 🎁","Almost there! 🤫"],
streak:["You're on fire! 🔥🔥🔥","Unstoppable! 💪✨"],
milestone:["Look at you go! 🎉","You're dedicated! 💖"],
deep:["True reader! Impressed! 📖✨","Top 5% of readers! 🏆"],
complete:["YOU DID IT! 🎉🎊💖","100%!! AMAZING! 🏆✨🎀"],
stay:["More below... 👇","Keep scrolling! ✨"],
bored:["Hey! Over here! 👋","Don't leave yet! 🤭"],
walk:["Love your scrolling style! 💃","Where are we going? 🎀"],
dance:["Let's gooo! 🎶💃","We're vibing! ✨🎵"],
peekaboo:["Peek-a-boo! 🙈","I see you! 👀💕","Caught you looking! 🤭"]
};
function showSpeech(ctx){
if(!speechEl||C.dancer===false)return;var now=Date.now();if(now-lastSpeechTime<4e3)return;lastSpeechTime=now;
var pool;if(ctx==="welcome"){pool=visitCount>1?MSG.returning:MSG["welcome"+getTC()]}else{pool=MSG[ctx]||MSG.validation}
speechEl.textContent=pool[rnd(0,pool.length-1)];speechEl.classList.add("show");
clearTimeout(speechTimeout);speechTimeout=setTimeout(function(){speechEl.classList.remove("show")},3500);
}
function getTC(){var h=new Date().getHours();return h<12?"Morning":h<17?"Afternoon":h<21?"Evening":"Night"}

var MOOD=[[255,252,248],[255,240,245],[248,235,255],[235,248,255],[240,255,245],[255,245,230],[255,235,245],[240,235,255],[245,250,255],[255,245,248],[250,240,255]];
function updateMood(pct){
if(C.bgMood===false)return;
var idx=pct<=0.5?(pct/0.5)*7:7+((pct-0.5)/0.5)*3,i=Math.floor(idx),t=idx-i;t=t*t*(3-2*t);
var c1=MOOD[Math.min(i,10)],c2=MOOD[Math.min(i+1,10)];
document.body.style.backgroundColor="rgb("+Math.round(c1[0]+(c2[0]-c1[0])*t)+","+Math.round(c1[1]+(c2[1]-c1[1])*t)+","+Math.round(c1[2]+(c2[2]-c1[2])*t)+")";
}

function updateHeart(pct){
if(C.heart===false)return;
var fill=0.2+(pct*0.8),y=24-(fill*24);
var hf=document.getElementById("plHF"),hp=document.getElementById("plHP");
if(hf){hf.setAttribute("y",y);hf.setAttribute("height",24)}if(hp)hp.textContent=Math.round(fill*100)+"%";
}

function updateStreak(){
if(C.dancer===false)return;
streakCount++;if(streakCount>=3){streakEl.textContent="🔥 "+streakCount+" streak!";streakEl.classList.add("show");
if(streakCount===5||streakCount===10){showSpeech("streak");for(var i=0;i<3;i++)(function(j){setTimeout(addSparkle,j*100)})(i)}}
clearTimeout(streakTimer);streakTimer=setTimeout(function(){if(streakCount>=3&&scrollPct<0.9)showSpeech("lossAversion");streakCount=0;streakEl.classList.remove("show")},5e3);
}

function onScroll(){
if(ticking)return;ticking=true;
requestAnimationFrame(function(){ticking=false;
var sT=window.pageYOffset,dH=document.documentElement.scrollHeight-window.innerHeight;if(dH<=0)return;
scrollPct=Math.min(Math.max(sT/dH,0),1);speed=Math.abs(sT-lastY);lastY=sT;
updateMood(scrollPct);updateHeart(scrollPct);checkSparkle();
var cards=Math.floor(scrollPct*15);if(cards>lastCardY){lastCardY=cards;updateStreak()}
if(scrollPct>=0.98&&!milestoneHit["100"]){milestoneHit["100"]=true;startVictory()}
else if(speed>30){startDancing()}else if(speed>3){startWalking()}
[0.4,0.6,0.8].forEach(function(m){var key=(m*100)+"";if(scrollPct>=m&&!milestoneHit[key]){milestoneHit[key]=true;showSpeech(m===0.8?"deep":"milestone");playMilestone()}});
clearTimeout(scrollTimer);scrollTimer=setTimeout(function(){if(state!=="victory")startIdle()},300);
});}

function init(){
if(!spriteReady)return;
injectDOM();
if(C.dancer!==false){setFrame(0)}
if(C.heart!==false){updateHeart(0)}
window.addEventListener("scroll",onScroll,{passive:true});
if(C.dancer!==false){setTimeout(function(){startWelcome();showSpeech("welcome")},1500)}
}

function rnd(a,b){return Math.floor(Math.random()*(b-a+1))+a}
})();
