<?php
// admin/departments.php - safe, full page (copy-paste)
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

// --- PARAMETERS
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = (int)($_GET['per'] ?? 10); if ($per <= 0) $per = 10;
$sort = $_GET['sort'] ?? 'name';
$dir = (strtolower($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
$show_deleted = ($_GET['show_deleted'] ?? '') === '1';

// Normalize sort accepted values
$allowedSort = ['name','code','students'];
if (!in_array($sort, $allowedSort)) $sort = 'name';

// --- HELPERS that were missing (provide here)
if (!function_exists('build_sort_link')) {
    function build_sort_link($col) {
        $params = $_GET;
        $currentSort = $params['sort'] ?? 'name';
        $currentDir  = strtolower($params['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        if ($currentSort === $col) {
            $params['dir'] = ($currentDir === 'asc') ? 'desc' : 'asc';
        } else {
            $params['dir'] = 'asc';
        }
        $params['sort'] = $col;
        $params['page'] = 1;
        return 'departments.php?' . http_build_query($params);
    }
}
if (!function_exists('sort_arrow')) {
    function sort_arrow($col) {
        $currentSort = $_GET['sort'] ?? 'name';
        $currentDir  = strtolower($_GET['dir'] ?? 'asc');
        if ($currentSort !== $col) return '';
        return ($currentDir === 'desc') ? '↓' : '↑';
    }
}

// --- SAFELY detect whether deleted_at column exists (migration may not have been run)
$has_deleted_at = false;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM department LIKE 'deleted_at'")->fetch();
    $has_deleted_at = !empty($colCheck);
} catch (Exception $e) {
    $has_deleted_at = false;
}

// --- Build WHERE & params
$whereClauses = [];
$params = [];

if ($q !== '') {
    $whereClauses[] = "(d.Dept_Name LIKE :q OR d.Dept_Code LIKE :q)";
    $params[':q'] = "%$q%";
}
if ($has_deleted_at && !$show_deleted) {
    $whereClauses[] = "d.deleted_at IS NULL";
}
$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// --- Sorting map
$sortMap = ['name' => 'd.Dept_Name', 'code' => 'd.Dept_Code', 'students' => 'students'];
$orderSql = ($sortMap[$sort] ?? $sortMap['name']) . ' ' . $dir;

// --- Count total
try {
    $countSql = "
      SELECT COUNT(DISTINCT d.DepartmentID) AS cnt
      FROM department d
      LEFT JOIN course c ON c.DepartmentID = d.DepartmentID
      LEFT JOIN class cl ON cl.CourseID = c.CourseID
      LEFT JOIN student s ON s.ClassID = cl.ClassID
      $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k=>$v) $countStmt->bindValue($k,$v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $total = 0;
    $page = 1;
    $errMsg = $e->getMessage();
}

// --- Pagination calculations
$totalPages = max(1, (int)ceil($total / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

// --- Data query with safe binding
try {
    $dataSql = "
      SELECT d.DepartmentID, d.Dept_Code, d.Dept_Name " . ($has_deleted_at ? ", d.deleted_at" : ", NULL AS deleted_at") . ",
             COUNT(s.UserID) AS students
      FROM department d
      LEFT JOIN course c ON c.DepartmentID = d.DepartmentID
      LEFT JOIN class cl ON cl.CourseID = c.CourseID
      LEFT JOIN student s ON s.ClassID = cl.ClassID
      $whereSql
      GROUP BY d.DepartmentID, d.Dept_Code, d.Dept_Name " . ($has_deleted_at ? ", d.deleted_at" : "") . "
      ORDER BY $orderSql
      LIMIT :offset, :limit
    ";
    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', (int)$per, PDO::PARAM_INT);
    $stmt->execute();
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $departments = [];
    $errMsg = $e->getMessage();
}

// build deleted count for toggle badge (optional, useful)
$deletedCount = 0;
if ($has_deleted_at) {
    try {
        $deletedCount = (int)$pdo->query("SELECT COUNT(*) FROM department WHERE deleted_at IS NOT NULL")->fetchColumn();
    } catch (Exception $e) {
        $deletedCount = 0;
    }
}

// For JS usage
$deptJson = [];
foreach ($departments as $d) {
    $deptJson[(int)$d['DepartmentID']] = [
        'Dept_Code'=>$d['Dept_Code'],
        'Dept_Name'=>$d['Dept_Name'],
        'students'=>(int)($d['students'] ?? 0),
        'deleted_at'=>$d['deleted_at'] ?? null
    ];
}

// Flash (optional)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Departments — Admin</title>
<link rel="stylesheet" href="../style.css">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--bg:#0f1724;--card:#0b1520;--muted:#94a3b8;--text:#e6eef8;--accent1:#7c3aed;--accent2:#6d28d9;--ok:#10b981;--danger:#ef4444;}
/* layout & controls */
.center-box{max-width:1100px;margin:0 auto;padding:18px;}
.admin-controls{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;}
.left-controls{display:flex;gap:8px;align-items:center;}
.search-input{padding:8px 10px;border-radius:6px;border:1px solid rgba(255,255,255,0.04);background:var(--card);color:var(--text);}
.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;border:0;cursor:pointer;}
.btn-muted{background:#374151;color:#fff;border-radius:8px;padding:8px 12px;border:0;cursor:pointer;}
.add-btn{background:linear-gradient(180deg,var(--accent1),var(--accent2));color:#fff;padding:10px 16px;border-radius:8px;font-weight:700;border:0;cursor:pointer;}
.card{background:var(--card);border-radius:10px;padding:18px;box-shadow:0 6px 18px rgba(0,0,0,0.4);}

/* --- top-actions (grouped toolbar) --- */
.top-actions {
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  padding:10px;
  background: rgba(255,255,255,0.01);
  border-radius:10px;
  margin-bottom:14px;
}

/* top-actions: make export closer to left group */
.top-actions {
  display:flex;
  align-items:center;
  gap:12px;
  padding:10px;
  background: rgba(255,255,255,0.01);
  border-radius:10px;
  margin-bottom:14px;
  /* use flex-start so items sit together; we'll push only right group to the far right */
  justify-content:flex-start;
}

/* keep groups inline; center-buttons no longer centered by auto margins */
.top-actions > div { display:flex; gap:10px; align-items:center; }

/* left group stays at start */
.left-buttons { flex: 0 0 auto; }

/* put export just after left group with small spacing */
.center-buttons { flex: 0 0 auto; margin-left:6px; }

/* right group pushed to far right */
.right-buttons { margin-left: auto; display:flex; align-items:center; gap:8px; }

/* smaller visual tweaks */
.btn-danger { background: linear-gradient(180deg,#dc2626,#b91c1c); color:#fff; border:0; padding:8px 12px; border-radius:8px; cursor:pointer; }
.toggle-btn { border-radius:8px; padding:8px 12px; color:#fff; text-decoration:none; font-weight:600; }
.toggle-btn.active-toggle { background:#10b981; color:#072014; }
.toggle-btn:not(.active-toggle) { background:#ef4444; }

@media (max-width:900px) {
  .top-actions { flex-direction:column; align-items:stretch; }
  .top-actions > div { justify-content: space-between; }
}

.top-actions > div { display:flex; gap:10px; align-items:center; }
.btn-danger { background: linear-gradient(180deg,#dc2626,#b91c1c); color:#fff; border:0; padding:8px 12px; border-radius:8px; cursor:pointer; }
.toggle-btn { border-radius:8px; padding:8px 12px; color:#fff; text-decoration:none; font-weight:600; }
.toggle-btn.active-toggle { background:#10b981; color:#072014; }
.toggle-btn:not(.active-toggle) { background:#ef4444; }
.toggle-label { color:var(--muted); font-size:0.9rem; font-style:italic; margin-left:6px; }

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

/* --- Enhanced Delete Modal --- */
.delete-modal {
  width: 480px;
  max-width: 95%;
  background: linear-gradient(180deg, #0b1520 0%, #0f172a 100%);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.7);
  color: var(--text);
  animation: fadeInScale 0.2s ease-out;
}

/* Make Search and Clear buttons same size and alignment */
.search-buttons {
  display: flex;
  gap: 8px;
}

.search-buttons button,
.search-buttons a.btn-muted {
  width: 90px;             /* fixed width so they match */
  height: 38px;            /* uniform height */
  border-radius: 6px;
  text-align: center;
  justify-content: center;
  align-items: center;
  font-weight: 600;
  display: inline-flex;
}

.delete-header { border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 6px; margin-bottom: 12px; }
.delete-header h3 { margin: 0; color: #fca5a5; font-size: 1.25rem; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px; }
.delete-body { line-height: 1.55; font-size: 0.95rem; }
.warning-text { background: rgba(239,68,68,0.1); border-left: 3px solid #ef4444; padding: 8px 10px; border-radius: 6px; color: #f87171; margin: 10px 0; }
.confirm-label { display: block; margin-top: 14px; font-weight: 600; color: #cbd5e1; margin-bottom: 6px; }
.confirm-input { width: 100%; background: #0a1220; color: #f8fafc; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 10px; font-size: 0.95rem; transition: all 0.2s ease; }
.confirm-input:focus { border-color: #38bdf8; outline: none; box-shadow: 0 0 0 2px rgba(56,189,248,0.2); }
.delete-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.05); }
.btn-cancel { background: #1e293b; color: #e2e8f0; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
.btn-cancel:hover { background: #334155; }
.btn-delete { background: linear-gradient(90deg, #dc2626, #b91c1c); border: none; color: white; padding: 8px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-delete:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-delete:hover:not(:disabled) { background: linear-gradient(90deg, #ef4444, #dc2626); box-shadow: 0 0 10px rgba(239,68,68,0.4); }

/* modal backdrop / blur */
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.6);display:none;align-items:center;justify-content:center;z-index:400;}
.modal-backdrop.open{display:flex;backdrop-filter: blur(6px);background: rgba(2,6,23,0.5);}

/* animations */
@keyframes fadeInScale { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
@keyframes shake { 10%, 90% { transform: translateX(-2px); } 20%, 80% { transform: translateX(4px); } 30%, 50%, 70% { transform: translateX(-6px); } 40%, 60% { transform: translateX(6px); } }
.shake { animation: shake 0.4s ease; }

/* toast */
.toast{position:fixed;right:18px;bottom:18px;background:#0b1520;padding:12px 16px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.6);color:#e6eef8;z-index:600;display:none;}
.toast.show{display:block;}
.toast.success { background: #065f46; color: #ecfdf5; }
.toast.error { background: #7f1d1d; color: #fee2e2; }

/* responsive */
@media (max-width:900px) {
  .top-actions { flex-direction: column; align-items:stretch; }
  .top-actions > div { justify-content: space-between; }
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
    <?php foreach([5,10,,50] as $p): ?>
      <option value="<?= $p ?>" <?= $per == $p ? 'selected' : '' ?>><?= $p ?>/page</option>
    <?php endforeach; ?>
  </select>
  <div class="search-buttons">
    <button type="submit" class="btn-muted">Search</button>
    <a href="departments.php" class="btn-muted">Clear</a>
  </div>
</form>

        </div>
      </div>

      <!-- TOP ACTIONS -->
      <div class="top-actions" aria-label="Actions">
        <div class="left-buttons">
  <button id="openAddBtn" class="add-btn">＋ Add Department</button>
  <a class="btn btn-blue" href="export_departments.php">Export All</a>
  <button id="bulkDeleteBtn" class="btn-danger">Delete Selected</button>
</div>


        <div class="right-buttons">
          <?php if ($show_deleted): ?>
            <a class="btn toggle-btn active-toggle" href="departments.php">🟢 Show Active</a>
            <span class="toggle-label">Viewing deleted (<?= (int)$deletedCount ?>)</span>
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
                <th class="checkbox-col"><input id="chkAll" type="checkbox"></th>
                <th style="width:100px"><a href="<?= e(build_sort_link('code')) ?>">Code <?= e(sort_arrow('code')) ?></a></th>
                <th><a href="<?= e(build_sort_link('name')) ?>">Name <?= e(sort_arrow('name')) ?></a></th>
                <th style="width:120px;text-align:right"><a href="<?= e(build_sort_link('students')) ?>">Students <?= e(sort_arrow('students')) ?></a></th>
                <th style="width:160px">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($departments)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:28px;">No departments found.</td></tr>
              <?php else: foreach ($departments as $d): $isDeleted = !empty($d['deleted_at']); ?>
                <tr class="row-hover">
                  <td class="checkbox-col"><input class="row-chk" type="checkbox" value="<?= e($d['DepartmentID']) ?>" <?= $isDeleted ? 'disabled' : '' ?>></td>
                  <td><span class="code-badge"><?= e($d['Dept_Code'] ?? '-') ?></span></td>
                  <td>
                    <?= e($d['Dept_Name']) ?>
                    <?php if ($isDeleted): ?><div style="color:var(--muted);font-size:.95rem;margin-top:6px;">Deleted at <?= e($d['deleted_at']) ?></div><?php endif; ?>
                  </td>
                  <td style="text-align:right"><?= e((int)$d['students']) ?></td>
                  <td>
                    <div class="actions-inline">
                      <?php if (!$isDeleted): ?>
                        <button class="link-update" data-id="<?= e($d['DepartmentID']) ?>">Update</button>
                      <?php else: ?>
                        <button class="btn-muted" data-undo-id="<?= e($d['DepartmentID']) ?>">Undo</button>
                      <?php endif; ?>
                      <button class="link-delete" data-id="<?= e($d['DepartmentID']) ?>">Delete</button>
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
            // simple pager rendering
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

<!-- Add/Edit Modal -->
<div id="modalBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog">
    <h3 id="modalTitle">Add Department</h3>
    <form id="modalForm">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" id="modalAction" value="add">
      <input type="hidden" name="id" id="modalId" value="0">
      <div class="row">
        <label for="modal_code">Code</label>
        <input id="modal_code" name="dept_code" type="text" required style="width:100%;padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)">
      </div>
      <div class="row">
        <label for="modal_name">Name</label>
        <input id="modal_name" name="dept_name" type="text" required style="width:100%;padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" id="modalCancel" class="btn-muted">Cancel</button>
        <button id="modalSubmit" class="btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- DELETE CONFIRMATION MODAL (Enhanced Design) -->
<div id="deleteBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal delete-modal" role="dialog">
    <div class="delete-header">
      <h3>⚠️ Confirm Delete</h3>
    </div>
    <div class="delete-body">
      <p>You are about to delete <strong id="deleteDeptName"></strong>.</p>
      <p class="warning-text">This action will <b>soft-delete</b> the department. You can restore it later using the <em>Undo</em> option.</p>

      <label class="confirm-label" for="confirmDelete">
        Please type <code>DELETE</code> below to confirm:
      </label>
      <input id="confirmDelete" type="text" placeholder="Type DELETE here" class="confirm-input">
    </div>

    <div class="delete-footer">
      <button id="deleteCancel" class="btn-cancel">Cancel</button>
      <button id="deleteConfirm" class="btn-delete" disabled>Delete permanently</button>
    </div>
  </div>
</div>

<!-- hidden export form -->
<form id="exportForm" method="post" action="export_departments.php" style="display:none;"></form>

<div id="toast" class="toast"></div>

<script>
// Data and CSRF for JS
const deptMap = <?= json_encode($deptJson, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS) ?>;
const csrfToken = <?= json_encode($csrf) ?>;

// simple helpers
function postJSON(url, data){
    const fd = new FormData();
    for (const k in data) {
        if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k+'[]', v));
        else fd.append(k, data[k]);
    }
    fd.append('csrf_token', csrfToken);
    return fetch(url, { method:'POST', body: fd }).then(r => r.json());
}
function showToast(msg, t=2500, cls=''){
    const s=document.getElementById('toast');
    s.textContent=msg;
    s.classList.remove('success','error','show');
    if (cls) s.classList.add(cls);
    s.classList.add('show');
    setTimeout(()=>{ s.classList.remove('show'); if (cls) s.classList.remove(cls); }, t);
}

// ELEMENTS
const chkAll = document.getElementById('chkAll');
const rowChecks = ()=>Array.from(document.querySelectorAll('.row-chk'));
const openAddBtn = document.getElementById('openAddBtn');
const modalBackdrop = document.getElementById('modalBackdrop');
const modalForm = document.getElementById('modalForm');
const modalAction = document.getElementById('modalAction');
const modalId = document.getElementById('modalId');
const modalCode = document.getElementById('modal_code');
const modalName = document.getElementById('modal_name');
const modalCancel = document.getElementById('modalCancel');
const modalSubmit = document.getElementById('modalSubmit');

const deleteBackdrop = document.getElementById('deleteBackdrop');
const deleteDeptName = document.getElementById('deleteDeptName');
const confirmDelete = document.getElementById('confirmDelete');
const deleteConfirm = document.getElementById('deleteConfirm');
const deleteCancel = document.getElementById('deleteCancel');

const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
const exportForm = document.getElementById('exportForm');

// checkbox master toggle
if (chkAll) chkAll.addEventListener('change', ()=> rowChecks().forEach(c=>{ if(!c.disabled) c.checked = chkAll.checked; }));

// Open add modal
if (openAddBtn) openAddBtn.addEventListener('click', ()=>{
    modalAction.value = 'add';
    modalId.value = 0;
    modalCode.value = '';
    modalName.value = '';
    modalBackdrop.classList.add('open');
    modalCode.focus();
});

// Edit buttons
document.querySelectorAll('.link-update').forEach(btn=>{
    btn.addEventListener('click', ()=>{
        const id = btn.dataset.id;
        const d = deptMap[id] || {};
        modalAction.value = 'edit';
        modalId.value = id;
        modalCode.value = d.Dept_Code || '';
        modalName.value = d.Dept_Name || '';
        modalBackdrop.classList.add('open');
        modalCode.focus();
    });
});

// Cancel modal
if (modalCancel) modalCancel.addEventListener('click', ()=> modalBackdrop.classList.remove('open'));

// AJAX add/edit submit
if (modalForm) modalForm.addEventListener('submit', function(e){
    e.preventDefault();
    const act = modalAction.value;
    const id = modalId.value;
    modalSubmit.disabled = true;
    modalSubmit.textContent = 'Saving...';
    const payload = { action: act, dept_code: modalCode.value.trim(), dept_name: modalName.value.trim() };
    if (act === 'edit') payload.id = id;
    postJSON('api/departments.php', payload).then(resp=>{
        modalSubmit.disabled = false;
        modalSubmit.textContent = 'Save';
        if (resp && resp.ok) {
            modalBackdrop.classList.remove('open');
            showToast('Saved', 1200, 'success');
            setTimeout(()=> location.reload(), 600);
        } else {
            showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error');
        }
    }).catch(()=>{ modalSubmit.disabled = false; modalSubmit.textContent = 'Save'; showToast('Network error',2500,'error'); });
});

// Delete flow (typed double confirm)
document.querySelectorAll('.link-delete').forEach(btn=>{
    btn.addEventListener('click', ()=>{
        const id = btn.dataset.id;
        const d = deptMap[id] || {};
        deleteDeptName.textContent = d.Dept_Name || ('#'+id);
        confirmDelete.value = '';
        deleteConfirm.disabled = true;
        deleteConfirm.dataset.id = id;
        deleteBackdrop.classList.add('open');
        confirmDelete.focus();
    });
});
if (confirmDelete) confirmDelete.addEventListener('input', ()=>{
    deleteConfirm.disabled = (confirmDelete.value !== 'DELETE');
    if (confirmDelete.value.length >= 6 && confirmDelete.value !== 'DELETE') {
        confirmDelete.classList.add('shake');
        setTimeout(()=> confirmDelete.classList.remove('shake'), 380);
    }
});
if (deleteCancel) deleteCancel.addEventListener('click', ()=> deleteBackdrop.classList.remove('open'));
if (deleteConfirm) deleteConfirm.addEventListener('click', ()=>{
    const id = deleteConfirm.dataset.id;
    deleteConfirm.disabled = true;
    deleteConfirm.textContent = 'Deleting...';
    postJSON('api/departments.php', { action: 'delete', id: id }).then(resp=>{
        if (resp && resp.ok) { deleteBackdrop.classList.remove('open'); showToast('Deleted', 1200, 'success'); setTimeout(()=>location.reload(),600); }
        else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error'); deleteConfirm.disabled = false; deleteConfirm.textContent = 'Delete permanently'; }
    }).catch(()=>{ showToast('Network error', 2500, 'error'); deleteConfirm.disabled = false; deleteConfirm.textContent = 'Delete permanently'; });
});

// Bulk delete
if (bulkDeleteBtn) bulkDeleteBtn.addEventListener('click', ()=>{
    const selected = rowChecks().filter(c=>c.checked).map(c=>c.value);
    if (!selected.length) return alert('Select rows first');
    if (!confirm('Delete selected departments?')) return;
    postJSON('api/departments.php', { action: 'bulk_delete', ids: selected }).then(resp=>{
        if (resp && resp.ok) { showToast('Deleted ' + (resp.count||selected.length), 1500, 'success'); setTimeout(()=>location.reload(),600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error');
    }).catch(()=>showToast('Network error',2500,'error'));
});

// Bulk export removed — no listener

// per-row undo (if present)
document.querySelectorAll('[data-undo-id]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
        const id = btn.dataset.undoId;
        postJSON('api/departments.php', { action: 'undo', id: id }).then(resp=>{
            if (resp && resp.ok) { showToast('Restored', 1200, 'success'); setTimeout(()=>location.reload(),600); }
            else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'), 2500, 'error');
        }).catch(()=>showToast('Network error',2500,'error'));
    });
});

// Click outside modal closes
document.querySelectorAll('.modal-backdrop').forEach(b=>{
    b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(b=>b.classList.remove('open')); });

</script>
</body>
</html>
