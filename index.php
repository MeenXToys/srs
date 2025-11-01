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
/* Sliders side by side */
.sliders-row {
  display: flex;
  gap: 40px;
  justify-content: center;
  margin: 40px 0;
  flex-wrap: wrap;
}
.slider-container {
  position: relative;
  width: 400px; /* same as old slider */
  text-align: center;
}
.slider-slides {
  display: none;
  position: relative;
}
.slider-slides img {
  width: 100%;
  height: 250px;
  object-fit: cover;
  border-radius: 8px;
  cursor: pointer;
}

/* Caption below image */
.caption {
  margin-top: 5px;
  font-weight: bold;
  color: #333;
  font-size: 0.95rem;
  transition: 0.3s;
}
.slider-slides:hover .caption {
  color: #004aad;
}

/* Arrows */
.prev, .next {
  cursor: pointer;
  position: absolute;
  top: 45%;
  padding: 6px;
  font-weight: bold;
  font-size: 20px;
  color: #fff;
  background-color: rgba(0,0,0,0.4);
  border-radius: 3px;
  user-select: none;
  transition: 0.3s;
}
.next { right: 0; }
.prev { left: 0; }
.prev:hover, .next:hover { background-color: rgba(0,0,0,0.6); }

/* Dots overlay on image, smaller and semi-transparent */
.dot-container {
  position: absolute;
  bottom: 10px; /* slightly above bottom of image */
  width: 100%;
  text-align: center;
  z-index: 10;
}
.dot {
  cursor: pointer;
  height: 6px;          /* smaller */
  width: 6px;           /* smaller */
  margin: 2px 2px;
  background-color: rgba(255,255,255,0.4); /* more transparent */
  border-radius: 50%;
  display: inline-block;
  transition: background-color 0.3s;
}
.active, .dot:hover {
  background-color: rgba(255,255,255,0.9);
}

/* Fade animation */
.fade { animation: fadeEffect 1s; }
@keyframes fadeEffect { from {opacity: .4} to {opacity: 1} }

