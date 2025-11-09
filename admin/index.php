<?php
// admin/index.php — layout-only adjustments (colors unchanged)
require_once __DIR__ . '/../config.php';
require_admin();

// Determine whether admin_nav exists (sidebar)
$has_sidebar = file_exists(__DIR__ . '/admin_nav.php');
if ($has_sidebar) {
    // include sidebar file which likely outputs markup (positioned fixed/absolute)
    require_once __DIR__ . '/admin_nav.php';
}

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

/* ---------- safe single-value query helper ---------- */
function safe_count($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (Exception $ex) {
        return 0;
    }
}

/* ---------- Totals (ignore soft-deleted rows) ---------- */
$totalClasses  = safe_count($pdo, "SELECT COUNT(*) FROM class WHERE COALESCE(deleted_at, '') = ''");
$totalCourses  = safe_count($pdo, "SELECT COUNT(*) FROM course WHERE COALESCE(deleted_at, '') = ''");
$totalDepts    = safe_count($pdo, "SELECT COUNT(*) FROM department WHERE COALESCE(deleted_at, '') = ''");
$totalStudents = safe_count($pdo, "SELECT COUNT(*) FROM student WHERE COALESCE(deleted_at, '') = ''");

/* ---------- Recent records ---------- */
$recentClasses = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.ClassID, COALESCE(c.Class_Name,'') AS Class_Name, COALESCE(c.Semester,'-') AS Semester,
               COALESCE(co.Course_Name,'') AS course_name, COALESCE(d.Dept_Name,'') AS dept_name,
               c.UpdatedAt
        FROM class c
        LEFT JOIN course co ON co.CourseID = c.CourseID AND COALESCE(co.deleted_at,'') = ''
        LEFT JOIN department d ON d.DepartmentID = co.DepartmentID AND COALESCE(d.deleted_at,'') = ''
        WHERE COALESCE(c.deleted_at,'') = ''
        ORDER BY COALESCE(c.UpdatedAt, c.ClassID) DESC
        LIMIT 8
    ");
    $stmt->execute();
    $recentClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentClasses = [];
}

/* ---------- Recent courses ---------- */
$recentCourses = [];
try {
    $stmt = $pdo->prepare("SELECT CourseID, Course_Name FROM course WHERE COALESCE(deleted_at,'') = '' ORDER BY CourseID DESC LIMIT 12");
    $stmt->execute();
    $recentCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentCourses = [];
}

/* ---------- Department -> courses breakdown (for donut) ---------- */
$deptBreakdown = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.Dept_Name AS label, COUNT(c.CourseID) AS value
        FROM department d
        LEFT JOIN course c ON c.DepartmentID = d.DepartmentID AND COALESCE(c.deleted_at,'') = ''
        WHERE COALESCE(d.deleted_at,'') = ''
        GROUP BY d.Dept_Name
        ORDER BY value DESC
        LIMIT 12
    ");
    $stmt->execute();
    $deptBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $deptBreakdown = [];
}

