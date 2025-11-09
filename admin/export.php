<?php
// admin/export.php
require_once __DIR__ . '/../config.php';
require_admin();

// safe echo
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ----------------- CSRF ----------------- */
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

/* ----------------- locate admin nav ----------------- */
$navCandidates = [
    __DIR__ . '/admin_nav.php',
    __DIR__ . '/../admin_nav.php',
    __DIR__ . '/nav.php',
    __DIR__ . '/../nav.php'
];
$navToInclude = null;
foreach ($navCandidates as $c) { if (file_exists($c)) { $navToInclude = $c; break; } }

/* ----------------- helpers & datasets ----------------- */
$datasets = [
    'students' => 'Students',
    'departments' => 'Departments',
    'courses' => 'Courses',
    'classes' => 'Classes'
];

function normalize_headers(array $row): array {
    return array_map(function($h){ return ucwords(str_replace('_',' ',$h)); }, array_keys($row));
}
function export_filename(string $dataset, string $format): string {
    $date = date('Ymd_His');
    $ext = ($format === 'json') ? 'json' : (($format === 'xls') ? 'xls' : (($format === 'pdf') ? 'pdf' : 'csv'));
    return "{$dataset}_export_{$date}.{$ext}";
}
function csv_escape($val) {
    if ($val === null) return '';
    $v = (string)$val;
    if (strpos($v, '"') !== false) $v = str_replace('"','""',$v);
    if (strpos($v, ',') !== false || strpos($v, "\n") !== false || strpos($v, '"') !== false) {
        return "\"{$v}\"";
    }
    return $v;
}

