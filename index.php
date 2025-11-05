<?php
require_once 'config.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>GMI Student Portal</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="img/favicon.png" type="image/png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
<style>
/* ===== Theme variables ===== */
:root{
  --nav-height:72px; /* <- adjust to your nav actual height */
  --bg-900:#0b1418;
  --bg-800:#0f1b21;
  --muted:#9fb0c4;
  --text:#e6eef8;
  --accent-blue:#23b0f0;
  --accent-purple:#7c5cff;
  --btn-blue:#4f8df7;
  --btn-teal:#06b6d4;
  --shadow-strong:0 28px 80px rgba(0,0,0,0.65);
  --shadow-soft:0 12px 30px rgba(0,0,0,0.45);
  --radius:18px;
  --maxWidth:1320px;
  --transition:220ms cubic-bezier(.2,.9,.2,1);
  --ease-out:cubic-bezier(.22,.9,.35,1);
}

/* Reset & base */
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:"Inter","Poppins",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;
  color:var(--text);
  background:linear-gradient(180deg,var(--bg-900),var(--bg-800));
  -webkit-font-smoothing:antialiased;
  line-height:1.45;

  /* ensure body content sits below a fixed nav */
  padding-top: var(--nav-height);
}

/* NAV overlay styling (covers common nav selectors) */
/* If your nav.php uses a different selector, adjust here */
nav, .site-nav, .navbar, #main-nav {
  position: fixed;        /* keep nav on top while scrolling */
  top: 0;
  left: 0;
  right: 0;
  height: var(--nav-height);
  z-index: 11000;         /* above hero */
  background: rgba(6,10,14,0.22); /* translucent so the hero image shows through */
  backdrop-filter: blur(6px);     /* subtle glass effect */
  border-bottom: 1px solid rgba(255,255,255,0.02);
  display: flex;
  align-items: center;
  /* if your nav already has its own background, you can change the above */
}

/* optional: nav link contrast (adjust to fit your nav markup) */
nav a, .site-nav a, .navbar a, #main-nav a {
  color: var(--text);
  text-shadow: 0 1px 0 rgba(0,0,0,0.5);
}

/* Page wrapper */
.container {
  width:92%;
  max-width:var(--maxWidth);
  margin:32px auto;
  display:grid;
  gap:36px;
  grid-template-columns:1fr;
}

/* HERO: full viewport (hero image starts at very top so nav overlays it) */
.hero {
  /* keep hero behind nav visually — nav sits above due to z-index */
  min-height: calc(100vh - var(--nav-height)); /* account for fixed nav height */
  width:100%;
  margin-top: 0;
  display:flex;
  align-items:center;
  justify-content:center;
  padding: calc(var(--nav-height) + 24px) 20px 56px; /* push content below nav so it's not covered */
  background-image:
    linear-gradient(180deg, rgba(6,12,18,0.72), rgba(6,12,18,0.64)),
    url('srs/img/indexgmi.png');
  background-size: cover;
  background-position: center top; /* move image toward top so nav overlays the image nicely */
  position:relative;
  overflow:hidden;
}

/* subtle vignette */
.hero::before{
  content:"";
  position:absolute;
  inset:0;
  pointer-events:none;
  box-shadow: inset 0 120px 160px rgba(0,0,0,0.5), inset 0 -80px 120px rgba(10,20,28,0.45);
}

/* hero inner content */
.hero-inner{
  width:100%;
  max-width:1180px;
  display:flex;
  gap:28px;
  align-items:center;
  justify-content:space-between;
  flex-wrap:wrap;
  z-index:1;
}

/* copy */
.hero-copy{ flex:1 1 520px; min-width:280px; color:var(--text); text-align:left; }
.hero h1{
  font-family:"Poppins","Inter",sans-serif;
  font-weight:800;
  text-transform:uppercase;
  font-size:clamp(34px,6vw,72px);
  margin:0 0 10px;
  line-height:1;
  letter-spacing:1px;
  text-shadow:0 10px 30px rgba(0,0,0,0.6), 0 0 18px rgba(35,176,240,0.06);
}
.hero p{
  margin:0 0 18px;
  color:var(--muted);
  font-size:clamp(14px,1.4vw,18px);
  max-width:720px;
}

