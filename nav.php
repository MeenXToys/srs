<?php
// nav.php - ordinary user navbar (place on non-admin pages)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// current user info (from session)
$user = $_SESSION['user'] ?? null;
$loggedIn = !empty($user);
$role = $user['Role'] ?? null;
$isAdmin = $loggedIn && strcasecmp($role, 'Admin') === 0;

// safe echo helper

?>
<link rel="icon" href="img/favicon.png" type="image/png">
<link rel="stylesheet" href="style.css">

<nav class="navbar" aria-label="Main navigation">
  <div class="logo">
    <a href="index.php" title="Main Menu">
      <img src="img/favicon.png" alt="GMI Logo" class="nav-logo">
    </a>
  </div>

  <div class="nav-links">
    <?php if ($loggedIn): ?>
      <a href="dashboard.php">Dashboard</a>
      <a href="editprofile.php">Edit Profile</a>
      <a href="timetable.php">Timetable</a>

      <?php if ($isAdmin): ?>
        <!-- Optional: simple admin link (only visible to admins) -->
        <a href="admin/index.php">Admin</a>
      <?php endif; ?>

      <a href="logout.php">Log Out</a>
      <a href="contactus.php" class="contact-button">Contact Us</a>
    <?php else: ?>
      <a href="index.php">Main Menu</a>
      <a href="registration.php">Register</a>
      <a href="login.php">Log In</a>
      <a href="contactus.php" class="contact-button">Contact Us</a>
    <?php endif; ?>
  </div>
</nav>
