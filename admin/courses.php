<?php
// admin/courses.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

// small safe echo
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

// params
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = (int)($_GET['per'] ?? 10); if ($per <= 0) $per = 10;
$sort = $_GET['sort'] ?? 'name';
$dir = (strtolower($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
$show_deleted = ($_GET['show_deleted'] ?? '') === '1';

// allowed sorts
$allowed = ['name','code','students'];
if (!in_array($sort, $allowed)) $sort = 'name';

// helper functions (build sort links, arrow)
function build_sort_link($col) {
    $params = $_GET;
    $currentSort = $params['sort'] ?? 'name';
    $currentDir  = strtolower($params['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    if ($currentSort === $col) $params['dir'] = ($currentDir === 'asc') ? 'desc' : 'asc';
    else $params['dir'] = 'asc';
    $params['sort'] = $col; $params['page'] = 1;
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($params);
}
function sort_arrow($col) {
    $currentSort = $_GET['sort'] ?? 'name';
    $currentDir  = strtolower($_GET['dir'] ?? 'asc');
    if ($currentSort !== $col) return '';
    return ($currentDir === 'desc') ? '↓' : '↑';
}

// detect deleted_at column
$has_deleted_at = false;
try { $has_deleted_at = (bool)$pdo->query("SHOW COLUMNS FROM course LIKE 'deleted_at'")->fetch(); } catch(Exception $e) { $has_deleted_at = false; }

// fetch departments for dropdown (id, code, name)
$departments = [];
try {
    $dstmt = $pdo->query("SELECT DepartmentID, Dept_Code, Dept_Name FROM department ORDER BY Dept_Code IS NULL, Dept_Code ASC, Dept_Name ASC");
    $departments = $dstmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    $departments = [];
}

// where clause
$where = [];
$params = [];
if ($q !== '') { $where[] = "(c.Course_Name LIKE :q OR c.Course_Code LIKE :q OR d.Dept_Name LIKE :q)"; $params[':q'] = "%$q%"; }
if ($has_deleted_at && !$show_deleted) $where[] = "c.deleted_at IS NULL";
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// count
try {
    $countSql = "
      SELECT COUNT(DISTINCT c.CourseID) FROM course c
      LEFT JOIN class cl ON cl.CourseID = c.CourseID
      LEFT JOIN student s ON s.ClassID = cl.ClassID
      LEFT JOIN department d ON d.DepartmentID = c.DepartmentID
      $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k=>$v) $countStmt->bindValue($k,$v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch(Exception $e) { $total = 0; $errMsg = $e->getMessage(); }

// pagination
$totalPages = max(1, (int)ceil($total / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page -1) * $per;

// data (order by dept then course)
try {
    $orderMap = ['name'=>'c.Course_Name','code'=>'c.Course_Code','students'=>'students'];
    $orderSql = ($orderMap[$sort] ?? $orderMap['name']) . ' ' . $dir;

    $sql = "
      SELECT c.CourseID, c.Course_Code, c.Course_Name, c.DepartmentID " . ($has_deleted_at ? ", c.deleted_at" : ", NULL AS deleted_at") . ",
             d.Dept_Name, d.Dept_Code, COUNT(s.UserID) AS students
      FROM course c
      LEFT JOIN department d ON d.DepartmentID = c.DepartmentID
      LEFT JOIN class cl ON cl.CourseID = c.CourseID
      LEFT JOIN student s ON s.ClassID = cl.ClassID
      $whereSql
      GROUP BY c.CourseID, c.Course_Code, c.Course_Name, d.Dept_Name, d.Dept_Code, c.DepartmentID " . ($has_deleted_at ? ", c.deleted_at" : "") . "
      ORDER BY COALESCE(d.Dept_Code, d.Dept_Name) ASC, $orderSql
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

// group rows by department (Dept_Code preferred else Dept_Name)
$groups = [];
foreach ($rows as $r) {
    $deptCode = $r['Dept_Code'] ?? '';
    $deptName = $r['Dept_Name'] ?? '';
    if ($deptCode === null || trim($deptCode) === '') {
        $deptKey = $deptName ? $deptName : 'Unassigned';
    } else {
        $deptKey = $deptCode;
    }
    if (!isset($groups[$deptKey])) {
        $groups[$deptKey] = [
            'dept_code' => $deptCode,
            'dept_name' => $deptName,
            'items' => []
        ];
    }
    $groups[$deptKey]['items'][] = $r;
}

// JS map for actions
$map = [];
foreach ($rows as $r) {
    $map[(int)$r['CourseID']] = [
        'Course_Code'=>$r['Course_Code'],
        'Course_Name'=>$r['Course_Name'],
        'DepartmentID'=>$r['DepartmentID'],
        'Dept_Name'=>$r['Dept_Name'],
        'Dept_Code'=>$r['Dept_Code'],
        'students'=>(int)$r['students'],
        'deleted_at'=>$r['deleted_at'] ?? null
    ];
}

// deleted count
$deletedCount = 0;
if ($has_deleted_at) {
    try { $deletedCount = (int)$pdo->query("SELECT COUNT(*) FROM course WHERE deleted_at IS NOT NULL")->fetchColumn(); } catch(Exception $e) { $deletedCount = 0; }
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Courses — Admin</title>
<link rel="stylesheet" href="../style.css">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--bg:#0f1724;--card:#0b1520;--muted:#94a3b8;--text:#e6eef8;--accent1:#7c3aed;--accent2:#6d28d9;--ok:#10b981;--danger:#ef4444;}
.center-box{max-width:1100px;margin:0 auto;padding:18px;}
.admin-controls{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;}
.left-controls{display:flex;gap:8px;align-items:center;}
.search-input{padding:8px 10px;border-radius:6px;border:1px solid rgba(255,255,255,0.04);background:var(--card);color:var(--text);min-width:220px;}
.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;border:0;cursor:pointer;}
.btn-muted{background:#374151;color:#fff;border-radius:8px;padding:8px 12px;border:0;cursor:pointer;}
.add-btn{background:linear-gradient(180deg,var(--accent1),var(--accent2));color:#fff;padding:10px 16px;border-radius:8px;font-weight:700;border:0;cursor:pointer;}
.card{background:var(--card);border-radius:10px;padding:18px;box-shadow:0 6px 18px rgba(0,0,0,0.4);}

/* toolbar */
.top-actions { display:flex; align-items:center; gap:12px; padding:10px; background: rgba(255,255,255,0.01); border-radius:10px; margin-bottom:14px; justify-content:flex-start; }
.top-actions > div { display:flex; gap:10px; align-items:center; }
.left-buttons { flex: 0 0 auto; }
.right-buttons { margin-left: auto; display:flex; align-items:center; gap:8px; }
.btn-danger { background: linear-gradient(180deg,#dc2626,#b91c1c); color:#fff; border:0; padding:8px 12px; border-radius:8px; cursor:pointer; }
.toggle-btn { border-radius:8px; padding:8px 12px; color:#fff; text-decoration:none; font-weight:600; }
.toggle-btn.active-toggle { background:#10b981; color:#072014; }
.toggle-btn:not(.active-toggle) { background:#ef4444; }
.toggle-label { color:var(--muted); font-size:0.9rem; font-style:italic; margin-left:6px; }

/* search buttons same width */
.search-buttons { display:flex; gap:8px; }
.search-buttons button, .search-buttons a.btn-muted { width:90px; height:38px; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; font-weight:600; }

/* table */
.table-wrap{overflow:auto;}
table{width:100%;border-collapse:collapse;margin-top:12px;}
th,td{padding:12px;border-top:1px solid rgba(255,255,255,0.03);color:var(--text);vertical-align:middle;}
th{color:var(--muted);text-align:left;font-weight:700;}
.code-badge{background:#071725;color:#7dd3fc;padding:6px 10px;border-radius:6px;font-weight:700;}
.actions-inline{display:flex;gap:12px;align-items:center;justify-content:flex-end;}
.link-update{color:var(--ok);background:none;border:0;padding:0;cursor:pointer;font-weight:700;text-decoration:none;font-size:.95rem;}
.link-delete{color:var(--danger);background:none;border:0;padding:0;cursor:pointer;font-weight:700;text-decoration:none;font-size:.95rem;}
.checkbox-col{width:36px;text-align:center;}
.row-hover:hover td{background:rgba(255,255,255,0.01);}

/* department header row */
.dept-row td {
    background: rgba(255,255,255,0.02);
    padding:14px 12px;
    font-weight:800;
    color: #cfe8ff;
    border-top: 1px solid rgba(255,255,255,0.03);
}
.dept-sub { color: var(--muted); font-weight:600; margin-left:10px; font-size:0.95rem; }

/* modal styles (like department popup) */
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.6);display:none;align-items:center;justify-content:center;z-index:400;}
.modal-backdrop.open{display:flex;backdrop-filter: blur(6px);background: rgba(2,6,23,0.5);}
.delete-modal { width: 480px; max-width: 95%; background: linear-gradient(180deg, #0b1520 0%, #0f172a 100%); border: 1px solid rgba(255,255,255,0.08); border-radius: 19px; box-shadow: 0 10px 30px rgba(0,0,0,0.7); color: var(--text); animation: fadeInScale 0.2s ease-out; }
.modal {
  width: 560px; max-width: 94%;
  background: linear-gradient(180deg, #071026 0%, #081626 100%);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px; box-shadow: 0 22px 64px rgba(2,6,23,0.85), inset 0 1px 0 rgba(255,255,255,0.02);
  color: var(--text); animation: fadeInScale 0.22s ease-out; overflow: hidden; display: flex; flex-direction: column;
}
.modal .modal-header { padding: 20px 26px; border-bottom: 1px solid rgba(255,255,255,0.03); display:flex; align-items:center; gap:12px; }
.modal .modal-header h3 { margin:0; font-size:1.15rem; color:#f1f9ff; letter-spacing:0.3px; }
.modal .modal-body { padding: 18px 26px; line-height:1.55; color: #dbeafe; font-size: 1rem; flex:1 1 auto; }
.form-row { margin-bottom:12px; display:flex; flex-direction:column; gap:6px; }
.modal label { color:#cbd5e1; font-weight:600; font-size:.95rem; }
.modal input[type="text"], .modal select {
  width:100%; padding:12px 14px; border-radius:10px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); color: var(--text); font-size:0.97rem; transition: box-shadow .14s ease, border-color .14s ease, transform .08s ease;
}
.modal input::placeholder { color: rgba(230,238,248,0.35); font-weight:600; }
.modal input:focus, .modal select:focus { outline: none; border-color: #38bdf8; box-shadow: 0 0 0 4px rgba(56,189,248,0.06); }
.modal select { -webkit-appearance: none; appearance: none; background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); border: 1px solid rgba(255,255,255,0.08); color: #e6eef8; padding-right: 40px; font-size: 1rem; font-weight: 600; border-radius: 10px; cursor: pointer; }
.modal select option[disabled] { color: #98a0aa; font-weight: 600; }
.modal select option { background: #071025; color: #e6eef8; padding: 10px 12px; font-weight: 600; }
.modal .select-wrap { position: relative; display:block; }
.modal .select-wrap::after{ content: "▾"; position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: #9fd5ff; pointer-events: none; font-size: 12px; opacity: 0.95; }

/* footer buttons */
.modal .modal-footer { display:flex; justify-content:flex-end; gap:12px; padding:16px 26px; border-top: 1px solid rgba(255,255,255,0.02); background: linear-gradient(180deg, rgba(0,0,0,0.02), transparent); }
.modal .btn-muted { padding:10px 18px; border-radius:10px; font-weight:700; }
.modal .btn-primary { padding:10px 18px; border-radius:10px; font-weight:700; background: linear-gradient(90deg, var(--accent1), var(--accent2)); color:#fff; border:0; cursor:pointer; }

/* delete modal specific */
.delete-header { border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 6px; margin-bottom: 12px; }
.delete-header h3 { margin: 0; color: #fca5a5; font-size: 1.25rem; padding: 22px 26px; }
.delete-body { padding: 22px 26px; }
.confirm-input { width: 100%; background: #0a1220; color: #f8fafc; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 10px; font-size: 0.95rem; }
.delete-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; padding:18px 26px; border-top: 1px solid rgba(255,255,255,0.05); }

/* small util */
@keyframes fadeInScale { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
@keyframes shake { 10%,90%{transform:translateX(-2px);}20%,80%{transform:translateX(4px);} }
.shake { animation: shake 0.4s ease; }

/* toast */
.toast{position:fixed;right:18px;bottom:18px;background:#0b1520;padding:12px 16px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.6);color:#e6eef8;z-index:600;display:none;}
.toast.show{display:block;}
.toast.success { background: #065f46; color: #ecfdf5; }
.toast.error { background: #7f1d1d; color: #fee2e2; }

/* responsive */
@media (max-width:560px) {
  .modal { width: 96%; }
  .modal .modal-header, .modal .modal-body, .modal .modal-footer { padding-left:16px; padding-right:16px; }
}
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

      <div class="admin-controls">
        <div class="left-controls">
<form id="searchForm" method="get" style="display:flex;gap:8px;align-items:center;">
  <input name="q" class="search-input" placeholder="Search code or name..." value="<?= e($q) ?>">
  <select name="per" onchange="this.form.submit()" class="search-input">
    <?php foreach([5,10,25,50] as $p): ?>
      <option value="<?= $p ?>" <?= $per == $p ? 'selected' : '' ?>><?= $p ?>/page</option>
    <?php endforeach; ?>
  </select>
  <div class="search-buttons">
    <button type="submit" class="btn-muted">Search</button>
    <a href="courses.php" class="btn-muted">Clear</a>
  </div>
</form>
        </div>
      </div>

      <!-- TOP ACTIONS -->
      <div class="top-actions" aria-label="Actions">
        <div class="left-buttons">
          <button id="openAddBtn" class="add-btn">＋ Add Course</button>
          <a class="btn" href="api/courses.php?export=1">Export All</a>
          <button id="bulkDeleteBtn" class="btn-danger">Delete Selected</button>
        </div>

        <div class="right-buttons">
          <?php if ($show_deleted): ?>
            <a class="btn toggle-btn active-toggle" href="courses.php">🟢 Show Active</a>
            <span class="toggle-label">Viewing deleted (<?= (int)$deletedCount ?>)</span>
          <?php else: ?>
            <a class="btn toggle-btn" href="courses.php?show_deleted=1">🔴 Show Deleted <?= $deletedCount ? "({$deletedCount})" : '' ?></a>
          <?php endif; ?>
        </div>
      </div>
      <!-- /TOP ACTIONS -->

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <h2 style="margin:0;color:#cfe8ff;">Course List</h2>
          <div style="color:var(--muted)"><?= $total ?> result<?= $total==1?'':'s' ?></div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="checkbox-col"><input id="chkAll" type="checkbox"></th>
                <th style="width:120px"><a href="<?= e(build_sort_link('code')) ?>">Code <?= e(sort_arrow('code')) ?></a></th>
                <th><a href="<?= e(build_sort_link('name')) ?>">Name <?= e(sort_arrow('name')) ?></a></th>
                <th style="min-width:200px">Department</th>
                <th style="width:80px;text-align:right"><a href="<?= e(build_sort_link('students')) ?>">Students <?= e(sort_arrow('students')) ?></a></th>
                <th style="width:160px">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px;">No courses found.</td></tr>
              <?php else: ?>
                <?php foreach ($groups as $gKey => $g): ?>
                  <tr class="dept-row">
                    <td colspan="6" class="dept-header" data-dept-code="<?= e($g['dept_code']) ?>" data-dept-name="<?= e($g['dept_name']) ?>">
                      <span style="font-size:1rem;"><?= e($gKey ?: 'Unassigned') ?></span>
                      <?php if (!empty($g['dept_name'])): ?><span class="dept-sub">— <?= e($g['dept_name']) ?></span><?php endif; ?>
                    </td>
                  </tr>

                  <?php foreach ($g['items'] as $r): $isDeleted = !empty($r['deleted_at']); ?>
                    <tr class="row-hover">
                      <td class="checkbox-col"><input class="row-chk" type="checkbox" value="<?= e($r['CourseID']) ?>" <?= $isDeleted ? 'disabled' : '' ?>></td>
                      <td><span class="code-badge"><?= e($r['Course_Code'] ?? '-') ?></span></td>
                      <td><?= e($r['Course_Name']) ?><?php if ($isDeleted): ?><div style="color:var(--muted);font-size:.95rem;margin-top:6px;">Deleted at <?= e($r['deleted_at']) ?></div><?php endif; ?></td>
                      <td class="dept-col" data-dept-id="<?= e($r['DepartmentID'] ?? '') ?>" data-dept-code="<?= e($r['Dept_Code'] ?? '') ?>" data-dept-name="<?= e($r['Dept_Name'] ?? '') ?>"><?= e($r['Dept_Name'] ?? '-') ?></td>
                      <td style="text-align:right"><?= e((int)$r['students']) ?></td>
                      <td>
                        <div class="actions-inline">
                          <?php if (!$isDeleted): ?>
                            <button class="link-update" data-id="<?= e($r['CourseID']) ?>">Update</button>
                          <?php else: ?>
                            <button class="btn-muted" data-undo-id="<?= e($r['CourseID']) ?>">Undo</button>
                          <?php endif; ?>
                          <button class="link-delete" data-id="<?= e($r['CourseID']) ?>">Delete</button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                <?php endforeach; ?>
              <?php endif; ?>
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
            if ($show_deleted) $baseParams['show_deleted'] = 1;
            $baseParams['sort'] = $sort;
            $baseParams['dir'] = strtolower($dir) === 'desc' ? 'desc' : 'asc';
            for ($p=1; $p <= max(1, $totalPages); $p++) {
                $baseParams['page'] = $p;
                $u = 'courses.php?' . http_build_query($baseParams);
                $cls = ($p == $page) ? 'style="font-weight:700;margin-right:6px;color:#fff"' : 'style="margin-right:6px;color:var(--muted)"';
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

<!-- Add/Edit Modal (styled like department) -->
<div id="modalBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-header"><h3 id="modalTitle">Add Course</h3></div>

    <form id="modalForm" class="modal-body" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" id="modalAction" value="add">
      <input type="hidden" name="id" id="modalId" value="0">

      <div class="form-row">
        <label for="modal_code">Course Code</label>
        <input id="modal_code" name="course_code" type="text" required placeholder="e.g. CID, MED, EED">
      </div>

      <div class="form-row">
        <label for="modal_name">Course Name</label>
        <input id="modal_name" name="course_name" type="text" required placeholder="e.g. COMPUTER & INFORMATION">
      </div>

      <div class="form-row">
        <label for="modal_dept">Department <span style="color:#f87171;font-weight:700;">(required)</span></label>
        <div class="select-wrap">
          <select id="modal_dept" name="department_id" required>
            <option value="" disabled selected>-- Select department --</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= e($d['DepartmentID']) ?>"><?= e($d['Dept_Code'] ? "{$d['Dept_Code']} — {$d['Dept_Name']}" : $d['Dept_Name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" id="modalCancel" class="btn-muted">Cancel</button>
        <button id="modalSubmit" class="btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- DELETE CONFIRMATION MODAL -->
<div id="deleteBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal delete-modal" role="dialog">
    <div class="delete-header"><h3>⚠️ Confirm Delete</h3></div>
    <div class="delete-body">
      <p>You are about to delete <strong id="deleteName"></strong>.</p>
      <p class="warning-text">This action will <b>soft-delete</b> the course. You can restore it later using the <em>Undo</em> option.</p>

      <label class="confirm-label" for="confirmDelete">Please type <code>DELETE</code> below to confirm:</label>
      <input id="confirmDelete" type="text" placeholder="Type DELETE here" class="confirm-input">
    </div>

    <div class="delete-footer">
      <button id="deleteCancel" class="btn-cancel">Cancel</button>
      <button id="deleteConfirm" class="btn-delete" disabled>Delete permanently</button>
    </div>
  </div>
</div>

<div id="toast" class="toast"></div>

<script>
const csrfToken = <?= json_encode($csrf) ?>;
const courseMap = <?= json_encode($map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

function postJSON(url, data){
    const fd = new FormData();
    for (const k in data) {
        if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k+'[]', v));
        else fd.append(k, data[k]);
    }
    fd.append('csrf_token', csrfToken);
    return fetch(url, { method:'POST', body: fd }).then(r => r.json());
}
function showToast(msg, timeout=2200, cls='') {
    const s = document.getElementById('toast'); s.textContent = msg; s.classList.add('show'); if (cls) s.classList.add(cls);
    setTimeout(()=> { s.classList.remove('show'); if (cls) s.classList.remove(cls); }, timeout);
}

// elements
const chkAll = document.getElementById('chkAll');
if (chkAll) chkAll.addEventListener('change', ()=> document.querySelectorAll('.row-chk').forEach(c=>{ if(!c.disabled) c.checked = chkAll.checked; }));

// modal elements
const modalBackdrop = document.getElementById('modalBackdrop');
const modalTitle = document.getElementById('modalTitle');
const modalForm = document.getElementById('modalForm');
const modalAction = document.getElementById('modalAction');
const modalId = document.getElementById('modalId');
const modalCode = document.getElementById('modal_code');
const modalName = document.getElementById('modal_name');
const modalDept = document.getElementById('modal_dept');
const modalCancel = document.getElementById('modalCancel');
const modalSubmit = document.getElementById('modalSubmit');

// delete modal elements
const deleteBackdrop = document.getElementById('deleteBackdrop');
const deleteName = document.getElementById('deleteName');
const confirmDelete = document.getElementById('confirmDelete');
const deleteConfirm = document.getElementById('deleteConfirm');
const deleteCancel = document.getElementById('deleteCancel');

const openAddBtn = document.getElementById('openAddBtn');

// open add
if (openAddBtn) openAddBtn.addEventListener('click', ()=> {
    modalAction.value = 'add';
    modalId.value = 0;
    modalCode.value = '';
    modalName.value = '';
    modalDept.value = '';
    modalTitle.textContent = 'Add Course';
    modalSubmit.textContent = 'Save';
    modalBackdrop.classList.add('open');
    modalCode.focus();
});

// edit buttons
document.querySelectorAll('.link-update').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.id; const d = courseMap[id] || {};
        modalAction.value = 'edit';
        modalId.value = id;
        modalCode.value = d.Course_Code || '';
        modalName.value = d.Course_Name || '';
        modalDept.value = d.DepartmentID || '';
        modalTitle.textContent = 'Update Course';
        modalSubmit.textContent = 'Update';
        modalBackdrop.classList.add('open');
        modalCode.focus();
    });
});

// cancel modal
modalCancel.addEventListener('click', ()=> modalBackdrop.classList.remove('open'));

// submit add/edit
modalForm.addEventListener('submit', function(e){
    e.preventDefault();
    const act = modalAction.value;
    const id = modalId.value;
    const code = modalCode.value.trim();
    const name = modalName.value.trim();
    const dept = modalDept.value;
    if (!dept) { showToast('Please select a department', 2000, 'error'); return; }
    if (!code || !name) { showToast('Please fill required fields', 2000, 'error'); return; }
    const btn = modalSubmit;
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = (act==='edit') ? 'Updating...' : 'Saving...';
    const payload = { action: act, course_code: code, course_name: name, department_id: dept };
    if (act === 'edit') payload.id = id;
    postJSON('api/courses.php', payload).then(resp=>{
        btn.disabled = false; btn.textContent = orig;
        if (resp && resp.ok) {
            modalBackdrop.classList.remove('open');
            showToast('Saved', 1200, 'success');
            setTimeout(()=> location.reload(), 600);
        } else {
            showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error');
        }
    }).catch(()=>{ btn.disabled = false; btn.textContent = orig; showToast('Network error',2200,'error'); });
});

// delete flow
document.querySelectorAll('.link-delete').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.id; const d = courseMap[id] || {};
        deleteName.textContent = d.Course_Name || ('#'+id);
        confirmDelete.value = '';
        deleteConfirm.disabled = true;
        deleteConfirm.dataset.id = id;
        deleteBackdrop.classList.add('open');
        confirmDelete.focus();
    });
});
confirmDelete.addEventListener('input', ()=> {
    deleteConfirm.disabled = (confirmDelete.value !== 'DELETE');
    if (confirmDelete.value.length >= 6 && confirmDelete.value !== 'DELETE') {
        confirmDelete.classList.add('shake');
        setTimeout(()=> confirmDelete.classList.remove('shake'), 380);
    }
});
deleteCancel.addEventListener('click', ()=> deleteBackdrop.classList.remove('open'));
deleteConfirm.addEventListener('click', ()=> {
    const id = deleteConfirm.dataset.id;
    const btn = deleteConfirm; btn.disabled = true; btn.textContent = 'Deleting...';
    postJSON('api/courses.php', { action: 'delete', id: id }).then(resp=>{
        if (resp && resp.ok) { deleteBackdrop.classList.remove('open'); showToast('Deleted', 1200, 'success'); setTimeout(()=>location.reload(),600); }
        else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error'); btn.disabled = false; btn.textContent = 'Delete permanently'; }
    }).catch(()=>{ showToast('Network error',2500,'error'); btn.disabled = false; btn.textContent = 'Delete permanently'; });
});

// undo
document.querySelectorAll('[data-undo-id]').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.undoId;
        postJSON('api/courses.php', { action: 'undo', id: id }).then(resp=>{
            if (resp && resp.ok) { showToast('Restored', 1200, 'success'); setTimeout(()=>location.reload(),600); }
            else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error');
        }).catch(()=>showToast('Network error',2500,'error'));
    });
});

// bulk delete
document.getElementById('bulkDeleteBtn').addEventListener('click', ()=> {
    const selected = Array.from(document.querySelectorAll('.row-chk')).filter(c=>c.checked).map(c=>c.value);
    if (!selected.length) return alert('Select rows first');
    if (!confirm('Delete selected courses?')) return;
    postJSON('api/courses.php', { action: 'bulk_delete', ids: selected }).then(resp=>{
        if (resp && resp.ok) { showToast('Deleted ' + (resp.count || selected.length), 1200, 'success'); setTimeout(()=>location.reload(),600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error');
    }).catch(()=>showToast('Network error',2500,'error'));
});

// close modals on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(b => b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); }));
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(b=>b.classList.remove('open')); });
</script>
</body>
</html>
