<?php
// admin/students_manage.php (fixed StudentID & Email handling)
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

// safe echo
if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

// CSRF
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

// helper to pick probable column name (keeps page flexible)
function pick_col(array $cols, array $candidates, $default = null) {
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return $default;
}

// discover student columns (graceful)
$studentCols = [];
try {
    $studentCols = $pdo->query("SHOW COLUMNS FROM student")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {
    $studentCols = [];
    $errMsg = "DB error reading student columns: " . $ex->getMessage();
}

/*
 * IMPORTANT: prefer StudentID first (was defaulting to UserID).
 * We want to display the actual StudentID mapped in the student table.
 */
$col_id     = pick_col($studentCols, ['StudentID','Student_Id','studentid','UserID','id','user_id','student_id'], 'StudentID');
$col_name   = pick_col($studentCols, ['FullName','fullname','Name','name','student_name'], 'FullName');
$col_email  = pick_col($studentCols, ['Email','email','EmailAddress','email_address','email_addr'], null);
$col_class  = pick_col($studentCols, ['ClassID','class_id','Class','class'], 'ClassID');
$col_deleted= pick_col($studentCols, ['deleted_at','deleted','is_deleted'], null);

function hascol_student($name){ global $studentCols; return $name !== null && in_array($name, $studentCols); }

// load departments + courses for modal selects (used in add/update modal)
$departments = [];
$courses = [];
try {
    $departments = $pdo->query("SELECT DepartmentID, Dept_Code, Dept_Name FROM department ORDER BY Dept_Code IS NULL, Dept_Code ASC, Dept_Name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $departments = []; }
try {
    $courses = $pdo->query("SELECT CourseID, Course_Code, Course_Name, DepartmentID FROM course ORDER BY Course_Code ASC, Course_Name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $courses = []; }

// params / pagination / filters
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = (int)($_GET['per'] ?? 10); if ($per <= 0) $per = 10;
$sort = $_GET['sort'] ?? 'name';
$dir = (strtolower($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
$show_deleted = ($_GET['show_deleted'] ?? '') === '1';

// allowed sorts
$allowed = ['name','email','class','id'];
if (!in_array($sort, $allowed)) $sort = 'name';
function build_sort_link($col){ $p=$_GET; $cur=$p['sort'] ?? 'name'; $cdir=strtolower($p['dir']??'asc'); $p['dir']=($cur===$col)?($cdir==='asc'?'desc':'asc'):'asc'; $p['sort']=$col; $p['page']=1; return basename($_SERVER['PHP_SELF']).'?'.http_build_query($p); }
function sort_arrow($col){ $cur=$_GET['sort'] ?? 'name'; $d=strtolower($_GET['dir'] ?? 'asc'); if($cur!==$col) return ''; return $d==='desc'?'↓':'↑'; }

// build WHERE depending on available columns
$where = []; $params = [];
if ($q !== '') {
    $parts = [];
    $qLike = "%$q%";
    if (hascol_student($col_name)) $parts[] = "s.`{$col_name}` LIKE :q";
    // if student table has email column use it; otherwise search user.email later via join alias u.Email
    if (hascol_student($col_email)) {
        $parts[] = "s.`{$col_email}` LIKE :q";
    } else {
        // mark to search user email via join
        $parts[] = "u.`Email` LIKE :q";
    }
    if ($parts) {
        $where[] = '(' . implode(' OR ', $parts) . ')';
        $params[':q'] = $qLike;
    }
}
if ($col_deleted && hascol_student($col_deleted) && !$show_deleted) {
    $where[] = "s.`{$col_deleted}` IS NULL";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// COUNT query: need to include user join if we're searching email on user
try {
    // include LEFT JOIN to user to support email lookup / display
    $countSql = "SELECT COUNT(DISTINCT s.`" . ($col_id ?: 'StudentID') . "`)
                 FROM student s
                 LEFT JOIN `class` c ON c.ClassID = s.ClassID
                 LEFT JOIN `user` u ON u.UserID = s.UserID
                 $whereSql";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k=>$v) $countStmt->bindValue($k,$v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch (Exception $ex) {
    $total = 0;
    $errMsg = $errMsg ?? ("DB count error: " . $ex->getMessage());
}

// pagination
$totalPages = max(1, (int)ceil($total / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

// choose select columns safely
$select = [];

/* Student ID: prefer actual student.StudentID column if present,
   otherwise fall back to student.UserID (but DB dump shows StudentID exists) */
if (hascol_student('StudentID')) {
    $select[] = "s.`StudentID` AS StudentID";
} elseif (hascol_student($col_id)) {
    // if pick_col returned something else, use it
    $select[] = "s.`{$col_id}` AS StudentID";
} else {
    // final fallback - use UserID
    $select[] = "s.`UserID` AS StudentID";
}

// FullName
$select[] = hascol_student($col_name) ? "s.`{$col_name}` AS FullName" : "NULL AS FullName";

// Email: prefer student table email if present, otherwise take from user table
if (hascol_student($col_email)) {
    $select[] = "s.`{$col_email}` AS Email";
    $emailUsedFrom = 'student';
} else {
    $select[] = "u.`Email` AS Email";
    $emailUsedFrom = 'user';
}

// ClassID and deleted_at
$select[] = hascol_student($col_class) ? "s.`{$col_class}` AS ClassID" : "NULL AS ClassID";
$select[] = ($col_deleted && hascol_student($col_deleted)) ? "s.`{$col_deleted}` AS deleted_at" : "NULL AS deleted_at";

$selectSql = implode(", ", $select);

// order map - map logical sort names to selected aliases
$orderMap = ['name'=>'FullName','email'=>'Email','class'=>'ClassID','id'=>'StudentID'];
$orderSql = ($orderMap[$sort] ?? $orderMap['name']) . ' ' . $dir;

// fetch rows - include LEFT JOIN to user (for Email fallback) and class if needed
try {
    $sql = "
      SELECT $selectSql
      FROM student s
      LEFT JOIN `class` c ON c.ClassID = s.ClassID
      LEFT JOIN `user` u ON u.UserID = s.UserID
      $whereSql
      ORDER BY $orderSql
      LIMIT :offset, :limit
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', (int)$per, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    $rows = [];
    $errMsg = $errMsg ?? ("DB select error: " . $ex->getMessage());
}

// build map for JS
$map = [];
foreach ($rows as $r) {
    // ensure we use StudentID value (could be varchar)
    $id = $r['StudentID'] ?? null;
    if ($id === null) continue;
    // for JS map keys, use the actual DB primary key if numeric UserID exists in record.
    // But to remain consistent with your UI we use StudentID string as key (castable).
    $map[(string)$id] = [
        'FullName' => $r['FullName'] ?? null,
        'Email' => $r['Email'] ?? null,
        'ClassID' => $r['ClassID'] ?? null,
        'deleted_at' => $r['deleted_at'] ?? null
    ];
}

// deleted count
$deletedCount = 0;
if ($col_deleted && hascol_student($col_deleted)) {
    try { $deletedCount = (int)$pdo->query("SELECT COUNT(*) FROM student WHERE `{$col_deleted}` IS NOT NULL")->fetchColumn(); } catch (Exception $e) { $deletedCount = 0; }
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Students — Admin</title>
<link rel="stylesheet" href="../style.css">
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- keep Bootstrap for general styles if used -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root{
  --bg:#0f1724; --card:#07111a; --muted:#9aa8bd; --text:#e8f6ff;
  --accent1:#7c3aed; --accent2:#6d28d9; --danger:#ef4444; --ok:#10b981;
}

/* layout & styles remain same as your provided design; repeating minimal rules so file is self-contained */
body{background:var(--bg); color:var(--text); font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial;margin:0;}
.admin-main{min-height:100vh;}
.center-box{ width: calc(100% - 48px); max-width: none; margin:24px auto; padding:30px; box-sizing:border-box; }
.admin-controls{display:flex;justify-content:flex-start;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;}
.search-input{padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);background:rgba(255,255,255,0.02);color:var(--text);min-width:300px;height:44px;}
.top-actions{display:flex;align-items:center;gap:12px;margin-bottom:18px;justify-content:space-between;}
.btn-pill{display:inline-flex;align-items:center;justify-content:center;padding:10px 22px;border-radius:12px;font-weight:700;border:1px solid rgba(255,255,255,0.06);cursor:pointer;min-height:44px;}
.btn-pill.add{background:linear-gradient(90deg,var(--accent1),var(--accent2)); color:#fff;}
.btn-pill.export{background:linear-gradient(90deg,#3388ff,var(--accent2)); color:#fff;}
.btn-pill.delete{background:linear-gradient(90deg,#bf3b3b,#8e2b2b); color:#fff;}
/* card */
.card{ width:100% !important; max-width:none !important; background: var(--card); border-radius:12px; padding:18px; margin-top: 18px; box-shadow:0 8px 28px rgba(2,6,23,0.6); display:flex; flex-direction:column; gap:18px; overflow:visible; min-height:320px; }
.table-wrap{overflow:auto;margin-top:14px;padding-top:4px;width:100%;}
table{width:100%;border-collapse:collapse;min-width:700px;}
th,td{padding:12px;border-top:1px solid rgba(255,255,255,0.03);vertical-align:middle;color:var(--text);font-size:0.95rem;}
th{color:var(--muted);font-weight:700;text-align:left;}
.checkbox-col{width:46px;text-align:center;}
.link-update{color:#06b76a;font-weight:700;background:none;border:0;padding:0;cursor:pointer;}
.link-delete{color:var(--danger);font-weight:700;background:none;border:0;padding:0;cursor:pointer;}
.row-hover:hover td{background:rgba(255,255,255,0.01);}
.pager{display:flex;align-items:center;gap:8px;margin-top:12px;color:var(--muted);}
.custom-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:900;}
.custom-backdrop.open{display:flex;background: rgba(2,6,23,0.6);backdrop-filter:blur(6px);}
.modal-card{width:720px;max-width:96%;background:linear-gradient(180deg,#071026 0%,#081626 100%);border:1px solid rgba(255,255,255,0.06);border-radius:12px;padding:22px;color:var(--text);box-shadow:0 20px 64px rgba(2,6,23,0.85);}
.input-label{display:block;margin-bottom:6px;color:#cfe8ff;font-weight:700}
.form-input, .form-select, .form-file { width:100%; padding:12px 14px; border-radius:10px; background: rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.04); color:var(--text); box-sizing:border-box; }
.row-actions{display:flex;gap:12px;justify-content:flex-end;align-items:center;margin-top:8px}
.cancel-btn{background:#38424b;color:#fff;padding:12px 18px;border-radius:10px;border:0;cursor:pointer}
.save-btn{background:linear-gradient(90deg,#2f6df6,#206ef0);color:#fff;padding:12px 22px;border-radius:10px;border:0;cursor:pointer}
.toast{position:fixed;right:18px;bottom:18px;background:#0b1520;padding:12px 16px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.6);color:var(--text);z-index:1100;display:none}
.toast.show{display:block}
@media (max-width:1200px){ .center-box{width: calc(100% - 36px); padding:20px;} .modal-card{width:94%;} .table-wrap{overflow:auto} }
@media (max-width:780px){ .center-box{padding:16px;} .search-input{min-width:120px;} .btn-pill{padding:10px 14px;} }
</style>
</head>
<body>
<main class="admin-main">
  <div class="admin-container">
    <div class="center-box">

      <?php if (!empty($errMsg)): ?>
        <div style="background:#3b1f1f;color:#ffdede;padding:12px;border-radius:8px;margin-bottom:12px;">
          <?= e($errMsg) ?>
        </div>
      <?php endif; ?>

      <?php if ($flash): ?><div id="pageFlash" class="toast show"><?= e($flash) ?></div><?php endif; ?>

      <div class="admin-controls">
        <form method="get" style="display:flex;gap:12px;align-items:center;width:100%;">
          <input name="q" class="search-input" placeholder="Search name or email..." value="<?= e($q) ?>">
          <select name="per" onchange="this.form.submit()" class="search-input" style="width:120px;">
            <?php foreach([10,25,50,100] as $p): ?>
              <option value="<?= $p ?>" <?= $per == $p ? 'selected' : '' ?>><?= $p ?>/page</option>
            <?php endforeach; ?>
          </select>
          <div style="margin-left:auto;"></div>
        </form>
      </div>

      <div class="top-actions" aria-label="Actions">
        <div class="left-buttons">
          <button id="openAddBtn" class="btn-pill add">＋ Add Student</button>
          <a class="btn-pill export" href="api/students.php?export=1">Export All</a>
          <button id="bulkDeleteBtn" class="btn-pill delete">Delete Selected</button>
        </div>

        <div class="right-buttons">
          <?php if ($show_deleted): ?>
            <a class="btn-pill" href="students_manage.php">Show Active</a>
            <div style="color:var(--muted);margin-left:8px;">Viewing deleted (<?= (int)$deletedCount ?>)</div>
          <?php else: ?>
            <a class="btn-pill" href="students_manage.php?show_deleted=1">Show Deleted <?= $deletedCount ? "({$deletedCount})" : '' ?></a>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <h2>Student List</h2>
          <div class="meta"><?= $total ?> result<?= $total==1?'':'s' ?></div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="checkbox-col"><input id="chkAll" type="checkbox" aria-label="Select all"></th>
                <th style="width:140px"><a href="<?= e(build_sort_link('id')) ?>" style="color:inherit;text-decoration:none;">Student ID <?= e(sort_arrow('id')) ?></a></th>
                <th><a href="<?= e(build_sort_link('name')) ?>" style="color:inherit;text-decoration:none;">Name <?= e(sort_arrow('name')) ?></a></th>
                <th><a href="<?= e(build_sort_link('email')) ?>" style="color:inherit;text-decoration:none;">Email <?= e(sort_arrow('email')) ?></a></th>
                <th style="width:180px">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:28px;">No students found.</td></tr>
              <?php else: foreach ($rows as $r):
                    // use StudentID (string) as the unique id presented in UI
                    $displayId = $r['StudentID'] ?? ($r['UserID'] ?? '-');
                    $isDeleted = !empty($r['deleted_at']);
              ?>
                <tr class="row-hover" data-id="<?= e($displayId) ?>">
                  <td class="checkbox-col"><input class="row-chk" type="checkbox" value="<?= e($displayId) ?>" <?= $isDeleted ? 'disabled' : '' ?> aria-label="Select row"></td>
                  <td style="font-weight:700;"><?= e($displayId) ?></td>
                  <td>
                    <?= e($r['FullName'] ?? '-') ?>
                    <?php if ($isDeleted): ?><div style="color:var(--muted);font-size:.9rem;margin-top:6px;">Deleted at <?= e($r['deleted_at']) ?></div><?php endif; ?>
                  </td>
                  <td><?= e($r['Email'] ?? '-') ?></td>
                  <td>
                    <?php if (!$isDeleted): ?>
                      <button class="link-update" data-id="<?= e($displayId) ?>">Update</button>
                    <?php else: ?>
                      <button data-undo-id="<?= e($displayId) ?>" class="link-update">Undo</button>
                    <?php endif; ?>
                    &nbsp;&nbsp;
                    <button class="link-delete" data-id="<?= e($displayId) ?>">Delete</button>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
          <div>
            <?php
            $baseParams = [];
            if ($q !== '') $baseParams['q'] = $q;
            if ($per !== 10) $baseParams['per'] = $per;
            if ($show_deleted) $baseParams['show_deleted'] = 1;
            $baseParams['sort'] = $sort;
            $baseParams['dir'] = strtolower($dir) === 'desc' ? 'desc' : 'asc';
            for ($p=1; $p <= max(1,$totalPages); $p++) {
                $baseParams['page'] = $p;
                $u = basename($_SERVER['PHP_SELF']) . '?' . http_build_query($baseParams);
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

<!-- ADD / UPDATE STUDENT Modal (custom backdrop) -->
<div id="studentModalBackdrop" class="custom-backdrop" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="studentModalTitle">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
      <h3 id="studentModalTitle">Add Student</h3>
      <button id="studentModalClose" style="background:none;border:0;color:var(--muted);font-size:20px;cursor:pointer">✕</button>
    </div>

    <form id="studentForm" enctype="multipart/form-data" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" id="studentFormAction" value="add">
      <input type="hidden" name="id" id="studentFormId" value="0">

      <div class="form-row">
        <label class="input-label" for="full_name">Full Name</label>
        <input id="full_name" name="fullname" class="form-input" type="text" placeholder="e.g. John Doe" required>
      </div>

      <div class="form-row">
        <label class="input-label" for="email">Email</label>
        <input id="email" name="email" class="form-input" type="email" placeholder="you@example.com">
      </div>

      <div style="display:flex;gap:12px;">
        <div style="flex:1" class="form-row">
          <label class="input-label" for="password">Password <span style="font-weight:700;color:#f87171">(optional)</span></label>
          <input id="password" name="password" class="form-input" type="password" placeholder="••••••">
        </div>
        <div style="flex:1" class="form-row">
          <label class="input-label" for="password_confirm">Confirm Password</label>
          <input id="password_confirm" name="password_confirm" class="form-input" type="password" placeholder="••••••">
        </div>
      </div>

      <div class="form-row" style="display:flex;gap:12px;">
        <div style="flex:1">
          <label class="input-label" for="dept_select">Department</label>
          <select id="dept_select" name="department_id" class="form-select">
            <option value="">-- Select department --</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= e($d['DepartmentID']) ?>"><?= e($d['Dept_Code'] ? "{$d['Dept_Code']} — {$d['Dept_Name']}" : $d['Dept_Name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1">
          <label class="input-label" for="course_select">Course</label>
          <select id="course_select" name="course_id" class="form-select">
            <option value="">-- Select department first --</option>
            <?php foreach ($courses as $c): ?>
              <option data-dept="<?= e($c['DepartmentID']) ?>" value="<?= e($c['CourseID']) ?>"><?= e($c['Course_Code'] ? "{$c['Course_Code']} — {$c['Course_Name']}" : $c['Course_Name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="display:flex;gap:12px;">
        <div style="flex:1" class="form-row">
          <label class="input-label" for="class_input">Class (ID)</label>
          <input id="class_input" name="class_id" class="form-input" type="text" placeholder="e.g. DCBS1">
        </div>
        <div style="width:140px" class="form-row">
          <label class="input-label" for="semester_select">Semester</label>
          <select id="semester_select" name="semester" class="form-select">
            <?php for($s=1;$s<=8;$s++): ?><option value="<?= $s ?>"><?= $s ?></option><?php endfor; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <label class="input-label" for="profile_file">Profile Image (optional)</label>
        <input id="profile_file" name="profile_image" class="form-file" type="file" accept="image/*">
      </div>

      <div class="row-actions">
        <button type="button" id="studentCancel" class="cancel-btn">Cancel</button>
        <button type="submit" id="studentSave" class="save-btn">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Modal -->
<div id="deleteBackdrop" class="custom-backdrop" aria-hidden="true">
  <div class="delete-modal" role="dialog">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h3 style="margin:0;color:#fca5a5">⚠️ Confirm Delete</h3>
      <button id="deleteClose" style="background:none;border:0;color:var(--muted);font-size:18px;cursor:pointer">✕</button>
    </div>

    <p>You are about to delete <strong id="deleteStudentName"></strong>.</p>
    <div class="warning-text">This will <b>soft-delete</b> the student if supported. You can restore it later via Undo.</div>

    <label style="display:block;margin-top:10px;color:var(--muted);">Please type <code>DELETE</code> below to confirm:</label>
    <input id="confirmDelete" class="confirm-input" placeholder="Type DELETE here">

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
      <button id="deleteCancel" class="cancel-btn">Cancel</button>
      <button id="deleteConfirm" class="save-btn" disabled style="background:linear-gradient(90deg,#dc2626,#b91c1c)">Delete permanently</button>
    </div>
  </div>
</div>

<div id="toast" class="toast"></div>

<!-- Bootstrap JS (optional) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const csrfToken = <?= json_encode($csrf) ?>;
const studentMap = <?= json_encode($map, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS) ?>;
const courses = <?= json_encode($courses, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS) ?>;

function showToast(msg, time=2200) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'), time);
}
function postJSON(url, data) {
  return fetch(url, { method: 'POST', body: data, credentials:'same-origin' })
    .then(async r => {
      const text = await r.text();
      try { return JSON.parse(text); } catch(e) { throw new Error('Server returned non-JSON: ' + text); }
    });
}

/* modal wiring (unchanged) */
const studentModalBackdrop = document.getElementById('studentModalBackdrop');
const studentModalClose = document.getElementById('studentModalClose');
const studentForm = document.getElementById('studentForm');
const studentFormAction = document.getElementById('studentFormAction');
const studentFormId = document.getElementById('studentFormId');
const studentSave = document.getElementById('studentSave');
const studentCancel = document.getElementById('studentCancel');
const openAddBtn = document.getElementById('openAddBtn');
const deptSelect = document.getElementById('dept_select');
const courseSelect = document.getElementById('course_select');

const deleteBackdrop = document.getElementById('deleteBackdrop');
const deleteStudentName = document.getElementById('deleteStudentName');
const confirmDelete = document.getElementById('confirmDelete');
const deleteConfirm = document.getElementById('deleteConfirm');
const deleteCancel = document.getElementById('deleteCancel');
const deleteClose = document.getElementById('deleteClose');

const chkAll = document.getElementById('chkAll');
const rowChecks = ()=> Array.from(document.querySelectorAll('.row-chk'));

/* Open Add modal */
openAddBtn.addEventListener('click', ()=> {
  studentFormAction.value = 'add';
  studentFormId.value = 0;
  studentForm.reset();
  document.getElementById('studentModalTitle').textContent = 'Add Student';
  studentSave.textContent = 'Save';
  filterCoursesByDept();
  studentModalBackdrop.classList.add('open');
  setTimeout(()=> document.getElementById('full_name').focus(), 80);
});
studentModalClose.addEventListener('click', ()=> studentModalBackdrop.classList.remove('open'));
studentCancel.addEventListener('click', ()=> studentModalBackdrop.classList.remove('open'));

/* filter course options by department (client-side) */
function filterCoursesByDept() {
  const dept = deptSelect.value;
  courseSelect.innerHTML = '';
  if (!dept) {
    courseSelect.innerHTML = '<option value="">-- Select department first --</option>';
    return;
  }
  const frag = document.createDocumentFragment();
  const placeholder = document.createElement('option'); placeholder.value=''; placeholder.textContent='-- Select course --';
  frag.appendChild(placeholder);
  courses.forEach(c => {
    if (String(c.DepartmentID) === String(dept)) {
      const opt = document.createElement('option');
      opt.value = c.CourseID;
      opt.textContent = (c.Course_Code ? c.Course_Code + ' — ' + c.Course_Name : c.Course_Name);
      frag.appendChild(opt);
    }
  });
  if (!frag.querySelectorAll || frag.childNodes.length <= 1) {
    courseSelect.innerHTML = '<option value="">-- No courses for this department --</option>';
    return;
  }
  courseSelect.appendChild(frag);
}
if (deptSelect) deptSelect.addEventListener('change', filterCoursesByDept);

/* Update / Delete buttons using event delegation (unchanged) */
document.addEventListener('click', function(e) {
  const up = e.target.closest && e.target.closest('.link-update');
  if (up) {
    const id = up.dataset.id;
    const d = studentMap[id] || {};
    studentFormAction.value = 'edit';
    studentFormId.value = id;
    document.getElementById('full_name').value = d.FullName || '';
    document.getElementById('email').value = d.Email || '';
    document.getElementById('class_input').value = d.ClassID || '';
    document.getElementById('password').value = '';
    document.getElementById('password_confirm').value = '';
    document.getElementById('studentModalTitle').textContent = 'Update Student';
    studentSave.textContent = 'Update';
    filterCoursesByDept();
    studentModalBackdrop.classList.add('open');
    setTimeout(()=> document.getElementById('full_name').focus(), 80);
    e.preventDefault();
    return;
  }

  const del = e.target.closest && e.target.closest('.link-delete');
  if (del) {
    const id = del.dataset.id;
    const d = studentMap[id] || {};
    deleteStudentName.textContent = d.FullName || ('#'+id);
    confirmDelete.value = '';
    deleteConfirm.disabled = true;
    deleteConfirm.dataset.id = id;
    deleteBackdrop.classList.add('open');
    setTimeout(()=> confirmDelete.focus(), 80);
    e.preventDefault();
    return;
  }
});

/* Submit add / edit (unchanged) */
studentForm.addEventListener('submit', function(ev){
  ev.preventDefault();
  studentSave.disabled = true;
  const origText = studentSave.textContent;
  studentSave.textContent = (studentFormAction.value === 'edit') ? 'Updating...' : 'Saving...';

  const fd = new FormData(studentForm);
  fd.append('csrf_token', csrfToken);

  const pw = (document.getElementById('password').value || '').trim();
  const pwc = (document.getElementById('password_confirm').value || '').trim();
  if ((pw || pwc) && pw !== pwc) {
    showToast('Passwords do not match');
    studentSave.disabled = false; studentSave.textContent = origText;
    return;
  }

  postJSON('api/students.php', fd).then(resp=>{
    studentSave.disabled = false; studentSave.textContent = origText;
    if (resp && resp.ok) {
      studentModalBackdrop.classList.remove('open');
      showToast('Saved', 1200);
      setTimeout(()=> location.reload(), 600);
    } else {
      showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 3500);
    }
  }).catch(err=>{
    console.error(err);
    showToast('Network or server error (see console)', 3500);
    studentSave.disabled = false; studentSave.textContent = origText;
  });
});

/* Delete typed confirm flow (unchanged) */
if (confirmDelete) confirmDelete.addEventListener('input', function(){
  deleteConfirm.disabled = (this.value !== 'DELETE');
});
if (deleteConfirm) deleteConfirm.addEventListener('click', function(){
  const id = this.dataset.id;
  if (!id) return;
  this.disabled = true;
  const orig = this.textContent;
  this.textContent = 'Deleting...';
  const fd = new FormData();
  fd.append('action','delete'); fd.append('id', id); fd.append('csrf_token', csrfToken);
  postJSON('api/students.php', fd).then(resp=>{
    if (resp && resp.ok) {
      deleteBackdrop.classList.remove('open');
      showToast('Deleted', 1200);
      setTimeout(()=> location.reload(), 600);
    } else {
      showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 3500);
      this.disabled = false; this.textContent = orig;
    }
  }).catch(err=>{
    console.error(err);
    showToast('Network or server error', 3500);
    this.disabled = false; this.textContent = orig;
  });
});
if (deleteCancel) deleteCancel.addEventListener('click', ()=> deleteBackdrop.classList.remove('open'));
if (deleteClose) deleteClose.addEventListener('click', ()=> deleteBackdrop.classList.remove('open'));

/* Undo restore (unchanged) */
document.addEventListener('click', function(e){
  const u = e.target.closest && e.target.closest('[data-undo-id]');
  if (u) {
    const id = u.dataset.undoId;
    if (!confirm('Restore this student?')) return;
    const fd = new FormData(); fd.append('action','undo'); fd.append('id', id); fd.append('csrf_token', csrfToken);
    postJSON('api/students.php', fd).then(resp=>{
      if (resp && resp.ok) { showToast('Restored',1200); setTimeout(()=>location.reload(),600); }
      else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
    }).catch(()=> showToast('Network error'));
  }
});

/* Bulk delete (unchanged) */
const bulkBtn = document.getElementById('bulkDeleteBtn');
if (bulkBtn) bulkBtn.addEventListener('click', function(){
  const selected = rowChecks().filter(c=>c.checked).map(c=>c.value);
  if (!selected.length) return alert('Select rows first');
  if (!confirm('Delete selected students?')) return;
  const fd = new FormData(); fd.append('action','bulk_delete'); selected.forEach(id=>fd.append('ids[]', id)); fd.append('csrf_token', csrfToken);
  postJSON('api/students.php', fd).then(resp=>{
    if (resp && resp.ok) { showToast('Deleted ' + (resp.count||selected.length),1200); setTimeout(()=>location.reload(),600); }
    else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
  }).catch(()=> showToast('Network error'));
});

/* Master checkbox */
if (chkAll) chkAll.addEventListener('change', ()=> {
  const checked = chkAll.checked;
  rowChecks().forEach(cb => { if (!cb.disabled) cb.checked = checked; });
});

/* close on backdrop click or Esc */
document.querySelectorAll('.custom-backdrop').forEach(b => {
  b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.custom-backdrop.open').forEach(b=>b.classList.remove('open')); });
</script>
</body>
</html>
