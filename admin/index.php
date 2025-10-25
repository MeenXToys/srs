<?php
// admin/index.php
require_once __DIR__ . '/../config.php';
require_admin(); // uses your config.php helper. :contentReference[oaicite:2]{index=2}

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// --- STATISTICS ---
// 1) Total registered students (count rows in student)
$totalStudentsStmt = $pdo->query("SELECT COUNT(*) AS cnt FROM student");
$totalStudents = (int)$totalStudentsStmt->fetchColumn();

// 2) Total students per department
// student -> class (ClassID) -> course (CourseID) -> department (DepartmentID)
$deptSql = "
  SELECT d.DepartmentID, d.Dept_Code, d.Dept_Name, COUNT(s.UserID) AS students
  FROM department d
  LEFT JOIN course c ON c.DepartmentID = d.DepartmentID
  LEFT JOIN class cl ON cl.CourseID = c.CourseID
  LEFT JOIN student s ON s.ClassID = cl.ClassID
  GROUP BY d.DepartmentID, d.Dept_Code, d.Dept_Name
  ORDER BY students DESC, d.Dept_Name
";
$deptStmt = $pdo->query($deptSql);
$departments = $deptStmt->fetchAll();

// 3) Total students per course
$courseSql = "
  SELECT c.CourseID, c.Course_Code, c.Course_Name, COUNT(s.UserID) AS students
  FROM course c
  LEFT JOIN class cl ON cl.CourseID = c.CourseID
  LEFT JOIN student s ON s.ClassID = cl.ClassID
  GROUP BY c.CourseID, c.Course_Code, c.Course_Name
  ORDER BY students DESC, c.Course_Name
";
$courseStmt = $pdo->query($courseSql);
$courses = $courseStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>GMI - Admin Page</title>
  <link rel="stylesheet" href="../style.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin-top:18px; }
    .stat-card { background:var(--panel); padding:18px; border-radius:12px; box-shadow:var(--shadow-1); text-align:center; }
    .stat-card h2 { font-size:2.2rem; margin:6px 0; color:var(--accent); }
    .actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:18px; }
    .actions a, .actions button { text-decoration:none; padding:10px 14px; border-radius:10px; display:inline-block; font-weight:700; }
    .btn-add { background:#3b82f6; color:#fff; }
    .btn-edit { background:#f59e0b; color:#fff; }
    .btn-link { background:#475569; color:#fff; }
    table.stats { width:100%; border-collapse:collapse; margin-top:12px; }
    table.stats th, table.stats td { padding:10px 12px; text-align:left; border-top:1px solid rgba(255,255,255,0.03); color:#e6eef8; }
    table.stats thead th { color:#cbd5e1; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../nav.php'; ?>

  <main style="max-width:1100px;margin:30px auto;padding:20px;">
    <h1>Admin Dashboard</h1>

    <!-- quick action buttons -->
    <div class="actions">
      <a href="add_department.php" class="btn-add">+ Add Department</a>
      <a href="add_course.php" class="btn-add">+ Add Course</a>
      <a href="add_class.php" class="btn-add">+ Add Class</a>
      <!-- change timetable - link to directory or admin timetable editor -->
      <a href="../timetable.php" target="_blank" class="btn-link">Change Timetable (open editor)</a>
      <a href="students.php" class="btn-edit">Edit Student Data</a>
    </div>

    <!-- stats cards -->
    <div class="stat-grid" role="region" aria-label="Statistics">
      <div class="stat-card">
        <div class="label">Total registered students</div>
        <h2><?= e($totalStudents) ?></h2>
        <div class="small-muted">All students with rows in <code>student</code> table.</div>
      </div>

      <div class="stat-card">
        <div class="label">Departments</div>
        <h2><?= e(count($departments)) ?></h2>
        <div class="small-muted">Departments found in <code>department</code> table.</div>
      </div>

      <div class="stat-card">
        <div class="label">Courses</div>
        <h2><?= e(count($courses)) ?></h2>
        <div class="small-muted">Courses found in <code>course</code> table.</div>
      </div>
    </div>

    <!-- per-department breakdown -->
    <section style="margin-top:26px;">
      <h2>Students per Department</h2>
      <table class="stats" aria-describedby="dept-breakdown">
        <thead>
          <tr><th style="width:70px">ID</th><th>Dept Code</th><th>Department</th><th style="width:120px">Students</th></tr>
        </thead>
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

    <!-- per-course breakdown -->
    <section style="margin-top:22px;">
      <h2>Students per Course</h2>
      <table class="stats" aria-describedby="course-breakdown">
        <thead>
          <tr><th style="width:70px">ID</th><th>Course Code</th><th>Course Name</th><th style="width:120px">Students</th></tr>
        </thead>
        <tbody>
          <?php foreach($courses as $c): ?>
            <tr>
              <td><?= e($c['CourseID']) ?></td>
              <td><?= e($c['Course_Code']) ?></td>
              <td><?= e($c['Course_Name']) ?></td>
              <td><?= e($c['students']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <p style="margin-top:18px;" class="small-muted">Queries rely on your schema (tables: <code>student</code>, <code>class</code>, <code>course</code>, <code>department</code>) as in your SQL dump. :contentReference[oaicite:3]{index=3}</p>
  </main>
</body>
</html>