/* Robust dataset fetchers */
function fetch_dataset(PDO $pdo, string $dataset): array {
    if ($dataset === 'students') {
        // prefer student table; fallback to user role
        try {
            $stmt = $pdo->query("
                SELECT 
                  COALESCE(s.StudentID, u.UserID) AS StudentID,
                  COALESCE(s.MatricNo,'') AS Matric,
                  COALESCE(s.FullName,u.Email) AS FullName,
                  COALESCE(u.Email,'') AS Email,
                  COALESCE(s.IC_Number,'') AS IC_Number,
                  COALESCE(s.Phone,'') AS Phone,
                  COALESCE(s.ClassID,'') AS ClassID
                FROM `student` s
                LEFT JOIN `user` u ON u.UserID = s.UserID
                ORDER BY s.FullName ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) return $rows;
        } catch (Throwable $t) { /* ignore and fallback */ }

        // fallback: users with Role='Student'
        try {
            $stmt = $pdo->query("SELECT UserID AS StudentID, '' AS Matric, COALESCE(Email,'') AS FullName, COALESCE(Email,'') AS Email, '' AS IC_Number, '' AS Phone, '' AS ClassID FROM `user` WHERE Role='Student' ORDER BY Email");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $t) { return []; }
    }

    if ($dataset === 'departments') {
        $stmt = $pdo->query("SELECT DepartmentID, Dept_Code, Dept_Name FROM department ORDER BY Dept_Name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($dataset === 'courses') {
        $stmt = $pdo->query("SELECT CourseID, Course_Code, Course_Name, Credit, DepartmentID FROM course ORDER BY Course_Code");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($dataset === 'classes') {
        $stmt = $pdo->query("
            SELECT cl.ClassID, cl.Class_Name, cl.Semester, COALESCE(co.Course_Code,'') AS Course_Code, COALESCE(co.Course_Name,'') AS Course_Name
            FROM `class` cl
            LEFT JOIN `course` co ON co.CourseID = cl.CourseID
            ORDER BY cl.Class_Name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [];
}

/* Robust counts for dataset sizes (fixing previous bug) */
function dataset_counts(PDO $pdo) {
    $out = ['students'=>0,'courses'=>0,'classes'=>0,'departments'=>0];
    try {
        // students: prefer student table count, fallback to user role count
        $c = 0;
        try {
            $c = (int)$pdo->query("SELECT COUNT(*) FROM student")->fetchColumn();
        } catch (Throwable $t) { $c = 0; }
        if ($c <= 0) {
            try { $c = (int)$pdo->query("SELECT COUNT(*) FROM `user` WHERE Role='Student'")->fetchColumn(); } catch (Throwable $t) { $c = 0; }
        }
        $out['students'] = $c;

        // courses
        try { $out['courses'] = (int)$pdo->query("SELECT COUNT(*) FROM course")->fetchColumn(); } catch (Throwable $t) { $out['courses'] = 0; }

        // classes
        try { $out['classes'] = (int)$pdo->query("SELECT COUNT(*) FROM class")->fetchColumn(); } catch (Throwable $t) { $out['classes'] = 0; }

        // departments
        try { $out['departments'] = (int)$pdo->query("SELECT COUNT(*) FROM department")->fetchColumn(); } catch (Throwable $t) { $out['departments'] = 0; }
    } catch (Throwable $t) { /* ignore */ }
    return $out;
}

/* ----------------- sample CSV (GET) ----------------- */
if (isset($_GET['sample'])) {
    $dataset = $_GET['sample'];
    if (!array_key_exists($dataset, $datasets)) {
        http_response_code(400);
        echo "Unknown sample dataset.";
        exit;
    }
    $samples = [
        'students' => [
            ['StudentID' => '1', 'Matric' => 'S001', 'FullName' => 'Sample Student', 'Email' => 'student@example.com', 'ClassID' => 'C1']
        ],
        'courses' => [
            ['CourseID' => '1', 'Course_Code' => 'CRS101', 'Course_Name' => 'Intro to Sample', 'Credit' => '3']
        ],
        'classes' => [
            ['ClassID' => '1', 'Class_Name' => 'Sample Class', 'Semester' => '1', 'Course_Name' => 'Intro to Sample', 'Department' => 'Sample Dept']
        ],
        'departments' => [
            ['DepartmentID' => '1', 'Dept_Name' => 'Sample Dept', 'Dept_Code' => 'SD01']
        ]
    ];
    $now = date('Ymd_His');
    $filename = $dataset . '_sample_' . $now . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    $rows = $samples[$dataset];
    if (!empty($rows)) {
        $out = fopen('php://output','w');
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($out, $r);
    }
    exit;
}

/* ================= Handle AJAX preview BEFORE output ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview']) && $_POST['preview'] === '1') {
    $dataset = $_POST['dataset'] ?? 'students';
    try {
        $rows = fetch_dataset($pdo, $dataset);
    } catch (Exception $ex) {
        echo '<div class="flash error">Error fetching data.</div>';
        exit;
    }
    $rows = array_slice($rows, 0, 50);
    if (empty($rows)) {
        echo '<div class="flash error">No data found for selected dataset.</div>';
        exit;
    }
    // render small table
    echo '<table class="table-preview"><thead><tr>';
    foreach (normalize_headers($rows[0]) as $h) echo '<th>'.e($h).'</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $v) echo '<td>'.e((string)$v).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    exit;
}

/* ================= Handle AJAX estimate BEFORE output =================
   POST { action: 'estimate', dataset: 'students', include_headers: '1', csrf_token }
   returns JSON: { rows: n, approx_bytes: n, sample_rows: m }
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'estimate') {
    // CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, (string)$token)) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit;
    }

    $dataset = $_POST['dataset'] ?? 'students';
    $include_headers = isset($_POST['include_headers']) && $_POST['include_headers'] == '1';

    // count total rows and sample up to 100 rows to compute avg length
    try {
        // count
        if ($dataset === 'students') {
            $count = 0;
            try { $count = (int)$pdo->query("SELECT COUNT(*) FROM student")->fetchColumn(); } catch(Throwable$e){ $count = 0; }
            if ($count <= 0) {
                try { $count = (int)$pdo->query("SELECT COUNT(*) FROM `user` WHERE Role='Student'")->fetchColumn(); } catch(Throwable$e){ $count = 0; }
            }
            $sql = "SELECT COALESCE(s.StudentID,u.UserID) AS StudentID, COALESCE(s.MatricNo,'') AS Matric, COALESCE(s.FullName,u.Email) AS FullName, COALESCE(u.Email,'') AS Email, COALESCE(s.ClassID,'') AS ClassID FROM student s LEFT JOIN `user` u ON u.UserID=s.UserID LIMIT 100";
        } elseif ($dataset === 'courses') {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM course")->fetchColumn();
            $sql = "SELECT CourseID, Course_Code, Course_Name, Credit FROM course LIMIT 100";
        } elseif ($dataset === 'classes') {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM class")->fetchColumn();
            $sql = "SELECT ClassID, Class_Name, Semester FROM class LIMIT 100";
        } elseif ($dataset === 'departments') {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM department")->fetchColumn();
            $sql = "SELECT DepartmentID, Dept_Name, Dept_Code FROM department LIMIT 100";
        } else {
            $count = 0; $sql = '';
        }

        $sampleRows = [];
        if ($sql) {
            $stmt = $pdo->query($sql);
            $sampleRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // estimate bytes: header bytes + avg row bytes * total rows
        $headerBytes = 0;
        if ($include_headers && !empty($sampleRows)) {
            $headerLine = implode(',', normalize_headers($sampleRows[0]));
            $headerBytes = mb_strlen($headerLine, '8bit') + 2; // \r\n
        }
        $sampleTotal = 0; $sampleCount = max(1, count($sampleRows));
        foreach ($sampleRows as $r) {
            $line = implode(',', array_map(function($v){ return (string)$v; }, $r));
            $sampleTotal += mb_strlen($line, '8bit') + 2;
        }
        $avgRow = $sampleTotal / $sampleCount;
        $approx = (int)round($headerBytes + ($avgRow * max(0,$count)));
        // human readable
        $hr = function($n){
            if ($n < 1024) return $n . ' B';
            if ($n < 1024*1024) return round($n/1024,1) . ' KB';
            return round($n/(1024*1024),1) . ' MB';
        };

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'dataset' => $dataset,
            'rows' => $count,
            'sample_rows' => count($sampleRows),
            'approx_bytes' => $approx,
            'approx_human' => $hr($approx)
        ]);
        exit;

    } catch (Throwable $t) {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'Estimation failed']); exit;
    }
}

/* ----------------- Handle exports (CSV/XLS/JSON/PDF) BEFORE output ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_export']) && $_POST['do_export'] === '1') {
    // verify CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, (string)$token)) {
        http_response_code(400);
        echo "Invalid CSRF token";
        exit;
    }

    $dataset = $_POST['dataset'] ?? 'students';
    $format = $_POST['format'] ?? 'csv';
    $include_headers = isset($_POST['include_headers']) && $_POST['include_headers'] == '1';

    try {
        $rows = fetch_dataset($pdo, $dataset);
    } catch (Throwable $t) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Database error while exporting.'];
        header('Location: export.php'); exit;
    }

    if (empty($rows) && $format !== 'pdf') {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'No data found for selected dataset.'];
        header('Location: export.php'); exit;
    }

    $filename = export_filename($dataset, $format);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        if ($include_headers && !empty($rows)) fputcsv($out, normalize_headers($rows[0]));
        foreach ($rows as $r) fputcsv($out, array_map(function($v){ return is_null($v) ? '' : (string)$v; }, $r));
        fclose($out);
        $_SESSION['last_export'] = ['dataset'=>$dataset,'format'=>$format,'time'=>date('Y-m-d H:i:s')];
        exit;
    }

    if ($format === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />";
        echo "<table border='1'><thead><tr>";
        if ($include_headers && !empty($rows)) {
            foreach (normalize_headers($rows[0]) as $h) echo "<th>".e($h)."</th>";
        } elseif (!empty($rows)) {
            foreach (array_keys($rows[0]) as $k) echo "<th>".e($k)."</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $r) {
            echo "<tr>";
            foreach ($r as $v) echo "<td>".e((string)$v)."</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        $_SESSION['last_export'] = ['dataset'=>$dataset,'format'=>$format,'time'=>date('Y-m-d H:i:s')];
        exit;
    }

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        if (!$include_headers) {
            // return numeric-indexed arrays (values only)
            $vals = array_map(function($r){ return array_values($r); }, $rows);
            echo json_encode($vals, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        }
        $_SESSION['last_export'] = ['dataset'=>$dataset,'format'=>$format,'time'=>date('Y-m-d H:i:s')];
        exit;
    }

    if ($format === 'pdf') {
        $printHtml = '<!doctype html><html><head><meta charset="utf-8"><title>Export - '.htmlspecialchars($datasets[$dataset] ?? $dataset).'</title>';
        $printHtml .= '<style>body{font-family:Arial,Helvetica,sans-serif;color:#111}table{width:100%;border-collapse:collapse}th,td{border:1px solid #666;padding:6px;text-align:left}th{background:#f2f2f2}</style>';
        $printHtml .= '</head><body>';
        $printHtml .= '<h2>Export: '.htmlspecialchars($datasets[$dataset] ?? $dataset).'</h2>';
        if (!empty($rows)) {
            $printHtml .= '<table><thead><tr>';
            foreach (normalize_headers($rows[0]) as $h) $printHtml .= '<th>'.e($h).'</th>';
            $printHtml .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $printHtml .= '<tr>';
                foreach ($r as $v) $printHtml .= '<td>'.e((string)$v).'</td>';
                $printHtml .= '</tr>';
            }
            $printHtml .= '</tbody></table>';
        } else {
            $printHtml .= '<p>No data found.</p>';
        }
        $printHtml .= '<script>window.onload=function(){window.print()}</script></body></html>';
        echo $printHtml; exit;
    }

    $_SESSION['flash'] = ['type'=>'error','msg'=>'Unsupported export format.'];
    header('Location: export.php'); exit;
}

/* ----------------- page vars ----------------- */
$dataset = $_GET['dataset'] ?? 'students';
$format = $_GET['format'] ?? 'csv';
$counts = dataset_counts($pdo);
$totalStudents = $counts['students'];
$totalCourses  = $counts['courses'];
$totalClasses  = $counts['classes'];
$totalDepts    = $counts['departments'];

$recent_export = $_SESSION['last_export'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Export — Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="../style.css">
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
/* Neon theme */
:root{
  --bg:#071019;
  --panel:#071827;
  --card:rgba(7,24,39,0.7);
  --text:#E6F7FF;
  --muted:#9fb7c6;
  --neon1:#00e0ff; /* cyan */
  --neon2:#8a2be2; /* violet */
  --neon3:#39ff14; /* green */
  --accent-gradient: linear-gradient(90deg, var(--neon1), var(--neon2));
  --glow: 0 6px 30px rgba(138,43,226,0.12);
  --sidebar-width:260px;
  --card-border: rgba(255,255,255,0.04);
}

/* base */
html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,Arial,sans-serif}
.admin-main{padding:28px}
.container{max-width:1300px;margin:0 auto;display:grid;grid-template-columns:280px 1fr 280px;gap:20px;align-items:start}
@media (max-width:1200px){ .container{grid-template-columns:1fr} }

/* cards */
.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:12px;padding:18px;border:1px solid var(--card-border);box-shadow:var(--glow);overflow:hidden}
.card .card-title{font-weight:800;margin:0 0 8px 0}
.card .card-body{padding-top:6px}
.header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.h1{font-size:38px;font-weight:800;margin:0;color:var(--neon2);text-shadow:0 2px 0 rgba(0,0,0,0.35), 0 6px 20px rgba(138,43,226,0.06)}
.small{color:var(--muted);font-size:0.95rem}

/* neon buttons */
.btn {
  display:inline-block;
  padding:10px 14px;
  border-radius:10px;
  border:1px solid rgba(255,255,255,0.06);
  background: linear-gradient(90deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
  color:var(--text);
  font-weight:800;
  cursor:pointer;
  position:relative;
  overflow:visible;
  transition:transform .14s ease, box-shadow .14s ease;
  box-shadow: 0 6px 18px rgba(0,0,0,0.5);
}

/* main neon appearance */
.btn.neon {
  background: linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
  color: #061016;
  font-weight:900;
  /* gradient overlay */
  border:0;
  padding:12px 18px;
}
.btn.neon::before {
  content:"";
  position:absolute; inset:0; z-index:0;
  border-radius:10px;
  background: var(--accent-gradient);
  filter: blur(8px);
  opacity:0.14;
}
.btn.neon > span { position:relative; z-index:2; color:#061016; }

/* neon solid look */
.btn.neon.solid {
  background: linear-gradient(90deg, var(--neon1), var(--neon2));
  color:#04121a;
  box-shadow: 0 8px 30px rgba(138,43,226,0.15), 0 0 18px rgba(0,224,255,0.06);
}

/* ghost neon */
.btn.ghost {
  background:transparent;
  border:1px solid rgba(255,255,255,0.04);
  color:var(--text);
}
.btn.ghost.neon {
  border:1px solid rgba(255,255,255,0.04);
  box-shadow: 0 8px 30px rgba(0,0,0,0.45), 0 0 12px rgba(138,43,226,0.06) inset;
}
.btn.ghost.neon:hover { transform:translateY(-2px); }

/* preview table */
.table-preview{width:100%;border-collapse:collapse;margin-top:12px}
.table-preview th, .table-preview td{border:1px solid rgba(255,255,255,0.04);padding:8px;text-align:left}
.table-preview th{background:rgba(255,255,255,0.02);color:var(--muted)}
.preview-area{min-height:220px;max-height:520px;overflow:auto;border-radius:8px;padding:8px;background:rgba(255,255,255,0.01);}

/* right panel / chart etc */
.right-helper .section-title{font-weight:800;margin-bottom:8px;color:var(--neon1)}
.legend .dot{width:12px;height:12px;border-radius:50%;display:inline-block}
.links a{display:inline-block;padding:10px;border-radius:8px;background:rgba(255,255,255,0.02);text-decoration:none;color:var(--text);font-weight:600}
.sample-buttons .btn.ghost{width:100%;text-align:center}

/* transforms when sidebar open */
html.sidebar-open .container{transform:translateX(var(--sidebar-width));transition:transform 220ms ease-in-out}
.sidebar-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.45);opacity:0;pointer-events:none;transition:opacity 220ms ease-in-out;z-index:80}
html.sidebar-open .sidebar-backdrop{opacity:1;pointer-events:auto}

/* form controls */
label{display:block;color:var(--muted);font-weight:700;margin-bottom:8px}
select,input[type="text"]{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.04);background:rgba(255,255,255,0.02);color:var(--text)}

@media (max-width:1200px){ .container{grid-template-columns:1fr} .right-helper{order:3} }

/* Small tweaks for better form layout */
.card form .control{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.04);background:rgba(255,255,255,0.02);color:var(--text)}
.card form .btn{width:100%;margin-top:6px}
.left-col-buttons .btn{display:block;margin-bottom:8px}
</style>
</head>
<body class="body body-has-admin-sidebar body-has-admin-sedebar">
<?php
if ($navToInclude) {
    include_once $navToInclude;
} else {
    echo '<div style="height:56px;background:#07111a;color:#fff;display:flex;align-items:center;padding:0 18px;position:fixed;left:0;right:0;top:0;z-index:90">';
    echo '<button id="fallbackNavToggle" style="margin-right:12px;padding:8px 10px;border-radius:6px;background:#0b1220;border:0;color:#9fb3c8;cursor:pointer">☰</button>';
    echo '<strong style="margin-right:12px">Admin</strong>';
    echo '</div>';
}
?>
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

<main class="admin-main">
  <div class="container">

    <!-- left -->
    <div>
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="font-weight:800;font-size:1.05rem">Quick Export</div>
            <div class="small" style="margin-top:6px">Pick a dataset for instant CSV download.</div>
          </div>
          <a href="index.php" class="btn ghost">Back</a>
        </div>

        <form method="post" style="margin-top:12px;display:flex;flex-direction:column;gap:10px">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="do_export" value="1">
          <button class="btn neon solid" name="dataset" value="students" formaction="export.php" formmethod="post"> <span>Students CSV</span></button>
          <button class="btn neon" name="dataset" value="courses" formaction="export.php" formmethod="post"> <span>Courses CSV</span></button>
          <button class="btn neon" name="dataset" value="classes" formaction="export.php" formmethod="post"> <span>Classes CSV</span></button>
          <button class="btn neon" name="dataset" value="departments" formaction="export.php" formmethod="post"> <span>Departments CSV</span></button>
          <button class="btn ghost neon" name="dataset" value="all" formaction="export.php" formmethod="post"> <span>Combined CSV</span></button>
        </form>

        <div class="small" style="margin-top:10px">Tip: Combined CSV includes sections for each dataset.</div>
      </div>

      <div style="height:12px"></div>

      <div class="card">
        <div style="font-weight:800">Export Form</div>
        <div class="small" style="margin-top:6px">Select options below, Preview, Estimate size or Export.</div>

        <form id="exportForm" method="post" style="margin-top:12px">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="do_export" value="1">
          <div style="margin-bottom:10px">
            <label for="dataset">Dataset</label>
            <select id="dataset" name="dataset" class="control">
              <?php foreach ($datasets as $k=>$v): ?>
                <option value="<?= e($k) ?>" <?= $k === $dataset ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="margin-bottom:10px">
            <label for="format">Format</label>
            <select id="format" name="format" class="control">
              <option value="csv" <?= $format === 'csv' ? 'selected' : '' ?>>CSV (recommended)</option>
              <option value="xls" <?= $format === 'xls' ? 'selected' : '' ?>>Excel (.xls)</option>
              <option value="json" <?= $format === 'json' ? 'selected' : '' ?>>JSON</option>
              <option value="pdf" <?= $format === 'pdf' ? 'selected' : '' ?>>PDF (print)</option>
            </select>
          </div>

          <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
            <label style="margin:0;display:flex;align-items:center;gap:8px"><input type="checkbox" name="include_headers" id="include_headers" checked> Include headers</label>
            <button type="button" id="estimateBtn" class="btn ghost neon" style="margin-left:auto">Estimate size</button>
          </div>

          <div style="display:flex;gap:8px">
            <button type="submit" class="btn neon solid"><span>Export</span></button>
            <button type="button" id="previewBtn" class="btn ghost neon">Preview</button>
          </div>

          <div class="small" style="margin-top:10px">Preview returns the first 50 rows. Estimation samples up to 100 rows and extrapolates size.</div>
        </form>

        <div id="estimateResult" class="small" style="margin-top:10px;color:var(--muted)"></div>
      </div>
    </div>

    <!-- middle -->
    <div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div>
          <div class="h1">Export</div>
          <div class="small" style="margin-top:8px">Neon theme + practical export tools for admins.</div>
        </div>
      </div>

      <div class="card" id="previewCard">
        <?php if (isset($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
          <div class="flash <?= $f['type'] === 'success' ? 'success' : 'error' ?>"><?= e($f['msg']) ?></div>
        <?php endif; ?>

        <div id="previewArea">
          <div class="small">Use the form to preview or estimate; results will show here.</div>
        </div>
      </div>

      <div style="height:12px"></div>

      <div class="card">
        <strong>Recent exports</strong>
        <div class="small" style="margin-top:8px">
          This shows last export recorded in session.
        </div>
        <div style="margin-top:10px">
          <?php if (!empty($recent_export)): ?>
            <div class="small">Last exported: <strong><?= e($recent_export['dataset'] ?? 'unknown') ?></strong> at <?= e($recent_export['time'] ?? '') ?></div>
          <?php else: ?>
            <div class="small">No recent exports recorded in session.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- right -->
    <div class="right-helper">
      <div class="card">
        <div class="section-title">Dataset sizes</div>
        <canvas id="datasetPie" style="width:100%;height:220px"></canvas>
        <div style="display:flex;justify-content:space-between;margin-top:10px">
          <div class="small">Students</div><div class="small"><?= e($totalStudents) ?></div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:6px">
          <div class="small">Courses</div><div class="small"><?= e($totalCourses) ?></div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:6px">
          <div class="small">Classes</div><div class="small"><?= e($totalClasses) ?></div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:6px">
          <div class="small">Departments</div><div class="small"><?= e($totalDepts) ?></div>
        </div>
      </div>

      <div style="height:12px"></div>

      <div class="card">
        <div class="section-title">Quick actions</div>
        <div class="links">
          <a href="import.php">Import data</a>
          <a href="admin/reports.php">Reports</a>
          <a href="admin_settings.php">Settings</a>
          <a href="students_manage.php">Manage Students</a>
        </div>

        <div class="sample-buttons">
          <div style="margin-top:12px;font-weight:700">Download sample CSV</div>
          <a class="btn ghost neon" href="export.php?sample=students">Sample Students CSV</a>
          <a class="btn ghost neon" href="export.php?sample=courses">Sample Courses CSV</a>
          <a class="btn ghost neon" href="export.php?sample=classes">Sample Classes CSV</a>
          <a class="btn ghost neon" href="export.php?sample=departments">Sample Depts CSV</a>
        </div>
      </div>

      <div style="height:12px"></div>

      <div class="card">
        <div class="section-title">Help & formats</div>
        <div class="help-text">
          <strong>CSV</strong> — best for spreadsheets; includes BOM for Excel. <br>
          <strong>XLS</strong> — legacy Excel-compatible HTML table. <br>
          <strong>JSON</strong> — machine-readable. <br>
          <strong>PDF</strong> — printable view, saved by browser.
        </div>
      </div>
    </div>

  </div>
</main>

<footer style="padding:12px 22px;color:var(--muted);font-size:0.9rem">© <?= date('Y') ?> Your Institution · Export</footer>

<script>
/* Sidebar integration (detect width and set CSS var) */
(function(){
  const html = document.documentElement;
  const backdrop = document.getElementById('sidebarBackdrop');
  const sidebarSelectors = ['.sidebar', '#sidebar', '.admin-sidebar', '.left-sidebar', 'nav.sidebar'];
  let sidebarEl = null;
  for (const s of sidebarSelectors) {
    const el = document.querySelector(s);
    if (el) { sidebarEl = el; break; }
  }
  if (!sidebarEl) {
    document.querySelectorAll('nav, aside, [role="navigation"]').forEach(el => {
      if (sidebarEl) return;
      const w = el.offsetWidth;
      if (w && w > 120) sidebarEl = el;
    });
  }
  function updateSidebarWidth() {
    let w = 260;
    if (sidebarEl) w = sidebarEl.offsetWidth || w;
    document.documentElement.style.setProperty('--sidebar-width', w + 'px');
  }
  updateSidebarWidth();
  window.addEventListener('resize', updateSidebarWidth);
  if (sidebarEl && 'ResizeObserver' in window) {
    try { new ResizeObserver(updateSidebarWidth).observe(sidebarEl); } catch(e) {}
  }
  function openSidebar(){ html.classList.add('sidebar-open'); try{ localStorage.setItem('admin_sidebar_open','1'); }catch(e){} }
  function closeSidebar(){ html.classList.remove('sidebar-open'); try{ localStorage.setItem('admin_sidebar_open','0'); }catch(e){} }
  function toggleSidebar(){ html.classList.contains('sidebar-open') ? closeSidebar() : openSidebar(); }
  try{ if (localStorage.getItem('admin_sidebar_open') === '1') { html.classList.add('sidebar-open'); updateSidebarWidth(); } } catch(e){}
  if (backdrop) backdrop.addEventListener('click', function(){ closeSidebar(); document.body.style.overflow = ''; });
  document.addEventListener('click', function(ev){
    const t = ev.target;
    if (t.closest('[data-toggle="sidebar"], .nav-toggle, #sidebarToggle, .menu-toggle, .hamburger, .sidebar-toggle, #fallbackNavToggle')) {
      ev.preventDefault();
      updateSidebarWidth();
      toggleSidebar();
      if (html.classList.contains('sidebar-open')) document.body.style.overflow = 'hidden'; else document.body.style.overflow = '';
    }
  }, false);
  document.addEventListener('keydown', function(ev){ if (ev.key === 'Escape') { closeSidebar(); document.body.style.overflow = ''; } });
})();

/* Preview button (AJAX) */
document.getElementById('previewBtn').addEventListener('click', function(){
  const form = document.getElementById('exportForm');
  const data = new FormData(form);
  data.append('preview','1');
  fetch('export.php', { method: 'POST', body: data, credentials: 'same-origin' })
    .then(r => r.text())
    .then(html => {
      document.getElementById('previewArea').innerHTML = html;
      document.getElementById('previewArea').scrollIntoView({ behavior: 'smooth' });
    })
    .catch(err => {
      document.getElementById('previewArea').innerHTML = '<div class="flash error">Preview failed.</div>';
    });
});

/* Estimate button (AJAX) */
document.getElementById('estimateBtn').addEventListener('click', function(){
  const form = document.getElementById('exportForm');
  const data = new FormData(form);
  data.append('action','estimate');
  data.append('csrf_token', '<?= e($csrf) ?>');
  data.append('dataset', document.getElementById('dataset').value);
  data.append('include_headers', document.getElementById('include_headers').checked ? '1' : '0');

  const el = document.getElementById('estimateResult');
  el.textContent = 'Estimating...';

  fetch('export.php', { method: 'POST', body: data, credentials: 'same-origin' })
    .then(r => r.json())
    .then(json => {
      if (!json.ok) {
        el.textContent = 'Estimate failed.';
      } else {
        el.innerHTML = `Rows: <strong>${json.rows}</strong> — Sampled <strong>${json.sample_rows}</strong> rows — Approx. file size: <strong>${json.approx_human}</strong>`;
      }
    })
    .catch(err => { el.textContent = 'Estimate failed.'; });
});


/*
  Safe, lazy-loading dataset chart

  Usage: Replace previous dataset-chart code with this block.
  - Chart will only be created when admin clicks "Show chart"
  - If totalRows > SAFE_LIMIT, chart will NOT render and a message is displayed
  - No DPR scaling or continuous reflows; animations and tooltips disabled
*/

(function(){
  const students = <?= (int)$totalStudents ?>;
  const courses  = <?= (int)$totalCourses ?>;
  const classes  = <?= (int)$totalClasses ?>;
  const depts    = <?= (int)$totalDepts ?>;
  const SAFE_LIMIT = 50000; // if sum > this, we won't render the chart automatically

  // button injection helper (will be visible in the Dataset sizes card)
  function ensureToggleButton(container) {
    if (!container) return null;
    let btn = container.querySelector('.chart-toggle-btn');
    if (btn) return btn;
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn ghost neon chart-toggle-btn';
    btn.style.marginTop = '8px';
    btn.textContent = 'Show chart';
    // place it below the title or at top of card
    const header = container.querySelector('.section-title, strong, h3');
    if (header && header.parentNode) header.parentNode.insertBefore(btn, header.nextSibling);
    else container.insertBefore(btn, container.firstChild);
    return btn;
  }

  // ensure canvas exists, but do not force reflow/scaling
  function ensureCanvasIn(container) {
    let canvas = container.querySelector('#datasetPie');
    if (canvas && canvas.tagName && canvas.tagName.toLowerCase() === 'canvas') return canvas;

    // if there's some placeholder or image accidentally added, clean only the chart wrapper
    let wrap = container.querySelector('.dataset-pie-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'dataset-pie-wrap';
      wrap.style.width = '100%';
      wrap.style.minHeight = '180px';
      wrap.style.boxSizing = 'border-box';
      container.appendChild(wrap);
    } else {
      // clear only wrapper children
      while (wrap.firstChild) wrap.removeChild(wrap.firstChild);
    }

    canvas = document.createElement('canvas');
    canvas.id = 'datasetPie';
    canvas.style.width = '100%';
    canvas.style.height = '180px';
    canvas.setAttribute('aria-hidden','false');
    wrap.appendChild(canvas);
    return canvas;
  }

  // safe destroy helper
  function safeDestroyChart() {
    try {
      if (window._datasetPieChart && typeof window._datasetPieChart.destroy === 'function') {
        window._datasetPieChart.destroy();
      }
    } catch(e){ console.warn('destroy chart error', e); }
    window._datasetPieChart = null;
  }

  // draw function executed during idle (or immediately if idle API unavailable)
  function drawChart(canvas) {
    if (!canvas || typeof Chart === 'undefined') {
      if (canvas && canvas.parentNode) {
        canvas.parentNode.innerHTML = '<div class="small" style="padding:18px;color:var(--muted)">Chart library not loaded.</div>';
      }
      return;
    }

    // if all zeros show friendly message and don't render
    if ([students,courses,classes,depts].every(v => v === 0)) {
      const parent = canvas.parentNode;
      if (parent) parent.innerHTML = '<div class="small" style="padding:18px;color:var(--muted)">No data available yet.</div>';
      return;
    }

    // don't do heavy DPI scaling — Chart.js will handle rendering responsively with pixel ratio defaults
    safeDestroyChart();

    try {
      // minimal, low-overhead chart config
      window._datasetPieChart = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: ['Students','Courses','Classes','Departments'],
          datasets: [{
            data: [students, courses, classes, depts],
            backgroundColor: ['#00e0ff','#8a2be2','#58a6ff','#39ff14'],
            borderWidth: 0
          }]
        },
        options: {
          animation: false,            // disable animations (cpu saver)
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom',
                     labels: { color: 'rgba(255,255,255,0.85)', boxWidth: 12 } },
            tooltip: { enabled: false } // disable tooltips
          },
          layout: { padding: { top: 6, bottom: 6 } }
        }
      });
    } catch (err) {
      console.error('chart draw error', err);
      const parent = canvas.parentNode;
      if (parent) parent.innerHTML = '<div class="small" style="padding:18px;color:var(--muted)">Chart failed to render.</div>';
    }
  }

  // main init
  function init() {
    // find the card container reliably
    const container = document.querySelector('.right-helper .card') ||
                      (Array.from(document.querySelectorAll('.card')).find(n => /dataset sizes/i.test(n.innerText)) || document.body);

    if (!container) return;

    // if totals are enormous, show informational message and don't even add toggle
    const totalRows = students + courses + classes + depts;
    if (totalRows > SAFE_LIMIT) {
      const wrap = container.querySelector('.dataset-pie-wrap') || document.createElement('div');
      wrap.className = 'dataset-pie-wrap';
      wrap.innerHTML = '<div class="small" style="padding:18px;color:var(--muted)">Dataset too large to render chart safely (' + totalRows + ' records). Use previews or export instead.</div>';
      if (!container.querySelector('.dataset-pie-wrap')) container.appendChild(wrap);
      return;
    }

    const btn = ensureToggleButton(container);
    const canvas = ensureCanvasIn(container);

    // default: don't draw; show a small "chart hidden" note
    const wrap = canvas.parentNode;
    if (wrap) {
      wrap.innerHTML = '<div class="small" style="padding:12px;color:var(--muted)">Chart is hidden. Click "Show chart" to render.</div>';
      wrap.appendChild(canvas); // make sure canvas still present for future
      canvas.style.display = 'none';
    }

    // when clicking toggle, run draw during idle
    btn.addEventListener('click', function(){
      // if already drawn, toggle destroy/hide
      if (window._datasetPieChart) {
        safeDestroyChart();
        canvas.style.display = 'none';
        btn.textContent = 'Show chart';
        const note = document.createElement('div');
        note.className = 'small';
        note.style.color = 'var(--muted)';
        note.style.padding = '12px';
        note.textContent = 'Chart hidden.';
        const parent = canvas.parentNode;
        if (parent) {
          // remove previous note if any
          Array.from(parent.querySelectorAll('.small')).forEach(n=>n.remove());
          parent.insertBefore(note, canvas);
        }
        return;
      }

      // reveal canvas
      canvas.style.display = '';
      btn.textContent = 'Hide chart';

      // draw in idle time (or immediately if not supported)
      const job = function(){ drawChart(canvas); };
      if ('requestIdleCallback' in window) {
        requestIdleCallback(job, {timeout: 500});
      } else {
        setTimeout(job, 50);
      }
    }, { once: false });
  }

  // safe init at DOM ready
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})();
</script>

</body>
</html>
