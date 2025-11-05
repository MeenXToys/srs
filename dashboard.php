<?php
require_once 'config.php';
require_login();

// fetch user info (reads Profile_Image from user table)
$stmt = $pdo->prepare("
    SELECT s.FullName, c.Semester, c.Class_Name, u.Email, u.UserID, u.Profile_Image
    FROM user u
    LEFT JOIN student s ON s.UserID = u.UserID
    LEFT JOIN class c ON s.ClassID = c.ClassID
    WHERE u.UserID = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user']['UserID']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// avatar resolution: prefer uploads/filename, otherwise default
$avatar = 'img/default-profile.png';
if (!empty($profile['Profile_Image'])) {
  $candidate = __DIR__ . '/uploads/' . basename($profile['Profile_Image']);
  if (file_exists($candidate)) {
    $avatar = 'uploads/' . basename($profile['Profile_Image']);
  } else {
    $candidate2 = __DIR__ . '/' . ltrim($profile['Profile_Image'], '/');
    if (file_exists($candidate2))
      $avatar = ltrim($profile['Profile_Image'], '/');
  }
}

// ----------------------------------------------------
// METRICS: CALCULATE CGPA FROM DATABASE
// ----------------------------------------------------

// 1. Calculate CGPA (simple average of all semester GPAs)
$cgpaStmt = $pdo->prepare("SELECT ROUND(AVG(GPA), 2) AS cgpa, COUNT(*) AS count_gpa FROM gpa WHERE UserID = :uid");
$cgpaStmt->execute([':uid' => $_SESSION['user']['UserID']]);
$cgpaResult = $cgpaStmt->fetch(PDO::FETCH_ASSOC);

$gpa = (float)($cgpaResult['cgpa'] ?? 0.00); // Set $gpa to calculated CGPA, default to 0.00 if none
// You might also want to display 'N/A' if $gpa is 0.00 and $cgpaResult['count_gpa'] is 0.

// 2. Placeholder/Example Metrics
$credits = 84; // Needs a real query for total completed credits
$attendance = 95; // Needs a real query for overall attendance percentage
$status = 'Active';

$recent = [
  ['time' => '2025-11-02', 'text' => 'Registered for DMTM1 (Sem 1)'],
  ['time' => '2025-10-29', 'text' => 'Course registration opens next week'],
  ['time' => '2025-10-22', 'text' => 'Library hours extended during exams'],
];
// ----------------------------------------------------
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Student Dashboard — GMI</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <style>
    :root {
      --bg: #0b1318;
      --card: #0f1720;
      --muted: #9fb0c6;
      --text: #e9f1fa;
      --accent: #3db7ff;
      --accent2: #7c5cff;
      --success: #22c55e;
      --danger: #ef4444;
      --radius: 14px;
      --shadow: 0 14px 40px rgba(2, 6, 23, 0.6);
    }

    /* base */
    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      background: linear-gradient(180deg, #071022, #07121a 60%);
      color: var(--text);
    }

    a {
      color: inherit;
      text-decoration: none
    }

    /* page container */
    /* replace previous .main-wrap rule with this */
    .main-wrap {
      max-width: 1200px;
      margin: 5px auto 36px;
      /* <- reduced top margin (was 36px) */
      padding: 10px;
    }


    /* header below navbar */
    .header-only {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 24px;
      background: rgba(255, 255, 255, 0.02);
      padding: 18px 22px;
      border-radius: var(--radius);
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .header-left img {
      width: 46px;
      height: 46px;
      object-fit: contain;
    }

    .page-title {
      font-size: 1.55rem;
      font-weight: 800;
      letter-spacing: 0.6px;
    }

    .page-sub {
      color: var(--muted);
      font-size: 0.95rem;
      margin-top: 4px;
    }

    .logout-btn {
      background: linear-gradient(90deg, var(--accent), var(--accent2));
      padding: 10px 18px;
      border-radius: 999px;
      color: #fff;
      font-weight: 700;
      border: none;
      cursor: pointer;
      box-shadow: 0 0 20px rgba(61, 183, 255, 0.25);
      transition: all .3s;
    }

    .logout-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 0 30px rgba(124, 92, 255, 0.45);
    }

    /* dashboard grid */
    .dashboard {
      display: grid;
      grid-template-columns: 340px 1fr;
      gap: 24px
    }

    @media (max-width:980px) {
      .dashboard {
        grid-template-columns: 1fr
      }
    }

    /* sidebar */
    .sidebar {
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0.01));
      border-radius: var(--radius);
      padding: 22px;
      box-shadow: var(--shadow);
      border: 1px solid rgba(255, 255, 255, 0.03);
    }

    .avatar-wrap {
      display: flex;
      justify-content: center;
      margin-top: -36px
    }

    .avatar {
      width: 120px;
      height: 120px;
      border-radius: 999px;
      overflow: hidden;
      border: 4px solid rgba(255, 255, 255, 0.04);
      background: linear-gradient(120deg, var(--accent), var(--accent2));
      box-shadow: 0 10px 30px rgba(60, 130, 240, 0.12);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 999px
    }

    .user-info {
      text-align: center;
      margin-top: 12px
    }

    .user-info h3 {
      font-size: 1.12rem;
      margin-bottom: 6px
    }

    .user-info p {
      color: var(--muted);
      margin: 0;
      font-size: .92rem
    }

    /* small stat tiles inside sidebar */
    .side-stats {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
      margin-top: 16px
    }

    .side-stat {
      background: rgba(255, 255, 255, 0.02);
      padding: 12px;
      border-radius: 10px;
      text-align: center;
      transition: all .18s;
    }

    .side-stat:hover {
      transform: translateY(-4px);
      box-shadow: 0 8px 22px rgba(0, 0, 0, 0.5)
    }

    .side-stat .num {
      font-weight: 800;
      font-size: 1.25rem
    }

    .side-stat .lbl {
      color: var(--muted);
      font-size: .82rem
    }

    /* actions row */
    .side-actions {
      display: flex;
      gap: 10px;
      margin-top: 14px
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 10px;
      font-weight: 700;
      border: 0;
      cursor: pointer;
      text-decoration: none;
      color: #fff;
    }

    .btn-edit {
      background: linear-gradient(90deg, var(--accent), var(--accent2));
      box-shadow: 0 10px 30px rgba(60, 130, 240, 0.12)
    }

    .btn-timetable {
      background: transparent;
      border: 1px solid rgba(255, 255, 255, 0.06);
      color: var(--text)
    }

    /* Quick links area: colored animated buttons */
    .quick-links {
      margin-top: 20px
    }

    .quick-links h4 {
      color: var(--muted);
      margin-bottom: 8px
    }

    .qgrid {
      display: flex;
      flex-direction: column;
      gap: 10px
    }

    .q-btn {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      border-radius: 10px;
      background: rgba(255, 255, 255, 0.02);
      border: 1px solid rgba(255, 255, 255, 0.03);
      color: var(--text);
      font-weight: 700;
      transition: transform .18s, box-shadow .18s, background .18s;
    }

    .q-btn i {
      width: 34px;
      height: 34px;
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 14px
    }

    .q-btn:hover {
      transform: translateY(-6px);
      box-shadow: 0 18px 40px rgba(2, 6, 23, 0.6)
    }

    .q-btn.learn {
      background: linear-gradient(90deg, #1e3a8a66, #1e293b44);
      border-left: 4px solid #2563eb;
    }

    .q-btn.learn i {
      background: #2563eb
    }

    .q-btn.grades {
      background: linear-gradient(90deg, #064e3b66, #042f2e44);
      border-left: 4px solid #10b981;
    }

    .q-btn.grades i {
      background: #10b981
    }

    .q-btn.contact {
      background: linear-gradient(90deg, #6b21a866, #3b076466);
      border-left: 4px solid #8b5cf6;
    }

    .q-btn.contact i {
      background: #8b5cf6
    }

    /* main content */
    .main {
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.01), rgba(255, 255, 255, 0.00));
      padding: 22px;
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      border: 1px solid rgba(255, 255, 255, 0.03)
    }

    .tiles {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px
    }

    @media (max-width:900px) {
      .tiles {
        grid-template-columns: repeat(2, 1fr)
      }
    }

    .tile {
      background: rgba(255, 255, 255, 0.03);
      padding: 14px;
      border-radius: 10px;
      text-align: center
    }

    .tile .num {
      font-weight: 800;
      font-size: 1.5rem
    }

    .tile .lbl {
      color: var(--muted);
      font-size: .9rem
    }

    /* chart + recent area */
    .chart-area {
      display: grid;
      grid-template-columns: 1fr 320px;
      gap: 16px;
      margin-top: 20px
    }

    @media (max-width:980px) {
      .chart-area {
        grid-template-columns: 1fr
      }
    }

    .chart-card,
    .recent-card {
      background: rgba(0, 0, 0, 0.18);
      padding: 14px;
      border-radius: 10px;
    }

    .chart-card h4,
    .recent-card h4 {
      margin: 0 0 8px 0;
      font-weight: 800
    }

    .chart-card canvas {
      width: 100% !important;
      height: 320px !important;
    }

    .recent-list {
      list-style: none;
      padding: 0;
      margin: 0;
      display: flex;
      flex-direction: column;
      gap: 10px
    }

    .recent-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px;
      border-radius: 8px;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0.01));
      transition: all .14s
    }

    .recent-item:hover {
      transform: translateY(-4px);
      box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45)
    }

    .recent-item small {
      display: block;
      color: var(--muted);
      margin-top: 6px
    }

    /* footer */
    .footer {
      margin-top: 20px;
      padding: 14px;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.02);
      text-align: center;
      color: var(--muted)
    }

    .fade-up {
      animation: fadeUp .45s ease both
    }

    @keyframes fadeUp {
      from {
        opacity: 0;
        transform: translateY(8px)
      }

      to {
        opacity: 1;
        transform: none
      }
    }
  </style>
