(function(){
  'use strict';
  if(window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  // Inject 7 gradient clouds
  for(var i=1;i<=7;i++){
    var c=document.createElement('div');
    c.className='dp-cloud dp-cloud-'+i;
    c.setAttribute('aria-hidden','true');
    document.body.appendChild(c);
  }
  // Inject grain + vignette
  var g=document.createElement('div');
  g.className='dp-grain';g.setAttribute('aria-hidden','true');
  document.body.appendChild(g);
  var v=document.createElement('div');
  v.className='dp-vignette';v.setAttribute('aria-hidden','true');
  document.body.appendChild(v);
  // Wrap content images in .dp-img-wrap for shimmer + tilt
  document.querySelectorAll('.entry-content img').forEach(function(img){
    if(img.parentElement.classList.contains('dp-img-wrap')) return;
    var w=document.createElement('span');
    w.className='dp-img-wrap';
    img.parentNode.insertBefore(w,img);
    w.appendChild(img);
  });
  // Add scroll-reveal class
  document.querySelectorAll('.dp-img-wrap').forEach(function(el){
    el.classList.add('dp-reveal');
  });
  // IntersectionObserver for scroll reveal
  if('IntersectionObserver' in window){
    var io=new IntersectionObserver(function(entries){
      entries.forEach(function(e){
        if(e.isIntersecting){e.target.classList.add('dp-visible');io.unobserve(e.target);}
      });
    },{threshold:0.1,rootMargin:'0px 0px -40px 0px'});
    document.querySelectorAll('.dp-reveal').forEach(function(el){io.observe(el);});
  } else {
    document.querySelectorAll('.dp-reveal').forEach(function(el){el.classList.add('dp-visible');});
  }
})();