/* CTA */
.action-buttons{ display:flex; gap:12px; flex-wrap:wrap; }
.action-btn{
  padding:12px 20px;
  border-radius:12px;
  display:inline-flex;
  gap:10px;
  align-items:center;
  text-decoration:none;
  font-weight:700;
  color:#fff;
  transition:transform var(--transition) var(--ease-out);
  box-shadow:var(--shadow-soft);
}
.action-btn-primary{ background:linear-gradient(90deg,var(--btn-blue),var(--accent-purple)); }
.action-btn-secondary{ background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); color:var(--text); border:1px solid rgba(255,255,255,0.04); }
.action-btn-third{ background:linear-gradient(90deg,var(--btn-teal),#0ea5e9); }

/* promo tile */
.promo-tile{
  min-width:260px; max-width:360px;
  background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
  padding:16px;border-radius:12px;border:1px solid rgba(255,255,255,0.03);
  box-shadow:var(--shadow-soft);
  color:var(--muted);
}

/* SLIDERS (content after hero) */
.slider-row{
  display:flex; gap:26px; flex-wrap:wrap; justify-content:center; align-items:flex-start;
}
.slider-container{
  width:420px; max-width:calc(100% - 40px);
  background:linear-gradient(180deg, rgba(255,255,255,0.012), rgba(255,255,255,0.008));
  border-radius:14px; padding:16px; border:1px solid rgba(255,255,255,0.03);
  box-shadow:var(--shadow-soft); position:relative; overflow:hidden;
}
.slider-header{ font-weight:800; color:var(--accent-blue); margin-bottom:10px; font-size:18px; }
.slider-slides{ min-height:220px; }
.slider-slides img{ width:100%; height:220px; object-fit:cover; border-radius:10px; cursor:pointer; display:block; transition:transform 420ms var(--ease-out); }
.slider-slides img:hover{ transform:scale(1.02); }
.caption{ margin-top:10px; color:var(--muted); text-align:center; font-weight:600; }

/* nav/dots */
.prev,.next{ position:absolute; top:12px; width:40px; height:40px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; background:rgba(0,0,0,0.55); color:#fff; border:1px solid rgba(255,255,255,0.04); cursor:pointer; }
.prev{ left:12px } .next{ right:12px }
.dot-container{ display:flex; gap:8px; justify-content:center; margin-top:12px }
.dot{ width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,0.12);display:inline-block;cursor:pointer; transition:all 180ms;}
.dot.active{ width:28px; background:linear-gradient(90deg,var(--accent-blue),var(--accent-purple)); border-radius:999px; }

/* FEATURES */
.feature-section{ padding:36px 20px; border-radius:12px; background:linear-gradient(180deg, rgba(255,255,255,0.01), rgba(255,255,255,0.004)); border:1px solid rgba(255,255,255,0.02); }
.feature-section h2{ text-align:center; font-family:Poppins, sans-serif; margin:0 0 18px; font-size:32px; color:var(--accent-blue); font-weight:800; letter-spacing:1.2px; text-transform:uppercase;}
.feature-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-top:12px; }
.card{ padding:22px; border-radius:12px; background:linear-gradient(180deg, rgba(255,255,255,0.018), rgba(255,255,255,0.006)); text-align:center; border:1px solid rgba(255,255,255,0.03); box-shadow:0 10px 28px rgba(0,0,0,0.45); }
.card i{ color:var(--accent-blue); font-size:36px; margin-bottom:12px; display:block; }
.card h3{ margin:0 0 10px; font-weight:800; }
.card p{ margin:0; color:var(--muted); line-height:1.45; }

/* LIGHTBOX */
#lightbox{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.85); z-index:9999; padding:20px; }
#lightbox img{ max-width:96%; max-height:92vh; border-radius:8px; box-shadow:0 40px 120px rgba(0,0,0,0.85); }
.close-lightbox{ position:absolute; top:18px; right:22px; font-size:32px; color:var(--accent-blue); background:transparent; border:none; cursor:pointer; }

/* responsive */
@media (max-width:1100px){ .feature-grid{ grid-template-columns:repeat(2,1fr); } .slider-container{ width:48%; } }
@media (max-width:720px){
  .feature-grid{ grid-template-columns:1fr; }
  .slider-container{ width:100%; }
  .hero{ padding:28px 12px; min-height:calc(60vh - 60px); }
  .hero h1{ font-size:clamp(26px,7.5vw,46px); text-align:center; }
  .hero-copy{ text-align:center; }
  .promo-tile{ display:none; }
}
</style>
</head>
<body>
<?php include 'nav.php'; ?>

<header class="hero" role="banner" aria-label="Site hero">
  <div class="hero-inner">
    <div class="hero-copy">
      <h1 id="hero-title">Student Registration System</h1>
      <p>Register online in minutes — secure, fast, and paperless. Built for students, staff and administration with a modern, mobile-friendly interface.</p>
      <div class="action-buttons" role="group" aria-label="Primary actions">
        <a href="registration.php" class="action-btn action-btn-primary"><i class="fa-solid fa-user-plus"></i> Register Now</a>
        <a href="login.php" class="action-btn action-btn-secondary"><i class="fa-solid fa-right-to-bracket"></i> Log In</a>
        <a href="timetable.php" class="action-btn action-btn-third"><i class="fa-solid fa-calendar-days"></i> Timetable</a>
      </div>
    </div>

    <aside class="promo-tile" aria-labelledby="promo-title">
      <h4 id="promo-title" style="margin:0 0 8px;color:var(--accent-blue);font-weight:700;">Why register online?</h4>
      <p style="margin:0;color:var(--muted);">Faster approvals, instant status tracking and secure student records with centralized management.</p>
      <div style="display:flex;gap:8px;margin-top:12px;">
        <span style="background:rgba(255,255,255,0.02);padding:8px 10px;border-radius:8px;font-weight:700;color:var(--text)">Paperless</span>
        <span style="background:rgba(255,255,255,0.02);padding:8px 10px;border-radius:8px;font-weight:700;color:var(--text)">Secure</span>
      </div>
    </aside>
  </div>
</header>

<main class="container" role="main" aria-live="polite">
  <div class="slider-row" aria-label="Site sliders">
    <section class="slider-container" data-slider-id="events" tabindex="0" aria-roledescription="carousel" aria-label="GMI Events">
      <div class="slider-header">GMI Events</div>

      <div class="slider-slides" data-caption="Talent Show 2025">
        <img src="img/talentshow.jpg" alt="Talent Show 2025" loading="lazy">
        <div class="caption">Talent Show 2025</div>
      </div>

      <div class="slider-slides" data-caption="Storytelling Competition" style="display:none;">
        <!-- filename fixed to storytelling_competition.jpg -->
        <img src="img/storyteling.jpg" alt="Storytelling Competition" loading="lazy">
        <div class="caption">Storytelling Competition</div>
      </div>

      <div class="slider-slides" data-caption="SRA Night Market" style="display:none;">
        <img src="img/nightmarket.jpg" alt="SRA Night Market" loading="lazy">
        <div class="caption">SRA Night Market</div>
      </div>

      <div class="slider-slides" data-caption="Mini Sports Carnival" style="display:none;">
        <img src="img/minicarnival.jpg" alt="Mini Sports Carnival" loading="lazy">
        <div class="caption">Mini Sports Carnival</div>
      </div>

      <button class="prev" aria-hidden="true" data-action="prev" title="Previous slide">❮</button>
      <button class="next" aria-hidden="true" data-action="next" title="Next slide">❯</button>

      <div class="dot-container" aria-hidden="true" data-dots></div>
    </section>

    <section class="slider-container" data-slider-id="life" tabindex="0" aria-roledescription="carousel" aria-label="Life in GMI">
      <div class="slider-header">Life in GMI</div>

      <div class="slider-slides" data-caption="GMI Open Day">
        <img src="img/openday.jpg" alt="GMI Open Day" loading="lazy">
        <div class="caption">GMI Open Day</div>
      </div>

      <div class="slider-slides" data-caption="Raya with CBS6" style="display:none;">
        <img src="img/raya.jpg" alt="Raya with CBS6" loading="lazy">
        <div class="caption">Raya with CBS6</div>
      </div>

      <div class="slider-slides" data-caption="Futsal Revolution Day" style="display:none;">
        <img src="img/futsal.jpg" alt="Futsal Revolution Day" loading="lazy">
        <div class="caption">Futsal Revolution Day</div>
      </div>

      <div class="slider-slides" data-caption="Penang Trip" style="display:none;">
        <img src="img/penang.jpg" alt="Penang Trip" loading="lazy">
        <div class="caption">Penang Trip</div>
      </div>

      <button class="prev" aria-hidden="true" data-action="prev" title="Previous slide">❮</button>
      <button class="next" aria-hidden="true" data-action="next" title="Next slide">❯</button>

      <div class="dot-container" aria-hidden="true" data-dots></div>
    </section>
  </div>

  <section class="feature-section" aria-labelledby="features-title">
    <h2 id="features-title">Features</h2>
    <div class="feature-grid" role="list">
      <div class="card" role="listitem">
        <i class="fa-solid fa-file-pen"></i>
        <h3>Easy Enrollment</h3>
        <p>Fill out forms online anytime, anywhere with ease.</p>
      </div>

      <div class="card" role="listitem">
        <i class="fa-solid fa-lock"></i>
        <h3>Secure Data</h3>
        <p>Protects student information with advanced encryption and role-based access control.</p>
      </div>

      <div class="card" role="listitem">
        <i class="fa-solid fa-chart-line"></i>
        <h3>Track Applications</h3>
        <p>Check your registration status instantly with real-time updates and notifications.</p>
      </div>
    </div>
  </section>

  <section class="feature-section" aria-labelledby="how-title">
    <h2 id="how-title">How It Works</h2>
    <div class="feature-grid" style="margin-top:12px;">
      <div class="card">
        <i class="fa-solid fa-user"></i>
        <h3>Create or Log In</h3>
        <p>Sign up or log in to access your student portal securely.</p>
      </div>
      <div class="card">
        <i class="fa-solid fa-clipboard-list"></i>
        <h3>Fill in Registration Forms</h3>
        <p>Complete your student details and upload supporting documents with ease.</p>
      </div>
      <div class="card">
        <i class="fa-solid fa-check-circle"></i>
        <h3>Confirm and Get Approval</h3>
        <p>Submit and wait for confirmation from administration. Track progress live.</p>
      </div>
    </div>
  </section>
</main>

<div id="lightbox" role="dialog" aria-modal="true" aria-hidden="true">
  <button class="close-lightbox" aria-label="Close lightbox">&times;</button>
  <img src="" alt="Enlarged image">
</div>

<script>
/* Slider + Lightbox (keeps your logic) */
(function(){
  const containers = Array.from(document.querySelectorAll('.slider-container'));
  containers.forEach(container => {
    const slides = Array.from(container.querySelectorAll('.slider-slides'));
    const dotsWrap = container.querySelector('[data-dots]');
    const prev = container.querySelector('[data-action="prev"]');
    const next = container.querySelector('[data-action="next"]');

    // create dots
    dotsWrap.innerHTML = '';
    slides.forEach((s,i) => {
      const dot = document.createElement('button');
      dot.className = 'dot' + (i===0?' active':'');
      dot.dataset.index = i;
      dot.setAttribute('role','button');
      dot.setAttribute('aria-label','Slide ' + (i+1));
      dot.title = 'Go to slide ' + (i+1);
      dot.style.border = 'none';
      dotsWrap.appendChild(dot);
    });

    let idx = 0;
    const INTERVAL = 4200;
    let timer = null;

    function show(i){
      idx = (i + slides.length) % slides.length;
      slides.forEach((s,j) => s.style.display = (j===idx ? 'block' : 'none'));
      Array.from(dotsWrap.children).forEach((d,j)=> d.classList.toggle('active', j===idx));
      container.setAttribute('aria-label', container.getAttribute('aria-roledescription') + ' — ' + (slides[idx].dataset.caption || '') );
    }

    function start() { stop(); timer = setInterval(()=> show(idx+1), INTERVAL); }
    function stop(){ if(timer){ clearInterval(timer); timer=null; } }

    prev.addEventListener('click', e => { e.preventDefault(); show(idx-1); stop(); start(); });
    next.addEventListener('click', e => { e.preventDefault(); show(idx+1); stop(); start(); });

    dotsWrap.addEventListener('click', e => {
      if(!e.target.classList.contains('dot')) return;
      const i = Number(e.target.dataset.index);
      show(i); stop(); start();
    });

    slides.forEach(slide => {
      const img = slide.querySelector('img');
      if(!img) return;
      img.addEventListener('click', () => openLightbox(img.src, img.alt || slide.dataset.caption || ''));
      img.setAttribute('tabindex','0');
      img.addEventListener('keydown', e => { if(e.key === 'Enter') openLightbox(img.src, img.alt || slide.dataset.caption || ''); });
    });

    container.addEventListener('mouseenter', stop);
    container.addEventListener('mouseleave', start);
    container.addEventListener('focusin', stop);
    container.addEventListener('focusout', start);

    container.addEventListener('keydown', e => {
      if(e.key === 'ArrowLeft') { e.preventDefault(); show(idx-1); stop(); start(); }
      if(e.key === 'ArrowRight'){ e.preventDefault(); show(idx+1); stop(); start(); }
    });

    show(0);
    start();
  });

  // Lightbox
  const lightbox = document.getElementById('lightbox');
  const lbImg = lightbox.querySelector('img');
  const closeBtn = lightbox.querySelector('.close-lightbox');

  function openLightbox(src, alt='') {
    lbImg.src = src;
    lbImg.alt = alt;
    lightbox.style.display = 'flex';
    lightbox.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
    closeBtn.focus();
  }
  function closeLightbox(){
    lightbox.style.display = 'none';
    lbImg.src = '';
    lightbox.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
  }

  window.openLightbox = openLightbox;
  window.closeLightbox = closeLightbox;
  closeBtn.addEventListener('click', closeLightbox);
  lightbox.addEventListener('click', e => { if(e.target === lightbox) closeLightbox(); });
  window.addEventListener('keydown', e => { if(e.key === 'Escape' && lightbox.style.display === 'flex') closeLightbox(); });
})();
</script>

<footer style="text-align:center;padding:20px;color:var(--muted);border-top:1px solid rgba(255,255,255,0.02);">
  &copy; <?=date('Y')?> GMI Student Registration System · <a href="contactus.php" style="color:var(--muted);text-decoration:underline;">Contact Us</a>
</footer>
</body>
</html>
