<?php
/**
 * Template Name: Affiliate Homepage (Mia)
 * Template Post Type: page
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Track page view
do_action('pl_affiliate_page_view');

// Get latest blog posts for the blog section
$latest_posts = new WP_Query([
    'posts_per_page' => 3,
    'post_status'    => 'publish',
    'orderby'        => 'date',
    'order'          => 'DESC',
]);

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Best Pinterest Marketing Course 2026 — Free Trial | Cheerlives</title>
<meta name="description" content="The Pinterest course behind 65M monthly impressions. Cheerlives recommends this to every reader. Free 7-day trial, no credit card.">
<?php wp_head(); ?>
<style>
/* ============================================================
   Mia Affiliate Landing Page — Complete Inline Styles
   ============================================================ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;color:#1a1a2e;background:#fff;line-height:1.6;overflow-x:hidden}
img{max-width:100%;height:auto;display:block}
a{text-decoration:none;color:inherit}

/* --- NAV --- */
.nav{position:fixed;top:0;left:0;right:0;z-index:1000;background:rgba(26,26,46,.95);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid rgba(255,255,255,.08);padding:0 24px;height:60px;display:flex;align-items:center;justify-content:space-between}
.nav-logo{font-size:18px;font-weight:800;color:#fff;letter-spacing:-.5px}
.nav-logo span{color:#FF6B35}
.nav-links{display:flex;align-items:center;gap:28px}
.nav-links a{color:rgba(255,255,255,.75);font-size:13px;font-weight:500;transition:color .2s}
.nav-links a:hover{color:#fff}
.nav-cta{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#FF6B35,#FF8F5E);color:#fff!important;padding:8px 18px;border-radius:50px;font-weight:700;font-size:13px;transition:transform .2s,box-shadow .2s;box-shadow:0 2px 12px rgba(255,107,53,.3)}
.nav-cta:hover{transform:translateY(-1px);box-shadow:0 4px 20px rgba(255,107,53,.4)}

/* --- HERO --- */
.hero{padding:100px 24px 60px;background:linear-gradient(165deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;top:-200px;right:-200px;width:600px;height:600px;background:radial-gradient(circle,rgba(255,107,53,.08) 0%,transparent 70%);pointer-events:none}
.hero-inner{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center}
.hero-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(255,107,53,.12);border:1px solid rgba(255,107,53,.25);padding:6px 14px;border-radius:50px;font-size:11px;font-weight:600;color:#FF6B35;text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px}
.hero-badge-dot{width:6px;height:6px;border-radius:50%;background:#FF6B35;animation:pulse-dot 2s infinite}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.3}}
.hero h1{font-size:42px;font-weight:800;color:#fff;line-height:1.15;margin-bottom:16px;letter-spacing:-.5px}
.hero h1 .hl{background:linear-gradient(135deg,#FF6B35,#FF8F5E);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{font-size:16px;color:rgba(255,255,255,.7);margin-bottom:28px;line-height:1.7;max-width:480px}
.hero-cta-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:32px}
.aff-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:14px 28px;border-radius:50px;font-weight:700;font-size:15px;transition:all .25s;cursor:pointer;border:none}
.aff-cta-primary{background:linear-gradient(135deg,#FF6B35,#FF8F5E);color:#fff;box-shadow:0 4px 20px rgba(255,107,53,.35)}
.aff-cta-primary:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(255,107,53,.45)}
.aff-cta-ghost{background:transparent;border:2px solid rgba(255,255,255,.25);color:#fff}
.aff-cta-ghost:hover{border-color:rgba(255,255,255,.5);background:rgba(255,255,255,.05)}
.hero-trust{display:flex;align-items:center;gap:12px;font-size:12px;color:rgba(255,255,255,.5)}
.hero-trust-avatars{display:flex}
.hero-trust-avatars span{width:28px;height:28px;border-radius:50%;border:2px solid #1a1a2e;margin-right:-8px;display:flex;align-items:center;justify-content:center;font-size:12px;background:linear-gradient(135deg,#FF6B35,#e84393)}
.hero-img-wrap{position:relative;display:flex;justify-content:center}
.hero-portrait{width:100%;max-width:420px;border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.hero-stats-float{position:absolute;background:rgba(255,255,255,.95);backdrop-filter:blur(8px);border-radius:14px;padding:12px 16px;box-shadow:0 8px 30px rgba(0,0,0,.15);font-size:12px;font-weight:600;color:#1a1a2e}
.hero-stat-top{top:20px;right:-10px;animation:float-y 3s ease-in-out infinite}
.hero-stat-bottom{bottom:40px;left:-10px;animation:float-y 3s ease-in-out infinite .5s}
.hero-stat-val{font-size:22px;font-weight:800;color:#FF6B35;display:block}
@keyframes float-y{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}

/* --- SOCIAL PROOF BAR --- */
.proof-bar{background:#f8f9fa;border-top:1px solid #eee;border-bottom:1px solid #eee;padding:20px 24px;text-align:center}
.proof-inner{max-width:900px;margin:0 auto;display:flex;align-items:center;justify-content:center;gap:40px;flex-wrap:wrap}
.proof-item{display:flex;align-items:center;gap:8px;font-size:13px;color:#666;font-weight:500}
.proof-item strong{color:#1a1a2e;font-size:15px}

/* --- MEET MIA --- */
.meet{padding:80px 24px;background:#fff}
.meet-inner{max-width:1000px;margin:0 auto;display:grid;grid-template-columns:1fr 1.3fr;gap:48px;align-items:center}
.meet-img{border-radius:20px;box-shadow:0 12px 40px rgba(0,0,0,.1)}
.meet-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#FF6B35;margin-bottom:8px}
.meet h2{font-size:32px;font-weight:800;color:#1a1a2e;line-height:1.2;margin-bottom:16px}
.meet-text{font-size:15px;color:#555;line-height:1.8;margin-bottom:20px}
.meet-stat-row{display:flex;gap:20px;flex-wrap:wrap}
.meet-stat{background:#f8f9fa;border-radius:12px;padding:14px 20px;text-align:center;flex:1;min-width:100px}
.meet-stat-val{font-size:22px;font-weight:800;color:#FF6B35;display:block}
.meet-stat-label{font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px}

/* --- SCREENSHOT / RESULTS --- */
.results{padding:80px 24px;background:linear-gradient(180deg,#f8f9fa,#fff)}
.results-inner{max-width:900px;margin:0 auto;text-align:center}
.results h2{font-size:32px;font-weight:800;margin-bottom:12px}
.results-sub{font-size:15px;color:#666;margin-bottom:40px;max-width:600px;margin-left:auto;margin-right:auto}
.results-screenshot{border-radius:16px;box-shadow:0 16px 50px rgba(0,0,0,.12);border:1px solid #e5e7eb;max-width:750px;margin:0 auto}
.results-caption{font-size:12px;color:#999;margin-top:16px;font-style:italic}

/* --- WHY CARDS --- */
.why{padding:80px 24px;background:#fff}
.why-inner{max-width:1000px;margin:0 auto}
.why h2{font-size:32px;font-weight:800;text-align:center;margin-bottom:12px}
.why-sub{text-align:center;font-size:15px;color:#666;margin-bottom:48px;max-width:600px;margin-left:auto;margin-right:auto}
.why-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
.why-card{background:#f8f9fa;border:1px solid #eee;border-radius:16px;padding:32px 24px;text-align:center;transition:transform .25s,box-shadow .25s}
.why-card:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(0,0,0,.08)}
.why-icon{font-size:40px;margin-bottom:16px;display:block}
.why-card h3{font-size:18px;font-weight:700;margin-bottom:10px;color:#1a1a2e}
.why-card p{font-size:14px;color:#666;line-height:1.7}
.why-cta-wrap{text-align:center;margin-top:40px}

/* --- MID BANNER --- */
.mid-banner{padding:80px 24px;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);position:relative;overflow:hidden}
.mid-banner::before{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:url('https://cheerlives.com/homepageimages/Pinterest%20Working%20Shot.jpeg') center/cover;opacity:.15;pointer-events:none}
.mid-inner{max-width:800px;margin:0 auto;text-align:center;position:relative;z-index:1}
.mid-inner h2{font-size:32px;font-weight:800;color:#fff;margin-bottom:12px}
.mid-inner p{font-size:16px;color:rgba(255,255,255,.7);margin-bottom:32px;max-width:550px;margin-left:auto;margin-right:auto}

/* --- CURRICULUM --- */
.curriculum{padding:80px 24px;background:#fff}
.curriculum-inner{max-width:900px;margin:0 auto}
.curriculum h2{font-size:32px;font-weight:800;text-align:center;margin-bottom:12px}
.curriculum-sub{text-align:center;font-size:15px;color:#666;margin-bottom:48px}
.curriculum-list{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:40px}
.curriculum-item{display:flex;align-items:flex-start;gap:12px;padding:16px;background:#f8f9fa;border-radius:12px;border:1px solid #eee;transition:border-color .2s}
.curriculum-item:hover{border-color:#FF6B35}
.curriculum-num{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#FF6B35,#FF8F5E);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0}
.curriculum-item h4{font-size:14px;font-weight:700;color:#1a1a2e;margin-bottom:2px}
.curriculum-item p{font-size:12px;color:#888}
.curriculum-cta-wrap{text-align:center}

/* --- TESTIMONIALS --- */
.testimonials{padding:80px 24px;background:#f8f9fa}
.testimonials-inner{max-width:1000px;margin:0 auto}
.testimonials h2{font-size:32px;font-weight:800;text-align:center;margin-bottom:48px}
.testimonial-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
.testimonial-card{background:#fff;border-radius:16px;padding:28px;box-shadow:0 4px 16px rgba(0,0,0,.05);border:1px solid #eee}
.testimonial-stars{color:#FFB800;font-size:16px;margin-bottom:12px;letter-spacing:2px}
.testimonial-text{font-size:14px;color:#555;line-height:1.7;margin-bottom:16px;font-style:italic}
.testimonial-author{display:flex;align-items:center;gap:10px}
.testimonial-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#FF6B35,#e84393);display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff;font-weight:700}
.testimonial-name{font-size:13px;font-weight:700;color:#1a1a2e}
.testimonial-role{font-size:11px;color:#999}
.testimonials-cta-wrap{text-align:center;margin-top:40px}

/* --- FAQ --- */
.faq{padding:80px 24px;background:#fff}
.faq-inner{max-width:800px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:start}
.faq-content h2{font-size:32px;font-weight:800;margin-bottom:32px}
.faq-item{border-bottom:1px solid #eee;padding:16px 0}
.faq-q{font-size:15px;font-weight:700;color:#1a1a2e;cursor:pointer;display:flex;justify-content:space-between;align-items:center}
.faq-q::after{content:'+';font-size:20px;color:#FF6B35;font-weight:300;transition:transform .2s}
.faq-q.open::after{transform:rotate(45deg)}
.faq-a{font-size:13px;color:#666;line-height:1.7;max-height:0;overflow:hidden;transition:max-height .3s,padding .3s}
.faq-a.open{max-height:200px;padding-top:10px}
.faq-img{border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.1);position:sticky;top:80px}

/* --- FINAL CTA --- */
.final-cta{padding:100px 24px;background:linear-gradient(165deg,#1a1a2e 0%,#0f3460 100%);text-align:center;position:relative;overflow:hidden}
.final-cta::before{content:'';position:absolute;bottom:-100px;left:50%;transform:translateX(-50%);width:800px;height:400px;background:radial-gradient(ellipse,rgba(255,107,53,.1),transparent 70%);pointer-events:none}
.final-inner{max-width:700px;margin:0 auto;position:relative;z-index:1}
.final-inner h2{font-size:36px;font-weight:800;color:#fff;margin-bottom:16px}
.final-inner p{font-size:16px;color:rgba(255,255,255,.7);margin-bottom:32px;max-width:550px;margin-left:auto;margin-right:auto}
.final-cta-row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:24px}
.final-guarantee{font-size:12px;color:rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;gap:6px}

/* --- BLOG SECTION --- */
.blog-section{padding:80px 24px;background:#fff}
.blog-section-inner{max-width:1000px;margin:0 auto}
.blog-section h2{font-size:28px;font-weight:800;text-align:center;margin-bottom:8px}
.blog-section-sub{text-align:center;font-size:14px;color:#888;margin-bottom:40px}
.blog-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
.blog-card{background:#fff;border-radius:14px;overflow:hidden;border:1px solid #eee;transition:transform .2s,box-shadow .2s;display:block}
.blog-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.08)}
.blog-img{height:160px;background:#f0f0f0}
.blog-body{padding:18px}
.blog-cat{font-size:11px;font-weight:700;text-transform:uppercase;color:#FF6B35;margin-bottom:6px;letter-spacing:.5px}
.blog-body h4{font-size:15px;font-weight:700;color:#1a1a2e;margin-bottom:6px;line-height:1.3}
.blog-body p{font-size:12px;color:#888;line-height:1.5}

/* --- FOOTER --- */
.aff-footer{padding:40px 24px;background:#1a1a2e;text-align:center;border-top:1px solid rgba(255,255,255,.06)}
.aff-footer p{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:4px}
.aff-footer a{color:#FF6B35;font-weight:600}
.aff-footer .disclosure{font-size:10px;color:rgba(255,255,255,.25);max-width:600px;margin:12px auto 0;line-height:1.6}

/* --- STICKY MOBILE BAR --- */
.sticky-bar{position:fixed;bottom:0;left:0;right:0;z-index:999;background:rgba(26,26,46,.97);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);padding:10px 16px;display:none;align-items:center;justify-content:space-between;border-top:1px solid rgba(255,255,255,.1)}
.sticky-bar-text{font-size:12px;color:rgba(255,255,255,.8);font-weight:600}
.sticky-bar-text span{color:#FF6B35}
.sticky-bar .aff-cta{padding:10px 20px;font-size:13px}

/* --- RESPONSIVE --- */
@media(max-width:768px){
    .nav-links a:not(.nav-cta){display:none}
    .hero{padding:80px 16px 40px}
    .hero-inner{grid-template-columns:1fr;gap:32px;text-align:center}
    .hero h1{font-size:28px}
    .hero-sub{margin-left:auto;margin-right:auto;font-size:14px}
    .hero-cta-row{justify-content:center}
    .hero-trust{justify-content:center}
    .hero-img-wrap{order:-1}
    .hero-portrait{max-width:280px}
    .hero-stat-top{right:0;top:10px}
    .hero-stat-bottom{left:0;bottom:20px}
    .meet-inner{grid-template-columns:1fr;text-align:center}
    .meet-img{max-width:300px;margin:0 auto}
    .why-grid{grid-template-columns:1fr}
    .curriculum-list{grid-template-columns:1fr}
    .testimonial-grid{grid-template-columns:1fr}
    .faq-inner{grid-template-columns:1fr}
    .faq-img{display:none}
    .blog-grid{grid-template-columns:1fr}
    .sticky-bar{display:flex}
    .proof-inner{gap:20px}
    .proof-item{font-size:11px}
    .results h2,.why h2,.curriculum h2,.testimonials h2,.meet h2{font-size:24px}
    .mid-inner h2{font-size:24px}
    .final-inner h2{font-size:26px}
}
@media(min-width:769px) and (max-width:1024px){
    .hero h1{font-size:34px}
    .hero-inner{gap:32px}
    .why-grid{grid-template-columns:repeat(3,1fr)}
    .testimonial-grid{grid-template-columns:repeat(2,1fr)}
}

/* WP admin bar offset */
body.admin-bar .nav { top: 32px; }
@media screen and (max-width: 782px) {
  body.admin-bar .nav { top: 46px; }
}
/* Admin toggle notice */
.pl-aff-admin-notice {
  position: fixed; bottom: 80px; right: 20px; z-index: 9999;
  background: #1a1a2e; color: #fff; padding: 12px 18px;
  border-radius: 10px; font-size: 13px; font-family: sans-serif;
  box-shadow: 0 4px 20px rgba(0,0,0,0.3);
  display: flex; align-items: center; gap: 10px;
}
.pl-aff-admin-notice a { color: #FF6B35; font-weight: 700; }
</style>
</head>
<body <?php body_class(); ?>>
<?php if (current_user_can('manage_options')) : ?>
<div class="pl-aff-admin-notice">
  &#x1F4CC; Affiliate Page Active &mdash;
  <a href="<?php echo admin_url('admin.php?page=pl-affiliate-router&tab=homepage'); ?>">Manage Toggle</a>
</div>
<?php endif; ?>

<!-- ========== NAV ========== -->
<nav class="nav">
  <a href="<?php echo esc_url(home_url('/')); ?>" class="nav-logo">cheer<span>lives</span></a>
  <div class="nav-links">
    <a href="#why">Why This Course</a>
    <a href="#curriculum">Curriculum</a>
    <a href="#testimonials">Reviews</a>
    <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="nav-cta aff-cta" data-pos="nav">Start Free Trial &rarr;</a>
  </div>
</nav>

<!-- ========== HERO ========== -->
<section class="hero">
  <div class="hero-inner">
    <div>
      <div class="hero-badge">
        <span class="hero-badge-dot"></span>
        RECOMMENDED BY 40,000+ MONTHLY VISITORS
      </div>
      <h1>The Pinterest Course Our <span class="hl">40,000+ Monthly Readers</span> Need</h1>
      <p class="hero-sub">Mia's Pinterest marketing system generated <strong>65M+ monthly impressions</strong>. Now she's teaching everything &mdash; strategy, SEO, pin design, automation &mdash; in one comprehensive course.</p>
      <div class="hero-cta-row">
        <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-primary" data-pos="hero">Start Free 7-Day Trial &rarr;</a>
        <a href="#meet-mia" class="aff-cta aff-cta-ghost">Meet Mia &darr;</a>
      </div>
      <div class="hero-trust">
        <div class="hero-trust-avatars">
          <span>&#x1F469;</span>
          <span>&#x1F468;</span>
          <span>&#x1F469;&#x200D;&#x1F9B0;</span>
          <span>&#x1F468;&#x200D;&#x1F9B1;</span>
        </div>
        <span>Join 170,000+ students &bull; 4.8&star; average rating</span>
      </div>
    </div>
    <div class="hero-img-wrap">
      <img src="https://cheerlives.com/homepageimages/Hero%20Portrait.webp" alt="Mia — Pinterest Marketing Expert" class="hero-portrait" width="420" height="560" loading="eager" fetchpriority="high">
      <div class="hero-stats-float hero-stat-top">
        <span class="hero-stat-val">65M+</span>
        Monthly Impressions
      </div>
      <div class="hero-stats-float hero-stat-bottom">
        <span class="hero-stat-val">2.4M</span>
        Monthly Viewers
      </div>
    </div>
  </div>
</section>

<!-- ========== SOCIAL PROOF BAR ========== -->
<div class="proof-bar">
  <div class="proof-inner">
    <div class="proof-item">&#x2B50; <strong>4.8</strong> out of 5 rating</div>
    <div class="proof-item">&#x1F393; <strong>170,000+</strong> students enrolled</div>
    <div class="proof-item">&#x1F4C8; <strong>25M+</strong> pins created by students</div>
    <div class="proof-item">&#x1F30D; Students from <strong>120+</strong> countries</div>
  </div>
</div>

<!-- ========== MEET MIA ========== -->
<section class="meet" id="meet-mia">
  <div class="meet-inner">
    <img src="https://cheerlives.com/homepageimages/Lifestyle%20Teaching%20Shot.jpeg" alt="Mia teaching Pinterest strategy" class="meet-img" width="450" height="450" loading="lazy">
    <div>
      <div class="meet-label">Meet Your Instructor</div>
      <h2>Mia &mdash; From Zero to 65M Monthly Impressions</h2>
      <p class="meet-text">Mia started her Pinterest journey with zero followers and zero strategy. Within 18 months, she built a system that generates <strong>65 million monthly impressions</strong> across multiple accounts. Her pins have been saved over 25 million times. She's spoken at 12 digital marketing conferences and helped 40,000 monthly visitors discover content through Pinterest.</p>
      <p class="meet-text">Now she teaches the exact framework &mdash; from keyword research to pin design to automation &mdash; that took her accounts from invisible to unstoppable.</p>
      <div class="meet-stat-row">
        <div class="meet-stat"><span class="meet-stat-val">40K+</span><span class="meet-stat-label">Monthly Visitors</span></div>
        <div class="meet-stat"><span class="meet-stat-val">280K</span><span class="meet-stat-label">Followers</span></div>
        <div class="meet-stat"><span class="meet-stat-val">25M</span><span class="meet-stat-label">Pin Saves</span></div>
      </div>
    </div>
  </div>
</section>

<!-- ========== RESULTS SCREENSHOT ========== -->
<section class="results">
  <div class="results-inner">
    <h2>Real Results, Not Theory</h2>
    <p class="results-sub">Here's what Mia's Pinterest analytics dashboard actually looks like &mdash; and what yours can look like too.</p>
    <img src="https://cheerlives.com/homepageimages/Capture.PNG" alt="Pinterest analytics dashboard showing 65M impressions" class="results-screenshot" width="750" height="420" loading="lazy">
    <p class="results-caption">Mia's actual Pinterest analytics &mdash; 65M+ monthly impressions across managed accounts</p>
  </div>
</section>

<!-- ========== WHY CARDS ========== -->
<section class="why" id="why">
  <div class="why-inner">
    <h2>Why Cheerlives Readers Love This Course</h2>
    <p class="why-sub">We've tested dozens of Pinterest resources. This is the only one we recommend to every single reader.</p>
    <div class="why-grid">
      <div class="why-card">
        <span class="why-icon">&#x1F4CC;</span>
        <h3>Built for Pinterest Addicts</h3>
        <p>Not a generic social media course. Every lesson, template, and strategy is Pinterest-specific. From keyword research to Rich Pins to Idea Pins &mdash; it's all here.</p>
        <div style="margin-top:18px">
          <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-primary" data-pos="why-1" style="font-size:13px;padding:10px 20px">Try It Free &rarr;</a>
        </div>
      </div>
      <div class="why-card">
        <span class="why-icon">&#x1F4CA;</span>
        <h3>170,000+ Students Can't Be Wrong</h3>
        <p>Rated 4.8/5 by 170,000+ students. Real people getting real Pinterest traffic. Not vanity metrics &mdash; actual clicks, saves, and conversions.</p>
        <div style="margin-top:18px">
          <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-primary" data-pos="why-2" style="font-size:13px;padding:10px 20px">Join Them &rarr;</a>
        </div>
      </div>
      <div class="why-card">
        <span class="why-icon">&#x1F381;</span>
        <h3>7-Day Free Trial, Zero Risk</h3>
        <p>Start learning today with a free 7-day trial. No credit card required upfront. If it's not for you, just don't continue. No hard feelings, no hidden charges.</p>
        <div style="margin-top:18px">
          <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-primary" data-pos="why-3" style="font-size:13px;padding:10px 20px">Start Free &rarr;</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ========== MID BANNER ========== -->
<section class="mid-banner">
  <div class="mid-inner">
    <h2>Your Pinterest Strategy Starts Here</h2>
    <p>Join the thousands of creators, bloggers, and businesses who transformed their Pinterest presence with Mia's proven system.</p>
    <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-primary" data-pos="mid-banner" style="font-size:16px;padding:16px 36px">Start Your Free Trial &rarr;</a>
  </div>
</section>

<!-- ========== CURRICULUM ========== -->
<section class="curriculum" id="curriculum">
  <div class="curriculum-inner">
    <h2>What You'll Learn</h2>
    <p class="curriculum-sub">A complete Pinterest marketing education &mdash; from setting up your profile to scaling to millions of impressions.</p>
    <div class="curriculum-list">
      <div class="curriculum-item">
        <span class="curriculum-num">1</span>
        <div><h4>Pinterest Profile Optimization</h4><p>Set up your account for maximum discoverability</p></div>
      </div>
      <div class="curriculum-item">
        <span class="curriculum-num">2</span>
        <div><h4>Keyword Research Mastery</h4><p>Find the exact keywords your audience is searching</p></div>
      </div>
      <div class="curriculum-item">
        <span class="curriculum-num">3</span>
        <div><h4>Pin Design That Converts</h4><p>Create pins that get saved, clicked, and shared</p></div>
      </div>
      <div class="curriculum-item">
        <span class="curriculum-num">4</span>
        <div><h4>Board Strategy & Organization</h4><p>Structure your boards for algorithmic advantage</p></div>
      </div>
      <div class="curriculum-item">
        <span class="curriculum-num">5</span>
        <div><h4>SEO for Pinterest</h4><p>Rank your pins in both Pinterest and Google search</p></div>
      </div>
      <div class="curriculum-item">
        <span class="curriculum-num">6</span>
        <div><h4>Idea Pins & Video Content</h4><p>Leverage Pinterest's newest formats for 10x reach</p></div>
      </div>
      <div class="curriculum-item">
        <span class="curriculum-num">7</span>
        <div><h4>Analytics & Optimization</h4><p>Read your data and double down on what works</p></div>
      </div>
      <div class="curriculum-item">
        <span class="curriculum-num">8</span>
        <div><h4>Automation & Scaling</h4><p>Grow to millions of impressions on autopilot</p></div>
      </div>
    </div>
    <div class="curriculum-cta-wrap">
      <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-primary" data-pos="curriculum">Get Full Access — Start Free &rarr;</a>
    </div>
  </div>
</section>

<!-- ========== TESTIMONIALS ========== -->
<section class="testimonials" id="testimonials">
  <div class="testimonials-inner">
    <h2>What Students Are Saying</h2>
    <div class="testimonial-grid">
      <div class="testimonial-card">
        <div class="testimonial-stars">&#x2B50;&#x2B50;&#x2B50;&#x2B50;&#x2B50;</div>
        <p class="testimonial-text">"I went from 500 monthly views to 2.1 million in 4 months. Mia's keyword strategy alone was worth 10x the price. My blog traffic tripled."</p>
        <div class="testimonial-author">
          <div class="testimonial-avatar">S</div>
          <div><div class="testimonial-name">Sarah K.</div><div class="testimonial-role">Lifestyle Blogger</div></div>
        </div>
      </div>
      <div class="testimonial-card">
        <div class="testimonial-stars">&#x2B50;&#x2B50;&#x2B50;&#x2B50;&#x2B50;</div>
        <p class="testimonial-text">"Finally a Pinterest course that actually delivers. No fluff, just actionable strategies. I'm now getting 500+ clicks to my shop every single day from Pinterest alone."</p>
        <div class="testimonial-author">
          <div class="testimonial-avatar">J</div>
          <div><div class="testimonial-name">Jessica M.</div><div class="testimonial-role">Etsy Shop Owner</div></div>
        </div>
      </div>
      <div class="testimonial-card">
        <div class="testimonial-stars">&#x2B50;&#x2B50;&#x2B50;&#x2B50;&#x2B50;</div>
        <p class="testimonial-text">"The automation module saved me 15 hours per week. I set up Mia's system once and now Pinterest drives consistent traffic while I focus on creating content."</p>
        <div class="testimonial-author">
          <div class="testimonial-avatar">R</div>
          <div><div class="testimonial-name">Rachel T.</div><div class="testimonial-role">Food Blogger</div></div>
        </div>
      </div>
    </div>
    <div class="testimonials-cta-wrap">
      <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-primary" data-pos="testimonials">Join 170,000+ Students &rarr;</a>
    </div>
  </div>
</section>

<!-- ========== FAQ ========== -->
<section class="faq" id="faq">
  <div class="faq-inner">
    <div class="faq-content">
      <h2>Common Questions</h2>
      <div class="faq-item">
        <div class="faq-q">Is this really free to start?</div>
        <div class="faq-a">Yes! You get a full 7-day free trial with complete access to all course materials. No credit card required upfront.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">I'm a complete Pinterest beginner. Is this for me?</div>
        <div class="faq-a">Absolutely. Mia starts from the very basics and builds up. Whether you have 0 followers or 10,000, the strategies scale with you.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">How quickly will I see results?</div>
        <div class="faq-a">Most students see increased impressions within 2-3 weeks. Significant traffic growth typically happens within 60-90 days as Pinterest's algorithm picks up your optimized content.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">What if I don't like the course?</div>
        <div class="faq-a">Cancel anytime during your free trial and you won't be charged. After the trial, you can cancel your subscription at any time with no penalties.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Does this work for any niche?</div>
        <div class="faq-a">Yes. Mia's students range from fashion bloggers to real estate agents to recipe creators. The Pinterest algorithm strategies work across every niche.</div>
      </div>
    </div>
    <img src="https://cheerlives.com/homepageimages/Mobile%20Pinterest%20Shot%20(1).jpeg" alt="Pinterest on mobile device" class="faq-img" width="350" height="500" loading="lazy">
  </div>
</section>

<!-- ========== FINAL CTA ========== -->
<section class="final-cta">
  <div class="final-inner">
    <h2>Ready to Transform Your Pinterest?</h2>
    <p>Start your free 7-day trial today. Learn the exact system behind 65M+ monthly impressions. No credit card, no commitment, no risk.</p>
    <div class="final-cta-row">
      <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-primary" data-pos="final-cta" style="font-size:16px;padding:16px 36px">Start Free 7-Day Trial &rarr;</a>
      <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-ghost" data-pos="final-preview">Watch Preview &rarr;</a>
    </div>
    <div class="final-guarantee">&#x1F512; Free trial &bull; Cancel anytime &bull; 170,000+ students</div>
  </div>
</section>

<!-- ========== BLOG SECTION ========== -->
<section class="blog-section">
  <div class="blog-section-inner">
    <h2>From the Cheerlives Blog</h2>
    <p class="blog-section-sub">More inspiration, tips, and ideas from our team</p>
    <div class="blog-grid">
      <?php while ($latest_posts->have_posts()) : $latest_posts->the_post();
          $thumb = get_the_post_thumbnail_url(null, 'medium');
          $cat   = get_the_category();
          $cat_name = !empty($cat) ? esc_html($cat[0]->name) : 'Lifestyle'; ?>
      <a href="<?php the_permalink(); ?>" class="blog-card" style="text-decoration:none;color:inherit;">
        <div class="blog-img" style="background: <?php echo $thumb ? 'url('.esc_url($thumb).') center/cover' : 'linear-gradient(135deg,#f8c8d8,#e8a0b4)'; ?>;"></div>
        <div class="blog-body">
          <div class="blog-cat"><?php echo $cat_name; ?></div>
          <h4><?php the_title(); ?></h4>
          <p><?php echo wp_trim_words(get_the_excerpt(), 12, '...'); ?></p>
        </div>
      </a>
      <?php endwhile; wp_reset_postdata(); ?>
    </div>
  </div>
</section>

<!-- ========== FOOTER ========== -->
<footer class="aff-footer">
  <p>&copy; <?php echo date('Y'); ?> <a href="<?php echo esc_url(home_url('/')); ?>">Cheerlives</a>. All rights reserved.</p>
  <p class="disclosure">Affiliate Disclosure: This page contains affiliate links. If you sign up through our links, we may earn a commission at no extra cost to you. We only recommend products we genuinely believe in. This course is hosted on Skillshare.</p>
</footer>

<!-- ========== STICKY MOBILE BAR ========== -->
<div class="sticky-bar">
  <div class="sticky-bar-text">&#x1F4CC; <span>Free Trial</span> &mdash; Start Learning Today</div>
  <a href="https://fxo.co/1522574/social" target="_blank" rel="sponsored noopener" class="aff-cta aff-cta-primary" data-pos="sticky-bottom">Start Free &rarr;</a>
</div>

<!-- ========== CLICK TRACKING ========== -->
<script>
(function() {
  'use strict';
  var AFFILIATE_URL = 'https://fxo.co/1522574/social';

  function trackClick(pos) {
    // Send to WP REST endpoint
    var data = {
      pos: pos,
      page: 'affiliate-homepage',
      ts: Date.now(),
      ua: navigator.userAgent.substring(0, 100),
      ref: document.referrer.substring(0, 200)
    };
    if (navigator.sendBeacon) {
      navigator.sendBeacon(
        '<?php echo esc_url(rest_url('pl/v1/affiliate-click')); ?>',
        JSON.stringify(data)
      );
    } else {
      fetch('<?php echo esc_url(rest_url('pl/v1/affiliate-click')); ?>', {
        method: 'POST',
        body: JSON.stringify(data),
        headers: {'Content-Type':'application/json'},
        keepalive: true
      });
    }
  }

  // Track all .aff-cta clicks
  document.addEventListener('click', function(e) {
    var el = e.target.closest('.aff-cta');
    if (el) {
      var pos = el.getAttribute('data-pos') || 'unknown';
      trackClick(pos);
    }
  }, { passive: true });

  // Track page view
  trackClick('page_view');

  // FAQ accordion
  document.querySelectorAll('.faq-q').forEach(function(q) {
    q.addEventListener('click', function() {
      var a = this.nextElementSibling;
      var isOpen = this.classList.contains('open');
      document.querySelectorAll('.faq-q').forEach(function(qq) { qq.classList.remove('open'); });
      document.querySelectorAll('.faq-a').forEach(function(aa) { aa.classList.remove('open'); });
      if (!isOpen) {
        this.classList.add('open');
        a.classList.add('open');
      }
    });
  });
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
