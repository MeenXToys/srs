<?php
// admin/classes.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

/* ---------------- utilities to detect column names (robust across DB variants) ---------------- */
function pick_col(array $cols, array $candidates, $default = null) {
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return $default;
}

try { $classCols  = $pdo->query("SHOW COLUMNS FROM `class`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $classCols = []; $errMsg = $e->getMessage(); }
try { $courseCols = $pdo->query("SHOW COLUMNS FROM `course`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $courseCols = []; if(!isset($errMsg)) $errMsg = $e->getMessage(); }
try { $studentCols= $pdo->query("SHOW COLUMNS FROM `student`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $studentCols = []; if(!isset($errMsg)) $errMsg = $e->getMessage(); }
try { $deptCols   = $pdo->query("SHOW COLUMNS FROM `department`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $deptCols = []; if(!isset($errMsg)) $errMsg = $e->getMessage(); }

$class_id_col   = pick_col($classCols,   ['ClassID','class_id','id','ID'], 'ClassID');
$class_code_col = pick_col($classCols,   ['Class_Code','ClassCode','class_code','Code','code'], null);
$class_name_col = pick_col($classCols,   ['Class_Name','ClassName','class_name','Name','name'], null);
$class_sem_col  = pick_col($classCols,   ['Semester','semester','Sem','sem'], 'Semester');

$course_id_col   = pick_col($courseCols, ['CourseID','course_id','id','ID'], 'CourseID');
$course_code_col = pick_col($courseCols, ['Course_Code','CourseCode','course_code','Code','code'], null);
$course_name_col = pick_col($courseCols, ['Course_Name','CourseName','course_name','Name','name'], null);
$course_dept_col = pick_col($courseCols, ['DepartmentID','department_id','DeptID','dept_id'], 'DepartmentID');

$student_id_col = pick_col($studentCols, ['UserID','Student_ID','StudentID','id','ID'], 'UserID');

$dept_id_col   = pick_col($deptCols, ['DepartmentID','department_id','id','ID'], 'DepartmentID');
$dept_code_col = pick_col($deptCols, ['Dept_Code','DeptCode','dept_code','Code','code'], null);
$dept_name_col = pick_col($deptCols, ['Dept_Name','DeptName','dept_name','Name','name'], null);

/* ---------------- request params (filters + paging) ---------------- */
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = (int)($_GET['per'] ?? 10); if ($per <= 0) $per = 10;
$filter_dept = (int)($_GET['dept'] ?? 0);
$filter_sem  = ($_GET['sem'] ?? '');
$show_deleted = ($_GET['show_deleted'] ?? '') === '1';

/* detect deleted_at presence */
$has_deleted_at = in_array('deleted_at', $classCols);

/* fetch departments for filters + modal select */
$departments = [];
try {
    $dsql = "SELECT {$dept_id_col} AS DepartmentID, " .
            ($dept_code_col ? "{$dept_code_col} AS Dept_Code, " : "NULL AS Dept_Code, ") .
            ($dept_name_col ? "{$dept_name_col} AS Dept_Name" : "NULL AS Dept_Name") .
            " FROM department
              ORDER BY " . ($dept_code_col ? "{$dept_code_col} IS NULL, {$dept_code_col} ASC, " : "") . ($dept_name_col ? "{$dept_name_col} ASC" : "1");
    $departments = $pdo->query($dsql)->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    // dropdown can be empty gracefully
}

/* ---------------- build WHERE for list ---------------- */
$where = [];
$params = [];

if ($q !== '') {
    $parts = [];
    if ($course_name_col) $parts[] = "c.`{$course_name_col}` LIKE :q";
    if ($course_code_col) $parts[] = "c.`{$course_code_col}` LIKE :q";
    if ($class_name_col) $parts[] = "cl.`{$class_name_col}` LIKE :q";
    if ($class_code_col) $parts[] = "cl.`{$class_code_col}` LIKE :q";
    if ($parts) { $where[] = '(' . implode(' OR ', $parts) . ')'; $params[':q'] = "%$q%"; }
}
if ($filter_dept) { $where[] = "c.`{$course_dept_col}` = :dept"; $params[':dept'] = $filter_dept; }
if ($filter_sem !== '') { $where[] = "cl.`{$class_sem_col}` = :sem"; $params[':sem'] = $filter_sem; }
if ($has_deleted_at && !$show_deleted) $where[] = "cl.`deleted_at` IS NULL";

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------------- total count for pagination ---------------- */
try {
    $countSql = "SELECT COUNT(DISTINCT cl.`{$class_id_col}`) FROM `class` cl
                 LEFT JOIN `course` c ON c.`{$course_id_col}` = cl.`{$course_id_col}`
                 $whereSql";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k=>$v) $countStmt->bindValue($k,$v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch(Exception $e) {
    $total = 0;
    $errMsg = $e->getMessage();
}

/* ---------------- pagination math ---------------- */
$totalPages = max(1, (int)ceil($total / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

/* ---------------- select columns (aliases stable) ---------------- */
$select = [];
$select[] = "cl.`{$class_id_col}` AS ClassID";
$select[] = ($class_code_col ? "cl.`{$class_code_col}` AS Class_Code" : "NULL AS Class_Code");
$select[] = ($class_name_col ? "cl.`{$class_name_col}` AS Class_Name" : "NULL AS Class_Name");
$select[] = ($class_sem_col ? "cl.`{$class_sem_col}` AS Semester" : "NULL AS Semester");

$select[] = ($course_id_col ? "c.`{$course_id_col}` AS CourseID" : "NULL AS CourseID");
$select[] = ($course_code_col ? "c.`{$course_code_col}` AS Course_Code" : "NULL AS Course_Code");
$select[] = ($course_name_col ? "c.`{$course_name_col}` AS Course_Name" : "NULL AS Course_Name");
$select[] = ($course_dept_col ? "c.`{$course_dept_col}` AS DepartmentID" : "NULL AS DepartmentID");

$select[] = ($dept_code_col ? "d.`{$dept_code_col}` AS Dept_Code" : "NULL AS Dept_Code");
$select[] = ($dept_name_col ? "d.`{$dept_name_col}` AS Dept_Name" : "NULL AS Dept_Name");

/* students count */
if ($student_id_col && in_array($student_id_col, $studentCols)) $select[] = "COUNT(s.`{$student_id_col}`) AS students";
else $select[] = "COUNT(s.UserID) AS students";

$selectSql = implode(", ", $select);

/* ordering: Semester, Dept_Code, Class_Name (consistent & predictable) */
$orderSql = ($class_sem_col ? "cl.`{$class_sem_col}` ASC, " : "") .
            ($dept_code_col ? "d.`{$dept_code_col}` ASC, " : ( $dept_name_col ? "d.`{$dept_name_col}` ASC, " : "" )) .
            ($class_name_col ? "cl.`{$class_name_col}` ASC" : ( $class_code_col ? "cl.`{$class_code_col}` ASC" : "cl.`{$class_id_col}` ASC"));

/* group by clauses (all non-aggregated selected columns) */
$groupBy = ["cl.`{$class_id_col}`"];
if ($class_code_col) $groupBy[] = "cl.`{$class_code_col}`";
if ($class_name_col) $groupBy[] = "cl.`{$class_name_col}`";
if ($class_sem_col) $groupBy[] = "cl.`{$class_sem_col}`";
if ($course_id_col) $groupBy[] = "c.`{$course_id_col}`";
if ($course_code_col) $groupBy[] = "c.`{$course_code_col}`";
if ($course_name_col) $groupBy[] = "c.`{$course_name_col}`";
if ($course_dept_col) $groupBy[] = "c.`{$course_dept_col}`";
if ($dept_code_col) $groupBy[] = "d.`{$dept_code_col}`";
if ($dept_name_col) $groupBy[] = "d.`{$dept_name_col}`";
$groupBySql = implode(", ", $groupBy);

/* ---------------- fetch rows ---------------- */
try {
    $sql = "
      SELECT $selectSql
      FROM `class` cl
      LEFT JOIN `course` c ON c.`{$course_id_col}` = cl.`{$course_id_col}`
      LEFT JOIN `department` d ON d.`{$dept_id_col}` = c.`{$course_dept_col}`
      LEFT JOIN `student` s ON s.`{$student_id_col}` = cl.`{$class_id_col}`
      $whereSql
      GROUP BY $groupBySql
      ORDER BY $orderSql
      LIMIT :offset, :limit
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', (int)$per, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    $rows = [];
    $errMsg = $e->getMessage();
}

/* prepare JS map */
$map = [];
foreach ($rows as $r) {
    $map[(int)$r['ClassID']] = [
        'Class_Code' => $r['Class_Code'] ?? null,
        'Class_Name' => $r['Class_Name'] ?? null,
        'CourseID' => $r['CourseID'] ?? null,
        'Course_Name' => $r['Course_Name'] ?? null,
        'Course_Code' => $r['Course_Code'] ?? null,
        'Semester' => $r['Semester'] ?? null,
        'DepartmentID' => $r['DepartmentID'] ?? null,
        'Dept_Code' => $r['Dept_Code'] ?? null,
        'Dept_Name' => $r['Dept_Name'] ?? null,
        'students' => (int)($r['students'] ?? 0),
        'deleted_at' => $r['deleted_at'] ?? null
    ];
}

/* deleted count for toggle */
$deletedCount = 0;
if ($has_deleted_at) {
    try { $deletedCount = (int)$pdo->query("SELECT COUNT(*) FROM `class` WHERE `deleted_at` IS NOT NULL")->fetchColumn(); } catch(Exception $e) { $deletedCount = 0; }
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Classes — Admin</title>
<link rel="stylesheet" href="../style.css">
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- Bootstrap CSS (used for modal styling) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root{
  --bg:#0f1724;
  --card:#07111a;
  --muted:#9aa8bd;
  --text:#e8f6ff;
  --accent-blue-1:#2563eb;
  --accent-blue-2:#1d4ed8;
  --accent-red-1:#ef4444;
  --accent-red-2:#dc2626;
  --accent-purple-1:#7c3aed;
  --accent-purple-2:#6d28d9;
  --select-bg: rgba(255,255,255,0.02);
  --select-border: rgba(255,255,255,0.04);
  --select-contrast: #dff6ff;
}

/* page */
body { background: var(--bg); color: var(--text); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; }

/* Use a full-width center container so card can occupy wide layout */
.center-box{
  max-width: none !important;
  width: calc(100% - 48px);
  margin: auto;
  padding: 22px 24px;
  box-sizing: border-box;
}

/* Card: remove fixed height and let it grow naturally.
   This prevents an inner scrollbar on the table area.
*/
.card{
  width:100% !important;
  max-width:none !important;
  background: var(--card);
  border-radius:12px;
  padding:18px;
  margin-top: 40px;
  box-shadow:0 8px 28px rgba(2,6,23,0.6);
  display:flex;
  flex-direction:column;
  gap:18px;
  overflow:visible;   /* allow content to expand, no inner clipping */
  height:auto;        /* allow card to grow with content (no fixed vh) */
  min-height:420px;
}

/* header remains fixed; table area can expand */
.card > div:first-child { flex: 0 0 auto; }
.card > .table-wrap { flex: 1 1 auto; }

/* Table container: no internal scrollbars; page will scroll */
.table-wrap{
  overflow:visible !important;
  border-radius:8px;
  margin-top:4px;
  padding-right:0;
  box-sizing:border-box;
}

/* Table layout: allow Course column to expand while other columns are fixed */
.table-wrap table {
  width:100%;
  border-collapse:collapse;
  table-layout: auto;
  min-width: 0;
}

/* cells */
th, td {
  padding:12px;
  border-top:1px solid rgba(255,255,255,0.03);
  color:var(--text);
  vertical-align:middle;
  word-break:break-word;
  white-space:normal;
}
th {
  color:var(--muted);
  text-align:left;
  font-weight:700;
  font-size:0.95rem;
}

/* Show the checkbox column on the far left (was previously hidden) */
.checkbox-col {
  display: table-cell !important;
  width: 56px;
  min-width: 56px;
  max-width: 56px;
  text-align: center;
  vertical-align: middle;
  padding-left: 8px;
  padding-right: 8px;
}

/* Style the per-row checkbox to look nicer (bigger clickable area) */
.row-chk {
  width:18px;
  height:18px;
  cursor:pointer;
  -webkit-appearance: none;
  appearance: none;
  border: 2px solid rgba(255,255,255,0.08);
  background: transparent;
  border-radius: 5px;
  display:inline-block;
  position: relative;
}
.row-chk:focus { outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
.row-chk:checked {
  background: linear-gradient(180deg, var(--accent-purple-1), var(--accent-purple-2));
  border-color: rgba(255,255,255,0.08);
}
.row-chk:checked::after {
  content: "✓";
  position: absolute;
  color: white;
  left: 50%;
  top: 50%;
  transform: translate(-50%,-58%);
  font-size: 12px;
  line-height: 1;
  font-weight: 700;
}

/* ------ SELECTED ROW STYLES (added, keeps overall design) ------ */
/* highlight selected row when its checkbox is checked */
tr.selected-row td {
  background: linear-gradient(180deg, rgba(124,58,237,0.06), rgba(124,58,237,0.02));
  box-shadow: inset 0 0 0 1px rgba(124,58,237,0.14);
  transition: background .18s ease, box-shadow .18s ease;
}

/* Column sizing:
   - Class column fixed (compact)
   - Course / Department: flexible (auto)
   - Students: fixed, right-aligned
   - Action: fixed, right-aligned
*/
.table-wrap th:nth-child(2),
.table-wrap td:nth-child(2) {
  width: 220px;
  min-width: 160px;
  max-width: 280px;
}

.table-wrap th:nth-child(3),
.table-wrap td:nth-child(3) {
  /* flexible: no width so it uses remaining space */
}

.table-wrap th:nth-child(4),
.table-wrap td:nth-child(4) {
  width: 110px;
  min-width: 90px;
  text-align: right;
}

.table-wrap th:nth-child(5),
.table-wrap td:nth-child(5) {
  width: 160px;
  min-width: 140px;
  text-align: right;
}

/* keep action buttons from wrapping and forcing width */
.actions-inline { display:flex; gap:12px; align-items:center; justify-content:flex-end; }
.link-update, .link-delete, .btn-muted { white-space:nowrap; }

/* subtle hover */
.row-hover:hover td { background: rgba(255,255,255,0.01); }

/* filters + buttons */
.filters-row{
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:nowrap;
  width:100%;
  margin-bottom:12px;
}
.search-input{
  padding:8px 12px;
  border-radius:10px;
  border:1px solid var(--select-border);
  background: var(--select-bg);
  color:var(--select-contrast);
  font-size:0.95rem;
  height:44px;
  -webkit-appearance:none;
  -moz-appearance:none;
  appearance:none;
}
.search-box { flex:0 0 260px; min-width:140px; }
.small { flex:0 0 48px; max-width:48px; text-align:center; padding-left:6px; padding-right:6px; }
.dept { flex:1 1 220px; min-width:140px; }
.sem { flex:0 0 92px; min-width:80px; max-width:96px; text-align:center; }

/* ====== Fix: keep top action buttons inline and aligned ====== */
.top-actions {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between; /* left buttons left, right-buttons right */
  flex-wrap: nowrap;
  margin-bottom: 6px; /* small gap before card */
}

/* left and right groups */
.left-buttons, .right-buttons {
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:nowrap;
}

/* ensure buttons don't expand to fill width */
.top-actions .btn {
  flex: 0 0 auto;
  width: auto !important;
  min-width: 120px;
  white-space: nowrap;
  border-radius: 10px;
}

/* special min-widths to keep consistent */
.top-actions .add-btn { min-width:140px; }
.top-actions .btn-export { min-width:130px; }
.top-actions .btn-danger { min-width:140px; }

/* keep the toggle on the right neat */
.right-buttons .toggle-btn { min-width:140px; }

/* small screen: allow wrapping but keep buttons reasonable */
@media (max-width: 740px) {
  .filters-row { flex-wrap: wrap; gap:10px; }
  .top-actions { flex-wrap: wrap; gap:8px; }
  .top-actions .btn { min-width: calc(50% - 12px); }
  .right-buttons .toggle-btn { min-width: 120px; }
}

/* Buttons base */
.btn {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:10px;
  padding:0 14px;
  min-width:110px;
  height:44px;
  border-radius:12px;
  color:#fff;
  text-decoration:none;
  border:0;
  cursor:pointer;
  font-weight:500;
  font-size:1rem;
  box-shadow: 0 10px 30px rgba(2,6,23,0.6);
}
.add-btn{ background: linear-gradient(90deg,var(--accent-purple-1),var(--accent-purple-2)); min-width:130px; height:44px; border:1px solid rgba(255,255,255,0.04); }
.btn-export{ background: linear-gradient(90deg,var(--accent-blue-1),var(--accent-blue-2)); min-width:130px; height:44px; border:1px solid rgba(255,255,255,0.04); }
.btn-danger{ background: linear-gradient(90deg,var(--accent-red-1),var(--accent-red-2)); min-width:130px; height:44px; border:1px solid rgba(255,255,255,0.04); }
.btn-muted{ padding:0 12px; min-width:80px; height:40px; border-radius:10px; background:#374151; color:white; border:1px solid rgba(255,255,255,0.03); }

/* modal */
.modal-content {
  background: linear-gradient(180deg, #071026 0%, #081626 100%) !important;
  border: 1px solid rgba(255,255,255,0.06) !important;
  color: var(--text) !important;
}
.form-control, .form-select { background: rgba(255,255,255,0.02); color: var(--text); border:1px solid rgba(255,255,255,0.04); }

/* toast */
#toast { position: fixed; right: 20px; bottom: 20px; background: rgba(0,0,0,0.6); color: #fff; padding: 10px 14px; border-radius:8px; display:none; z-index:1200; }
#toast.show { display:block; animation: fadeInOut 2s ease forwards; }
@keyframes fadeInOut { 0%{opacity:0;transform:translateY(6px)}10%{opacity:1;transform:translateY(0)}90%{opacity:1}100%{opacity:0;transform:translateY(6px)} }
</style>
</head>
<body>
<main class="admin-main">
  <div class="admin-container">
    <div class="center-box">

      <?php if (!empty($errMsg)): ?>
        <div style="background:#3b1f1f;color:#ffdede;padding:10px;border-radius:6px;margin-bottom:12px;">
          Database error: <?= e($errMsg) ?>
        </div>
      <?php endif; ?>

      <?php if ($flash): ?><div id="pageFlash" class="toast show"><?= e($flash) ?></div><?php endif; ?>

      <!-- Filters (single row) -->
      <form id="searchForm" method="get" class="filters-row" style="align-items:center;">
        <input name="q" class="search-input search-box" placeholder="Search code or name..." value="<?= e($q) ?>">

        <select name="per" class="search-input small" onchange="this.form.submit()">
          <?php foreach([5,10,25,50] as $p): ?>
            <option value="<?= $p ?>" <?= $per == $p ? 'selected' : '' ?>><?= e((string)$p) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="dept" class="search-input dept" onchange="this.form.submit()">
          <option value="0"><?= e('-- All Dept --') ?></option>
          <?php foreach ($departments as $d): $sel = ($filter_dept && $filter_dept == $d['DepartmentID']) ? 'selected':''; ?>
            <option value="<?= e($d['DepartmentID']) ?>" <?= $sel ?>><?= e($d['Dept_Code'] ? "{$d['Dept_Code']} — {$d['Dept_Name']}" : $d['Dept_Name']) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="sem" class="search-input sem" onchange="this.form.submit()">
          <option value=""><?= e('-- All Semesters --') ?></option>
          <?php for ($s=1;$s<=8;$s++): $sel = ($filter_sem !== '' && $filter_sem == $s) ? 'selected':''; ?>
            <option value="<?= $s ?>" <?= $sel ?>><?= $s ?></option>
          <?php endfor; ?>
        </select>

        <div class="search-actions" style="margin-left:6px;display:flex;gap:8px;align-items:center;">
          <button type="submit" class="btn-muted">Search</button>
          <a href="classes.php" class="btn-muted">Clear</a>
        </div>
      </form>

      <!-- Actions -->
      <div class="top-actions" aria-label="Actions">
        <div class="left-buttons">
          <button id="openAddBtn" class="btn add-btn" type="button">＋ Add Class</button>
          <a class="btn btn-export" href="api/classes.php?export=1">Export All</a>
          <button id="bulkDeleteBtn" class="btn btn-danger" type="button">Delete Selected</button>
        </div>

        <div class="right-buttons">
          <?php if ($show_deleted): ?>
            <a class="toggle-btn active-toggle btn" href="classes.php">Show Active</a>
            <span style="color:var(--muted);margin-left:8px">Viewing deleted (<?= (int)$deletedCount ?>)</span>
          <?php else: ?>
            <a class="toggle-btn btn" href="classes.php?show_deleted=1">Show Deleted <?= $deletedCount ? "({$deletedCount})" : '' ?></a>
          <?php endif; ?>
        </div>
      </div>

      <!-- List -->
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <h2 style="margin:0;color:#cfe8ff;">Class List</h2>
          <div style="color:var(--muted)"><?= $total ?> result<?= $total==1?'':'s' ?></div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <!-- checkbox column visible now -->
                <th class="checkbox-col"><input id="chkAll" type="checkbox"></th>

                <!-- 1) Class -->
                <th>Class</th>

                <!-- 2) Course / Department (flexible) -->
                <th>Course / Department</th>

                <!-- 3) Students -->
                <th style="width:80px;text-align:right">Students</th>

                <!-- 4) Action -->
                <th style="width:160px">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:28px;">No classes found.</td></tr>
              <?php else: foreach ($rows as $r): $isDeleted = !empty($r['deleted_at']); ?>
                <tr class="row-hover" data-id="<?= e($r['ClassID']) ?>">
                  <!-- checkbox (visible) -->
                  <td class="checkbox-col">
                    <input class="row-chk" type="checkbox" value="<?= e($r['ClassID']) ?>" <?= $isDeleted ? 'disabled' : '' ?> aria-label="Select <?= e($r['Class_Name'] ?? 'class') ?>">
                  </td>

                  <!-- Class -->
                  <td>
                    <div style="font-weight:700;font-size:0.98rem"><?= e($r['Class_Name'] ?? '-') ?></div>
                    <div style="color:var(--muted);font-size:.9rem;margin-top:6px;">Sem <?= e($r['Semester'] ?? '-') ?></div>
                  </td>

                  <!-- Course / Department (flexible) -->
                  <td>
                    <div style="font-weight:700"><?= e($r['Course_Code'] ? "{$r['Course_Code']} — {$r['Course_Name']}" : ($r['Course_Name'] ?? '-')) ?></div>
                    <div style="color:var(--muted);font-size:.85rem;margin-top:6px;"><?= e($r['Dept_Code'] ? "{$r['Dept_Code']} — {$r['Dept_Name']}" : ($r['Dept_Name'] ?? '-')) ?></div>
                  </td>

                  <!-- Students -->
                  <td style="text-align:right"><?= e((int)$r['students']) ?></td>

                  <!-- Action -->
                  <td style="text-align:right">
                    <div class="actions-inline">
                      <?php if (!$isDeleted): ?>
                        <button type="button" class="link-update btn btn-link" data-id="<?= e($r['ClassID']) ?>" style="color:#06b76a">Update</button>
                      <?php else: ?>
                        <button type="button" class="btn-muted" data-undo-id="<?= e($r['ClassID']) ?>">Undo</button>
                      <?php endif; ?>
                      <button type="button" class="link-delete btn btn-link" data-id="<?= e($r['ClassID']) ?>" style="color:var(--accent-red-1)">Delete</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- pager -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
          <div>
            <?php
            $baseParams = [];
            if ($q !== '') $baseParams['q'] = $q;
            if ($per !== 10) $baseParams['per'] = $per;
            if ($filter_dept) $baseParams['dept'] = $filter_dept;
            if ($filter_sem !== '') $baseParams['sem'] = $filter_sem;
            if ($show_deleted) $baseParams['show_deleted'] = 1;
            for ($p=1; $p <= max(1, $totalPages); $p++) {
                $baseParams['page'] = $p;
                $u = 'classes.php?' . http_build_query($baseParams);
                $cls = ($p == $page) ? 'style="font-weight:700;margin-right:8px;color:#fff"' : 'style="margin-right:8px;color:var(--muted)"';
                echo "<a $cls href=\"".e($u)."\">$p</a>";
            }
            ?>
          </div>
          <div style="color:var(--muted)">Page <?= $page ?> of <?= $totalPages ?></div>
        </div>

      </div>
    </div>
  </div>
</main>

<!-- ===== Bootstrap Modals (Add/Edit and Delete) ===== -->

<!-- Add/Edit Modal -->
<div class="modal fade" id="classModal" tabindex="-1" aria-labelledby="classModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <form id="modalForm" class="modal-body p-4" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="classModalLabel">Add Class</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="modalId" value="0">

        <div class="mb-3">
          <label for="modal_name" class="form-label">Class Name</label>
          <input id="modal_name" name="class_name" type="text" required autocomplete="off" placeholder="e.g. DCBS1" class="form-control">
        </div>

        <div class="mb-3">
          <label for="modal_dept" class="form-label">Department <span style="color:#f87171;font-weight:700;">(wajib pilih)</span></label>
          <select id="modal_dept" name="department_id" required class="form-select">
            <option value="">-- Select department --</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= e($d['DepartmentID']) ?>"><?= e($d['Dept_Code'] ? "{$d['Dept_Code']} — {$d['Dept_Name']}" : $d['Dept_Name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label for="modal_course" class="form-label">Course <span style="color:#f87171;font-weight:700;">(wajib pilih)</span></label>
          <select id="modal_course" name="course_id" required class="form-select">
            <option value="">-- Select department first --</option>
          </select>
        </div>

        <div class="mb-3">
          <label for="modal_sem" class="form-label">Semester</label>
          <select id="modal_sem" name="semester" class="form-select">
            <?php for ($s=1;$s<=8;$s++): ?><option value="<?= $s ?>"><?= $s ?></option><?php endfor; ?>
          </select>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="modalCancel">Cancel</button>
          <button id="modalSubmit" class="btn" type="submit" style="background:linear-gradient(90deg,var(--accent-blue-1),var(--accent-blue-2));color:#fff;">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteModalLabel">⚠️ Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>You are about to delete <strong id="deleteName"></strong>.</p>
        <p style="background: rgba(239,68,68,0.06); border-left:3px solid var(--accent-red-1); padding:8px; border-radius:6px; color:#f87171;">
          This will soft-delete the row (if enabled).
        </p>
        <label class="confirm-label" for="confirmDelete" style="color:#6b7280; display:block; margin-top:8px;">
          Please type <code>DELETE</code> below to confirm:
        </label>
        <input id="confirmDelete" type="text" placeholder="Type DELETE here" class="form-control mt-2">
      </div>
      <div class="modal-footer">
        <button id="deleteCancel" type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button id="deleteConfirm" type="button" class="btn" disabled style="background:linear-gradient(90deg,var(--accent-red-1),var(--accent-red-2));color:#fff;">Delete permanently</button>
      </div>
    </div>
  </div>
</div>

<div id="toast" class="toast"></div>

<!-- Bootstrap JS bundle (Popper included) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const csrfToken = <?= json_encode($csrf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const classMap = <?= json_encode($map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

/* small helpers */
function postJSON(url, data){
    const fd = new FormData();
    for (const k in data) {
        if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k+'[]', v));
        else fd.append(k, data[k]);
    }
    fd.append('csrf_token', csrfToken);
    return fetch(url, { method:'POST', body: fd, credentials:'same-origin' }).then(r => r.json());
}
function getJSON(url){ return fetch(url, { credentials:'same-origin' }).then(r => r.json()); }
function showToast(msg, t=2200){
    const s = document.getElementById('toast');
    s.textContent = msg;
    s.classList.add('show');
    clearTimeout(s._hideTimer);
    s._hideTimer = setTimeout(()=> s.classList.remove('show'), t);
}

/* helpers for checkbox select all + row highlight + bulk delete enable */
const chkAll = document.getElementById('chkAll');
const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

function getRowCheckboxes() {
  return Array.from(document.querySelectorAll('.row-chk'));
}
function getEnabledRowCheckboxes() {
  return getRowCheckboxes().filter(c => !c.disabled);
}
function getSelectedIds() {
  return getRowCheckboxes().filter(c => c.checked && !c.disabled).map(c => c.value);
}
function updateRowHighlights() {
  getRowCheckboxes().forEach(cb => {
    const tr = cb.closest('tr');
    if (!tr) return;
    if (cb.checked && !cb.disabled) tr.classList.add('selected-row');
    else tr.classList.remove('selected-row');
  });
}
function updateBulkDeleteState() {
  const any = getSelectedIds().length > 0;
  if (bulkDeleteBtn) bulkDeleteBtn.disabled = !any;
}

/* initialize checkbox listeners */
function initSelectionControls() {
  // per-row listeners - attach change handlers
  getRowCheckboxes().forEach(cb => {
    cb.removeEventListener('change', rowCheckboxChangeHandler, false);
    cb.addEventListener('change', rowCheckboxChangeHandler, false);
  });

  // chkAll toggles all enabled row checkboxes
  if (chkAll) {
    chkAll.removeEventListener('change', chkAllHandler, false);
    chkAll.addEventListener('change', chkAllHandler, false);
  }

  // set initial states
  const enabled = getEnabledRowCheckboxes();
  if (chkAll) chkAll.checked = enabled.length ? enabled.every(c => c.checked) : false;
  updateRowHighlights();
  updateBulkDeleteState();
}

function rowCheckboxChangeHandler() {
  const enabled = getEnabledRowCheckboxes();
  if (chkAll) chkAll.checked = enabled.length ? enabled.every(c => c.checked) : false;
  updateRowHighlights();
  updateBulkDeleteState();
}

function chkAllHandler() {
  getEnabledRowCheckboxes().forEach(c => c.checked = chkAll.checked);
  updateRowHighlights();
  updateBulkDeleteState();
}

/* Allow clicking a row to toggle its checkbox (but ignore clicks on action buttons/links) */
document.addEventListener('click', function(e){
  const tr = e.target.closest && e.target.closest('tr[data-id]');
  if (!tr) return;
  // if click on input/label/button/a inside row -> do nothing special
  const ignore = e.target.closest('.actions-inline, button, a, input, select, label');
  if (ignore) return;
  // toggle the checkbox
  const cb = tr.querySelector('.row-chk');
  if (!cb || cb.disabled) return;
  cb.checked = !cb.checked;
  cb.dispatchEvent(new Event('change', { bubbles: true }));
}, false);

/* modal instances */
const classModalEl = document.getElementById('classModal');
const deleteModalEl = document.getElementById('deleteModal');
const classModal = classModalEl ? new bootstrap.Modal(classModalEl, { keyboard: false }) : null;
const deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl, { keyboard: false }) : null;

/* modal elements */
const modalForm = document.getElementById('modalForm');
const modalAction = document.getElementById('modalAction');
const modalId = document.getElementById('modalId');
const modalName = document.getElementById('modal_name');
const modalDept = document.getElementById('modal_dept');
const modalCourse = document.getElementById('modal_course');
const modalSem = document.getElementById('modal_sem');
const modalCancel = document.getElementById('modalCancel');
const modalSubmit = document.getElementById('modalSubmit');
const openAddBtn = document.getElementById('openAddBtn');

/* Open add modal */
if (openAddBtn) openAddBtn.addEventListener('click', ()=> {
    modalAction.value = 'add';
    modalId.value = 0;
    modalName.value = '';
    modalDept.value = '';
    modalCourse.innerHTML = '<option value="">-- Select department first --</option>';
    modalCourse.classList.remove('error','loading');
    modalSem.selectedIndex = 0;
    document.getElementById('classModalLabel').textContent = 'Add Class';
    modalSubmit.textContent = 'Save';
    if (classModal) classModal.show();
    setTimeout(()=> modalName.focus(), 120);
});

/* Set select HTML helper & states */
function setCourseSelectHtml(html, { loading=false, error=false } = {}) {
  modalCourse.classList.remove('loading','error');
  if (loading) modalCourse.classList.add('loading');
  if (error) modalCourse.classList.add('error');
  modalCourse.innerHTML = html;
}
function makeOption(course, selectedCourseId) {
  const label = course.Course_Code ? (course.Course_Code + ' — ' + course.Course_Name) : course.Course_Name;
  const sel = (selectedCourseId && String(selectedCourseId) === String(course.CourseID)) ? ' selected' : '';
  return `<option value="${course.CourseID}"${sel}>${label}</option>`;
}

/* Robust loadCoursesForDepartment: uses api/classes.php endpoint */
function loadCoursesForDepartment(deptId, selectedCourseId = '') {
  setCourseSelectHtml('<option value="">Loading...</option>', { loading:true });

  if (!deptId) {
    setCourseSelectHtml('<option value="">-- Select department first --</option>');
    return;
  }

  fetch('api/classes.php?get_courses=1&dept_id=' + encodeURIComponent(deptId), { credentials: 'same-origin' })
    .then(resp => {
      if (!resp.ok) throw new Error('HTTP ' + resp.status + ' ' + resp.statusText);
      return resp.json().catch(err => { throw new Error('Invalid JSON: ' + err.message); });
    })
    .then(data => {
      if (!Array.isArray(data)) {
        console.error('Unexpected payload from get_courses:', data);
        setCourseSelectHtml('<option value="">Error loading</option>', { error:true });
        return;
      }
      if (data.length === 0) {
        setCourseSelectHtml('<option value="">No courses found</option>');
        return;
      }
      let out = '<option value="">-- Select course --</option>';
      data.forEach(c => out += makeOption(c, selectedCourseId));
      setCourseSelectHtml(out);
    })
    .catch(err => {
      console.error('Failed to load courses for dept', deptId, err);
      setCourseSelectHtml('<option value="">Error loading</option>', { error:true });
    });
}

/* When department in modal changes, load courses */
if (modalDept) modalDept.addEventListener('change', ()=> loadCoursesForDepartment(modalDept.value));

/* open edit: reuse map prepared on server */
function openEditModal(id){
    const d = classMap[id] || {};
    modalAction.value = 'edit';
    modalId.value = id;
    modalName.value = d.Class_Name || '';
    modalDept.value = d.DepartmentID || '';
    modalSem.value = d.Semester || '';
    document.getElementById('classModalLabel').textContent = 'Update Class';
    modalSubmit.textContent = 'Update';
    if (d.DepartmentID) loadCoursesForDepartment(d.DepartmentID, d.CourseID || '');
    else modalCourse.innerHTML = '<option value="">-- Select department first --</option>';
    if (classModal) classModal.show();
    setTimeout(()=> modalName.focus(), 120);
}

/* submit add/edit */
if (modalForm) modalForm.addEventListener('submit', function(e){
    e.preventDefault();
    const act = modalAction.value;
    const id = modalId.value;
    const name = modalName.value.trim();
    const dept = modalDept.value;
    const course = modalCourse.value;
    const sem = modalSem.value;

    if (!dept) { alert('Please select Department (wajib pilih)'); return; }
    if (!course) { alert('Please select Course (wajib pilih)'); return; }

    const btn = modalSubmit;
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = (act === 'edit') ? 'Updating...' : 'Saving...';

    const payload = { action: act, class_name: name, course_id: course, semester: sem };
    if (act === 'edit') payload.id = id;

    postJSON('api/classes.php', payload).then(resp=>{
        btn.disabled = false; btn.textContent = orig;
        if (resp && resp.ok) { if (classModal) classModal.hide(); showToast('Saved'); setTimeout(()=>location.reload(),600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
    }).catch(()=>{ btn.disabled = false; btn.textContent = orig; showToast('Network error'); });
});

/* delete flow: open custom delete modal */
function openDeleteModal(id){
    const d = classMap[id] || {};
    document.getElementById('deleteName').textContent = d.Class_Name || ('#'+id);
    document.getElementById('confirmDelete').value = '';
    const delBtn = document.getElementById('deleteConfirm');
    delBtn.disabled = true;
    delBtn.dataset.id = id;
    if (deleteModal) deleteModal.show();
    setTimeout(()=> document.getElementById('confirmDelete').focus(), 120);
}

const confirmDeleteInput = document.getElementById('confirmDelete');
if (confirmDeleteInput) {
  confirmDeleteInput.addEventListener('input', function(){
      const delBtn = document.getElementById('deleteConfirm');
      delBtn.disabled = (this.value !== 'DELETE');
      if (this.value.length >= 6 && this.value !== 'DELETE') { this.classList.add('shake'); setTimeout(()=> this.classList.remove('shake'), 380); }
  });
}
const deleteConfirmBtn = document.getElementById('deleteConfirm');
if (deleteConfirmBtn) {
  deleteConfirmBtn.addEventListener('click', function(){
      const id = this.dataset.id; const btn = this; btn.disabled = true; const orig = btn.textContent; btn.textContent = 'Deleting...';
      postJSON('api/classes.php', { action: 'delete', id: id }).then(resp=>{
          if (resp && resp.ok) { if (deleteModal) deleteModal.hide(); showToast('Deleted'); setTimeout(()=>location.reload(),600); }
          else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown')); btn.disabled=false; btn.textContent=orig; }
      }).catch(()=>{ showToast('Network error'); btn.disabled=false; btn.textContent=orig; });
  });
}

/* undo */
document.querySelectorAll('[data-undo-id]').forEach(btn=> {
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.undoId;
        postJSON('api/classes.php', { action: 'undo', id: id }).then(resp=>{
            if (resp && resp.ok) { showToast('Restored'); setTimeout(()=>location.reload(),600); }
            else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
        }).catch(()=>showToast('Network error'));
    });
});

/* bulk delete */
if (bulkDeleteBtn) {
  bulkDeleteBtn.addEventListener('click', ()=> {
      const selected = Array.from(document.querySelectorAll('.row-chk')).filter(c=>c.checked && !c.disabled).map(c=>c.value);
      if (!selected.length) { showToast('Select rows first'); return; }
      if (!confirm('Delete selected classes?')) return;
      bulkDeleteBtn.disabled = true;
      const orig = bulkDeleteBtn.textContent;
      bulkDeleteBtn.textContent = 'Deleting...';
      postJSON('api/classes.php', { action: 'bulk_delete', ids: selected }).then(resp=>{
          if (resp && resp.ok) { showToast('Deleted ' + (resp.count || selected.length)); setTimeout(()=>location.reload(),600); }
          else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown')); bulkDeleteBtn.disabled=false; bulkDeleteBtn.textContent=orig; }
      }).catch(()=>{ showToast('Network error'); bulkDeleteBtn.disabled=false; bulkDeleteBtn.textContent=orig; });
  });
}

/* Event delegation for Update/Delete inside table (works the same) */
const tableWrap = document.querySelector('.table-wrap') || document;
tableWrap.addEventListener('click', function(e){
  const up = e.target.closest && e.target.closest('.link-update');
  if (up) {
    const id = up.dataset.id;
    if (!id) return;
    e.preventDefault();
    openEditModal(id);
    return;
  }
  const del = e.target.closest && e.target.closest('.link-delete');
  if (del) {
    const id = del.dataset.id;
    if (!id) return;
    e.preventDefault();
    openDeleteModal(id);
    return;
  }
}, false);

/* accessibility helper */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    // bootstrap handles closing modals
  }
});

/* Initialize selection controls after DOM is ready */
document.addEventListener('DOMContentLoaded', function(){
  initSelectionControls();
});
/* Also initialize immediately (script placed at end, but safe) */
initSelectionControls();
</script>
</body>
</html>
