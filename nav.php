<?php
// nav.php - ordinary user navbar (for all non-admin pages)

// Make sure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -----------------------------------------------------------------
   LOGIN DETECTION LOGIC (NO REDIRECT, NO REQUIRE)
   ----------------------------------------------------------------- */

// Check if structured session exists
$user = $_SESSION['user'] ?? null;

// If user not in structured array, check legacy session variables
if (empty($user) && (isset($_SESSION['user_id']) || isset($_SESSION['username']) || isset($_SESSION['name']))) {
    $user = [
        'UserID' => $_SESSION['user_id'] ?? null,
        'Name'   => $_SESSION['username'] ?? $_SESSION['name'] ?? null,
        'Role'   => $_SESSION['role'] ?? null
    ];
}

// Determine login status
$loggedIn = !empty($user) && (!empty($user['UserID']) || !empty($user['Name']));
$role = $user['Role'] ?? null;
$isAdmin = $loggedIn && strcasecmp($role, 'Admin') === 0;

// Safe echo function

?>

<!-- Favicon + Stylesheet -->
<link rel="icon" href="img/favicon.png" type="image/png">
<link rel="stylesheet" href="style.css">

<!-- Navigation Bar -->
<nav class="navbar" aria-label="Main navigation">
  <div class="logo">
    <a href="dashboard.php" title="Main Menu">
      <img src="img/favicon.png" alt="GMI Logo" class="nav-logo">
    </a>
  </div>

  <div class="nav-links">
    <?php if ($loggedIn): ?>
      <!-- Logged-in user menu -->
      <a href="dashboard.php">Dashboard</a>
      <a href="editprofile.php">Edit Profile</a>
      <a href="timetable.php">Timetable</a>

      <?php if ($isAdmin): ?>
        <!-- Admin-only link -->
        <a href="admin/index.php">Admin</a>
      <?php endif; ?>

      <a href="logout.php">Log Out</a>
      <a href="contactus.php" class="contact-button">Contact Us</a>

    <?php else: ?>
      <!-- Guest menu -->
      <a href="index.php">Main Menu</a>
      <a href="registration.php">Register</a>
      <a href="login.php">Log In</a>
      <a href="contactus.php" class="contact-button">Contact Us</a>
    <?php endif; ?>
  </div>
</nav>
