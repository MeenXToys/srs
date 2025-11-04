<?php
// admin/index.php (updated: improved layout, cards stretch to fill empty space)
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Safe helper to run single-value count queries
function safe_count($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// Fetch totals
$totalClasses  = safe_count($pdo, "SELECT COUNT(*) FROM `class`");
$totalCourses  = safe_count($pdo, "SELECT COUNT(*) FROM `course`");
$totalDepts    = safe_count($pdo, "SELECT COUNT(*) FROM `department`");
$totalStudents = safe_count($pdo, "SELECT COUNT(*) FROM `student`");

// Recent classes (join course & department if available)
$recentClasses = [];
try {
    $stmt = $pdo->prepare("
        SELECT
          c.ClassID,
          c.Class_Name,
          c.Semester,
          co.Course_Name AS course_name,
          d.Dept_Name AS dept_name,
          c.UpdatedAt
        FROM `class` c
        LEFT JOIN `course` co ON co.CourseID = c.CourseID
        LEFT JOIN `department` d ON d.DepartmentID = co.DepartmentID
        ORDER BY COALESCE(c.UpdatedAt, c.ClassID) DESC
        LIMIT 8
    ");
    $stmt->execute();
    $recentClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentClasses = [];
}

// Recent courses
$recentCourses = [];
try {
    $stmt = $pdo->prepare("SELECT CourseID, Course_Name FROM `course` ORDER BY CourseID DESC LIMIT 8");
    $stmt->execute();
    $recentCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentCourses = [];
}

// Faux small activity arrays for spark charts (replace with real metrics later if needed)
$activity = [
    'classes'  => [2,3,5,4,6,7,8,9,7,8,10],
    'students' => [1,2,2,4,4,6,7,9,8,9,11],
];

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    /* ---------- Theme & layout ---------- */
    :root{
      --bg: #071025;
      --panel: linear-gradient(180deg,#07111a 0%, #071620 100%);
      --muted: #97a6bd;
      --text: #e7f3ff;
      --glass: rgba(255,255,255,0.02);
      --accent1: #7c3aed;
      --accent2: #5b21b6;
      --ok: #10b981;
      --danger: #ef4444;
    }
    html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;}
    main.admin-main{min-height:100vh;padding:18px 22px;}
    /* make content wider so cards fit nicely but still centered */
    .content{max-width:1400px;margin:0 auto;display:grid;grid-template-columns:1fr;gap:18px;padding-bottom:36px;}

    /* header */
    .topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}
    h1.page-title{margin:0;font-size:1.5rem;letter-spacing:0.2px}
    p.subtitle{margin:4px 0 0 0;color:var(--muted);font-size:.95rem;max-width:900px}

    .actions-row{display:flex;gap:10px;align-items:center}

    /* stats wrapper: reduced height, centered content */
    .stats-hero{background:linear-gradient(180deg, rgba(0,0,0,0.12), rgba(0,0,0,0.06)); border-radius:16px; padding:18px; border:1px solid rgba(255,255,255,0.02); box-shadow: inset 0 -30px 60px rgba(2,6,23,0.35);}

    /* stats grid */
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;align-items:stretch}
    .stat-tile{background:var(--panel);border-radius:12px;padding:18px;display:flex;align-items:center;gap:14px;border:1px solid rgba(255,255,255,0.03);box-shadow:0 10px 30px rgba(2,6,23,0.55);transition:transform .12s ease,box-shadow .12s ease;height:100%;}
    .stat-tile:hover{transform:translateY(-6px);box-shadow:0 28px 60px rgba(2,6,23,0.75)}
    .stat-icon{width:56px;height:56px;border-radius:12px;background:rgba(255,255,255,0.02);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
    .stat-body{flex:1;min-width:0}
    .stat-num{font-weight:800;font-size:1.6rem;color:#fff}
    .stat-label{color:var(--muted);margin-top:6px;font-size:.95rem}
    .stat-spark{width:120px;height:44px;display:flex;align-items:center;justify-content:center;flex-shrink:0}

    /* two column layout for main content - both columns will stretch to same height */
    .main-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px;align-items:start}
    /* make direct children of left column flex column so inner cards can stretch */
    .main-left, aside {display:flex;flex-direction:column;gap:18px}
    .card{background:var(--panel);border-radius:12px;padding:18px;border:1px solid rgba(255,255,255,0.03);box-shadow:0 12px 40px rgba(0,0,0,0.6);display:flex;flex-direction:column}
    .card h3{margin:0 0 8px 0}
    .card .muted{color:var(--muted);font-size:.95rem;margin-bottom:12px}
    /* allow a card to grow to fill column height */
    .flex-fill{flex:1;display:flex;flex-direction:column}

    /* recent list */
    .recent-list{display:flex;flex-direction:column;gap:10px;overflow:auto;padding-right:6px}
    .recent-item{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:12px;border-radius:10px;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent);border:1px solid rgba(255,255,255,0.02)}
    .recent-left{display:flex;gap:12px;align-items:center}
    .badge{min-width:40px;height:40px;border-radius:999px;background:rgba(255,255,255,0.02);display:flex;align-items:center;justify-content:center;color:var(--text);font-weight:800}
    .recent-title{font-weight:800}
    .recent-sub{color:var(--muted);font-size:.9rem;margin-top:6px;max-width:540px;word-break:break-word}

    /* quick actions */
    .quick-actions{display:flex;flex-direction:column;gap:10px}
    .btn{display:inline-flex;align-items:center;gap:10px;padding:10px 14px;border-radius:999px;background:linear-gradient(90deg,var(--accent1),var(--accent2));color:#fff;border:0;cursor:pointer;font-weight:700;text-decoration:none}
    .btn-ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--text);padding:8px 12px;border-radius:8px;text-decoration:none}
    .small-muted{color:var(--muted);font-size:.92rem}

    /* recent courses list */
    .courses-list{max-height:220px;overflow:auto;margin-top:8px;border-top:1px dashed rgba(255,255,255,0.02);padding-top:8px}
    .course-row{display:flex;justify-content:space-between;padding:8px 6px;border-bottom:1px dashed rgba(255,255,255,0.02);align-items:center}
    .course-row:last-child{border-bottom:0}

    /* responsive */
    @media (max-width:1100px){
      .stats-grid{grid-template-columns:repeat(2,1fr)}
      .main-grid{grid-template-columns:1fr;align-items:stretch}
      .stat-spark{display:none}
    }
    @media (max-width:520px){
      .stats-grid{grid-template-columns:1fr}
      .stat-icon{width:48px;height:48px}
    }

    /* tiny helpers */
    .muted-muted{color:var(--muted);font-size:.9rem}
  </style>