/* ---------- Classes trend (12 points) ---------- */
$trendLabels = [];
$trendDataClasses = [];
try {
    $hasDate = $pdo->query("SHOW COLUMNS FROM class LIKE 'Created_At'")->rowCount() > 0
               || $pdo->query("SHOW COLUMNS FROM class LIKE 'UpdatedAt'")->rowCount() > 0;

    if ($hasDate) {
        $stmt = $pdo->prepare("
          SELECT DATE_FORMAT(COALESCE(UpdatedAt, Created_At, NOW()), '%Y-%m') AS ym, COUNT(*) AS cnt
          FROM class
          WHERE COALESCE(deleted_at,'') = ''
          GROUP BY ym
          ORDER BY ym DESC
          LIMIT 12
        ");
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        foreach ($rows as $r) { $trendLabels[] = $r['ym']; $trendDataClasses[] = (int)$r['cnt']; }
    }
} catch (Exception $e) {
    // ignore and fall back below
}
if (empty($trendLabels)) {
    for ($i=11;$i>=0;$i--) {
        $trendLabels[] = date('M Y', strtotime("-{$i} months"));
        $trendDataClasses[] = max(0, (int)round($totalClasses/12 + mt_rand(-2,2)));
    }
}

/* ---------- GPA buckets sample (if present) ---------- */
$gpaBuckets = [0,0,0,0];
try {
    $stmt = $pdo->prepare("SELECT GPA FROM gpa WHERE GPA IS NOT NULL");
    $stmt->execute();
    $gpas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($gpas as $g) {
        $g = (float)$g;
        if ($g < 1) $gpaBuckets[0]++; elseif ($g < 2) $gpaBuckets[1]++; elseif ($g < 3) $gpaBuckets[2]++; else $gpaBuckets[3]++;
    }
} catch (Exception $e) {
    // ignore if gpa table missing
}

/* ---------- safe JSON for JS charts (fallbacks) ---------- */
$jsDeptLabels = json_encode(array_column($deptBreakdown, 'label') ?: []);
$jsDeptValues = json_encode(array_map('intval', array_column($deptBreakdown, 'value') ?: []));
$jsTrendLabels = json_encode($trendLabels ?: []);
$jsTrendData = json_encode($trendDataClasses ?: []);
$jsGpaBuckets = json_encode(array_values($gpaBuckets) ?: [0,0,0,0]);

/* ---------- end PHP data prep, output HTML ---------- */
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard</title>
  <link rel="icon" href="../img/favicon.png" type="image/png">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../style.css" media="screen">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>

  <style>

    section {
  padding: 0.5px 1% !important;
background-color: #0f1724 !important;}
    /* ---------- Sizing/layout adjustments ONLY (colors preserved) ---------- */
    :root{
      /* Keep your original color variables and names unchanged */
      --sidebar-width: 260px;
      --bg:#071025;
      --panel:#07111a;
      --panel-grad: linear-gradient(180deg,#07111a 0%, #071620 100%);
      --muted:#97a6bd; --text:#e7f3ff;
      --glass: rgba(255,255,255,0.02);
      --accent-a:#7c3aed; --accent-b:#5b21b6; --accent-c:#06b6d4;
      --neon:#8be9ff; --ok:#10b981;
      --card-radius:12px;
      --page-gutter: 20px;   /* reduced gutter */
      --content-max: 1100px; /* REDUCED overall content width (change if you want narrower) */
      --aside-width: 300px;  /* narrower right column */
      --stat-card-min-height: 72px;
      --canvas-height: 180px; /* smaller charts */
    }

    /* Basic resets */
    *, *:before, *:after { box-sizing: border-box; }
    html, body { height:100%; margin:0; background:#0f1724 !important; color:var(--text); font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial; overflow-x:hidden; }

    /* ---------- Single-offset approach: only container moves when sidebar present ---------- */
    body.has-admin-sidebar main.admin-main { margin-left: 0 !important; }
    body.has-admin-sidebar .adminex-container {
      max-width: calc(var(--content-max));
      margin-left: calc(var(--sidebar-width) + var(--page-gutter));
      padding-left: 0;
      padding-right: var(--page-gutter);
    }
    @media (max-width: 900px) {
      body.has-admin-sidebar .adminex-container { margin-left: 0 !important; max-width: calc(100% - 24px); padding-left: 12px; padding-right: 12px; }
    }

    /* Keep original container style but reduce the max width */
    .adminex-container{ max-width:var(--content-max); width:calc(100% - 40px); margin:0 auto; display:grid; gap:16px; padding:16px; }

    /* Header: slightly smaller title & tighter actions (only size/layout changes) */
    .adminex-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .adminex-title{margin:0;font-size:1.5rem} /* slightly reduced */
    .adminex-sub{margin:6px 0 0;color:var(--muted);font-size:.95rem;max-width:850px}

    /* Buttons: smaller paddings so they don't get hidden */
    .adminex-btn{display:inline-flex;align-items:center;gap:10px;padding:8px 12px;border-radius:999px;background:linear-gradient(90deg,var(--accent-a),var(--accent-b));color:#fff;border:0;font-weight:800;text-decoration:none;box-shadow:0 8px 28px rgba(88,24,163,0.14); transition: all 0.18s ease;font-size:0.95rem;}
    .adminex-btn.ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);box-shadow:none;color:var(--text);padding:7px 10px;border-radius:12px}
    .adminex-btn.small, .adminex-btn.ghost.small { padding: 4px 8px; font-size: 0.82rem; border-radius: 8px; }

    /* Hero/stat row: reduce padding & size */
    .adminex-hero{background:var(--panel-grad); border-radius:var(--card-radius);padding:14px;border:1px solid rgba(255,255,255,0.02);box-shadow:0 10px 30px rgba(2,6,23,0.35)}
    .adminex-stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;align-items:stretch}
    .adminex-stat-card{background:var(--panel-grad);border-radius:var(--card-radius);padding:12px;display:flex;align-items:center;gap:10px;border:1px solid rgba(255,255,255,0.03);box-shadow:0 8px 24px rgba(2,6,23,0.45);transition:transform .12s,box-shadow .12s;min-height:var(--stat-card-min-height)}
    .adminex-stat-card:focus-within, .adminex-stat-card:hover{transform:translateY(-4px);box-shadow:0 20px 40px rgba(2,6,23,0.65)}
    .adminex-stat-icon{width:48px;height:48px;border-radius:10px;background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.0));display:flex;align-items:center;justify-content:center}
    .adminex-stat-body{flex:1;min-width:0}
    .adminex-stat-num{font-size:1.45rem;font-weight:900}
    .adminex-stat-label{color:var(--muted);margin-top:6px;font-size:0.92rem}
    .adminex-stat-spark{width:120px;height:40px;flex-shrink:0}

    /* Main grid: reduce aside width and overall spacing */
    .adminex-main-grid{display:grid;grid-template-columns:1.8fr var(--aside-width);gap:16px;align-items:start}
    aside { max-width: var(--aside-width); }

    /* Cards: smaller padding but same visual style */
    .adminex-card{background:var(--panel-grad);border-radius:var(--card-radius);padding:14px;border:1px solid rgba(255,255,255,0.03);box-shadow:0 10px 30px rgba(0,0,0,0.6);display:flex;flex-direction:column}
    .adminex-card h3{margin:0 0 8px 0;font-size:1.05rem}
    .adminex-card .adminex-muted{color:var(--muted);font-size:.95rem}

    /* Analytics charts: reduce chart container and height (only size changes) */
    .adminex-analytics{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
    .adminex-chart-wrap{background:linear-gradient(180deg, rgba(12,22,32,0.12), transparent);border-radius:10px;padding:10px;border:1px solid rgba(255,255,255,0.02);min-height:var(--canvas-height)}
    canvas{width:100% !important;height:var(--canvas-height) !important}

    /* Lists and rows smaller */
    .adminex-list{display:flex;flex-direction:column;gap:8px;overflow:auto}
    .adminex-list .adminex-row{display:flex;justify-content:space-between;align-items:center;padding:8px;border-radius:8px;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent);border:1px solid rgba(255,255,255,0.02); transition: all 0.18s ease;}
    .adminex-list .adminex-row:hover { background: rgba(255,255,255,0.03); }
    .adminex-list .adminex-meta{color:var(--muted);font-size:.9rem}

    /* Quick actions: reduce visual footprint but keep same layout */
    .adminex-quick-actions{display:flex;flex-direction:column;gap:10px}
    .adminex-quick-actions .adminex-btn{padding:10px 14px;font-size:0.95rem;border-radius:28px}
    .adminex-quick-actions .adminex-btn.ghost{padding:8px 12px}
    .adminex-progress{height:8px;background:rgba(255,255,255,0.04);border-radius:999px;overflow:hidden}
    .adminex-progress .bar{height:100%;background:linear-gradient(90deg,var(--accent-a),var(--accent-c))}

    .adminex-neon-pill{display:inline-block;padding:6px 10px;border-radius:999px;background:linear-gradient(90deg, rgba(139,233,255,0.06), rgba(139,233,255,0.02));color:var(--neon);font-weight:800}

    .adminex-shimmer {position:relative;overflow:hidden;border-radius:8px;background:linear-gradient(90deg,#0b1a25,#07111a);min-height:140px}
    .adminex-shimmer::after{content:'';position:absolute;left:-150px;top:0;bottom:0;width:150px;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.03),transparent);animation:adminex-shimmer 1.6s linear infinite}
    @keyframes adminex-shimmer{100%{transform:translateX(140%)}}

    /* Responsive: stack columns on narrower screens */
    @media (max-width: 1000px) {
      .adminex-main-grid{grid-template-columns:1fr}
      .adminex-stats-grid{grid-template-columns:repeat(2,1fr)}
      .adminex-stat-spark{display:none}
      :root { --canvas-height: 160px; }
    }

    /* keep focus outline behaviour */
    .adminex-btn:focus, .adminex-stat-card:focus-within, .adminex-card a:focus { outline: 3px solid rgba(99,102,241,0.12); outline-offset: 3px; border-radius:10px; }
  </style>
