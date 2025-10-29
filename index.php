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

  <!-- FOOTER -->
  <footer>
    &copy; <?=date('Y')?> GMI Student Registration System | <a href="contactus.php">Contact Us</a>
  </footer>
</body>
</html>
