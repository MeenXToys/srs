<?php
// admin/index.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

// Stats queries
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM student")->fetchColumn();
$departments = $pdo->query("
  SELECT d.DepartmentID, d.Dept_Code, d.Dept_Name, COUNT(s.UserID) AS students
  FROM department d
  LEFT JOIN course c ON c.DepartmentID = d.DepartmentID
  LEFT JOIN class cl ON cl.CourseID = c.CourseID
  LEFT JOIN student s ON s.ClassID = cl.ClassID
  GROUP BY d.DepartmentID, d.Dept_Code, d.Dept_Name
  ORDER BY students DESC
")->fetchAll();
$courses = $pdo->query("
  SELECT c.CourseID, c.Course_Code, c.Course_Name, COUNT(s.UserID) AS students
  FROM course c
  LEFT JOIN class cl ON cl.CourseID = c.CourseID
  LEFT JOIN student s ON s.ClassID = cl.ClassID
  GROUP BY c.CourseID, c.Course_Code, c.Course_Name
  ORDER BY students DESC
")->fetchAll();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — Dashboard</title>
  <link rel="stylesheet" href="../style.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .stat-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin-top:18px; }
    .stat-card{ background:#0b1520; padding:18px; border-radius:12px; color:#cfe8ff; box-shadow: 0 6px 18px rgba(0,0,0,0.4); }
    .stat-card .label{ color:#94a3b8; font-size:0.9rem; margin-bottom:6px; }
    .stat-card h2{ font-size:2rem; margin:0; color:#38bdf8; }
    .btn{ display:inline-block; padding:8px 12px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; margin-right:8px; }
    .btn-warning{ background:#f59e0b; }
    table.admin th, table.admin td{ padding:10px 12px; border-top:1px solid rgba(255,255,255,0.03); color:#cbd5e1; }
    table.admin thead th{ color:#94a3b8; text-align:left; }
  </style>
</head>
<body>
  <!-- admin_nav.php already printed the topbar + sidebar -->
  <main class="admin-main">
    <h1>Admin Dashboard</h1>

    <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="label">Total registered students</div>
        <h2><?= e($totalStudents) ?></h2>
        <div class="small-muted">Students with rows in student table.</div>
      </div>

      <div class="stat-card">
        <div class="label">Departments</div>
        <h2><?= e(count($departments)) ?></h2>
        <div class="small-muted">Departments found in department table.</div>
      </div>

      <div class="stat-card">
        <div class="label">Courses</div>
        <h2><?= e(count($courses)) ?></h2>
        <div class="small-muted">Courses found in course table.</div>
      </div>
    </div>

    <section style="margin-top:30px;">
      <h2>Students per Department</h2>
      <table class="admin" style="width:100%;border-collapse:collapse;margin-top:12px;">
        <thead><tr><th style="width:70px">ID</th><th>Dept Code</th><th>Department</th><th style="width:120px">Students</th></tr></thead>
        <tbody>
          <?php foreach($departments as $d): ?>
            <tr>
              <td><?= e($d['DepartmentID']) ?></td>
              <td><?= e($d['Dept_Code']) ?></td>
              <td><?= e($d['Dept_Name']) ?></td>
              <td><?= e($d['students']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </main>
</body>
</html>
