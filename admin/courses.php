<?php
// admin/courses.php
// Full fixed admin courses page (includes improved JS to avoid "Network error")

require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

// small safe echo
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// ensure session started (avoid notice)
if (session_status() === PHP_SESSION_NONE) session_start();

// ensure CSRF token
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

// helpers
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

// fetch departments for dropdown
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

// group rows by department
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

<!-- Bootstrap CSS (for modal) -->
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
  --accent-purple-1:#a84bff;
  --accent-purple-2:#7c3aed;
  --select-bg: rgba(255,255,255,0.02);
  --select-border: rgba(255,255,255,0.04);
  --select-contrast: #dff6ff;
}

/* page */
body { background: var(--bg); color: var(--text); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; }

/* center box */
.center-box{
  max-width: none !important;
  width: calc(100% - 48px);
  margin: auto;
  padding: 22px 24px;
  box-sizing: border-box;
}

/* top filters */
.admin-controls { margin-bottom: 8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.search-input{ padding:8px 12px; border-radius:10px; border:1px solid var(--select-border); background: var(--select-bg); color:var(--select-contrast); font-size:0.95rem; height:44px; }
.search-buttons { display:flex; gap:8px; }

/* pill buttons */
.btn-pill {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:10px;
  padding:10px 28px;
  height:44px;
  border-radius:12px;
  font-weight:700;
  font-size:1rem;
  color:#fff;
  text-decoration:none;
  border:1px solid rgba(255,255,255,0.06);
  cursor:pointer;
  box-shadow:
    0 10px 20px rgba(2,6,23,0.5),
    inset 0 -6px 18px rgba(255,255,255,0.03);
  transition: transform .08s ease, box-shadow .12s ease, opacity .12s ease;
}
.btn-pill.add-pill { background: linear-gradient(90deg,var(--accent-purple-1),var(--accent-purple-2)); }
.btn-pill.export-pill { background: linear-gradient(90deg,#3388ff,var(--accent-blue-2)); }
.btn-pill.delete-pill { background: linear-gradient(90deg,#bf3b3b,#8e2b2b); }
.btn-pill:hover { transform: translateY(-2px); box-shadow: 0 16px 28px rgba(2,6,23,0.6), inset 0 -6px 22px rgba(255,255,255,0.04); }

/* small muted */
.btn-muted{ padding:0 12px; min-width:80px; height:40px; border-radius:10px; background:#374151; color:white; border:1px solid rgba(255,255,255,0.03); display:inline-flex; align-items:center; justify-content:center; text-decoration:none; }
a.btn-muted { text-decoration:none; color:inherit; display:inline-flex; align-items:center; justify-content:center; }

/* toolbar */
.top-actions { display:flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap:nowrap; margin-bottom: 6px; }
.top-actions .left-buttons, .top-actions .right-buttons { display:flex; gap:14px; align-items:center; }

/* card */
.card{
  width:100% !important;
  max-width:none !important;
  background: var(--card);
  border-radius:12px;
  padding:18px;
  margin-top: 18px;
  box-shadow:0 8px 28px rgba(2,6,23,0.6);
  display:flex;
  flex-direction:column;
  gap:18px;
  overflow:visible;
  height:auto;
  min-height:420px;
}

/* table */
.table-wrap{ overflow:visible !important; border-radius:8px; margin-top:4px; padding-right:0; box-sizing:border-box; }
table { width:100%; border-collapse:collapse; table-layout: auto; min-width: 0; }
th, td { padding:12px; border-top:1px solid rgba(255,255,255,0.03); color:var(--text); vertical-align:middle; word-break:break-word; white-space:normal; }
th { color:var(--muted); text-align:left; font-weight:700; font-size:0.95rem; }

/* checkbox column */
.checkbox-col { width:46px; text-align:center; }

/* code badge */
.code-badge{ background:#071725; color:#7dd3fc; padding:6px 10px; border-radius:6px; font-weight:700; }

/* actions */
.actions-inline{ display:flex; gap:12px; align-items:center; justify-content:flex-end; }
.link-update{ color: #06b76a; background:none; border:0; padding:0; cursor:pointer; font-weight:700; }
.link-delete{ color: var(--accent-red-1); background:none; border:0; padding:0; cursor:pointer; font-weight:700; }

/* highlight */
.row-selected td { background: rgba(37,99,235,0.06); }
.dept-row td { background: rgba(255,255,255,0.01); padding:14px 12px; font-weight:800; color: #cfe8ff; border-top: 1px solid rgba(255,255,255,0.03); }
.dept-sub { color: var(--muted); font-weight:600; margin-left:10px; font-size:0.95rem; }
.row-hover:hover td { background: rgba(255,255,255,0.01); }

/* modal styling minimal - rely on bootstrap centering */
.modal-content { background: linear-gradient(180deg, #071026 0%, #081626 100%); border:1px solid rgba(255,255,255,0.06); color:var(--text); }
.form-control, .form-select { background: rgba(255,255,255,0.02); color: var(--text); border:1px solid rgba(255,255,255,0.04); }

/* toast */
.toast{position:fixed;right:18px;bottom:18px;background:#0b1520;padding:12px 16px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.6);color:#e6eef8;z-index:600;display:none;}
.toast.show{display:block;}
.toast.success { background: #065f46; color: #ecfdf5; }
.toast.error { background: #7f1d1d; color: #fee2e2; }

@media (max-width:740px) {
  .center-box { width: calc(100% - 28px); padding:16px; }
  .search-input { min-width:120px; }
  .checkbox-col { width:36px; }
  .btn-pill { padding:10px 18px; min-width:110px; }
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
        <form id="searchForm" method="get" style="display:flex;gap:8px;align-items:center;">
          <input name="q" class="search-input" placeholder="Search code or name..." value="<?= e($q) ?>">
          <select name="per" onchange="this.form.submit()" class="search-input">
            <?php foreach([5,10,25,50] as $p): ?>
              <option value="<?= $p ?>" <?= $per == $p ? 'selected' : '' ?>><?= $p ?>/page</option>
            <?php endforeach; ?>
          </select>
          <div class="search-buttons">
            <button type="submit" class="btn-muted">Search</button>
            <a href="courses.php" class="btn-muted" role="button">Clear</a>
          </div>
        </form>

        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
          <!-- extra controls if needed -->
        </div>
      </div>

      <!-- TOP ACTIONS -->
      <div class="top-actions" aria-label="Actions">
        <div class="left-buttons">
          <button id="openAddBtn" class="btn-pill add-pill">＋ Add Class</button>
          <a class="btn-pill export-pill" href="api/courses.php?export=1" role="button">Export All</a>
          <button id="bulkDeleteBtn" class="btn-pill delete-pill">Delete Selected</button>
        </div>

        <div class="right-buttons">
          <?php if ($show_deleted): ?>
            <a class="btn toggle-btn active-toggle" href="courses.php">🟢 Show Active</a>
            <span class="toggle-label" style="color:var(--muted);margin-left:8px">Viewing deleted (<?= (int)$deletedCount ?>)</span>
          <?php else: ?>
            <a class="btn toggle-btn" href="courses.php?show_deleted=1">🔴 Show Deleted <?= $deletedCount ? "({$deletedCount})" : '' ?></a>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <h2 style="margin:0;color:#cfe8ff;">Course List</h2>
          <div style="color:var(--muted)"><?= $total ?> result<?= $total==1?'':'s' ?></div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="checkbox-col"><input id="chkAll" type="checkbox" aria-label="Select all"></th>
                <th style="width:120px"><a href="<?= e(build_sort_link('code')) ?>" style="color:inherit;text-decoration:none;">Code <?= e(sort_arrow('code')) ?></a></th>
                <th><a href="<?= e(build_sort_link('name')) ?>" style="color:inherit;text-decoration:none;">Name <?= e(sort_arrow('name')) ?></a></th>
                <th style="min-width:200px">Department</th>
                <th style="width:80px;text-align:right"><a href="<?= e(build_sort_link('students')) ?>" style="color:inherit;text-decoration:none;">Students <?= e(sort_arrow('students')) ?></a></th>
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
                    <tr class="row-hover" data-id="<?= e($r['CourseID']) ?>">
                      <td class="checkbox-col"><input class="row-chk" type="checkbox" value="<?= e($r['CourseID']) ?>" <?= $isDeleted ? 'disabled' : '' ?> aria-label="Select row"></td>
                      <td><span class="code-badge"><?= e($r['Course_Code'] ?? '-') ?></span></td>
                      <td>
                        <?= e($r['Course_Name']) ?>
                        <?php if ($isDeleted): ?><div style="color:var(--muted);font-size:.95rem;margin-top:6px;">Deleted at <?= e($r['deleted_at']) ?></div><?php endif; ?>
                      </td>
                      <td class="dept-col" data-dept-id="<?= e($r['DepartmentID'] ?? '') ?>" data-dept-code="<?= e($r['Dept_Code'] ?? '') ?>" data-dept-name="<?= e($r['Dept_Name'] ?? '') ?>"><?= e($r['Dept_Name'] ?? '-') ?></td>
                      <td style="text-align:right"><?= e((int)$r['students']) ?></td>
                      <td>
                        <div class="actions-inline">
                          <?php if (!$isDeleted): ?>
                            <button type="button" class="link-update" data-id="<?= e($r['CourseID']) ?>">Update</button>
                          <?php else: ?>
                            <button type="button" class="btn-muted" data-undo-id="<?= e($r['CourseID']) ?>">Undo</button>
                          <?php endif; ?>
                          <button type="button" class="link-delete" data-id="<?= e($r['CourseID']) ?>">Delete</button>
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

<!-- Add/Edit Modal (Bootstrap centered) -->
<div class="modal fade" id="courseModal" tabindex="-1" aria-labelledby="courseModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <form id="modalForm" class="modal-body p-4" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="courseModalLabel">Add Course</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="modalId" value="0">

        <div class="mb-3">
          <label for="modal_code" class="form-label">Course Code</label>
          <input id="modal_code" name="course_code" type="text" required autocomplete="off" placeholder="e.g. CID, MED, EED" class="form-control">
        </div>

        <div class="mb-3">
          <label for="modal_name" class="form-label">Course Name</label>
          <input id="modal_name" name="course_name" type="text" required autocomplete="off" placeholder="e.g. COMPUTER & INFORMATION" class="form-control">
        </div>

        <div class="mb-3">
          <label for="modal_dept" class="form-label">Department <span style="color:#f87171;font-weight:700;">(required)</span></label>
          <select id="modal_dept" name="department_id" required class="form-select">
            <option value="" disabled selected>-- Select department --</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= e($d['DepartmentID']) ?>"><?= e($d['Dept_Code'] ? "{$d['Dept_Code']} — {$d['Dept_Name']}" : $d['Dept_Name']) ?></option>
            <?php endforeach; ?>
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
          This will soft-delete the course (if enabled).
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
// expose csrf token for JS
const csrfToken = <?= json_encode($csrf) ?>;
const courseMap = <?= json_encode($map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

/* Improved showToast to avoid overlapping timers */
function showToast(msg, t=2200, cls='') {
  const s = document.getElementById('toast');
  if (!s) return console.log('Toast:', msg);
  s.textContent = msg;
  s.classList.remove('success','error','show');
  if (cls) s.classList.add(cls);
  s.classList.add('show');
  if (s._hideTimer) clearTimeout(s._hideTimer);
  s._hideTimer = setTimeout(()=>{ s.classList.remove('show'); if (cls) s.classList.remove(cls); s._hideTimer = null; }, t);
}

/* Robust postJSON with timeout and better errors (returns parsed payload or {ok:false,error:...}) */
async function postJSON(url, data, opts = {}) {
  const timeout = opts.timeout || 10000; // 10s
  const controller = new AbortController();
  const id = setTimeout(()=> controller.abort(), timeout);

  try {
    const fd = new FormData();
    for (const k in data) {
      if (!Object.prototype.hasOwnProperty.call(data, k)) continue;
      if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k+'[]', v));
      else fd.append(k, data[k]);
    }
    if (!fd.has('csrf_token') && typeof csrfToken !== 'undefined') fd.append('csrf_token', csrfToken);

    const res = await fetch(url, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      signal: controller.signal,
      headers: {
        'Accept': 'application/json'
      }
    });
    clearTimeout(id);

    const text = await res.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch (e) {
      const msg = `Server returned non-JSON (${res.status} ${res.statusText}). Response: ${text ? text.substring(0,500) : '[empty]'}`;
      return { ok: false, error: msg, status: res.status, raw: text };
    }

    if (!res.ok) {
      const errMsg = payload && payload.error ? payload.error : `HTTP ${res.status} ${res.statusText}`;
      return { ok: false, error: errMsg, status: res.status, payload };
    }
    return payload ?? { ok: true };
  } catch (err) {
    clearTimeout(id);
    if (err.name === 'AbortError') {
      return { ok: false, error: 'Request timed out' };
    }
    return { ok: false, error: 'Network error: ' + (err.message || String(err)) };
  }
}

/* Checkbox / selection UI */
const chkAll = document.getElementById('chkAll');
if (chkAll) chkAll.addEventListener('change', ()=> {
  const checked = chkAll.checked;
  document.querySelectorAll('.row-chk').forEach(c=>{
    if (!c.disabled) {
      c.checked = checked;
      toggleRowSelected(c);
    }
  });
});
function toggleRowSelected(checkbox) {
  const tr = checkbox.closest('tr');
  if (!tr) return;
  if (checkbox.checked) tr.classList.add('row-selected');
  else tr.classList.remove('row-selected');
}
document.querySelectorAll('.row-chk').forEach(cb=>{
  cb.addEventListener('change', ()=> {
    toggleRowSelected(cb);
    const all = Array.from(document.querySelectorAll('.row-chk')).filter(x => !x.disabled);
    if (all.length) {
      const allChecked = all.every(x => x.checked);
      const anyChecked = all.some(x => x.checked);
      chkAll.indeterminate = !allChecked && anyChecked;
      chkAll.checked = allChecked;
    } else {
      chkAll.checked = false;
      chkAll.indeterminate = false;
    }
  });
});

/* Bootstrap modal instances */
const courseModalEl = document.getElementById('courseModal');
const deleteModalEl = document.getElementById('deleteModal');
const courseModal = courseModalEl ? new bootstrap.Modal(courseModalEl, { keyboard: false }) : null;
const deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl, { keyboard: false }) : null;

/* modal elements */
const modalForm = document.getElementById('modalForm');
const modalAction = document.getElementById('modalAction');
const modalId = document.getElementById('modalId');
const modalCode = document.getElementById('modal_code');
const modalName = document.getElementById('modal_name');
const modalDept = document.getElementById('modal_dept');
const modalSubmit = document.getElementById('modalSubmit');
const openAddBtn = document.getElementById('openAddBtn');

/* open add */
if (openAddBtn) openAddBtn.addEventListener('click', ()=> {
    modalAction.value = 'add';
    modalId.value = 0;
    if (modalCode) modalCode.value = '';
    if (modalName) modalName.value = '';
    if (modalDept) modalDept.value = '';
    document.getElementById('courseModalLabel').textContent = 'Add Course';
    modalSubmit.textContent = 'Save';
    if (courseModal) courseModal.show();
    setTimeout(()=> modalCode && modalCode.focus(), 120);
});

/* edit & delete delegation */
document.addEventListener('click', async function(e) {
    const up = e.target.closest && e.target.closest('.link-update');
    if (up) {
        const id = up.dataset.id; const d = courseMap[id] || {};
        modalAction.value = 'edit';
        modalId.value = id;
        if (modalCode) modalCode.value = d.Course_Code || '';
        if (modalName) modalName.value = d.Course_Name || '';
        if (modalDept) modalDept.value = d.DepartmentID || '';
        document.getElementById('courseModalLabel').textContent = 'Update Course';
        modalSubmit.textContent = 'Update';
        if (courseModal) courseModal.show();
        setTimeout(()=> modalCode && modalCode.focus(), 120);
        e.preventDefault();
        return;
    }
    const del = e.target.closest && e.target.closest('.link-delete');
    if (del) {
        const id = del.dataset.id; const d = courseMap[id] || {};
        document.getElementById('deleteName').textContent = d.Course_Name || ('#'+id);
        document.getElementById('confirmDelete').value = '';
        const delBtn = document.getElementById('deleteConfirm');
        delBtn.disabled = true;
        delBtn.dataset.id = id;
        if (deleteModal) deleteModal.show();
        setTimeout(()=> document.getElementById('confirmDelete').focus(), 120);
        e.preventDefault();
        return;
    }
});

/* submit add/edit */
if (modalForm) modalForm.addEventListener('submit', async function(e){
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
    const resp = await postJSON('api/courses.php', payload);
    btn.disabled = false; btn.textContent = orig;
    if (resp && resp.ok) {
        if (courseModal) courseModal.hide();
        showToast('Saved', 1200, 'success');
        setTimeout(()=> location.reload(), 600);
    } else {
        showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 4500, 'error');
    }
});

/* delete confirm handling */
const confirmDelete = document.getElementById('confirmDelete');
const deleteConfirm = document.getElementById('deleteConfirm');
if (confirmDelete) {
  confirmDelete.addEventListener('input', ()=> {
      deleteConfirm.disabled = (confirmDelete.value !== 'DELETE');
      if (confirmDelete.value.length >= 6 && confirmDelete.value !== 'DELETE') {
          confirmDelete.classList.add('shake');
          setTimeout(()=> confirmDelete.classList.remove('shake'), 380);
      }
  });
}
if (deleteConfirm) deleteConfirm.addEventListener('click', async function(){
    const id = this.dataset.id;
    const btn = this; btn.disabled = true; const orig = btn.textContent || 'Deleting...'; btn.textContent = 'Deleting...';
    const resp = await postJSON('api/courses.php', { action: 'delete', id: id });
    if (resp && resp.ok) { if (deleteModal) deleteModal.hide(); showToast('Deleted', 1200, 'success'); setTimeout(()=>location.reload(),600); }
    else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 4500, 'error'); btn.disabled=false; btn.textContent=orig; }
});

/* undo */
document.addEventListener('click', async function(e){
    const u = e.target.closest && e.target.closest('[data-undo-id]');
    if (u) {
        const id = u.dataset.undoId;
        const resp = await postJSON('api/courses.php', { action: 'undo', id: id });
        if (resp && resp.ok) { showToast('Restored', 1200, 'success'); setTimeout(()=>location.reload(),600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 4500, 'error');
    }
});

/* bulk delete */
const bulkBtn = document.getElementById('bulkDeleteBtn');
if (bulkBtn) bulkBtn.addEventListener('click', async ()=> {
    const selected = Array.from(document.querySelectorAll('.row-chk')).filter(c=>c.checked).map(c=>c.value);
    if (!selected.length) return alert('Select rows first');
    if (!confirm('Delete selected courses?')) return;
    const resp = await postJSON('api/courses.php', { action: 'bulk_delete', ids: selected });
    if (resp && resp.ok) { showToast('Deleted ' + (resp.count || selected.length), 1200, 'success'); setTimeout(()=>location.reload(),600); }
    else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 4500, 'error');
});
</script>
</body>
</html>
