<?php require_once 'config.php'; ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>GMI Student Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <?php include 'nav.php'; ?>

  <div class="hero" style="height:80vh; background: linear-gradient(rgba(19,26,34,0.85), rgba(19,26,34,0.85)), url('img/indexgmi.png') no-repeat center/cover;">
    <h1>STUDENT REGISTRATION SYSTEM</h1>
    <p>Register online in minutes. Secure, fast and paperless!</p>
    <div class="hero-buttons">
      <a href="registration.php" class="btn btn-primary">Register Now</a>
      <a href="login.php" class="btn btn-secondary">Log In</a>
      <a href="timetable.php" class="btn btn-third">Timetable</a>
    </div>
  </div>

  <section>
    <h2>Features</h2>
    <div class="grid">
      <div class="card"><i class="fa fa-file-signature"></i><h3>Easy Enrollment</h3><p>Fill out forms online anytime, anywhere.</p></div>
      <div class="card"><i class="fa fa-lock"></i><h3>Secure Data</h3><p>Protects student's info with advanced encryption.</p></div>
      <div class="card"><i class="fa fa-chart-line"></i><h3>Track Applications</h3><p>Check your registration status instantly.</p></div>
    </div>
  </section>

  <section>
    <h2>How It Works</h2>
    <div class="grid">
      <div class="card"><i class="fa fa-user"></i><h3>Create or Log In</h3><p>Sign up or log in to access the system.</p></div>
      <div class="card"><i class="fa fa-clipboard-list"></i><h3>Fill in Registration Forms</h3><p>Complete your student details securely.</p></div>
      <div class="card"><i class="fa fa-check-circle"></i><h3>Confirm and Get Approval</h3><p>Submit and wait for confirmation.</p></div>
    </div>
  </section>

  <footer>
    &copy; <?=date('Y')?> GMI Student Registration System | <a href="contactus.php">Contact Us</a>
  </footer>
</body>
</html>
