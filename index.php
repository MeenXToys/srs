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
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ====== Page-specific styles (sliders + hero) ====== */
.hero{
  text-align:center;
  padding:48px 18px 28px;
  color: #e6eef8;
}
.hero h1{ font-size:2rem; margin:0 0 8px; letter-spacing:1px; }
.hero p{ color: #aab8c8; margin:6px 0 18px; }

/* Buttons row under hero */
.hero-buttons{ display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:12px; }
.btn-primary{ background:linear-gradient(90deg,#004aad,#6366f1); color:#fff; padding:10px 16px; border-radius:8px; text-decoration:none; font-weight:700; }
.btn-secondary{ background:transparent; border:1px solid rgba(255,255,255,0.08); color:#fff; padding:10px 16px; border-radius:8px; text-decoration:none; font-weight:700; }
.btn-third{ background:linear-gradient(90deg,#06b6d4,#0ea5a1); color:#fff; padding:10px 16px; border-radius:8px; text-decoration:none; font-weight:700; }

/* Sliders row */
.sliders-row {
  display: flex;
  gap: 28px;
  justify-content: center;
  margin: 32px 12px;
  flex-wrap: wrap;
}

/* Each slider */
.slider-container {
  position: relative;
  width: 420px;
  max-width: calc(100vw - 40px);
  text-align: left;
  color:#fff;
}
.slider-header { font-weight:800; margin:6px 0 10px; color:#e6eef8; }

/* Slide item */
.slider-slides {
  display: none;
  position: relative;
  border-radius:8px;
  overflow:hidden;
  background:linear-gradient(180deg, rgba(0,0,0,0.06), rgba(0,0,0,0.12));
}
.slider-slides img {
  width: 100%;
  height: 260px;
  object-fit: cover;
  display:block;
  cursor: pointer;
}

/* caption overlay bottom */
.caption {
  padding:10px;
  background: linear-gradient(180deg, rgba(0,0,0,0.00), rgba(0,0,0,0.45));
  color: #e6eef8;
  font-weight:700;
  position: absolute;
  left:0; right:0; bottom:0;
  font-size:0.96rem;
}

/* Arrows */
.prev, .next {
  cursor: pointer;
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  padding: 8px 10px;
  font-weight: bold;
  font-size: 18px;
  color: #fff;
  background-color: rgba(0,0,0,0.35);
  border-radius: 6px;
  user-select: none;
  transition: background-color 0.15s;
  z-index: 8;
}
.next { right: 8px; }
.prev { left: 8px; }
.prev:hover, .next:hover { background-color: rgba(0,0,0,0.6); }

/* Dots */
.dot-container {
  position: absolute;
  bottom: 12px;
  width: 100%;
  text-align: center;
  z-index: 9;
}
.dot {
  cursor: pointer;
  height: 8px;
  width: 8px;
  margin: 0 4px;
  background-color: rgba(255,255,255,0.45);
  border-radius: 50%;
  display: inline-block;
  transition: background-color 0.2s, transform 0.15s;
}
.dot.active, .dot:hover {
  background-color: #ffffff;
  transform: scale(1.15);
}

/* Fade animation */
.fade { animation: fadeEffect .6s ease-in-out; }
@keyframes fadeEffect { from {opacity:.4} to {opacity:1} }

/* LIGHTBOX */
#lightbox {
  display:none;
  position:fixed;
  top:0;left:0;width:100%;height:100%;
  background: rgba(0,0,0,0.85);
  justify-content:center;align-items:center;z-index:1000;
}
#lightbox img { max-width:92%; max-height:92%; border-radius:8px; }
.close-lightbox { position:absolute; top:18px; right:22px; font-size:36px; color:#fff; cursor:pointer; }

/* responsive */
@media (max-width:960px){
  .slider-container { width: 46%; }
}
@media (max-width:700px){
  .sliders-row { flex-direction:column; align-items:center; gap:18px; }
  .slider-container { width: 92%; }
  .slider-slides img { height:220px; }
}
</style>
</head>
<body>
<?php include 'nav.php'; ?>

<!-- HERO SECTION -->
<div class="hero">
  <h1>STUDENT REGISTRATION SYSTEM</h1>
  <p>Register online in minutes. Secure, fast, and paperless!</p>
  <div class="hero-buttons">
    <a href="registration.php" class="btn-primary">Register Now</a>
    <a href="login.php" class="btn-secondary">Log In</a>
    <a href="timetable.php" class="btn-third">Timetable</a>
  </div>
</div>

<!-- TWO SLIDERS SIDE BY SIDE -->
<div class="sliders-row" aria-label="Site sliders">

  <!-- Slider: GMI Events -->
  <div class="slider-container" data-slider-id="events" tabindex="0" aria-roledescription="carousel" aria-label="GMI Events">
    <div class="slider-header">GMI Events</div>

    <!-- slides: add/remove slides here -->
    <div class="slider-slides" data-caption="Talent Show 2025">
      <img src="img/talentshow.jpg" alt="Talent Show 2025">
      <div class="caption">Talent Show 2025</div>
    </div>

    <div class="slider-slides" data-caption="Storytelling Competition">
      <img src="img/storytelling_competition.jpg" alt="Storytelling Competition">
      <div class="caption">Storytelling Competition</div>
    </div>

    <div class="slider-slides" data-caption="SRA Night Market">
      <img src="img/nightmarket.jpg" alt="SRA Night Market">
      <div class="caption">SRA Night Market</div>
    </div>

    <div class="slider-slides" data-caption="Mini Sports Carnival">
      <img src="img/minicarnival.jpg" alt="Mini Sports Carnival">
      <div class="caption">Mini Sports Carnival</div>
    </div>

    <a class="prev" aria-hidden="true" data-action="prev">❮</a>
    <a class="next" aria-hidden="true" data-action="next">❯</a>

    <div class="dot-container" aria-hidden="true" data-dots></div>
  </div>

  <!-- Slider: Life in GMI -->
  <div class="slider-container" data-slider-id="life" tabindex="0" aria-roledescription="carousel" aria-label="Life in GMI">
    <div class="slider-header">Life in GMI</div>

    <div class="slider-slides" data-caption="GMI Open Day">
      <img src="img/openday.jpg" alt="GMI Open Day">
      <div class="caption">GMI Open Day</div>
    </div>

    <div class="slider-slides" data-caption="Raya with CBS6">
      <img src="img/raya.jpg" alt="Raya with CBS6">
      <div class="caption">Raya with CBS6</div>
    </div>

    <div class="slider-slides" data-caption="Futsal Revolution Day">
      <img src="img/futsal.jpg" alt="Futsal Revolution Day">
      <div class="caption">Futsal Revolution Day</div>
    </div>

    <div class="slider-slides" data-caption="Penang Trip">
      <img src="img/penang.jpg" alt="Penang Trip">
      <div class="caption">Penang Trip</div>
    </div>

    <a class="prev" aria-hidden="true" data-action="prev">❮</a>
    <a class="next" aria-hidden="true" data-action="next">❯</a>

    <div class="dot-container" aria-hidden="true" data-dots></div>
  </div>

</div>

<!-- FEATURES -->
<section class="container" style="margin-top:20px;">
  <h2 style="color:#e6eef8;margin-bottom:10px;">Features</h2>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px;">
    <div class="card">
      <i class="fa-solid fa-file-pen" style="font-size:20px"></i>
      <h3>Easy Enrollment</h3>
      <p>Fill out forms online anytime, anywhere with ease.</p>
    </div>
    <div class="card">
      <i class="fa-solid fa-lock" style="font-size:20px"></i>
      <h3>Secure Data</h3>
      <p>Protects student information with advanced encryption.</p>
    </div>
    <div class="card">
      <i class="fa-solid fa-chart-line" style="font-size:20px"></i>
      <h3>Track Applications</h3>
      <p>Check your registration status instantly with real-time updates.</p>
    </div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section class="container" style="margin-top:22px;">
  <h2 style="color:#e6eef8;margin-bottom:10px;">How It Works</h2>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px;">
    <div class="card">
      <i class="fa-solid fa-user" style="font-size:20px"></i>
      <h3>Create or Log In</h3>
      <p>Sign up or log in to access your student portal.</p>
    </div>
    <div class="card">
      <i class="fa-solid fa-clipboard-list" style="font-size:20px"></i>
      <h3>Fill in Registration Forms</h3>
      <p>Complete your student details securely online.</p>
    </div>
    <div class="card">
      <i class="fa-solid fa-check-circle" style="font-size:20px"></i>
      <h3>Confirm and Get Approval</h3>
      <p>Submit and wait for confirmation from administration.</p>
    </div>
  </div>
</section>

<!-- LIGHTBOX -->
<div id="lightbox" role="dialog" aria-modal="true" aria-hidden="true">
  <span class="close-lightbox" aria-label="Close" onclick="closeLightbox()">&times;</span>
  <img src="" alt="Enlarged Image">
</div>

<script>
/* ====== Slider logic (vanilla, robust) ====== */
(function(){
  const sliders = Array.from(document.querySelectorAll('.slider-container'));
  const sliderState = new Map();

  sliders.forEach((container, idx) => {
    const slides = Array.from(container.querySelectorAll('.slider-slides'));
    const dotsWrap = container.querySelector('[data-dots]');
    const prevBtn = container.querySelector('[data-action="prev"]');
    const nextBtn = container.querySelector('[data-action="next"]');

    // Build dots dynamically
    dotsWrap.innerHTML = '';
    slides.forEach((s, i) => {
      const dot = document.createElement('span');
      dot.className = 'dot';
      dot.setAttribute('role','button');
      dot.setAttribute('aria-label','Slide ' + (i+1));
      dot.dataset.index = i;
      dotsWrap.appendChild(dot);
    });

    // state
    let current = 0;
    let intervalId = null;
    const INTERVAL_MS = 4000;

    function show(index) {
      current = (index + slides.length) % slides.length;
      slides.forEach((s,i) => {
        s.style.display = (i === current) ? 'block' : 'none';
        s.classList.toggle('fade', i === current);
      });
      const dots = Array.from(dotsWrap.children);
      dots.forEach((d,i) => d.classList.toggle('active', i === current));
    }

    // next/prev handlers
    function next() { show(current + 1); }
    function prev() { show(current - 1); }

    // click handlers
    prevBtn.addEventListener('click', (e) => { e.preventDefault(); prev(); resetTimer(); });
    nextBtn.addEventListener('click', (e) => { e.preventDefault(); next(); resetTimer(); });

    dotsWrap.addEventListener('click', (e) => {
      if (!e.target.classList.contains('dot')) return;
      const i = parseInt(e.target.dataset.index,10);
      show(i);
      resetTimer();
    });

    // clicking image opens lightbox
    slides.forEach(s => {
      const img = s.querySelector('img');
      if (!img) return;
      img.addEventListener('click', () => openLightbox(img.src, img.alt || s.dataset.caption || ''));
    });

    // pause on hover/focus
    container.addEventListener('mouseenter', pauseTimer);
    container.addEventListener('mouseleave', resumeTimer);
    container.addEventListener('focusin', pauseTimer);
    container.addEventListener('focusout', resumeTimer);

    // keyboard navigation (left/right)
    container.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowLeft') { prev(); resetTimer(); ev.preventDefault(); }
      if (ev.key === 'ArrowRight') { next(); resetTimer(); ev.preventDefault(); }
    });

    // timer functions
    function startTimer() {
      stopTimer();
      intervalId = setInterval(next, INTERVAL_MS);
      sliderState.set(container, { intervalId });
    }
    function stopTimer() { if (intervalId) { clearInterval(intervalId); intervalId = null; } sliderState.set(container, { intervalId: null }); }
    function resetTimer(){ stopTimer(); startTimer(); }
    function pauseTimer(){ stopTimer(); }
    function resumeTimer(){ startTimer(); }

    // init
    show(0);
    startTimer();
  });

  // Lightbox
  const lightbox = document.getElementById('lightbox');
  const lightboxImg = lightbox.querySelector('img');
  function openLightbox(src, alt){
    lightboxImg.src = src;
    lightboxImg.alt = alt || '';
    lightbox.style.display = 'flex';
    lightbox.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
  }
  window.openLightbox = openLightbox;
  function closeLightbox(){
    lightbox.style.display = 'none';
    lightboxImg.src = '';
    lightbox.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
  }
  window.closeLightbox = closeLightbox;
  lightbox.addEventListener('click', (e) => { if (e.target === lightbox) closeLightbox(); });

  // expose to window (for debug)
  window.__gmi_sliders = { count: document.querySelectorAll('.slider-container').length };
})();
</script>

<!-- FOOTER -->
<footer style="text-align:center;padding:22px 12px;color:#aab8c8;">
  &copy; <?=date('Y')?> GMI Student Registration System · <a href="contactus.php">Contact Us</a>
</footer>
</body>
</html>