</head>

<body>

  <?php include 'nav.php'; ?>

  <main class="main-wrap fade-up">
    <div class="header-only">
      <div class="header-left">
        <div>
          <div class="page-title">Student Dashboard</div>
          <div class="page-sub">Welcome back,
            <?= htmlspecialchars($profile['FullName'] ?? $_SESSION['user']['Email']) ?></div>
        </div>
      </div>
      <form method="post" action="logout.php" style="margin:0">
        <button class="logout-btn" type="submit"><i class="fa-solid fa-right-from-bracket"></i> Log Out</button>
      </form>
    </div>

    <div class="dashboard">
      <aside class="sidebar fade-up">
        <div class="avatar-wrap">
          <div class="avatar"><img src="<?= htmlspecialchars($avatar) ?>" alt="avatar"></div>
          
        </div>

        <div class="user-info">
          <h3><?= htmlspecialchars($profile['FullName'] ?? 'Student') ?></h3>
          <p><?= htmlspecialchars($profile['Email'] ?? '') ?></p>
          <p style="color:var(--muted);margin-top:6px"><?= htmlspecialchars($profile['Class_Name'] ?? '-') ?> ·
            <?= htmlspecialchars($profile['Semester'] ?? '-') ?></p>
        </div>

        <div class="side-stats">
          <a href="gpa.php" class="tile" style="text-decoration:none;">
  <div class="num"><?= $gpa > 0 ? number_format($gpa, 2) : 'N/A' ?></div>
  <div class="lbl">Current CGPA</div>
