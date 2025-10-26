<?php
// admin/courses.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
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

// WHERE
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

// data - note: order by department first so grouping is simple in PHP
try {
    // Order by department code/name, then course code/name
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

// group rows by department (Dept_Code preferred, fallback to Dept_Name or 'Unassigned')
$groups = [];
foreach ($rows as $r) {
    $deptCode = $r['Dept_Code'] ?? '';
    $deptName = $r['Dept_Name'] ?? '';
    if ($deptCode === null || trim($deptCode) === '') {
        // use Dept_Name as code if code empty, else 'Unassigned'
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

// prepare JS map for actions
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
.search-input{padding:8px 10px;border-radius:6px;border:1px solid rgba(255,255,255,0.04);background:var(--card);color:var(--text);}
.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;border:0;cursor:pointer;}
.btn-muted{background:#374151;color:#fff;border-radius:8px;padding:8px 12px;border:0;cursor:pointer;}
.add-btn{background:linear-gradient(180deg,var(--accent1),var(--accent2));color:#fff;padding:10px 16px;border-radius:8px;font-weight:700;border:0;cursor:pointer;}
.card{background:var(--card);border-radius:10px;padding:18px;box-shadow:0 6px 18px rgba(0,0,0,0.4);}

/* --- top-actions (grouped toolbar) --- */
.top-actions { display:flex; align-items:center; gap:12px; padding:10px; background: rgba(255,255,255,0.01); border-radius:10px; margin-bottom:14px; justify-content:flex-start; }
.top-actions > div { display:flex; gap:10px; align-items:center; }
.left-buttons { flex: 0 0 auto; }
.right-buttons { margin-left: auto; display:flex; align-items:center; gap:8px; }
.btn-danger { background: linear-gradient(180deg,#dc2626,#b91c1c); color:#fff; border:0; padding:8px 12px; border-radius:8px; cursor:pointer; }
.toggle-btn { border-radius:8px; padding:8px 12px; color:#fff; text-decoration:none; font-weight:600; }
.toggle-btn.active-toggle { background:#10b981; color:#072014; }
.toggle-btn:not(.active-toggle) { background:#ef4444; }
.toggle-label { color:var(--muted); font-size:0.9rem; font-style:italic; margin-left:6px; }

/* make Search/Clear same size */
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
/* small dept meta */
.dept-sub { color: var(--muted); font-weight:600; margin-left:10px; font-size:0.95rem; }

/* modal + delete */
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.6);display:none;align-items:center;justify-content:center;z-index:400;}
.modal-backdrop.open{display:flex;backdrop-filter: blur(6px);background: rgba(2,6,23,0.5);}
.modal{background:var(--card);border-radius:10px;padding:20px;width:520px;max-width:96%;color:var(--text);line-height:1.45;}
.form-field { display:flex; flex-direction:column; gap:6px; margin-bottom:8px; }
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
                    <td colspan="6">
                      <span style="font-size:1rem;"><?= e($gKey ?: 'Unassigned') ?></span>
                      <?php if (!empty($g['dept_name'])): ?><span class="dept-sub">— <?= e($g['dept_name']) ?></span><?php endif; ?>
                    </td>
                  </tr>

                  <?php foreach ($g['items'] as $r): $isDeleted = !empty($r['deleted_at']); ?>
                    <tr class="row-hover">
                      <td class="checkbox-col"><input class="row-chk" type="checkbox" value="<?= e($r['CourseID']) ?>" <?= $isDeleted ? 'disabled' : '' ?>></td>
                      <td><span class="code-badge"><?= e($r['Course_Code'] ?? '-') ?></span></td>
                      <td><?= e($r['Course_Name']) ?>
                        <?php if ($isDeleted): ?><div style="color:var(--muted);font-size:.95rem;margin-top:6px;">Deleted at <?= e($r['deleted_at']) ?></div><?php endif; ?>
                      </td>
                      <td><?= e($r['Dept_Name'] ?? '-') ?></td>
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

<!-- Add/Edit Modal -->
<div id="modalBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog">
    <h3 id="modalTitle">Add Course</h3>
    <form id="modalForm">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" id="modalAction" value="add">
      <input type="hidden" name="id" id="modalId" value="0">

      <div class="form-field">
        <label for="modal_code">Code</label>
        <input id="modal_code" name="course_code" type="text" required style="padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)">
      </div>

      <div class="form-field">
        <label for="modal_name">Name</label>
        <input id="modal_name" name="course_name" type="text" required style="padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)">
      </div>

      <div class="form-field">
        <label for="modal_dept">Department ID</label>
        <input id="modal_dept" name="department_id" type="text" placeholder="optional" style="padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)">
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
        <button type="button" id="modalCancel" class="btn-muted">Cancel</button>
        <button id="modalSubmit" class="btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- DELETE CONFIRMATION MODAL -->
<div id="deleteBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog">
    <h3>⚠️ Confirm Delete</h3>
    <p>You are about to delete <strong id="deleteName"></strong>.</p>
    <p class="warning-text" style="color:#f87171;">This will soft-delete the row (if enabled).</p>
    <label style="margin-top:8px;color:#cbd5e1;">Type <code>DELETE</code> below to confirm:</label>
    <input id="confirmDelete" type="text" placeholder="Type DELETE here" class="confirm-input" style="width:100%;padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text);margin-top:8px;">
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
      <button id="deleteCancel" class="btn-muted">Cancel</button>
      <button id="deleteConfirm" class="btn" disabled style="background:linear-gradient(90deg,#ef4444,#dc2626);color:#fff;">Delete permanently</button>
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
    const s = document.getElementById('toast'); s.textContent = msg; s.classList.add('show'); setTimeout(()=> s.classList.remove('show'), timeout);
}

// elements
const chkAll = document.getElementById('chkAll');
if (chkAll) chkAll.addEventListener('change', ()=> document.querySelectorAll('.row-chk').forEach(c=>{ if(!c.disabled) c.checked = chkAll.checked; }));

// open add
document.getElementById('openAddBtn').addEventListener('click', ()=> {
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalId').value = 0;
    document.getElementById('modal_code').value = '';
    document.getElementById('modal_name').value = '';
    document.getElementById('modal_dept').value = '';
    document.getElementById('modalBackdrop').classList.add('open');
    document.getElementById('modal_code').focus();
});

// edit buttons
document.querySelectorAll('.link-update').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.id; const d = courseMap[id] || {};
        document.getElementById('modalAction').value = 'edit';
        document.getElementById('modalId').value = id;
        document.getElementById('modal_code').value = d.Course_Code || '';
        document.getElementById('modal_name').value = d.Course_Name || '';
        document.getElementById('modal_dept').value = d.DepartmentID || '';
        document.getElementById('modalBackdrop').classList.add('open');
        document.getElementById('modal_code').focus();
    });
});

// cancel modal
document.getElementById('modalCancel').addEventListener('click', ()=> document.getElementById('modalBackdrop').classList.remove('open'));

// submit add/edit
document.getElementById('modalForm').addEventListener('submit', function(e){
    e.preventDefault();
    const act = document.getElementById('modalAction').value;
    const id = document.getElementById('modalId').value;
    const code = document.getElementById('modal_code').value.trim();
    const name = document.getElementById('modal_name').value.trim();
    const dept = document.getElementById('modal_dept').value.trim();
    const btn = document.getElementById('modalSubmit');
    btn.disabled = true; btn.textContent = 'Saving...';
    const payload = { action: act, course_code: code, course_name: name, department_id: dept || '' };
    if (act === 'edit') payload.id = id;
    postJSON('api/courses.php', payload).then(resp=>{
        btn.disabled = false; btn.textContent = 'Save';
        if (resp && resp.ok) { document.getElementById('modalBackdrop').classList.remove('open'); showToast('Saved'); setTimeout(()=>location.reload(), 600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
    }).catch(()=>{ btn.disabled=false; btn.textContent='Save'; showToast('Network error'); });
});

// delete flow
document.querySelectorAll('.link-delete').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.id; const d = courseMap[id] || {};
        document.getElementById('deleteName').textContent = d.Course_Name || ('#'+id);
        document.getElementById('confirmDelete').value = '';
        document.getElementById('deleteConfirm').disabled = true;
        document.getElementById('deleteConfirm').dataset.id = id;
        document.getElementById('deleteBackdrop').classList.add('open');
        document.getElementById('confirmDelete').focus();
    });
});
document.getElementById('confirmDelete').addEventListener('input', ()=> {
    document.getElementById('deleteConfirm').disabled = (document.getElementById('confirmDelete').value !== 'DELETE');
});
document.getElementById('deleteCancel').addEventListener('click', ()=> document.getElementById('deleteBackdrop').classList.remove('open'));
document.getElementById('deleteConfirm').addEventListener('click', ()=> {
    const id = document.getElementById('deleteConfirm').dataset.id;
    const btn = document.getElementById('deleteConfirm'); btn.disabled = true; btn.textContent = 'Deleting...';
    postJSON('api/courses.php', { action: 'delete', id: id }).then(resp=>{
        if (resp && resp.ok) { document.getElementById('deleteBackdrop').classList.remove('open'); showToast('Deleted'); setTimeout(()=>location.reload(),600); }
        else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown')); btn.disabled = false; btn.textContent = 'Delete permanently'; }
    }).catch(()=>{ showToast('Network error'); btn.disabled=false; btn.textContent='Delete permanently'; });
});

// undo
document.querySelectorAll('[data-undo-id]').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.undoId;
        postJSON('api/courses.php', { action: 'undo', id: id }).then(resp=>{
            if (resp && resp.ok) { showToast('Restored'); setTimeout(()=>location.reload(),600); }
            else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
        }).catch(()=>showToast('Network error'));
    });
});

// bulk delete
document.getElementById('bulkDeleteBtn').addEventListener('click', ()=> {
    const selected = Array.from(document.querySelectorAll('.row-chk')).filter(c=>c.checked).map(c=>c.value);
    if (!selected.length) return alert('Select rows first');
    if (!confirm('Delete selected courses?')) return;
    postJSON('api/courses.php', { action: 'bulk_delete', ids: selected }).then(resp=>{
        if (resp && resp.ok) { showToast('Deleted ' + (resp.count || selected.length)); setTimeout(()=>location.reload(),600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
    }).catch(()=>showToast('Network error'));
});

// close modals on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(b => b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); }));
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(b=>b.classList.remove('open')); });
</script>
</body>
</html>