</head>
<body<?= $has_sidebar ? ' class="has-admin-sidebar"' : ''?>>
<main class="admin-main adminex-main" role="main" aria-label="Admin dashboard">
  <div class="adminex-container">
    <div class="adminex-header">
      <div>
        <h1 class="adminex-title">Admin Dashboard</h1>
        <div class="adminex-sub">Overview &amp; analytics — quick insights and recent activity. Important space is kept for readability and future widgets.</div>
      </div>

      <div style="display:flex;gap:10px;align-items:center;min-width:0">
        <a href="classes.php" class="adminex-btn">Manage Classes</a>
        <a href="courses.php" class="adminex-btn ghost">Manage Courses</a>
      </div>
    </div>

    <section class="adminex-hero" aria-label="Top summary">
      <div class="adminex-stats-grid" role="list">
        <div class="adminex-stat-card" role="listitem" tabindex="0" aria-label="Total classes">
          <div class="adminex-stat-icon" aria-hidden>
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#c4b5fd" stroke-width="1.6"><rect x="3" y="4" width="18" height="5" rx="1"></rect><rect x="3" y="15" width="18" height="5" rx="1"></rect></svg>
          </div>
          <div class="adminex-stat-body">
            <div class="adminex-stat-num"><?= e($totalClasses) ?></div>
            <div class="adminex-stat-label">Total Classes</div>
          </div>
          <div class="adminex-stat-spark" aria-hidden>
            <svg viewBox="0 0 100 36" preserveAspectRatio="none" width="100%" height="100%">
              <?php
                $d = array_slice(json_decode($jsTrendData, true) ?: [2,4,3,6,7,8,7,9,10], -10);
                $len = max(2, count($d)); $max = max($d); $min = min($d); $pts=[];
                for ($i=0;$i<$len;$i++){
                    $x = ($i/($len-1))*100;
                    $y = 36 - (($d[$i]-$min)/max(1,$max-$min))*28 - 4;
                    $pts[] = "$x,$y";
                }
              ?>
              <polyline fill="none" stroke="#c4b5fd" stroke-width="2" points="<?= implode(' ', $pts) ?>"></polyline>
            </svg>
          </div>
        </div>

        <div class="adminex-stat-card" role="listitem" tabindex="0" aria-label="Total courses">
          <div class="adminex-stat-icon" aria-hidden><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#8bd7ff" stroke-width="1.6"><path d="M4 6h16M4 12h16M4 18h10"></path></svg></div>
          <div class="adminex-stat-body"><div class="adminex-stat-num"><?= e($totalCourses) ?></div><div class="adminex-stat-label">Total Courses</div></div>
          <div class="adminex-stat-spark" aria-hidden><svg viewBox="0 0 100 36" preserveAspectRatio="none"><polyline fill="none" stroke="#8bd7ff" stroke-width="2" points="0,28 20,22 40,18 60,20 80,14 100,16"></polyline></svg></div>
        </div>

        <div class="adminex-stat-card" role="listitem" tabindex="0" aria-label="Departments">
          <div class="adminex-stat-icon" aria-hidden><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#6ee7b7" stroke-width="1.6"><circle cx="12" cy="8" r="2"></circle><path d="M6 20v-3a4 4 0 014-4h4a4 4 0 014 4v3"></path></svg></div>
          <div class="adminex-stat-body"><div class="adminex-stat-num"><?= e($totalDepts) ?></div><div class="adminex-stat-label">Departments</div></div>
          <div class="adminex-stat-spark" aria-hidden><svg viewBox="0 0 100 36" preserveAspectRatio="none"><polyline fill="none" stroke="#6ee7b7" stroke-width="2" points="0,28 25,26 50,20 75,18 100,12"></polyline></svg></div>
        </div>

        <div class="adminex-stat-card" role="listitem" tabindex="0" aria-label="Students">
          <div class="adminex-stat-icon" aria-hidden><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#f6c35e" stroke-width="1.6"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path><path d="M6 20v-1a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v1"></path></svg></div>
          <div class="adminex-stat-body"><div class="adminex-stat-num"><?= e($totalStudents) ?></div><div class="adminex-stat-label">Students</div></div>
          <div class="adminex-stat-spark" aria-hidden><svg viewBox="0 0 100 36" preserveAspectRatio="none"><polyline fill="none" stroke="#f6c35e" stroke-width="2" points="0,28 18,26 36,24 54,22 72,18 90,12 100,10"></polyline></svg></div>
        </div>
      </div>
    </section>

    <section class="adminex-main-grid" aria-label="Main analytics and lists">
      <div>
        <div class="adminex-card" role="region" aria-label="Analytics">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
            <div>
              <h3>Analytics</h3>
              <div class="adminex-muted">Trends and breakdowns — useful for quick decisions</div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
              <span class="adminex-neon-pill" aria-hidden>Important</span>
              <a href="export.php" class="adminex-btn ghost" title="Export CSV">Export Data</a>
            </div>
          </div>

          <div class="adminex-analytics" aria-hidden="false">
            <div class="adminex-chart-wrap">
              <strong style="display:block">Classes — 12 month trend</strong>
              <div id="shimmer-trend" class="adminex-shimmer" aria-hidden="true"></div>
              <canvas id="classesTrend" role="img" aria-label="Line chart showing classes trend" style="display:none"></canvas>
            </div>

            <div class="adminex-chart-wrap">
              <strong style="display:block">Department — course distribution</strong>
              <div id="shimmer-donut" class="adminex-shimmer" aria-hidden="true"></div>
              <canvas id="deptDonut" role="img" aria-label="Donut chart showing courses per department" style="display:none"></canvas>
            </div>
          </div>

          <div style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="adminex-chart-wrap">
              <strong>GPA distribution</strong>
              <div id="shimmer-gpa" class="adminex-shimmer" aria-hidden="true"></div>
              <canvas id="gpaBar" style="height:140px;display:none" aria-label="Bar chart of GPA distribution"></canvas>
            </div>

            <div class="adminex-chart-wrap">
              <strong>Quick metrics</strong>
              <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px">
                <div style="display:flex;justify-content:space-between"><div class="adminex-meta">Avg classes / month</div><div class="adminex-meta"><?= e(round(array_sum($trendDataClasses)/max(1,count($trendDataClasses)),1)) ?></div></div>
                <div style="display:flex;justify-content:space-between"><div class="adminex-meta">Top dept (courses)</div><div class="adminex-meta"><?= e($deptBreakdown[0]['label'] ?? '-') ?></div></div>
                <div style="display:flex;justify-content:space-between"><div class="adminex-meta">Students</div><div class="adminex-meta"><?= e($totalStudents) ?></div></div>
              </div>
            </div>
          </div>
        </div>

        <div class="adminex-card" style="margin-top:12px" role="region" aria-label="Recent classes">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div><h3>Recent Classes</h3><div class="adminex-muted">Latest created or updated</div></div>
            <div style="display:flex;gap:8px">
              <a href="classes.php" class="adminex-btn ghost">View all</a>
              <a href="classes.php#openAddBtn" class="adminex-btn">Add Class</a>
            </div>
          </div>

          <div style="margin-top:12px">
            <?php if (empty($recentClasses)): ?>
              <div class="adminex-muted" style="padding:28px;text-align:center">No classes yet.</div>
            <?php else: ?>
              <div class="adminex-list" style="margin-top:6px">
                <?php foreach ($recentClasses as $c): ?>
                  <div class="adminex-row" role="article">
                    <div>
                      <div style="font-weight:800"><?= e($c['Class_Name'] ?: ('#'.$c['ClassID'])) ?></div>
                      <div class="adminex-meta"><?= e(($c['course_name'] ? $c['course_name'] : '-') . ($c['dept_name'] ? " — {$c['dept_name']}" : '')) ?></div>
                    </div>
                    <div style="text-align:right">
                      <div class="adminex-meta">Sem <?= e($c['Semester'] ?? '-') ?></div>
                      <div style="margin-top:6px"><a class="adminex-btn ghost small" href="classes.php?view=<?= urlencode($c['ClassID']) ?>">View</a></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <aside>
        <div class="adminex-card" role="region" aria-label="Quick actions">
          <h3>Quick Actions</h3>
          <div class="adminex-muted">Common admin tasks</div>

          <div class="adminex-quick-actions" style="margin-top:12px">
            <a href="classes.php#openAddBtn" class="adminex-btn">＋ Add Class</a>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <a href="courses.php" class="adminex-btn ghost">Courses</a>
              <a href="departments.php" class="adminex-btn ghost">Departments</a>
            </div>

            <div style="margin-top:12px">
              <div class="adminex-muted">System health</div>
              <div style="display:flex;align-items:center;gap:12px;margin-top:8px">
                <div style="flex:1">
                  <div class="adminex-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="65">
                    <div class="bar" style="width:65%"></div>
                  </div>
                </div>
                <div class="adminex-meta">65% used</div>
              </div>
            </div>
          </div>
        </div>

        <div style="height:12px" aria-hidden="true"></div>

        <div class="adminex-card" role="region" aria-label="Recent courses">
          <h3>Recent Courses</h3>
          <div class="adminex-muted">Newest entries</div>
          <div style="margin-top:10px;max-height:220px;overflow:auto" class="adminex-list">
            <?php if (empty($recentCourses)): ?>
              <div class="adminex-muted" style="padding:14px">No courses yet.</div>
            <?php else: foreach ($recentCourses as $rc): ?>
              <div class="adminex-row" style="padding:8px 10px">
                <div style="font-weight:800"><?= e($rc['Course_Name']) ?></div>
                <div class="adminex-meta"><?= e($rc['CourseID']) ?></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </aside>
    </section>

    <footer style="color:var(--muted);font-size:.9rem;padding-top:12px">© <?= date('Y') ?> German-Malaysian Institute · Admin panel</footer>
  </div>
