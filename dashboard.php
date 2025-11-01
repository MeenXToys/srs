<?php
require_once 'config.php';
require_login();

$stmt = $pdo->prepare("
    SELECT s.FullName, c.Semester, c.Class_Name, u.Email, u.UserID
    FROM student s
    LEFT JOIN class c ON s.ClassID = c.ClassID
    LEFT JOIN user u ON s.UserID = u.UserID
    WHERE u.UserID = ?
");
$stmt->execute([$_SESSION['user']['UserID']]);
$profile = $stmt->fetch() ?: [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>GMI Student Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="img/favicon.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap');

    :root {
      --bg: #0a0f1c;
      --card: rgba(30,41,59,0.6);
      --accent: #38bdf8;
      --accent2: #a855f7;
      --text: #f8fafc;
      --muted: #94a3b8;
      --shadow: 0 8px 30px rgba(0,0,0,0.6);
    }

    body {
      font-family: "Poppins", sans-serif;
      background: radial-gradient(circle at top left, #111827, #0a0f1c 70%);
      color: var(--text);
      margin: 0;
      padding: 0;
      overflow-x: hidden;
      animation: fadeIn 1.2s ease;
    }

    @keyframes fadeIn {
      from {opacity: 0; transform: translateY(20px);}
      to {opacity: 1; transform: translateY(0);}
    }

    main.page {
      padding: 2rem;
      max-width: 1300px;
      margin: auto;
    }

    /* Header section: fill top nicely, no empty space */
    .page-header {
      text-align: center;
      padding: 2rem 2rem 2.5rem; /* less top space */
      margin-bottom: 3rem;
      background: linear-gradient(135deg, var(--accent2), var(--accent));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .page-header h1 {
      font-size: 4rem; /* bigger dashboard title */
      font-weight: 800;
      letter-spacing: 1px;
      margin: 0;
    }

    .page-header p {
      font-size: 1.2rem;
      color: var(--muted);
      margin-top: 0.5rem;
    }

    /* Spacing between boxes */
    .dashboard-section {
      display: grid;
      gap: 3rem;
      grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
      margin-bottom: 3rem;
    }

    .dashboard-section + .dashboard-section {
      margin-top: 2rem;
    }

    .card {
      background: var(--card);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 16px;
      padding: 1.8rem;
      box-shadow: var(--shadow);
      backdrop-filter: blur(14px);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .card:hover {
      transform: translateY(-8px);
      box-shadow: 0 10px 40px rgba(56,189,248,0.3);
    }

    .card h2 {
      font-size: 1.4rem;
      color: var(--accent);
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 1rem;
    }

    .card ul {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    .card li {
      margin: 0.5rem 0;
      color: var(--text);
    }

    .profile-container {
      display: flex;
      align-items: center;
      gap: 1.5rem;
      flex-wrap: wrap;
    }

    .profile-pic {
      position: relative;
      width: 120px;
      height: 120px;
      border-radius: 50%;
      overflow: hidden;
      border: 3px solid var(--accent);
      box-shadow: 0 0 15px rgba(56,189,248,0.4);
      background: rgba(255,255,255,0.05);
    }

    .profile-pic img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .upload-btn {
      position: absolute;
      bottom: 4px;
      right: 4px;
      background: var(--accent2);
      color: white;
      border: none;
      border-radius: 50%;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: 0.3s;
    }

    .upload-btn:hover {
      background: var(--accent);
      transform: scale(1.1);
    }

    .btn {
      display: inline-block;
      padding: 0.6rem 1.2rem;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      backdrop-filter: blur(6px);
    }

    .btn-edit {
      background: linear-gradient(135deg, var(--accent), var(--accent2));
    }
    .btn-edit:hover {opacity: 0.9;}

    .btn-logout {
      background: linear-gradient(135deg, #ef4444, #b91c1c);
    }
    .btn-logout:hover {opacity: 0.9;}

    .actions {
      margin-top: 1rem;
      display: flex;
      gap: 10px;
    }

    .link-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 1rem;
    }

    .link-grid a {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      text-decoration: none;
      font-weight: 600;
      background: linear-gradient(135deg, rgba(56,189,248,0.1), rgba(168,85,247,0.1));
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 10px;
      color: var(--text);
      padding: 0.8rem;
      transition: all 0.3s ease;
    }

    .link-grid a:hover {
      background: linear-gradient(135deg, var(--accent2), var(--accent));
      transform: scale(1.05);
    }

    .events-table {
      width: 100%;
      border-collapse: collapse;
      color: var(--text);
      margin-top: 1rem;
    }

    .events-table th, .events-table td {
      padding: 0.8rem;
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .events-table th {
      color: var(--accent);
      text-align: left;
      font-weight: 600;
    }

    .dashboard-gallery {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1rem;
      margin-top: 1rem;
    }

    .dashboard-gallery img {
      width: 100%;
      height: 180px;
      object-fit: cover;
      border-radius: 12px;
      transition: transform 0.3s ease;
    }

    .dashboard-gallery img:hover {
      transform: scale(1.05);
    }

    footer.page-footer {
      text-align: center;
      padding: 2rem;
      color: var(--muted);
      font-size: 0.9rem;
      margin-top: 3rem;
    }

    .glow {
      text-shadow: 0 0 10px var(--accent), 0 0 20px var(--accent2);
    }

  </style>
</head>
<body>
  <?php include 'nav.php'; ?>

  <main class="page">
    <header class="page-header">
      <h1 class="glow">STUDENT DASHBOARD</h1>
      <p>Welcome, <strong><?=htmlspecialchars($profile['FullName'] ?? $_SESSION['user']['Email'])?></strong></p>
    </header>

    <section class="dashboard-section">
      <div class="card">
        <h2><i class="fa-solid fa-user"></i> Profile Overview</h2>

        <div class="profile-container">
          <div class="profile-pic">
            <img src="img/default-profile.png" alt="Profile Picture">
            <button class="upload-btn" title="Change photo"><i class="fa-solid fa-camera"></i></button>
          </div>

          <ul>
            <li><strong>Name:</strong> <?=htmlspecialchars($profile['FullName'] ?? '-')?></li>
            <li><strong>Email:</strong> <?=htmlspecialchars($profile['Email'] ?? '-')?></li>
            <li><strong>Class:</strong> <?=htmlspecialchars($profile['Class_Name'] ?? '-')?></li>
            <li><strong>Semester:</strong> <?=htmlspecialchars($profile['Semester'] ?? '-')?></li>
          </ul>
        </div>

        <div class="actions">
          <a href="editprofile.php" class="btn btn-edit">Edit Profile</a>
          <a href="logout.php" class="btn btn-logout">Log Out</a>
        </div>
      </div>

      <div class="card">
        <h2><i class="fa-solid fa-chart-line"></i> Academic Summary</h2>
        <ul>
          <li><strong>GPA:</strong> 3.72</li>
          <li><strong>Completed Credits:</strong> 84</li>
          <li><strong>Attendance:</strong> 95%</li>
          <li><strong>Status:</strong> Active</li>
        </ul>
      </div>
    </section>

    <section class="dashboard-section">
      <div class="card">
        <h2><i class="fa-solid fa-bell"></i> Notifications</h2>
        <ul>
          <li>[29 Oct] Course registration opens next week.</li>
          <li>[22 Oct] Library hours extended during exams.</li>
          <li>[15 Oct] New career workshop — register online.</li>
        </ul>
      </div>

      <div class="card">
        <h2><i class="fa-solid fa-link"></i> Quick Links</h2>
        <div class="link-grid">
          <a href="#"><i class="fa-solid fa-calendar-days"></i> Timetable</a>
          <a href="#"><i class="fa-solid fa-book-open"></i> E-Learning</a>
          <a href="#"><i class="fa-solid fa-user-check"></i> Attendance</a>
          <a href="#"><i class="fa-solid fa-graduation-cap"></i> Grades</a>
          <a href="#"><i class="fa-solid fa-bullhorn"></i> Events</a>
          <a href="#"><i class="fa-solid fa-envelope"></i> Contact</a>
        </div>
      </div>
    </section>

    <section class="dashboard-section">
      <div class="card" style="grid-column:1/-1;">
        <h2><i class="fa-solid fa-camera"></i> Campus Life Highlights</h2>
        <div class="dashboard-gallery">
          <img src="img/campus1.jpg" alt="Campus life">
          <img src="img/campus2.jpg" alt="Workshop">
          <img src="img/campus3.jpg" alt="Students activity">
          <img src="img/campus4.jpg" alt="Graduation">
        </div>
      </div>

      <div class="card" style="grid-column:1/-1;">
        <h2><i class="fa-solid fa-calendar-week"></i> Upcoming Events</h2>
        <table class="events-table">
          <tr><th>Date</th><th>Event</th><th>Location</th><th></th></tr>
          <tr><td>5 Nov</td><td>AI in Manufacturing</td><td>Auditorium A</td><td><a href="#">Details</a></td></tr>
          <tr><td>10 Nov</td><td>Entrepreneurship Bootcamp</td><td>Hall 3</td><td><a href="#">Register</a></td></tr>
          <tr><td>18 Nov</td><td>Midterm Exams</td><td>Campus</td><td><a href="#">View</a></td></tr>
        </table>
      </div>
    </section>

    <footer class="page-footer">
      © <?=date('Y')?> German-Malaysian Institute | Designed for the Future ⚡
    </footer>
  </main>
</body>
</html>
