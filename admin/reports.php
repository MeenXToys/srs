<?php
// admin/reports.php
require_once __DIR__ . '/../config.php';
require_admin();

if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

/* ----------------- CSRF ----------------- */
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

/* ----------------- helpers ----------------- */
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
function fetch_all_limit($pdo, $sql, $limit = 6) {
    try {
        $stmt = $pdo->prepare($sql . " LIMIT :L");
        $stmt->bindValue(':L', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}
function paginated_rows($pdo, $sql_count, $sql_rows, $page = 1, $per_page = 10, $params = []) {
    $total = 0;
    try {
        $stmt = $pdo->prepare($sql_count);
        foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->execute();
        $total = (int)$stmt->fetchColumn();
    } catch (Throwable $t) { $total = 0; }

    $offset = max(0, ($page - 1) * $per_page);
    $rows = [];
    try {
        $rstmt = $pdo->prepare($sql_rows . " LIMIT :L OFFSET :O");
        foreach ($params as $k=>$v) $rstmt->bindValue($k,$v);
        $rstmt->bindValue(':L', (int)$per_page, PDO::PARAM_INT);
        $rstmt->bindValue(':O', (int)$offset, PDO::PARAM_INT);
        $rstmt->execute();
        $rows = $rstmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) { $rows = []; }

    return ['total' => $total, 'rows' => $rows, 'page' => $page, 'per_page' => $per_page];
}

/* Escape for CSV (add quotes around fields that contain comma/quote/newline) */
function csv_escape($val) {
    if ($val === null) return '';
    $v = (string)$val;
    if (strpos($v, '"') !== false) $v = str_replace('"', '""', $v);
    if (strpos($v, ',') !== false || strpos($v, "\n") !== false || strpos($v, '"') !== false) {
        return "\"{$v}\"";
    }
    return $v;
}

/* ----------------- handle CSV export POST (existing) ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) && $_POST['action'] === 'export')) {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, (string)$token)) {
        http_response_code(400);
        echo "Invalid CSRF token";
        exit;
    }

    $type = $_POST['type'] ?? 'all'; // classes, courses, students, departments, all
    $now = date('Y-m-d_His');

    // helper to stream CSV table
    $stream_csv = function($filename, $headerRow, $rowsGenerator) use ($now) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'. basename($filename) .'"');
        // output BOM for Excel compatibility
        echo "\xEF\xBB\xBF";
        // header
        echo implode(',', array_map('csv_escape', $headerRow)) . "\n";
        // rows
        foreach ($rowsGenerator as $row) {
            $out = [];
            foreach ($row as $cell) $out[] = csv_escape($cell);
            echo implode(',', $out) . "\n";
        }
        exit;
    };

    try {
        if ($type === 'classes') {
            $sql = "SELECT c.ClassID, c.Class_Name, c.Semester, co.Course_Name AS Course, d.Dept_Name AS Department
                    FROM `class` c
                    LEFT JOIN `course` co ON co.CourseID = c.CourseID
                    LEFT JOIN `department` d ON d.DepartmentID = co.DepartmentID
                    ORDER BY c.ClassID ASC";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cols = ['ClassID','Class_Name','Semester','Course','Department'];
            $stream_csv("classes_{$now}.csv", $cols, $rows);
        } elseif ($type === 'courses') {
            $sql = "SELECT co.CourseID, co.Course_Name, d.Dept_Name AS Department
                    FROM `course` co
                    LEFT JOIN `department` d ON d.DepartmentID = co.DepartmentID
                    ORDER BY co.CourseID ASC";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cols = ['CourseID','Course_Name','Department'];
            $stream_csv("courses_{$now}.csv", $cols, $rows);
        } elseif ($type === 'students') {
            $sql = "SELECT s.StudentID, s.MatricNo AS Matric, s.FullName, s.Email, s.ClassID FROM `student` s ORDER BY s.StudentID ASC";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cols = ['StudentID','Matric','FullName','Email','ClassID'];
            $stream_csv("students_{$now}.csv", $cols, $rows);
        } elseif ($type === 'departments') {
            $sql = "SELECT DepartmentID, Dept_Name, Dept_Code FROM `department` ORDER BY DepartmentID ASC";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cols = ['DepartmentID','Dept_Name','Dept_Code'];
            $stream_csv("departments_{$now}.csv", $cols, $rows);
        } else {
            // combined export: produce a single CSV with section headers
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="report_all_'.$now.'.csv"');
            echo "\xEF\xBB\xBF";
            // classes
            echo "=== Classes ===\n";
            $stmt = $pdo->query("SELECT c.ClassID, c.Class_Name, c.Semester, co.Course_Name AS Course, d.Dept_Name AS Department
                    FROM `class` c
                    LEFT JOIN `course` co ON co.CourseID = c.CourseID
                    LEFT JOIN `department` d ON d.DepartmentID = co.DepartmentID
                    ORDER BY c.ClassID ASC");
            echo implode(',', array_map('csv_escape', ['ClassID','Class_Name','Semester','Course','Department'])) . "\n";
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out=[]; foreach ($r as $c) $out[] = csv_escape($c); echo implode(',',$out)."\n";
            }
            echo "\n=== Courses ===\n";
            $stmt = $pdo->query("SELECT co.CourseID, co.Course_Name, d.Dept_Name AS Department
                    FROM `course` co
                    LEFT JOIN `department` d ON d.DepartmentID = co.DepartmentID
                    ORDER BY co.CourseID ASC");
            echo implode(',', array_map('csv_escape', ['CourseID','Course_Name','Department'])) . "\n";
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out=[]; foreach ($r as $c) $out[] = csv_escape($c); echo implode(',',$out)."\n";
            }
            echo "\n=== Students ===\n";
            $stmt = $pdo->query("SELECT s.StudentID, s.MatricNo AS Matric, s.FullName, s.Email, s.ClassID FROM `student` s ORDER BY s.StudentID ASC");
            echo implode(',', array_map('csv_escape', ['StudentID','Matric','FullName','Email','ClassID'])) . "\n";
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out=[]; foreach ($r as $c) $out[] = csv_escape($c); echo implode(',',$out)."\n";
            }
            exit;
        }
    } catch (Throwable $t) {
        http_response_code(500);
        echo "Export failed.";
        exit;
    }
}

/* ----------------- page vars & pagination ----------------- */
$TYPE = in_array($_GET['type'] ?? 'classes', ['classes','courses','students','departments']) ? ($_GET['type'] ?? 'classes') : 'classes';
$PAGE = max(1, (int)($_GET['page'] ?? 1));
$PER_PAGE_DEFAULT = 8;

/* ----------------- summary data for page ----------------- */
$totalClasses = safe_count($pdo, "SELECT COUNT(*) FROM `class`");
$totalCourses = safe_count($pdo, "SELECT COUNT(*) FROM `course`");
$totalDepts   = safe_count($pdo, "SELECT COUNT(*) FROM `department`");
$totalStudents= safe_count($pdo, "SELECT COUNT(*) FROM `student`");

// previews (non-paginated small lists)
$previewClasses = fetch_all_limit($pdo, "SELECT c.ClassID, c.Class_Name, c.Semester, co.Course_Name AS Course, d.Dept_Name AS Department
    FROM `class` c
    LEFT JOIN `course` co ON co.CourseID = c.CourseID
    LEFT JOIN `department` d ON d.DepartmentID = co.DepartmentID
    ORDER BY c.ClassID DESC", 6);

$previewCourses = fetch_all_limit($pdo, "SELECT CourseID, Course_Name FROM `course` ORDER BY CourseID DESC", 6);

$previewStudents = fetch_all_limit($pdo, "SELECT StudentID, MatricNo AS Matric, FullName, Email FROM `student` ORDER BY StudentID DESC", 6);

$previewDepts = fetch_all_limit($pdo, "SELECT DepartmentID, Dept_Name, Dept_Code FROM `department` ORDER BY DepartmentID DESC", 6);

// Build simple chart data (top departments by course count)
$topDepts = [];
try {
    $stmt = $pdo->query("SELECT d.Dept_Name, COUNT(co.CourseID) AS course_count
                         FROM `department` d
                         LEFT JOIN `course` co ON co.DepartmentID = d.DepartmentID
                         GROUP BY d.DepartmentID
                         ORDER BY course_count DESC
                         LIMIT 12");
    $topDepts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) { $topDepts = []; }

/* ----------------- paginated table fetch for the main viewer ----------------- */
$paginated = ['total'=>0,'rows'=>[],'page'=>$PAGE,'per_page'=>$PER_PAGE_DEFAULT];
if ($TYPE === 'classes') {
    $paginated = paginated_rows(
        $pdo,
        "SELECT COUNT(*) FROM `class` c",
        "SELECT c.ClassID, c.Class_Name, c.Semester, co.Course_Name AS Course, d.Dept_Name AS Department
         FROM `class` c
         LEFT JOIN `course` co ON co.CourseID = c.CourseID
         LEFT JOIN `department` d ON d.DepartmentID = co.DepartmentID
         ORDER BY c.ClassID DESC",
        $PAGE,
        $PER_PAGE_DEFAULT
    );
} elseif ($TYPE === 'courses') {
    $paginated = paginated_rows(
        $pdo,
        "SELECT COUNT(*) FROM `course`",
        "SELECT co.CourseID, co.Course_Name, d.Dept_Name AS Department
         FROM `course` co
         LEFT JOIN `department` d ON d.DepartmentID = co.DepartmentID
         ORDER BY co.CourseID DESC",
        $PAGE,
        $PER_PAGE_DEFAULT
    );
} elseif ($TYPE === 'students') {
    $paginated = paginated_rows(
        $pdo,
        "SELECT COUNT(*) FROM `student`",
        "SELECT s.StudentID, s.MatricNo AS Matric, s.FullName, s.Email, s.ClassID
         FROM `student` s
         ORDER BY s.StudentID DESC",
        $PAGE,
        $PER_PAGE_DEFAULT
    );
} elseif ($TYPE === 'departments') {
    $paginated = paginated_rows(
        $pdo,
        "SELECT COUNT(*) FROM `department`",
        "SELECT DepartmentID, Dept_Name, Dept_Code FROM `department` ORDER BY DepartmentID DESC",
        $PAGE,
        $PER_PAGE_DEFAULT
    );
}

/* ----------------- render ----------------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reports — Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="../style.css">
<!-- Chart.js and html2pdf (client-side PDF export) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>

<style>
:root{
  --bg:#0f1724; --card:#0b1520; --muted:#94a3b8; --text:#e6eef8;
  --accent1:#7c3aed; --accent2:#6d28d9; --danger:#ef4444;
}
html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,Arial;}
.admin-main{padding:22px;}
.center-box{max-width:1200px;margin:0 auto;}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.header h1{margin:0}
.card{background:linear-gradient(180deg,var(--card),#07111a) !important;border-radius:12px;max-width:1000px !important; padding:1px;border:1px solid rgba(255,255,255,0.04);box-shadow:0 18px 48px rgba(0,0,0,0.6) !important;}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px;margin-bottom:16px}
.tile{grid-column:span 3;padding:14px;border-radius:12px;display:flex;align-items:center;gap:12px;background:linear-gradient(180deg, rgba(255,255,255,0.012), transparent);border:1px solid rgba(255,255,255,0.02)}
.tile .num{font-weight:800;font-size:1.6rem}
.tile .label{color:var(--muted)}
.actions{display:flex;gap:10px;align-items:center}
.btn{background:linear-gradient(90deg,var(--accent1),var(--accent2));color:#fff;padding:8px 12px;border-radius:10px;border:0;cursor:pointer;text-decoration:none;font-weight:700}
.btn.ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--text);padding:8px 12px;border-radius:10px}
.section{display:flex;gap:16px;align-items:flex-start}
.left{width:auto}
.right{flex:3; ,}
.preview-list{margin-top:12px}
.preview-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px dashed rgba(255,255,255,0.02)}
.preview-row:last-child{border-bottom:0}
.small{color:var(--muted);font-size:0.95rem}
.export-form{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.csv-note{margin-top:8px;color:var(--muted);font-size:0.92rem}
.bar-wrap{display:flex;flex-direction:column;gap:8px;margin-top:12px}
.bar{height:10px;background:rgba(255,255,255,0.04);border-radius:999px;overflow:hidden}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--accent1),var(--accent2))}

/* main viewer (paginated table) */
.viewer-controls{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}
.viewer-controls .tabs{display:flex;gap:6px;flex-wrap:wrap}
.tab{padding:8px 10px;border-radius:8px;background:transparent;border:1px solid rgba(255,255,255,0.03);color:var(--muted);cursor:pointer;text-decoration:none}
.tab.active{background:linear-gradient(90deg,rgba(56,189,248,0.08),rgba(255,255,255,0.01));color:#38bdf8;font-weight:700}
.table{width:100%;border-collapse:collapse;margin-top:8px}
.table th, .table td{padding:10px;border-top:1px solid rgba(255,255,255,0.02);color:var(--text);vertical-align:top;text-align:left}
.table th{color:var(--muted);font-weight:700}
.pager{display:flex;gap:6px;align-items:center;justify-content:flex-end;margin-top:12px}
.pager a{padding:8px 10px;border-radius:8px;background:transparent;border:1px solid rgba(255,255,255,0.03);color:var(--muted);text-decoration:none}
.pager a.current{background:#071528;color:#fff;border-color:rgba(255,255,255,0.02)}

/* chart area fixes: reserve space and fallback text when no-data or on error */
.chart-wrap{position:relative;background:linear-gradient(180deg, rgba(255,255,255,0.01), rgba(255,255,255,0.00));border-radius:8px;padding:12px;margin-top:12px}
#deptChart { width:100%; height:220px; display:block; background:transparent; border-radius:6px; }
.chart-fallback { display:flex; align-items:center; justify-content:center; height:220px; color:var(--muted); border-radius:6px; background:rgba(255,255,255,0.01); }

/* printable layout */
@media print {
  body * { visibility: hidden; }
  #printArea, #printArea * { visibility: visible; }
  #printArea { position: absolute; left: 0; top: 0; width: 100%; }
}

/* responsive */
@media (max-width:980px){ .grid .tile{grid-column:span 6} .left{width:100%} .section{flex-direction:column} }
</style>
</head>
<body>
<?php include __DIR__ . '/admin_nav.php'; ?>

<main class="admin-main">
  <div class="center-box">
    <div class="header">
      <div>
        <h1>Reports</h1>
        <div class="small">Export data, view summaries, printable PDF and downloadable charts.</div>
      </div>
      <div class="actions">
        <a href="index.php" class="btn ghost">Back</a>
      </div>
    </div>

    <!-- summary tiles -->
    <div class="grid" role="region" aria-label="Summary">
      <div class="tile card"><div><div class="num"><?= e($totalClasses) ?></div><div class="label">Classes</div></div></div>
      <div class="tile card"><div><div class="num"><?= e($totalCourses) ?></div><div class="label">Courses</div></div></div>
      <div class="tile card"><div><div class="num"><?= e($totalDepts) ?></div><div class="label">Departments</div></div></div>
      <div class="tile card"><div><div class="num"><?= e($totalStudents) ?></div><div class="label">Students</div></div></div>
    </div>

    <div class="section">
      <div class="left">
        
        

        <div class="card">
          <strong>Top departments (by course count)</strong>
          <div class="small" style="margin-top:6px">Shows departments with the most courses</div>

          <div class="chart-wrap">
            <?php if (empty($topDepts)): ?>
              <div class="chart-fallback">No department/course data available to display chart.</div>
            <?php else: ?>
              <canvas id="deptChart" aria-label="Top departments chart" role="img"></canvas>
            <?php endif; ?>
          </div>

          <div style="margin-top:8px;display:flex;gap:8px;">
            <button class="btn" id="btnDownloadChart">Download Chart PNG</button>
          </div>

          <div class="bar-wrap" style="margin-top:12px">
            <?php if (empty($topDepts)): ?>
              <div class="small">No department data available.</div>
            <?php else:
              $maxc = max(array_column($topDepts, 'course_count')) ?: 1;
              foreach ($topDepts as $td):
                $pct = round(($td['course_count'] / $maxc) * 100);
            ?>
              <div style="display:flex;justify-content:space-between;align-items:center">
                <div class="small"><?= e($td['Dept_Name']) ?></div>
                <div class="small"><?= e($td['course_count']) ?> course<?= $td['course_count']>1?'s':'' ?></div>
              </div>
              <div class="bar"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div style="height:15px"></div>

        <div class="card">
          <strong>Quick exports</strong>
          <div class="small" style="margin-top:6px">Download CSV files of the requested dataset. CSV is UTF-8 with BOM for Excel compatibility.</div>

          <form method="post" class="export-form" style="margin-top:12px">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="export">
            <button class="btn" name="type" value="classes" type="submit">Export Classes CSV</button>
            <button class="btn" name="type" value="courses" type="submit">Export Courses CSV</button>
            <button class="btn" name="type" value="students" type="submit">Export Students CSV</button>
            <button class="btn" name="type" value="departments" type="submit">Export Departments CSV</button>
            <button class="btn ghost" name="type" value="all" type="submit">Export Combined</button>
          </form>

          <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn" id="btnExportPDF">Export PDF (printable)</button>
            <button class="btn ghost" id="btnPrint" onclick="window.print()">Print</button>
          </div>

          <div class="csv-note">Tip: Use <strong>Export Combined</strong> to get a single CSV containing classes, courses and students in sections.</div>
        </div>

      </div>

      <div class="right">
        <div class="card" id="printArea" style="width: 700px !important;">
          <div class="viewer-controls">
            <div>
              <div style="font-weight:700;font-size:1.05rem">Preview — Paginated</div>
              <div class="small" style="margin-top:4px">Browse and download the latest records</div>
            </div>

            <div style="display:flex;gap:10px;align-items:center">
              <div class="tabs" role="tablist" aria-label="Record type">
                <?php
                  $types = ['classes'=>'Classes','courses'=>'Courses','students'=>'Students','departments'=>'Departments'];
                  foreach ($types as $k=>$lab):
                    $active = $TYPE === $k ? 'active' : '';
                    $url = '?type='.$k.'&page=1';
                ?>
                  <a class="tab <?= $active ?>" href="<?= e($url) ?>"><?= e($lab) ?></a>
                <?php endforeach; ?>
              </div>
              <div style="width:8px"></div>
              <div class="small-muted">Showing <?= e($TYPE) ?></div>
            </div>
          </div>

          <!-- Paginated table -->
          <div style="margin-top:12px;overflow:auto">
            <?php if ($TYPE === 'classes'): ?>
              <table class="table" role="table" aria-label="Classes table">
                <thead><tr><th>Class</th><th>Course · Department</th><th>Semester</th><th>ID</th></tr></thead>
                <tbody>
                  <?php if (empty($paginated['rows'])): ?>
                    <tr><td colspan="4" class="small">No classes found.</td></tr>
                  <?php else: foreach ($paginated['rows'] as $r): ?>
                    <tr>
                      <td><strong><?= e($r['Class_Name']) ?></strong></td>
                      <td class="small"><?= e($r['Course'] ?? '-') ?> · <?= e($r['Department'] ?? '-') ?></td>
                      <td><?= e($r['Semester'] ?? '-') ?></td>
                      <td class="small-muted"><?= e($r['ClassID']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>

            <?php elseif ($TYPE === 'courses'): ?>
              <table class="table" role="table" aria-label="Courses table">
                <thead><tr><th>Course</th><th>Department</th><th>ID</th></tr></thead>
                <tbody>
                  <?php if (empty($paginated['rows'])): ?>
                    <tr><td colspan="3" class="small">No courses found.</td></tr>
                  <?php else: foreach ($paginated['rows'] as $r): ?>
                    <tr>
                      <td><strong><?= e($r['Course_Name']) ?></strong></td>
                      <td class="small"><?= e($r['Department'] ?? '-') ?></td>
                      <td class="small-muted"><?= e($r['CourseID']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>

            <?php elseif ($TYPE === 'students'): ?>
              <table class="table" role="table" aria-label="Students table">
                <thead><tr><th>Name / Matric</th><th>Email</th><th>ClassID</th><th>ID</th></tr></thead>
                <tbody>
                  <?php if (empty($paginated['rows'])): ?>
                    <tr><td colspan="4" class="small">No students found.</td></tr>
                  <?php else: foreach ($paginated['rows'] as $r): ?>
                    <tr>
                      <td><strong><?= e($r['FullName'] ?? $r['Matric']) ?></strong><div class="small"><?= e($r['Matric'] ?? '') ?></div></td>
                      <td class="small"><?= e($r['Email'] ?? '') ?></td>
                      <td><?= e($r['ClassID'] ?? '-') ?></td>
                      <td class="small-muted"><?= e($r['StudentID'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>

            <?php else: /* departments */ ?>
              <table class="table" role="table" aria-label="Departments table">
                <thead><tr><th>Department</th><th>Code</th><th>ID</th></tr></thead>
                <tbody>
                  <?php if (empty($paginated['rows'])): ?>
                    <tr><td colspan="3" class="small">No departments found.</td></tr>
                  <?php else: foreach ($paginated['rows'] as $r): ?>
                    <tr>
                      <td><strong><?= e($r['Dept_Name'] ?? '-') ?></strong></td>
                      <td class="small"><?= e($r['Dept_Code'] ?? '') ?></td>
                      <td class="small-muted"><?= e($r['DepartmentID'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <!-- pager -->
          <div class="pager" aria-label="Pagination">
            <?php
              $total = $paginated['total'];
              $per = $paginated['per_page'];
              $cur = $paginated['page'];
              $pages = max(1, (int)ceil($total / max(1,$per)));
              $base = '?type=' . urlencode($TYPE) . '&page=';
              $start = max(1, $cur - 3);
              $end = min($pages, $cur + 3);
              if ($pages > 1):
                if ($cur > 1) echo '<a href="'.e($base.($cur-1)).'">‹ Prev</a>';
                for ($p = $start; $p <= $end; $p++) {
                  $cls = $p === $cur ? 'current' : '';
                  echo '<a class="'.e($cls).'" href="'.e($base.$p).'">'.e($p).'</a>';
                }
                if ($cur < $pages) echo '<a href="'.e($base.($cur+1)).'">Next ›</a>';
              endif;
            ?>
          </div>

          <div style="margin-top:10px" class="small">Showing page <?= e($cur) ?> of <?= e($pages) ?> (<?= e($total) ?> total records)</div>

        </div> <!-- end card / printArea -->
      </div>
    </div>

    <footer style="margin-top:18px;color:var(--muted);font-size:0.9rem">
      © <?= date('Y') ?> Your Institution · Reports
    </footer>
  </div>
</main>

<script>
// Prepare chart data for top departments
const topDepts = <?= json_encode(array_values($topDepts)) ?> || [];
const labels = topDepts.map(d => d.Dept_Name || '—');
const values = topDepts.map(d => Number(d.course_count) || 0);

// Only initialize chart when there is data
const chartCanvas = document.getElementById('deptChart');

function createChart() {
  if (!chartCanvas) return null;
  try {
    const ctx = chartCanvas.getContext('2d');
    // destroy existing chart if any (safety if you reload)
    if (window._deptChart && typeof window._deptChart.destroy === 'function') {
      window._deptChart.destroy();
      window._deptChart = null;
    }
    window._deptChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Courses per department',
          data: values,
          backgroundColor: labels.map((_,i) => `rgba(${(120+i*10)%255},${(60+i*20)%255},${(180+i*15)%255},0.9)`),
          borderRadius: 6,
          barPercentage: 0.6,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { mode: 'index' }
        },
        scales: {
          y: { beginAtZero: true, ticks: { color: 'rgba(255,255,255,0.8)' } },
          x: { ticks: { color: 'rgba(255,255,255,0.8)' } }
        }
      }
    });
    return window._deptChart;
  } catch (err) {
    // show a fallback message if chart fails
    console.error('Chart creation error:', err);
    if (chartCanvas && chartCanvas.parentNode) {
      chartCanvas.parentNode.innerHTML = '<div class="chart-fallback">Chart failed to render. Try reloading the page.</div>';
    }
    return null;
  }
}

if (labels.length === 0 || values.length === 0 || values.every(v => v === 0)) {
  // nothing to draw — ensure the canvas is removed and a friendly message shown
  if (chartCanvas && chartCanvas.parentNode) {
    chartCanvas.parentNode.innerHTML = '<div class="chart-fallback">No department/course data available to display chart.</div>';
  }
} else {
  // canvas exists and there is data — create chart after DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', createChart);
  } else {
    createChart();
  }
}

// Chart download
document.getElementById('btnDownloadChart').addEventListener('click', function(){
  if (!window._deptChart) { alert('No chart to download.'); return; }
  try {
    const a = document.createElement('a');
    a.href = window._deptChart.toBase64Image('image/png', 1);
    a.download = 'top_departments_<?= date("Ymd_His") ?>.png';
    a.click();
  } catch (e) {
    alert('Chart export not available in this browser.');
  }
});

// Export printable area as PDF (client-side)
document.getElementById('btnExportPDF').addEventListener('click', function(){
  const element = document.getElementById('printArea');
  if (!element) { alert('Nothing to print.'); return; }
  const opt = {
    margin:       10,
    filename:     'report_printable_<?= date("Ymd_His") ?>.pdf',
    image:        { type: 'jpeg', quality: 0.95 },
    html2canvas:  { scale: 2, useCORS: true },
    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
  };
  html2pdf().set(opt).from(element).save().catch(err => {
    alert('PDF export failed: ' + (err && err.message ? err.message : err));
  });
});
</script>

</body>
</html>