/* LIGHTBOX */
#lightbox {
  display:none;
  position:fixed;
  top:0;
  left:0;
  width:100%;
  height:100%;
  background: rgba(0,0,0,0.8);
  justify-content:center;
  align-items:center;
  z-index:1000;
}
#lightbox img {
  max-width:90%;
  max-height:90%;
  border-radius:8px;
}
.close-lightbox {
  position:absolute;
  top:20px;
  right:30px;
  font-size:36px;
  color:#fff;
  cursor:pointer;
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
    <a href="registration.php" class="btn btn-primary">Register Now</a>
    <a href="login.php" class="btn btn-secondary">Log In</a>
    <a href="timetable.php" class="btn btn-third">Timetable</a>
  </div>
</div>

<!-- TWO SLIDERS SIDE BY SIDE -->
<div class="sliders-row">
  <!-- GMI Events -->
  <div class="slider-container">
    <h3>GMI Events</h3>
    <div class="slider-slides fade">
      <img src="img/talentshow.jpg" alt="Activity 1">
      <div class="caption">Talent Show 2025</div>
    </div>
    <div class="slider-slides fade">
      <img src="img/storytelling_competition.jpg" alt="Activity 2">
      <div class="caption">Storytelling Competition</div>
    </div>
    <div class="slider-slides fade">
      <img src="img/nightmarket.jpg" alt="Activity 3">
      <div class="caption">SRA Night Market</div>
    </div>
     <div class="slider-slides fade">
      <img src="img/minicarnival.jpg" alt="Activity 1">
      <div class="caption">Mini Sports Carnival</div>
    </div>
    <a class="prev" onclick="plusSlides(-1,0)">&#10094;</a>
    <a class="next" onclick="plusSlides(1,0)">&#10095;</a>
    <div class="dot-container">
      <span class="dot" onclick="currentSlide(1,0)"></span>
      <span class="dot" onclick="currentSlide(2,0)"></span>
      <span class="dot" onclick="currentSlide(3,0)"></span>
    </div>
  </div>

  <!-- Life in GMI -->
  <div class="slider-container">
    <h3>Life in GMI</h3>
    <div class="slider-slides fade">
      <img src="img/openday.jpg" alt="Life 1">
      <div class="caption">GMI Open Day</div>
    </div>
    <div class="slider-slides fade">
      <img src="img/raya.jpg" alt="Life 2">
      <div class="caption">Raya with CBS6</div>
    </div>
    <div class="slider-slides fade">
      <img src="img/futsal.jpg" alt="Life 3">
      <div class="caption">Futsal Revolution Day</div>
    </div>
     <div class="slider-slides fade">
      <img src="img/penang.jpg" alt="Life 3">
      <div class="caption">Penang Trip</div>
    </div>
    <a class="prev" onclick="plusSlides(-1,1)">&#10094;</a>
    <a class="next" onclick="plusSlides(1,1)">&#10095;</a>
    <div class="dot-container">
      <span class="dot" onclick="currentSlide(1,1)"></span>
      <span class="dot" onclick="currentSlide(2,1)"></span>
      <span class="dot" onclick="currentSlide(3,1)"></span>
    </div>
  </div>
</div>

<!-- FEATURES -->
<section>
  <h2>Features</h2>
  <div class="grid">
    <div class="card">
      <i class="fa-solid fa-file-pen"></i>
      <h3>Easy Enrollment</h3>
      <p>Fill out forms online anytime, anywhere with ease.</p>
    </div>
    <div class="card">
      <i class="fa-solid fa-lock"></i>
      <h3>Secure Data</h3>
      <p>Protects student information with advanced encryption.</p>
    </div>
    <div class="card">
      <i class="fa-solid fa-chart-line"></i>
      <h3>Track Applications</h3>
      <p>Check your registration status instantly with real-time updates.</p>
    </div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section>
  <h2>How It Works</h2>
  <div class="grid">
    <div class="card">
      <i class="fa-solid fa-user"></i>
      <h3>Create or Log In</h3>
      <p>Sign up or log in to access your student portal.</p>
    </div>
    <div class="card">
      <i class="fa-solid fa-clipboard-list"></i>
      <h3>Fill in Registration Forms</h3>
      <p>Complete your student details securely online.</p>
    </div>
    <div class="card">
      <i class="fa-solid fa-check-circle"></i>
      <h3>Confirm and Get Approval</h3>
      <p>Submit and wait for confirmation from administration.</p>
    </div>
  </div>
</section>

<!-- LIGHTBOX -->
<div id="lightbox">
  <span class="close-lightbox" onclick="closeLightbox()">&times;</span>
  <img src="" alt="Enlarged Image">
</div>

<script>
let slideIndexes = [1,1];
showSlides(1,0);
showSlides(1,1);

function plusSlides(n, slider) {
  showSlides(slideIndexes[slider] += n, slider);
}

function currentSlide(n, slider) {
  showSlides(slideIndexes[slider] = n, slider);
}

function showSlides(n, slider) {
  let slides = document.getElementsByClassName("slider-container")[slider].getElementsByClassName("slider-slides");
  let dots = document.getElementsByClassName("slider-container")[slider].getElementsByClassName("dot");
  if (n > slides.length) {slideIndexes[slider] = 1}
  if (n < 1) {slideIndexes[slider] = slides.length}
  for (let i = 0; i < slides.length; i++) slides[i].style.display = "none";
  for (let i = 0; i < dots.length; i++) dots[i].className = dots[i].className.replace(" active", "");
  slides[slideIndexes[slider]-1].style.display = "block";
  dots[slideIndexes[slider]-1].className += " active";
  slides[slideIndexes[slider]-1].classList.add("fade");
}

// Auto-slide every 4 seconds for both sliders
setInterval(() => { plusSlides(1,0); }, 4000);
setInterval(() => { plusSlides(1,1); }, 4000);

// LIGHTBOX
const lightbox = document.getElementById('lightbox');
const lightboxImg = lightbox.querySelector('img');
document.querySelectorAll('.slider-slides img').forEach(img => {
  img.addEventListener('click', () => {
    lightbox.style.display = 'flex';
    lightboxImg.src = img.src;
  });
});
function closeLightbox() { lightbox.style.display = 'none'; }
lightbox.addEventListener('click', e => { if(e.target === lightbox) closeLightbox(); });
</script>

<!-- FOOTER -->
<footer>
  &copy; <?=date('Y')?> GMI Student Registration System | <a href="contactus.php">Contact Us</a>
</footer>
</body>
</html>
