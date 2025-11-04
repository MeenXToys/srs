<?php
// admin/departments.php
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

// helpers (sorting links/arrows)
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
try { $has_deleted_at = (bool)$pdo->query("SHOW COLUMNS FROM department LIKE 'deleted_at'")->fetch(); } catch(Exception $e) { $has_deleted_at = false; }

// where clause
$where = [];
$params = [];
if ($q !== '') { $where[] = "(d.Dept_Name LIKE :q OR d.Dept_Code LIKE :q)"; $params[':q'] = "%$q%"; }
if ($has_deleted_at && !$show_deleted) $where[] = "d.deleted_at IS NULL";
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// count
try {
    $countSql = "
      SELECT COUNT(DISTINCT d.DepartmentID) FROM department d
      LEFT JOIN course c ON c.DepartmentID = d.DepartmentID
      LEFT JOIN class cl ON cl.CourseID = c.CourseID
      LEFT JOIN student s ON s.ClassID = cl.ClassID
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

// data query
try {
    $orderMap = ['name'=>'d.Dept_Name','code'=>'d.Dept_Code','students'=>'students'];
    $orderSql = ($orderMap[$sort] ?? $orderMap['name']) . ' ' . $dir;

    $sql = "
      SELECT d.DepartmentID, d.Dept_Code, d.Dept_Name " . ($has_deleted_at ? ", d.deleted_at" : ", NULL AS deleted_at") . ",
             COUNT(s.UserID) AS students
      FROM department d
      LEFT JOIN course c ON c.DepartmentID = d.DepartmentID
      LEFT JOIN class cl ON cl.CourseID = c.CourseID
      LEFT JOIN student s ON s.ClassID = cl.ClassID
      $whereSql
      GROUP BY d.DepartmentID, d.Dept_Code, d.Dept_Name " . ($has_deleted_at ? ", d.deleted_at" : "") . "
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

// JS map
$map = [];
foreach ($rows as $r) {
    $map[(int)$r['DepartmentID']] = [
        'Dept_Code'=>$r['Dept_Code'],
        'Dept_Name'=>$r['Dept_Name'],
        'students'=>(int)$r['students'],
        'deleted_at'=>$r['deleted_at'] ?? null
    ];
}

// deleted count
$deletedCount = 0;
if ($has_deleted_at) {
    try { $deletedCount = (int)$pdo->query("SELECT COUNT(*) FROM department WHERE deleted_at IS NOT NULL")->fetchColumn(); } catch(Exception $e) { $deletedCount = 0; }
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Departments — Admin</title>
<link rel="stylesheet" href="../style.css">
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- Bootstrap CSS -->
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
body { background: var(--bg); color: var(--text); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial; }

/* center box (same as courses) */
.center-box{ max-width:none !important; width:calc(100% - 48px); margin:auto; padding:22px 24px; box-sizing:border-box; }

/* top filters */
.admin-controls { margin-bottom: 8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.search-input{ padding:8px 12px; border-radius:10px; border:1px solid var(--select-border); background: var(--select-bg); color:var(--select-contrast); font-size:0.95rem; height:44px; }
.search-buttons { display:flex; gap:8px; }

/* pill buttons */
.btn-pill { display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:10px 28px; height:44px; border-radius:12px; font-weight:700; font-size:1rem; color:#fff; text-decoration:none; border:1px solid rgba(255,255,255,0.06); cursor:pointer; box-shadow: 0 10px 20px rgba(2,6,23,0.5), inset 0 -6px 18px rgba(255,255,255,0.03); transition: transform .08s ease, box-shadow .12s ease, opacity .12s ease; -webkit-tap-highlight-color: transparent; }
.btn-pill.add-pill { background: linear-gradient(90deg,var(--accent-purple-1) 0%, var(--accent-purple-2) 100%); }
.btn-pill.export-pill { background: linear-gradient(90deg,#3388ff 0%, var(--accent-blue-2) 100%); }
.btn-pill.delete-pill { background: linear-gradient(90deg,#bf3b3b 0%, #8e2b2b 100%); }
.btn-pill:hover { transform: translateY(-2px); box-shadow: 0 16px 28px rgba(2,6,23,0.6), inset 0 -6px 22px rgba(255,255,255,0.04); }
.btn-muted{ padding:0 12px; min-width:80px; height:40px; border-radius:10px; background:#374151; color:white; border:1px solid rgba(255,255,255,0.03); display:inline-flex; align-items:center; justify-content:center; text-decoration:none; }
a.btn-muted { text-decoration:none; color:inherit; display:inline-flex; align-items:center; justify-content:center; }

/* toolbar */
.top-actions { display:flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap:nowrap; margin-bottom: 6px; }
.top-actions .left-buttons, .top-actions .right-buttons { display:flex; gap:14px; align-items:center; }

/* card */
.card{ width:100% !important; max-width:none !important; background: var(--card); border-radius:12px; padding:18px; margin-top: 18px; box-shadow:0 8px 28px rgba(2,6,23,0.6); display:flex; flex-direction:column; gap:18px; overflow:visible; min-height:320px; }

/* table */
.table-wrap{ overflow:visible !important; border-radius:8px; margin-top:4px; padding-right:0; box-sizing:border-box; }
table { width:100%; border-collapse:collapse; table-layout: auto; min-width: 0; }
th, td { padding:12px; border-top:1px solid rgba(255,255,255,0.03); color:var(--text); vertical-align:middle; word-break:break-word; white-space:normal; }
th { color:var(--muted); text-align:left; font-weight:700; font-size:0.95rem; }
.checkbox-col { width:46px; text-align:center; }
.code-badge{ background:#071725; color:#7dd3fc; padding:6px 10px; border-radius:6px; font-weight:700; }
.actions-inline{ display:flex; gap:12px; align-items:center; justify-content:flex-end; }
.link-update{ color: #06b76a; background:none; border:0; padding:0; cursor:pointer; font-weight:700; }
.link-delete{ color: var(--accent-red-1); background:none; border:0; padding:0; cursor:pointer; font-weight:700; }
.row-hover:hover td { background: rgba(255,255,255,0.01); }
.row-selected td { background: rgba(37,99,235,0.06); }

/* modal (Bootstrap will center) */
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
            <a href="departments.php" class="btn-muted" role="button">Clear</a>
          </div>
        </form>

        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        </div>
      </div>

      <!-- TOP ACTIONS -->
      <div class="top-actions" aria-label="Actions">
        <div class="left-buttons">
          <button id="openAddBtn" class="btn-pill add-pill">＋ Add Department</button>
          <a class="btn-pill export-pill" href="api/departments.php?export=1" role="button">Export All</a>
          <button id="bulkDeleteBtn" class="btn-pill delete-pill">Delete Selected</button>
        </div>

        <div class="right-buttons">
          <?php if ($show_deleted): ?>
            <a class="btn toggle-btn active-toggle" href="departments.php">🟢 Show Active</a>
            <span class="toggle-label" style="color:var(--muted);margin-left:8px">Viewing deleted (<?= (int)$deletedCount ?>)</span>
          <?php else: ?>
            <a class="btn toggle-btn" href="departments.php?show_deleted=1">🔴 Show Deleted <?= $deletedCount ? "({$deletedCount})" : '' ?></a>
          <?php endif; ?>
        </div>
      </div>
      <!-- /TOP ACTIONS -->

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <h2 style="margin:0;color:#cfe8ff;">Department List</h2>
          <div style="color:var(--muted)"><?= $total ?> result<?= $total==1?'':'s' ?></div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="checkbox-col"><input id="chkAll" type="checkbox" aria-label="Select all"></th>
                <th style="width:120px"><a href="<?= e(build_sort_link('code')) ?>" style="color:inherit;text-decoration:none;">Code <?= e(sort_arrow('code')) ?></a></th>
                <th><a href="<?= e(build_sort_link('name')) ?>" style="color:inherit;text-decoration:none;">Name <?= e(sort_arrow('name')) ?></a></th>
                <th style="width:80px;text-align:right"><a href="<?= e(build_sort_link('students')) ?>" style="color:inherit;text-decoration:none;">Students <?= e(sort_arrow('students')) ?></a></th>
                <th style="width:160px">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:28px;">No departments found.</td></tr>
              <?php else: foreach ($rows as $r): $isDeleted = !empty($r['deleted_at']); ?>
                <tr class="row-hover" data-id="<?= e($r['DepartmentID']) ?>">
                  <td class="checkbox-col"><input class="row-chk" type="checkbox" value="<?= e($r['DepartmentID']) ?>" <?= $isDeleted ? 'disabled' : '' ?> aria-label="Select row"></td>
                  <td><span class="code-badge"><?= e($r['Dept_Code'] ?? '-') ?></span></td>
                  <td>
                    <?= e($r['Dept_Name']) ?>
                    <?php if ($isDeleted): ?><div style="color:var(--muted);font-size:.95rem;margin-top:6px;">Deleted at <?= e($r['deleted_at']) ?></div><?php endif; ?>
                  </td>
                  <td style="text-align:right"><?= e((int)$r['students']) ?></td>
                  <td>
                    <div class="actions-inline">
                      <?php if (!$isDeleted): ?>
                        <button type="button" class="link-update" data-id="<?= e($r['DepartmentID']) ?>">Update</button>
                      <?php else: ?>
                        <button type="button" class="btn-muted" data-undo-id="<?= e($r['DepartmentID']) ?>">Undo</button>
                      <?php endif; ?>
                      <button type="button" class="link-delete" data-id="<?= e($r['DepartmentID']) ?>">Delete</button>
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
            if ($show_deleted) $baseParams['show_deleted'] = 1;
            $baseParams['sort'] = $sort;
            $baseParams['dir'] = strtolower($dir) === 'desc' ? 'desc' : 'asc';
            for ($p=1; $p <= max(1, $totalPages); $p++) {
                $baseParams['page'] = $p;
                $u = 'departments.php?' . http_build_query($baseParams);
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

<!-- Add/Edit Modal (Bootstrap centered, matches Courses) -->
<div class="modal fade" id="deptModal" tabindex="-1" aria-labelledby="deptModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <form id="modalForm" class="modal-body p-4" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="deptModalLabel">Add Department</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="modalId" value="0">

        <div class="mb-3">
          <label for="modal_code" class="form-label">Code</label>
          <input id="modal_code" name="dept_code" type="text" required autocomplete="off" placeholder="e.g. CID" class="form-control">
        </div>

        <div class="mb-3">
          <label for="modal_name" class="form-label">Name</label>
          <input id="modal_name" name="dept_name" type="text" required autocomplete="off" placeholder="e.g. Computer & Information" class="form-control">
        </div>

        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button id="modalSubmit" class="btn" type="submit" style="background:linear-gradient(90deg,var(--accent-blue-1),var(--accent-blue-2));color:#fff;">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Modal (Bootstrap) -->
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
          This will soft-delete the department (if enabled).
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

<!-- Bootstrap JS bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const csrfToken = <?= json_encode($csrf) ?>;
const deptMap = <?= json_encode($map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

function postJSON(url, data){
    const fd = new FormData();
    for (const k in data) {
        if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k+'[]', v));
        else fd.append(k, data[k]);
    }
    fd.append('csrf_token', csrfToken);
    return fetch(url, { method:'POST', body: fd, credentials:'same-origin' }).then(r => r.json());
}
function showToast(msg, t=2200, cls='') {
    const s = document.getElementById('toast'); s.textContent = msg; s.classList.add('show'); if (cls) s.classList.add(cls);
    setTimeout(()=> { s.classList.remove('show'); if (cls) s.classList.remove(cls); }, t);
}

/* check-all and row highlight */
const chkAll = document.getElementById('chkAll');
if (chkAll) chkAll.addEventListener('change', ()=> {
  const checked = chkAll.checked;
  document.querySelectorAll('.row-chk').forEach(c=>{
    if (!c.disabled) c.checked = checked;
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
    // master checkbox indeterminate
    const all = Array.from(document.querySelectorAll('.row-chk')).filter(x => !x.disabled);
    if (all.length) {
      const allChecked = all.every(x => x.checked);
      const anyChecked = all.some(x => x.checked);
      chkAll.indeterminate = !allChecked && anyChecked;
      chkAll.checked = allChecked;
    } else { chkAll.checked = false; chkAll.indeterminate = false; }
  });
});

/* Bootstrap modal instances */
const deptModalEl = document.getElementById('deptModal');
const deleteModalEl = document.getElementById('deleteModal');
const deptModal = deptModalEl ? new bootstrap.Modal(deptModalEl, { keyboard: false }) : null;
const deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl, { keyboard: false }) : null;

/* modal controls */
const modalForm = document.getElementById('modalForm');
const modalAction = document.getElementById('modalAction');
const modalId = document.getElementById('modalId');
const modalCode = document.getElementById('modal_code');
const modalName = document.getElementById('modal_name');
const modalSubmit = document.getElementById('modalSubmit');
const openAddBtn = document.getElementById('openAddBtn');

if (openAddBtn) openAddBtn.addEventListener('click', ()=> {
    modalAction.value = 'add';
    modalId.value = 0;
    modalCode.value = '';
    modalName.value = '';
    document.getElementById('deptModalLabel').textContent = 'Add Department';
    modalSubmit.textContent = 'Save';
    if (deptModal) deptModal.show();
    setTimeout(()=> modalCode.focus(), 120);
});

/* delegation: edit/delete */
document.addEventListener('click', function(e) {
    const up = e.target.closest && e.target.closest('.link-update');
    if (up) {
        const id = up.dataset.id; const d = deptMap[id] || {};
        modalAction.value = 'edit';
        modalId.value = id;
        modalCode.value = d.Dept_Code || '';
        modalName.value = d.Dept_Name || '';
        document.getElementById('deptModalLabel').textContent = 'Update Department';
        modalSubmit.textContent = 'Update';
        if (deptModal) deptModal.show();
        setTimeout(()=> modalCode.focus(), 120);
        e.preventDefault();
        return;
    }
    const del = e.target.closest && e.target.closest('.link-delete');
    if (del) {
        const id = del.dataset.id; const d = deptMap[id] || {};
        document.getElementById('deleteName').textContent = d.Dept_Name || ('#'+id);
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
if (modalForm) modalForm.addEventListener('submit', function(e){
    e.preventDefault();
    const act = modalAction.value;
    const id = modalId.value;
    const code = modalCode.value.trim();
    const name = modalName.value.trim();
    if (!code || !name) { showToast('Please fill required fields', 2000, 'error'); return; }
    const btn = modalSubmit;
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = (act==='edit') ? 'Updating...' : 'Saving...';
    const payload = { action: act, dept_code: code, dept_name: name };
    if (act === 'edit') payload.id = id;
    postJSON('api/departments.php', payload).then(resp=>{
        btn.disabled = false; btn.textContent = orig;
        if (resp && resp.ok) {
            if (deptModal) deptModal.hide();
            showToast('Saved', 1200, 'success');
            setTimeout(()=> location.reload(), 600);
        } else {
            showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error');
        }
    }).catch(()=>{ btn.disabled = false; btn.textContent = orig; showToast('Network error',2200,'error'); });
});

/* delete confirm input */
document.getElementById('confirmDelete').addEventListener('input', function(){
    document.getElementById('deleteConfirm').disabled = (this.value !== 'DELETE');
    if (this.value.length >= 6 && this.value !== 'DELETE') { this.classList.add('shake'); setTimeout(()=> this.classList.remove('shake'), 380); }
});
document.getElementById('deleteConfirm').addEventListener('click', function(){
    const id = this.dataset.id; const btn = this; btn.disabled = true; const orig = btn.textContent || 'Deleting...'; btn.textContent = 'Deleting...';
    postJSON('api/departments.php', { action: 'delete', id: id }).then(resp=>{
        if (resp && resp.ok) { if (deleteModal) deleteModal.hide(); showToast('Deleted', 1200, 'success'); setTimeout(()=>location.reload(),600); }
        else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error'); btn.disabled=false; btn.textContent=orig; }
    }).catch(()=>{ showToast('Network error',2500,'error'); btn.disabled=false; btn.textContent=orig; });
});

/* undo */
document.addEventListener('click', function(e){
    const u = e.target.closest && e.target.closest('[data-undo-id]');
    if (u) {
        const id = u.dataset.undoId;
        postJSON('api/departments.php', { action: 'undo', id: id }).then(resp=>{
            if (resp && resp.ok) { showToast('Restored', 1200, 'success'); setTimeout(()=>location.reload(),600); }
            else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error');
        }).catch(()=>showToast('Network error',2500,'error'));
    }
});

/* bulk delete */
const bulkBtn = document.getElementById('bulkDeleteBtn');
if (bulkBtn) bulkBtn.addEventListener('click', ()=> {
    const selected = Array.from(document.querySelectorAll('.row-chk')).filter(c=>c.checked).map(c=>c.value);
    if (!selected.length) return alert('Select rows first');
    if (!confirm('Delete selected departments?')) return;
    postJSON('api/departments.php', { action: 'bulk_delete', ids: selected }).then(resp=>{
        if (resp && resp.ok) { showToast('Deleted ' + (resp.count || selected.length), 1200, 'success'); setTimeout(()=>location.reload(),600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error');
    }).catch(()=>showToast('Network error',2500,'error'));
});
</script>
</body>
</html>
