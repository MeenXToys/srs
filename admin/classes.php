<?php
// admin/classes.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
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

/* sensible detection */
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
<style>
:root{--bg:#0f1724;--card:#0b1520;--muted:#94a3b8;--text:#e6eef8;--accent1:#7c3aed;--accent2:#6d28d9;--ok:#10b981;--danger:#ef4444;}
.center-box{max-width:1100px;margin:0 auto;padding:18px;}
.admin-controls{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;}
.left-controls{display:flex;gap:8px;align-items:center;}
.search-input{padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,0.03);background:rgba(255,255,255,0.01);color:var(--text);min-width:180px;}
/* shorten per dropdown visually */
.search-input.small { min-width:64px; max-width:64px; text-align:center; padding-left:6px; padding-right:6px; }

/* button styles aligned with Courses/Departments */
.btn{display:inline-block;padding:10px 14px;border-radius:10px;background:linear-gradient(90deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;border:0;cursor:pointer;font-weight:700;}
.btn-muted{background:#1f2937;color:#fff;border-radius:10px;padding:10px 14px;border:0;cursor:pointer;font-weight:700;}
.btn-danger{background:linear-gradient(90deg,#ef4444,#dc2626);color:#fff;border-radius:10px;padding:10px 14px;border:0;cursor:pointer;font-weight:700;}
.add-btn{background:linear-gradient(90deg,var(--accent1),var(--accent2));color:#fff;padding:10px 16px;border-radius:10px;font-weight:800;border:0;cursor:pointer;box-shadow:0 6px 20px rgba(124,58,237,0.12);}

/* top-actions */
.top-actions { display:flex; align-items:center; gap:12px; padding:10px 0; margin-bottom:14px; justify-content:flex-start; }
.top-actions > div { display:flex; gap:12px; align-items:center; }
.left-buttons { flex: 0 0 auto; }
.right-buttons { margin-left: auto; display:flex; align-items:center; gap:8px; }

/* toggle button match other frames */
.toggle-btn { border-radius:10px; padding:10px 16px; color:#fff; text-decoration:none; font-weight:700; background:linear-gradient(90deg,#2563eb,#1d4ed8); }
.toggle-btn.active-toggle { background: linear-gradient(90deg,#10b981,#0891b2); color:#072014; }

/* table */
.table-wrap{overflow:auto;border-radius:8px;margin-top:8px;}
table{width:100%;border-collapse:collapse;margin-top:12px;}
th,td{padding:14px;border-top:1px solid rgba(255,255,255,0.03);color:var(--text);vertical-align:middle;}
th{color:var(--muted);text-align:left;font-weight:700;font-size:0.95rem;}
.actions-inline{display:flex;gap:12px;align-items:center;justify-content:flex-end;}
.link-update{color:#06b76a;background:none;border:0;padding:0;cursor:pointer;font-weight:700;text-decoration:none;font-size:.95rem;}
.link-delete{color:#ef4444;background:none;border:0;padding:0;cursor:pointer;font-weight:700;text-decoration:none;font-size:.95rem;}
.checkbox-col{width:44px;text-align:center;}
.row-hover:hover td{background:rgba(255,255,255,0.01);}

/* card */
.card{background:var(--card);border-radius:12px;padding:18px;box-shadow:0 6px 18px rgba(0,0,0,0.45);}

/* modal (consistent with Courses modal) */
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.6);display:none;align-items:center;justify-content:center;z-index:400;}
.modal-backdrop.open{display:flex;backdrop-filter: blur(6px);background: rgba(2,6,23,0.5);}
.modal {
  width: 560px; max-width:94%;
  background: linear-gradient(180deg, #071026 0%, #081626 100%);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px;
  box-shadow: 0 22px 64px rgba(2,6,23,0.85), inset 0 1px 0 rgba(255,255,255,0.02);
  color: var(--text); animation: fadeInScale .22s ease-out; overflow: hidden; display:flex; flex-direction:column;
}
.modal-header { padding: 18px 22px; border-bottom:1px solid rgba(255,255,255,0.03); }
.modal-header h3 { margin:0; font-size:1.15rem; color:#f1f9ff; letter-spacing:0.3px; }
.modal-body { padding:16px 22px; color:#dbeafe; flex:1 1 auto; }
.modal-footer { display:flex; justify-content:flex-end; gap:12px; padding:14px 22px; border-top:1px solid rgba(255,255,255,0.02); }

/* form rows */
.form-row { margin-bottom:12px; display:flex; flex-direction:column; gap:6px; }
.modal label { color:#cbd5e1; font-weight:700; font-size:.95rem; }
.modal input[type="text"], .modal select {
  width:100%; padding:12px 14px; border-radius:10px; background: rgba(255,255,255,0.02);
  border:1px solid rgba(255,255,255,0.04); color:var(--text); font-size:.97rem;
}
.modal input:focus, .modal select:focus { outline:none; border-color:#38bdf8; box-shadow: 0 0 0 4px rgba(56,189,248,0.06); }

/* small utilities */
@keyframes fadeInScale { from { opacity:0; transform:scale(.98);} to { opacity:1; transform:scale(1);} }
.toast{position:fixed;right:18px;bottom:18px;background:#0b1520;padding:12px 16px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.6);color:#e6eef8;z-index:600;display:none;}
.toast.show{display:block;}
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

      <!-- Filters -->
      <form id="searchForm" method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
        <input name="q" class="search-input" placeholder="Search code or name..." value="<?= e($q) ?>">

        <select name="per" class="search-input small" onchange="this.form.submit()">
          <?php foreach([5,10,25,50] as $p): ?>
            <option value="<?= $p ?>" <?= $per == $p ? 'selected' : '' ?>><?= e((string)$p) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="dept" class="search-input" onchange="this.form.submit()">
          <option value="0">-- All Dept --</option>
          <?php foreach ($departments as $d): $sel = ($filter_dept && $filter_dept == $d['DepartmentID']) ? 'selected':''; ?>
            <option value="<?= e($d['DepartmentID']) ?>" <?= $sel ?>><?= e($d['Dept_Code'] ? "{$d['Dept_Code']} — {$d['Dept_Name']}" : $d['Dept_Name']) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="sem" class="search-input" onchange="this.form.submit()">
          <option value="">-- All Semesters --</option>
          <?php for ($s=1;$s<=8;$s++): $sel = ($filter_sem !== '' && $filter_sem == $s) ? 'selected':''; ?>
            <option value="<?= $s ?>" <?= $sel ?>><?= $s ?></option>
          <?php endfor; ?>
        </select>

        <div style="margin-left:auto; display:flex; gap:8px;">
          <button type="submit" class="btn-muted">Search</button>
          <a class="btn-muted" href="classes.php">Clear</a>
        </div>
      </form>

      <!-- Actions -->
      <div class="top-actions" aria-label="Actions">
        <div class="left-buttons">
          <button id="openAddBtn" class="add-btn">＋ Add Class</button>
          <a class="btn" href="api/classes.php?export=1">Export All</a>
          <button id="bulkDeleteBtn" class="btn-danger">Delete Selected</button>
        </div>

        <div class="right-buttons">
          <?php if ($show_deleted): ?>
            <a class="toggle-btn active-toggle" href="classes.php">🟢 Show Active</a>
            <span style="color:var(--muted);margin-left:8px">Viewing deleted (<?= (int)$deletedCount ?>)</span>
          <?php else: ?>
            <a class="toggle-btn" href="classes.php?show_deleted=1">🔴 Show Deleted <?= $deletedCount ? "({$deletedCount})" : '' ?></a>
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
                <th class="checkbox-col"><input id="chkAll" type="checkbox"></th>
                <th>Class</th>
                <th>Course</th>
                <th>Department</th>
                <th style="width:80px;text-align:right">Students</th>
                <th style="width:140px">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px;">No classes found.</td></tr>
              <?php else: foreach ($rows as $r): $isDeleted = !empty($r['deleted_at']); ?>
                <tr class="row-hover">
                  <td class="checkbox-col"><input class="row-chk" type="checkbox" value="<?= e($r['ClassID']) ?>" <?= $isDeleted ? 'disabled' : '' ?>></td>
                  <td>
                    <div style="font-weight:700;font-size:0.98rem"><?= e($r['Class_Name'] ?? '-') ?></div>
                    <div style="color:var(--muted);font-size:.9rem;margin-top:6px;">Sem <?= e($r['Semester'] ?? '-') ?></div>
                  </td>
                  <td>
                    <div style="font-weight:700"><?= e($r['Course_Code'] ? "{$r['Course_Code']} — {$r['Course_Name']}" : ($r['Course_Name'] ?? '-')) ?></div>
                  </td>
                  <td style="color:var(--muted)"><?= e($r['Dept_Code'] ? "{$r['Dept_Code']} — {$r['Dept_Name']}" : ($r['Dept_Name'] ?? '-')) ?></td>
                  <td style="text-align:right"><?= e((int)$r['students']) ?></td>
                  <td style="text-align:right">
                    <div class="actions-inline">
                      <?php if (!$isDeleted): ?>
                        <button class="link-update" data-id="<?= e($r['ClassID']) ?>">Update</button>
                      <?php else: ?>
                        <button class="btn-muted" data-undo-id="<?= e($r['ClassID']) ?>">Undo</button>
                      <?php endif; ?>
                      <button class="link-delete" data-id="<?= e($r['ClassID']) ?>">Delete</button>
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

<!-- Add/Edit Modal -->
<div id="modalBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-header">
      <h3 id="modalTitle">Add Class</h3>
    </div>

    <form id="modalForm" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" id="modalAction" value="add">
      <input type="hidden" name="id" id="modalId" value="0">

      <div class="form-row">
        <label for="modal_name">Class Name</label>
        <input id="modal_name" name="class_name" type="text" required autocomplete="off" placeholder="e.g. DCBS1">
      </div>

      <div class="form-row">
        <label for="modal_dept">Department <span style="color:#f87171;font-weight:700;">(wajib pilih)</span></label>
        <select id="modal_dept" name="department_id" required>
          <option value="">-- Select department --</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= e($d['DepartmentID']) ?>"><?= e($d['Dept_Code'] ? "{$d['Dept_Code']} — {$d['Dept_Name']}" : $d['Dept_Name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row">
        <label for="modal_course">Course <span style="color:#f87171;font-weight:700;">(wajib pilih)</span></label>
        <select id="modal_course" name="course_id" required>
          <option value="">-- Select department first --</option>
        </select>
      </div>

      <div class="form-row">
        <label for="modal_sem">Semester</label>
        <select id="modal_sem" name="semester">
          <?php for ($s=1;$s<=8;$s++): ?><option value="<?= $s ?>"><?= $s ?></option><?php endfor; ?>
        </select>
      </div>

      <div class="modal-footer">
        <button type="button" id="modalCancel" class="btn-muted">Cancel</button>
        <button id="modalSubmit" class="btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Modal -->
<div id="deleteBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog" style="width:480px;">
    <div class="modal-header"><h3>⚠️ Confirm Delete</h3></div>
    <div class="modal-body">
      <p>You are about to delete <strong id="deleteName"></strong>.</p>
      <p style="background: rgba(239,68,68,0.06); border-left:3px solid #ef4444; padding:8px; border-radius:6px; color:#f87171;">
        This will soft-delete the row (if enabled).
      </p>
      <label class="confirm-label" for="confirmDelete" style="color:#cbd5e1; display:block; margin-top:8px;">
        Please type <code>DELETE</code> below to confirm:
      </label>
      <input id="confirmDelete" type="text" placeholder="Type DELETE here" class="confirm-input" style="width:100%;padding:10px;border-radius:8px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.04);color:var(--text);margin-top:8px;">
    </div>
    <div class="modal-footer">
      <button id="deleteCancel" class="btn-muted">Cancel</button>
      <button id="deleteConfirm" class="btn" disabled style="background:linear-gradient(90deg,#ef4444,#dc2626);color:#fff;">Delete permanently</button>
    </div>
  </div>
</div>

<div id="toast" class="toast"></div>

<script>
const csrfToken = <?= json_encode($csrf) ?>;
const classMap = <?= json_encode($map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

function postJSON(url, data){
    const fd = new FormData();
    for (const k in data) {
        if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k+'[]', v));
        else fd.append(k, data[k]);
    }
    fd.append('csrf_token', csrfToken);
    return fetch(url, { method:'POST', body: fd }).then(r => r.json());
}
function getJSON(url){ return fetch(url, { credentials:'same-origin' }).then(r => r.json()); }
function showToast(msg, t=2200){ const s = document.getElementById('toast'); s.textContent = msg; s.classList.add('show'); setTimeout(()=> s.classList.remove('show'), t); }

/* helpers */
const chkAll = document.getElementById('chkAll');
if (chkAll) chkAll.addEventListener('change', ()=> document.querySelectorAll('.row-chk').forEach(c=>{ if(!c.disabled) c.checked = chkAll.checked; }));

/* modal elements */
const modalBackdrop = document.getElementById('modalBackdrop');
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

if (openAddBtn) openAddBtn.addEventListener('click', ()=> {
    modalAction.value = 'add';
    modalId.value = 0;
    modalName.value = '';
    modalDept.value = '';
    modalCourse.innerHTML = '<option value="">-- Select department first --</option>';
    modalSem.selectedIndex = 0;
    document.getElementById('modalTitle').textContent = 'Add Class';
    modalSubmit.textContent = 'Save';
    modalBackdrop.classList.add('open');
    modalName.focus();
});

/* load courses for department: expects api/classes.php?get_courses=1&dept_id=... returning array */
function loadCoursesForDepartment(deptId, selectedCourseId = '') {
    modalCourse.innerHTML = '<option value="">Loading...</option>';
    if (!deptId) { modalCourse.innerHTML = '<option value="">-- Select department first --</option>'; return; }
    getJSON('api/classes.php?get_courses=1&dept_id=' + encodeURIComponent(deptId)).then(resp=>{
        if (!resp || !Array.isArray(resp)) { modalCourse.innerHTML = '<option value="">No courses</option>'; return; }
        let out = '<option value="">-- Select course --</option>';
        resp.forEach(c=>{
            const sel = (selectedCourseId && String(selectedCourseId) === String(c.CourseID)) ? ' selected' : '';
            const label = c.Course_Code ? (c.Course_Code + ' — ' + c.Course_Name) : c.Course_Name;
            out += `<option value="${c.CourseID}"${sel}>${label}</option>`;
        });
        modalCourse.innerHTML = out;
    }).catch(()=>{ modalCourse.innerHTML = '<option value="">Error loading</option>'; });
}
modalDept.addEventListener('change', ()=> loadCoursesForDepartment(modalDept.value));

/* edit handlers */
document.querySelectorAll('.link-update').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.id; const d = classMap[id] || {};
        modalAction.value = 'edit';
        modalId.value = id;
        modalName.value = d.Class_Name || '';
        modalDept.value = d.DepartmentID || '';
        modalSem.value = d.Semester || '';
        document.getElementById('modalTitle').textContent = 'Update Class';
        modalSubmit.textContent = 'Update';
        if (d.DepartmentID) loadCoursesForDepartment(d.DepartmentID, d.CourseID || '');
        else modalCourse.innerHTML = '<option value="">-- Select department first --</option>';
        modalBackdrop.classList.add('open');
        modalName.focus();
    });
});

/* cancel modal */
modalCancel.addEventListener('click', ()=> modalBackdrop.classList.remove('open'));

/* submit add/edit */
modalForm.addEventListener('submit', function(e){
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
        if (resp && resp.ok) { modalBackdrop.classList.remove('open'); showToast('Saved'); setTimeout(()=>location.reload(),600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
    }).catch(()=>{ btn.disabled = false; btn.textContent = orig; showToast('Network error'); });
});

/* delete flow */
document.querySelectorAll('.link-delete').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.id; const d = classMap[id] || {};
        document.getElementById('deleteName').textContent = d.Class_Name || ('#'+id);
        document.getElementById('confirmDelete').value = '';
        document.getElementById('deleteConfirm').disabled = true;
        document.getElementById('deleteConfirm').dataset.id = id;
        document.getElementById('deleteBackdrop').classList.add('open');
        document.getElementById('confirmDelete').focus();
    });
});
document.getElementById('confirmDelete').addEventListener('input', function(){
    document.getElementById('deleteConfirm').disabled = (this.value !== 'DELETE');
    if (this.value.length >= 6 && this.value !== 'DELETE') { this.classList.add('shake'); setTimeout(()=> this.classList.remove('shake'), 380); }
});
document.getElementById('deleteCancel').addEventListener('click', ()=> document.getElementById('deleteBackdrop').classList.remove('open'));
document.getElementById('deleteConfirm').addEventListener('click', function(){
    const id = this.dataset.id; const btn = this; btn.disabled = true; btn.textContent = 'Deleting...';
    postJSON('api/classes.php', { action: 'delete', id: id }).then(resp=>{
        if (resp && resp.ok) { document.getElementById('deleteBackdrop').classList.remove('open'); showToast('Deleted'); setTimeout(()=>location.reload(),600); }
        else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown')); btn.disabled=false; btn.textContent='Delete permanently'; }
    }).catch(()=>{ showToast('Network error'); btn.disabled=false; btn.textContent='Delete permanently'; });
});

/* undo */
document.querySelectorAll('[data-undo-id]').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.undoId;
        postJSON('api/classes.php', { action: 'undo', id: id }).then(resp=>{
            if (resp && resp.ok) { showToast('Restored'); setTimeout(()=>location.reload(),600); }
            else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
        }).catch(()=>showToast('Network error'));
    });
});

/* bulk delete */
const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
if (bulkDeleteBtn) bulkDeleteBtn.addEventListener('click', ()=> {
    const selected = Array.from(document.querySelectorAll('.row-chk')).filter(c=>c.checked).map(c=>c.value);
    if (!selected.length) return alert('Select rows first');
    if (!confirm('Delete selected classes?')) return;
    postJSON('api/classes.php', { action: 'bulk_delete', ids: selected }).then(resp=>{
        if (resp && resp.ok) { showToast('Deleted ' + (resp.count || selected.length)); setTimeout(()=>location.reload(),600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
    }).catch(()=>showToast('Network error'));
});

/* close modals on backdrop click / Escape */
document.querySelectorAll('.modal-backdrop').forEach(b => b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); }));
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(b=>b.classList.remove('open')); });
</script>
</body>
</html>