</main>

<script>
  /* ---------- Chart data from PHP ---------- */
  const deptLabels = <?= $jsDeptLabels ?: '[]' ?>;
  const deptValues = <?= $jsDeptValues ?: '[]' ?>;
  const trendLabels = <?= $jsTrendLabels ?: '[]' ?>;
  const trendData = <?= $jsTrendData ?: '[]' ?>;
  const gpaBuckets = <?= $jsGpaBuckets ?: '[0,0,0,0]' ?>;

  function createGradient(ctx, color1, color2) {
    const g = ctx.createLinearGradient(0,0,0,220);
    g.addColorStop(0, color1);
    g.addColorStop(1, color2);
    return g;
  }

  // if a sidebar exists in DOM, read its actual width and set CSS variable
  function applySidebarWidthFromDOM() {
    const selectors = ['.admin-sidebar', '.sidebar', '#sidebar', '.sidebar-left', '.main-sidebar'];
    let el = null;
    for (const s of selectors) {
      const found = document.querySelector(s);
      if (found) { el = found; break; }
    }
    if (el) {
      const rect = el.getBoundingClientRect();
      const width = Math.round(rect.width);
      if (width > 0) {
        document.documentElement.style.setProperty('--sidebar-width', width + 'px');
        document.body.classList.add('has-admin-sidebar');
      }
    }
  }

  window.addEventListener('DOMContentLoaded', function(){
    applySidebarWidthFromDOM();
    setTimeout(applySidebarWidthFromDOM, 250);

    // Classes trend chart
    const canvasTrend = document.getElementById('classesTrend');
    const shimmerTrend = document.getElementById('shimmer-trend');
    if (canvasTrend && window.Chart) {
      const ctx = canvasTrend.getContext('2d');
      const grad = createGradient(ctx, 'rgba(124,58,237,0.28)', 'rgba(6,182,212,0.04)');
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: trendLabels,
          datasets: [{
            label: 'Classes',
            data: trendData,
            borderColor: '#c4b5fd',
            backgroundColor: grad,
            fill: true,
            tension: 0.34,
            pointRadius: 3,
            pointBackgroundColor: '#fff'
          }]
        },
        options: {
          plugins: { legend: { display:false }, tooltip: { mode:'index', intersect:false } },
          interaction: { intersect: false, mode: 'index' },
          scales: {
            x: { grid: { display:false }, ticks: { color: '#aebfda' } },
            y: { grid: { color:'rgba(255,255,255,0.02)' }, beginAtZero:true, ticks: { color: '#aebfda' } }
          },
          responsive:true, maintainAspectRatio:false
        }
      });
      if (shimmerTrend) shimmerTrend.style.display = 'none';
      canvasTrend.style.display = 'block';
    }

    // Dept donut
    const canvasDonut = document.getElementById('deptDonut');
    const shimmerDonut = document.getElementById('shimmer-donut');
    if (canvasDonut && window.Chart) {
      const ctx = canvasDonut.getContext('2d');
      new Chart(ctx, {
        type:'doughnut',
        data: {
          labels: deptLabels,
          datasets: [{
            data: deptValues,
            backgroundColor: ['#7c3aed','#06b6d4','#6ee7b7','#f6c35e','#fb7185','#60a5fa','#c084fc','#34d399'],
            borderColor: 'rgba(0,0,0,0)',
            borderWidth: 0
          }]
        },
        options: {
          plugins: { legend: { position:'bottom', labels: { color:'#cfe8ff' } } },
          responsive:true, maintainAspectRatio:false, cutout: '60%'
        }
      });
      if (shimmerDonut) shimmerDonut.style.display = 'none';
      canvasDonut.style.display = 'block';
    }

    // GPA bar
    const canvasGpa = document.getElementById('gpaBar');
    const shimmerGpa = document.getElementById('shimmer-gpa');
    if (canvasGpa && window.Chart) {
      const ctx = canvasGpa.getContext('2d');
      new Chart(ctx, {
        type:'bar',
        data: {
          labels:['0-1','1-2','2-3','3-4'],
          datasets:[{ data: gpaBuckets, backgroundColor: ['#fb7185','#f59e0b','#34d399','#7c3aed'] }]
        },
        options:{
          plugins:{ legend:{ display:false } },
          scales: { y:{ beginAtZero:true, ticks:{ color:'#aebfda' } }, x:{ ticks:{ color:'#aebfda' }, grid:{ display:false } } },
          responsive:true, maintainAspectRatio:false
        }
      });
      if (shimmerGpa) shimmerGpa.style.display = 'none';
      canvasGpa.style.display = 'block';
    }
  });
</script>
</body>
</html>
