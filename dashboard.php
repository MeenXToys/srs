<?php
require_once 'config.php';
require_login();

$stmt = $pdo->prepare("SELECT u.UserID, u.Email, u.Role, s.FullName, s.IC_Number, s.Phone, s.Semester, c.Class_Name
                       FROM `user` u
                       LEFT JOIN student s ON s.UserID = u.UserID
                       LEFT JOIN class c ON c.ClassID = s.ClassID
                       WHERE u.UserID = ?");
$stmt->execute([$_SESSION['user']['UserID']]);
$profile = $stmt->fetch() ?: [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dashboard — GMI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <?php include 'nav.php'; ?>

  <main class="page">
    <header class="page-header"><h1>STUDENT PORTAL GMI</h1></header>

    <section class="dashboard-card">
      <div class="dashboard-info">
        <h2>Welcome Back, <?=htmlspecialchars($profile['FullName'] ?? $_SESSION['user']['Email'])?></h2>
        <div class="info-box">
          <p><strong>Name:</strong> <?=htmlspecialchars($profile['FullName'] ?? '-')?></p>
          <p><strong>Student ID:</strong> <?=htmlspecialchars($profile['UserID'] ?? '-')?></p>
          <p><strong>Class:</strong> <?=htmlspecialchars($profile['Class_Name'] ?? '-')?></p>
        </div>
        <div class="dashboard-actions">
          <a href="timetable.php" class="btn btn-primary">View Timetable</a>
          <a href="editprofile.php" class="btn btn-edit">Edit Profile</a>
          <a href="logout.php" class="btn btn-logout">Log Out</a>
        </div>
      </div>

      <div class="shield-image">
        <img src="img/logodash.png" alt="GMI shield logo">
      </div>
    </section>

    <section class="dashboard-extra">
      <div class="extra-card">
        <h3>Attendance</h3>
        <p>Check your attendance records and status.</p>
        <a href="#">View Attendance</a>
      </div>
      <div class="extra-card">
        <h3>Notification Centre</h3>
        <p>No new notifications.</p>
      </div>

      <div class="extra-card">
        <h3>E-Learning Materials</h3>
        <p>Access lecture notes, assignments, and study guides.</p>
        <a href="#">Go to Materials</a>
      </div>
      <div class="extra-card">
        <h3>Resources Hub</h3>
        <p>Explore useful tools and resources for students.</p>
        <a href="#">Visit Hub</a>
      </div>

      <div class="extra-card" style="grid-column:1/-1;">
        <h3>Activities at GMI</h3>
        <div class="activities-grid">
          <div class="activity-item"><img src="img/futsal.jpg" alt=""><p>Futsal Revolution Tournament</p></div>
          <div class="activity-item"><img src="img/bengkel.jpg" alt=""><p>Bengkel Kewangan Islam</p></div>
          <div class="activity-item"><img src="img/raya.jpg" alt=""><p>Raya Celebration GMI 2025</p></div>
          <div class="activity-item"><img src="img/dean.jpg" alt=""><p>Dean List Recognition</p></div>
          <div class="activity-item"><img src="img/openday.jpg" alt=""><p>Open Day GMI 2025</p></div>
          <div class="activity-item"><img src="img/trip.jpg" alt=""><p>Trip to Penang</p></div>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
