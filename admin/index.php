<?php
// admin/index.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

// quick safe helpers
function safe_count($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// main stats
$totalClasses = safe_count($pdo, "SELECT COUNT(*) FROM `class`");
$totalCourses = safe_count($pdo, "SELECT COUNT(*) FROM `course`");
$totalDepts   = safe_count($pdo, "SELECT COUNT(*) FROM `department`");
$totalStudents= safe_count($pdo, "SELECT COUNT(*) FROM `student`");

// recent items (limited)
// NOTE: use correct column names that exist in your DB (Dept_Name not DepartmentName)
$recentClasses = [];
try {
    $stmt = $pdo->prepare("
        SELECT
          c.ClassID,
          c.Class_Name AS name,
          co.Course_Name AS course,
          d.Dept_Name AS dept,
          c.Semester
        FROM `class` c
        LEFT JOIN `course` co ON co.CourseID = c.CourseID
        LEFT JOIN `department` d ON d.DepartmentID = co.DepartmentID
        ORDER BY c.ClassID DESC
        LIMIT 6
    ");
    $stmt->execute();
    $recentClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // keep empty on error
    $recentClasses = [];
}

// recent courses
$recentCourses = [];
try {
    $stmt = $pdo->prepare("SELECT CourseID, Course_Name FROM `course` ORDER BY CourseID DESC LIMIT 6");
    $stmt->execute();
    $recentCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */ }

// small activity samples for sparklines (faux data) - you can later replace with real metrics
$activity = [
    'classes' => [2,3,5,4,6,7,8,9,7,8,10],
    'students'=> [4,6,8,7,12,11,14,18,16,20,22],
];

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../style.css">
  <style>
    :root{
      --bg:#0f1724;
      --card:#0b1520;
      --muted:#94a3b8;
      --text:#e6eef8;
      --accent1:#7c3aed;
      --accent2:#6d28d9;
      --ok:#10b981;
      --danger:#ef4444;
      --glass: rgba(255,255,255,0.02);
    }
    html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;}
    .admin-main{padding:20px 28px;}
    .center-box{max-width:1200px;margin:0 auto;}
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:18px;}
    .card{background:linear-gradient(180deg,var(--card),#07111a);border-radius:12px;padding:18px;box-shadow:0 12px 40px rgba(0,0,0,0.6);border:1px solid rgba(255,255,255,0.03);}
    /* stat is now an anchor */
    .stat {
      grid-column: span 3;
      padding:16px;
      border-radius:10px;
      display:flex;
      gap:14px;
      align-items:center;
      background: linear-gradient(180deg, rgba(255,255,255,0.012), transparent);
      border:1px solid rgba(255,255,255,0.02);
      text-decoration:none;
      color:inherit;
      cursor:pointer;
      transition:transform .12s,box-shadow .12s,outline-color .12s;
      outline: 2px solid transparent;
      outline-offset: 2px;
    }
    .stat:focus, .stat:hover { transform:translateY(-6px); box-shadow:0 30px 60px rgba(2,6,23,0.7); outline-color: rgba(56,189,248,0.18); }
    .stat .num { font-size:1.7rem; font-weight:800; color:#ffffff; }
    .stat .label { color:var(--muted); font-size:0.92rem; }
    .stat .spark { margin-left:auto; min-width:70px; height:36px; display:flex; align-items:center; justify-content:center; opacity:0.9; }

    /* big cards */
    .card-wide { grid-column: span 8; display:flex; flex-direction:column; gap:12px; }
    .card-narrow { grid-column: span 4; display:flex; flex-direction:column; gap:12px; }

    .list-row { display:flex; justify-content:space-between; gap:12px; align-items:center; padding:12px 0; border-bottom:1px dashed rgba(255,255,255,0.02); }
    .list-row:last-child{border-bottom:0;padding-bottom:0;margin-bottom:0;}
    .list-left { display:flex; gap:12px; align-items:center; }
    .badge { padding:6px 10px;border-radius:8px;background:var(--glass);color:var(--text);font-weight:700; font-size:0.9rem; border:1px solid rgba(255,255,255,0.03); }
    .actions { display:flex; gap:10px; align-items:center; }

    .btn { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:10px; background:linear-gradient(90deg,var(--accent1),var(--accent2)); color:#fff; font-weight:700; border:0; cursor:pointer; text-decoration:none; }
    .btn-ghost { background:transparent; border:1px solid rgba(255,255,255,0.04); color:var(--text); padding:8px 12px; border-radius:10px; text-decoration:none; }
    a.card-link{ color:var(--text); text-decoration:none; }

    h1.page-title{ margin:0 0 6px 0; font-size:1.6rem; color:#dff6ff; }
    p.subtitle{ margin:0;color:var(--muted);font-size:.95rem;margin-bottom:14px; }

    /* responsive */
    @media (max-width:1000px){
      .stat { grid-column: span 6; }
      .card-wide { grid-column: span 12; }
      .card-narrow { grid-column: span 12; }
    }

    /* small helper for sparkline */
    .sparkline { width:100%; height:36px; }
    .summary-tiles{display:flex;gap:12px;flex-wrap:wrap;}
    .quick-actions{display:flex;gap:10px;flex-wrap:wrap;}
    .small-muted{ color:var(--muted); font-size:0.9rem; }

    /* progress bar */
    .progress-track{height:10px;background:rgba(255,255,255,0.03);border-radius:999px;overflow:hidden;}
    .progress-fill{height:100%;background:linear-gradient(90deg,var(--accent1),var(--accent2));}

  </style>
</head>
<body>
<main class="admin-main">
  <div class="center-box">
    <header style="display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:18px;">
      <div>
        <h1 class="page-title">Admin Dashboard</h1>
        <p class="subtitle">Overview of classes, courses, departments and students. Quick insights & recent activity.</p>
      </div>
      <div style="display:flex;align-items:center;gap:12px;">
        <a href="classes.php" class="btn">Manage Classes</a>
        <a href="courses.php" class="btn btn-ghost">Manage Courses</a>
      </div>
    </header>

    <section class="grid" style="margin-bottom:18px;">
      <!-- clickable cards: use anchors so whole card is a link -->
      <a href="classes.php" class="stat" role="link" aria-label="Total Classes — view classes">
        <div>
          <div class="num"><?= e($totalClasses) ?></div>
          <div class="label">Total Classes</div>
        </div>
        <div class="spark" aria-hidden>
          <svg class="sparkline" viewBox="0 0 100 36" preserveAspectRatio="none">
            <?php
            $d = $activity['classes'];
            $max = max($d); $min = min($d);
            $pts = [];
            $len = count($d);
            for ($i=0;$i<$len;$i++){
                $x = ($i/($len-1))*100;
                $y = 36 - (($d[$i]-$min)/max(1,$max-$min))*32 - 2;
                $pts[] = "$x,$y";
            }
            ?>
            <polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
          </svg>
        </div>
      </a>

      <a href="courses.php" class="stat" role="link" aria-label="Total Courses — view courses">
        <div>
          <div class="num"><?= e($totalCourses) ?></div>
          <div class="label">Total Courses</div>
        </div>
        <div class="spark" aria-hidden>
          <svg class="sparkline" viewBox="0 0 100 36">
            <?php $d = [1,2,3,3,4,6,5,7,6,8]; $max = max($d); $min = min($d); $pts=[]; $len=count($d);
            for ($i=0;$i<$len;$i++){ $x = ($i/($len-1))*100; $y = 36 - (($d[$i]-$min)/max(1,$max-$min))*32 - 2; $pts[] = "$x,$y"; }
            ?>
            <polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="#06b6d4" stroke-width="2"></polyline>
          </svg>
        </div>
      </a>

      <a href="departments.php" class="stat" role="link" aria-label="Departments — view departments">
        <div>
          <div class="num"><?= e($totalDepts) ?></div>
          <div class="label">Departments</div>
        </div>
        <div class="spark" aria-hidden>
          <svg class="sparkline" viewBox="0 0 100 36">
            <?php $d=[1,1,2,2,3,3,4,4,4,5]; $max=max($d); $min=min($d); $pts=[];$len=count($d);
            for ($i=0;$i<$len;$i++){ $x = ($i/($len-1))*100; $y = 36 - (($d[$i]-$min)/max(1,$max-$min))*32 - 2; $pts[]="$x,$y"; } ?>
            <polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="#10b981" stroke-width="2"></polyline>
          </svg>
        </div>
      </a>

      <a href="students_manage.php" class="stat" role="link" aria-label="Students — view students">
        <div>
          <div class="num"><?= e($totalStudents) ?></div>
          <div class="label">Students</div>
        </div>
        <div class="spark" aria-hidden>
          <svg class="sparkline" viewBox="0 0 100 36">
            <?php $d = $activity['students']; $max=max($d); $min=min($d); $pts=[];$len=count($d);
            for ($i=0;$i<$len;$i++){ $x = ($i/($len-1))*100; $y = 36 - (($d[$i]-$min)/max(1,$max-$min))*32 - 2; $pts[]="$x,$y"; } ?>
            <polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="#f59e0b" stroke-width="2"></polyline>
          </svg>
        </div>
      </a>
    </section>

    <section class="grid" style="align-items:start; gap:18px;">
      <div class="card card-wide">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <div>
            <strong style="font-size:1.05rem">Recent Classes</strong>
            <div class="small-muted" style="margin-top:6px">Latest created or updated classes</div>
          </div>
          <div class="actions">
            <a href="classes.php" class="btn-ghost">View all</a>
            <a href="classes.php#openAddBtn" class="btn">Add Class</a>
          </div>
        </div>

        <div style="margin-top:12px;">
          <?php if (empty($recentClasses)): ?>
            <div class="small-muted" style="padding:18px;">No classes yet.</div>
          <?php else: foreach ($recentClasses as $c): ?>
            <div class="list-row">
              <div class="list-left">
                <div class="badge"><?= e($c['Semester'] ?? '-') ?></div>
                <div>
                  <div style="font-weight:700"><?= e($c['name'] ?? '-') ?></div>
                  <div class="small-muted" style="margin-top:4px;font-size:.92rem;"><?= e($c['course'] ? "{$c['course']} — {$c['dept']}" : ($c['dept'] ?? '-')) ?></div>
                </div>
              </div>
              <div style="display:flex;gap:10px;align-items:center;">
                <a class="card-link" href="classes.php?view=<?= urlencode($c['ClassID']) ?>">View</a>
                <a class="card-link" href="classes.php?edit=<?= urlencode($c['ClassID']) ?>">Edit</a>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="card card-narrow">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <strong style="font-size:1.05rem">Quick Actions</strong>
            <div class="small-muted" style="margin-top:6px">Common admin tasks</div>
          </div>
        </div>

        <div style="margin-top:12px;display:flex;flex-direction:column;gap:10px;">
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="classes.php#openAddBtn" class="btn" title="Add Class">＋ Add Class</a>
            <a href="courses.php" class="btn-ghost" title="Courses">Courses</a>
            <a href="departments.php" class="btn-ghost" title="Departments">Departments</a>
          </div>

          <div style="margin-top:6px;">
            <div class="small-muted">System health</div>
            <div style="margin-top:8px;">
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="flex:1">
                  <div class="progress-track">
                    <div class="progress-fill" style="width:65%"></div>
                  </div>
                </div>
                <div style="min-width:60px;text-align:right;color:var(--muted)">65% used</div>
              </div>
            </div>
          </div>

          <div style="margin-top:6px;">
            <div class="small-muted">Recent courses</div>
            <div style="margin-top:8px;">
              <?php if (empty($recentCourses)): ?>
                <div class="small-muted">No courses yet.</div>
              <?php else: foreach($recentCourses as $rc): ?>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed rgba(255,255,255,0.02);">
                  <div><?= e($rc['Course_Name']) ?></div>
                  <div class="small-muted"><?= e($rc['CourseID']) ?></div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>

        </div>

      </div>
    </section>

    <footer style="margin-top:20px;color:var(--muted);font-size:.9rem;">
      © <?= date('Y') ?> Your Institution · Admin panel
    </footer>

  </div>
</main>

</body>
</html>