</head>
<body>
<main class="admin-main">
  <div class="content">
    <div class="topbar">
      <div>
        <h1 class="page-title">Admin Dashboard</h1>
        <p class="subtitle">Overview of classes, courses, departments and students — quick insights and recent activity.</p>
      </div>
      <div class="actions-row">
        <a href="classes.php" class="btn" title="Manage classes">Manage Classes</a>
        <a href="courses.php" class="btn-ghost" title="Manage courses">Manage Courses</a>
      </div>
    </div>

    <!-- Stats hero -->
    <section class="stats-hero" aria-label="Top summary">
      <div class="stats-grid" role="list">
        <div class="stat-tile" role="listitem" aria-label="Total classes">
          <div class="stat-icon" aria-hidden>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#dbeafe" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="4" width="18" height="5" rx="1"></rect>
              <rect x="3" y="15" width="18" height="5" rx="1"></rect>
              <rect x="3" y="9.5" width="18" height="5" rx="1" opacity="0.7"></rect>
            </svg>
          </div>
          <div class="stat-body">
            <div class="stat-num"><?= e($totalClasses) ?></div>
            <div class="stat-label">Total Classes</div>
          </div>
          <div class="stat-spark" aria-hidden>
            <svg viewBox="0 0 100 36" preserveAspectRatio="none" style="width:100%;height:100%">
              <?php
                $d = $activity['classes']; $len = count($d); $max = max($d); $min = min($d); $pts = [];
                for ($i=0;$i<$len;$i++){
                  $x = ($i/($len-1))*100;
                  $y = 36 - (($d[$i]-$min)/max(1, $max-$min))*28 - 4;
                  $pts[] = "$x,$y";
                }
              ?>
              <polyline fill="none" stroke="#c4b5fd" stroke-width="2" stroke-linecap="round" points="<?= implode(' ', $pts) ?>"></polyline>
            </svg>
          </div>
        </div>

        <div class="stat-tile" role="listitem" aria-label="Total courses">
          <div class="stat-icon" aria-hidden>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#dbeafe" stroke-width="1.6">
              <path d="M4 6h16M4 12h16M4 18h10"></path>
            </svg>
          </div>
          <div class="stat-body">
            <div class="stat-num"><?= e($totalCourses) ?></div>
            <div class="stat-label">Total Courses</div>
          </div>
          <div class="stat-spark" aria-hidden>
            <svg viewBox="0 0 100 36" preserveAspectRatio="none"><polyline fill="none" stroke="#8bd7ff" stroke-width="2" points="0,28 20,22 40,18 60,20 80,14 100,16"></polyline></svg>
          </div>
        </div>

        <div class="stat-tile" role="listitem" aria-label="Departments">
          <div class="stat-icon" aria-hidden>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#dbeafe" stroke-width="1.6">
              <circle cx="12" cy="8" r="2"></circle><path d="M6 20v-3a4 4 0 014-4h4a4 4 0 014 4v3"></path>
            </svg>
          </div>
          <div class="stat-body">
            <div class="stat-num"><?= e($totalDepts) ?></div>
            <div class="stat-label">Departments</div>
          </div>
          <div class="stat-spark" aria-hidden>
            <svg viewBox="0 0 100 36" preserveAspectRatio="none"><polyline fill="none" stroke="#6ee7b7" stroke-width="2" points="0,28 25,26 50,20 75,18 100,12"></polyline></svg>
          </div>
        </div>

        <div class="stat-tile" role="listitem" aria-label="Students">
          <div class="stat-icon" aria-hidden>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#dbeafe" stroke-width="1.6">
              <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path>
              <path d="M6 20v-1a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v1"></path>
            </svg>
          </div>
          <div class="stat-body">
            <div class="stat-num"><?= e($totalStudents) ?></div>
            <div class="stat-label">Students</div>
          </div>
          <div class="stat-spark" aria-hidden>
            <?php
              $d = $activity['students']; $len = count($d); $max = max($d); $min = min($d); $pts=[];
              for ($i=0;$i<$len;$i++){ $x = ($i/($len-1))*100; $y = 36 - (($d[$i]-$min)/max(1,$max-$min))*28 - 4; $pts[]="$x,$y"; }
            ?>
            <svg viewBox="0 0 100 36" preserveAspectRatio="none"><polyline fill="none" stroke="#f6c35e" stroke-width="2" points="<?= implode(' ', $pts) ?>"></polyline></svg>
          </div>
        </div>
      </div>
    </section>

    <!-- Main content (left & right columns equal height behavior) -->
    <section class="main-grid" style="margin-top:6px">
      <div class="main-left">
        <div class="card flex-fill">
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
              <h3>Recent Classes</h3>
              <div class="muted">Latest created or updated classes</div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
              <a href="classes.php" class="btn-ghost">View all</a>
              <a href="classes.php#openAddBtn" class="btn">Add Class</a>
            </div>
          </div>

          <div style="margin-top:14px;flex:1;display:flex;flex-direction:column;min-height:140px">
            <?php if (empty($recentClasses)): ?>
              <div class="muted-muted" style="padding:36px;text-align:center;margin:auto">No classes yet.</div>
            <?php else: ?>
              <div class="recent-list" style="margin-top:6px">
                <?php foreach ($recentClasses as $c):
                    $title = $c['Class_Name'] ?: ('#' . ($c['ClassID'] ?? '—'));
                    $meta  = trim(($c['course_name'] ? $c['course_name'] : '') . ($c['dept_name'] ? " — {$c['dept_name']}" : ''));
                    $sem   = $c['Semester'] ?? '-';
                ?>
                  <div class="recent-item">
                    <div class="recent-left">
                      <div class="badge"><?= e($sem) ?></div>
                      <div>
                        <div class="recent-title"><?= e($title) ?></div>
                        <div class="recent-sub"><?= e($meta ?: '—') ?></div>
                      </div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px">
                      <div style="display:flex;gap:8px">
                        <a class="btn-ghost" href="classes.php?view=<?= urlencode($c['ClassID']) ?>">View</a>
                        <a class="btn-ghost" href="classes.php?edit=<?= urlencode($c['ClassID']) ?>">Edit</a>
                      </div>
                      <div class="small-muted"><?= e(!empty($c['UpdatedAt']) ? date('d M Y', strtotime($c['UpdatedAt'])) : '') ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card" style="margin-top:14px">
          <h3>Recent Courses</h3>
          <div class="muted">Latest courses added</div>
          <div class="courses-list" style="margin-top:8px">
            <?php if (empty($recentCourses)): ?>
              <div class="muted-muted" style="padding:18px">No courses yet.</div>
            <?php else: foreach ($recentCourses as $rc): ?>
              <div class="course-row">
                <div style="font-weight:700"><?= e($rc['Course_Name']) ?></div>
                <div class="small-muted"><?= e($rc['CourseID']) ?></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <aside>
        <div class="card flex-fill">
          <h3>Quick Actions</h3>
          <div class="muted">Common admin tasks</div>

          <div class="quick-actions" style="margin-top:12px">
            <a href="classes.php#openAddBtn" class="btn">＋ Add Class</a>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <a href="courses.php" class="btn-ghost">Courses</a>
              <a href="departments.php" class="btn-ghost">Departments</a>
            </div>

            <div style="margin-top:10px">
              <div class="small-muted">System health</div>
              <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
                <div style="flex:1">
                  <div style="height:10px;background:rgba(255,255,255,0.03);border-radius:999px;overflow:hidden">
                    <div style="width:65%;height:100%;background:linear-gradient(90deg,var(--accent1),var(--accent2))"></div>
                  </div>
                </div>
                <div class="small-muted" style="min-width:56px;text-align:right">65% used</div>
              </div>
            </div>

            <div style="margin-top:12px;flex:1;display:flex;flex-direction:column">
              <div class="small-muted">Recent courses (top)</div>
              <div style="margin-top:8px;overflow:auto">
                <?php if (empty($recentCourses)): ?>
                  <div class="muted-muted">No courses yet.</div>
                <?php else: foreach ($recentCourses as $rc): ?>
                  <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed rgba(255,255,255,0.02)">
                    <div style="font-weight:700"><?= e($rc['Course_Name']) ?></div>
                    <div class="small-muted"><?= e($rc['CourseID']) ?></div>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div style="height:12px"></div>

        <div class="card">
          <h3>Tips</h3>
          <div class="muted">Shortcuts & best practices</div>
          <ul style="margin-top:10px;color:var(--muted);padding-left:18px">
            <li>Use the <strong>Export</strong> feature to back up student lists.</li>
            <li>Keep course codes consistent to avoid duplication.</li>
            <li>Soft-delete students where possible to allow restoring.</li>
          </ul>
        </div>
      </aside>
    </section>

    <footer style="color:var(--muted);font-size:.9rem;padding:8px 0">
      © <?= date('Y') ?> Your Institution · Admin panel
    </footer>
  </div>
</main>
</body>
</html>