</a>
          <div class="side-stat">
            <div class="num"><?= (int) $credits ?></div>
            <div class="lbl">Credits</div>
          </div>
          <div class="side-stat">
            <div class="num"><?= (int) $attendance ?>%</div>
            <div class="lbl">Attendance</div>
          </div>
          <div class="side-stat">
            <div class="num"><?= htmlspecialchars($status) ?></div>
            <div class="lbl">Status</div>
          </div>
        </div>

        <div class="side-actions">
          <a href="editprofile.php" class="btn btn-edit"><i class="fa-solid fa-user-pen"></i> Edit</a>
          <a href="timetable.php" class="btn btn-timetable"><i class="fa-solid fa-calendar-days"></i> Timetable</a>
        </div>

        <div class="quick-links">
          <h4>Quick Links</h4>
          <div class="qgrid">
            <a href="#" class="q-btn learn"><i class="fa-solid fa-book-open"></i> E-Learning</a>
            <a href="gpa.php" class="q-btn grades"><i class="fa-solid fa-graduation-cap"></i> Grades</a>
            <a href="#" class="q-btn contact"><i class="fa-solid fa-envelope"></i> Contact</a>
          </div>
        </div>
      </aside>

      <section class="main fade-up">
        <div class="tiles">
          <div class="tile">
            <div class="num"><?= (int) $credits ?></div>
            <div class="lbl">Completed Credits</div>
          </div>
<a href="gpa.php" class="tile" style="text-decoration:none;">
  <div class="num"><?= $gpa > 0 ? number_format($gpa, 2) : 'N/A' ?></div>
  <div class="lbl">Current CGPA</div>
</a>
          <div class="tile">
            <div class="num"><?= (int) $attendance ?>%</div>
            <div class="lbl">Attendance</div>
          </div>
          <div class="tile">
            <div class="num"><?= htmlspecialchars($profile['Class_Name'] ?? '-') ?></div>
            <div class="lbl">Current Class</div>
          </div>
        </div>

        <div class="chart-area">
          <div class="chart-card">
            <h4>Academic Progress</h4>
            <canvas id="progressChart"></canvas>
          </div>

          <aside class="recent-card">
            <h4>Recent Activity</h4>
            <ul class="recent-list">
              <?php foreach ($recent as $r): ?>
                <li class="recent-item">
                  <div>
                    <div style="font-weight:700"><?= htmlspecialchars($r['text']) ?></div>
                    <small><?= htmlspecialchars($r['time']) ?></small>
                  </div>
                  <i class="fa-solid fa-chevron-right" style="color:var(--muted)"></i>
                </li>
              <?php endforeach; ?>
            </ul>

            <div style="display:flex;gap:10px;margin-top:12px">
              <a class="btn btn-edit" href="events.php"><i class="fa-solid fa-calendar-plus"></i> Register</a>
              <a class="btn btn-timetable" href="support.php"><i class="fa-solid fa-headset"></i> Support</a>
            </div>
          </aside>
        </div>

        <div class="footer">© <?= date('Y') ?> German-Malaysian Institute · Student Portal</div>
      </section>
    </div>
  </main>

  <script>
    const ctx = document.getElementById('progressChart').getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6', 'Week 7'],
        datasets: [{
          label: 'Progress',
          data: [10, 20, 35, 45, 60, 72, 85],
          fill: true,
          tension: 0.36,
          backgroundColor: 'rgba(56,189,248,0.06)',
          borderColor: '#38bdf8',
          pointRadius: 4,
          pointBackgroundColor: '#fff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: { ticks: { color: 'rgba(255,255,255,0.75)' } },
          y: { beginAtZero: true, ticks: { color: 'rgba(255,255,255,0.75)' } }
        },
        plugins: { legend: { display: false } }
      }
    });
  </script>

</body>

</html>