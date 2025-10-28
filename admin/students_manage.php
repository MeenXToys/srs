<?php
// admin/students_manage.php (updated — auto-detects class columns)
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

// safe echo
if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

// CSRF
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

// utility to pick probable column name
function pick_col(array $cols, array $candidates, $default = null) {
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return $default;
}

// ---- discover student columns ----
$studentCols = [];
try {
    $studentCols = $pdo->query("SHOW COLUMNS FROM student")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {
    $studentCols = [];
    $errMsg = "DB error reading student columns: " . $ex->getMessage();
}

$col_id     = pick_col($studentCols, ['UserID','StudentID','id','user_id','student_id'], 'UserID');
$col_name   = pick_col($studentCols, ['FullName','fullname','Name','name','student_name'], 'FullName');
$col_email  = pick_col($studentCols, ['Email','email','EmailAddress','email_address','email_addr'], null);
$col_class  = pick_col($studentCols, ['ClassID','class_id','Class','class'], 'ClassID');
$col_deleted= pick_col($studentCols, ['deleted_at','deleted','is_deleted'], null);
function hascol_student($name){ global $studentCols; return $name !== null && in_array($name, $studentCols); }

// ---- discover class table columns (guarded) ----
$classCols = [];
try {
    $classCols = $pdo->query("SHOW COLUMNS FROM `class`")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {
    // class table may not exist or permission issue - continue with empty array
    $classCols = [];
}
$col_class_name = pick_col($classCols, ['Class_Name','Name','class_name','className','ClassName'], null);
$col_class_code = pick_col($classCols, ['Class_Code','Code','class_code','classCode','ClassCode'], null);
function hascol_class($name){ global $classCols; return $name !== null && in_array($name, $classCols); }

// parameters / pagination
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = (int)($_GET['per'] ?? 10); if ($per <= 0) $per = 10;
$sort = $_GET['sort'] ?? 'name';
$dir = (strtolower($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
$show_deleted = ($_GET['show_deleted'] ?? '') === '1';

$allowed = ['name','email','class','id'];
if (!in_array($sort, $allowed)) $sort = 'name';
function build_sort_link($col){ $p=$_GET; $cur=$p['sort'] ?? 'name'; $cdir=strtolower($p['dir']??'asc'); $p['dir']=($cur===$col)?($cdir==='asc'?'desc':'asc'):'asc'; $p['sort']=$col; $p['page']=1; return 'students_manage.php?'.http_build_query($p); }
function sort_arrow($col){ $cur=$_GET['sort'] ?? 'name'; $d=strtolower($_GET['dir'] ?? 'asc'); if($cur!==$col) return ''; return $d==='desc'?'↓':'↑'; }

// where clause (use only existing columns)
$where = []; $params = [];
if ($q !== '') {
    $qLike = "%$q%";
    $parts = [];
    if (hascol_student($col_name)) $parts[] = "s.`{$col_name}` LIKE :q";
    if (hascol_student($col_email)) $parts[] = "s.`{$col_email}` LIKE :q";
    if (hascol_class($col_class_name)) $parts[] = "c.`{$col_class_name}` LIKE :q";
    // fallback: search student table primary name column if nothing else
    if (empty($parts) && hascol_student($col_name)) $parts[] = "s.`{$col_name}` LIKE :q";
    if (!empty($parts)) {
        $where[] = '(' . implode(' OR ', $parts) . ')';
        $params[':q'] = $qLike;
    }
}
if ($col_deleted && hascol_student($col_deleted) && !$show_deleted) {
    $where[] = "s.`{$col_deleted}` IS NULL";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// count total
try {
    $countSql = "SELECT COUNT(DISTINCT s.`{$col_id}`) FROM student s LEFT JOIN `class` c ON c.ClassID = s.ClassID $whereSql";
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

// build select list carefully — only select class cols that exist
$select = [];
$select[] = hascol_student($col_id) ? "s.`{$col_id}` AS StudentID" : "NULL AS StudentID";
$select[] = hascol_student($col_name) ? "s.`{$col_name}` AS FullName" : "NULL AS FullName";
$select[] = hascol_student($col_email) ? "s.`{$col_email}` AS Email" : "NULL AS Email";
$select[] = hascol_student($col_class) ? "s.`{$col_class}` AS ClassID" : "NULL AS ClassID";
$select[] = ($col_deleted && hascol_student($col_deleted)) ? "s.`{$col_deleted}` AS deleted_at" : "NULL AS deleted_at";
$select[] = hascol_class($col_class_name) ? "c.`{$col_class_name}` AS Class_Name" : "NULL AS Class_Name";
$select[] = hascol_class($col_class_code) ? "c.`{$col_class_code}` AS Class_Code" : "NULL AS Class_Code";
$selectSql = implode(", ", $select);

// order map — use aliased names where appropriate
$orderMap = [
    'name' => 'FullName',
    'email' => 'Email',
    'class' => (hascol_class($col_class_name) ? 'Class_Name' : 'ClassID'),
    'id' => 'StudentID'
];
$orderSql = ($orderMap[$sort] ?? $orderMap['name']) . ' ' . $dir;

// fetch rows (no GROUP BY — not aggregating)
try {
    $dataSql = "
      SELECT $selectSql
      FROM student s
      LEFT JOIN `class` c ON c.ClassID = s.ClassID
      $whereSql
      ORDER BY $orderSql
      LIMIT :offset, :limit
    ";
    $stmt = $pdo->prepare($dataSql);
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
    $id = $r['StudentID'] ?? null;
    if ($id === null) continue;
    $map[(int)$id] = [
        'FullName' => $r['FullName'] ?? null,
        'Email' => $r['Email'] ?? null,
        'ClassID' => $r['ClassID'] ?? null,
        'Class_Name' => $r['Class_Name'] ?? null,
        'Class_Code' => $r['Class_Code'] ?? null,
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
<style>
:root{--bg:#0f1724;--card:#0b1520;--muted:#94a3b8;--text:#e6eef8;--accent1:#7c3aed;--accent2:#6d28d9;--ok:#10b981;--danger:#ef4444;}
.center-box{max-width:1100px;margin:0 auto;padding:18px;}
.admin-controls{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;}
.left-controls{display:flex;gap:8px;align-items:center;}
.search-input{padding:8px 10px;border-radius:6px;border:1px solid rgba(255,255,255,0.04);background:var(--card);color:var(--text);}
.search-buttons{display:flex;gap:8px;}
.search-buttons button, .search-buttons a.btn-muted{width:90px;height:38px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-weight:600;}
.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;border:0;cursor:pointer;}
.btn-muted{background:#374151;color:#fff;border-radius:8px;padding:8px 12px;border:0;cursor:pointer;}
.add-btn{background:linear-gradient(180deg,var(--accent1),var(--accent2));color:#fff;padding:10px 16px;border-radius:8px;font-weight:700;border:0;cursor:pointer;}
.card{background:var(--card);border-radius:10px;padding:18px;box-shadow:0 6px 18px rgba(0,0,0,0.4);}

/* top-actions */
.top-actions { display:flex; align-items:center; gap:12px; padding:10px; background: rgba(255,255,255,0.01); border-radius:10px; margin-bottom:14px; justify-content:flex-start; }
.top-actions > div { display:flex; gap:10px; align-items:center; }
.left-buttons { flex: 0 0 auto; }
.right-buttons { margin-left: auto; display:flex; align-items:center; gap:8px; }

.btn-danger { background: linear-gradient(180deg,#dc2626,#b91c1c); color:#fff; border:0; padding:8px 12px; border-radius:8px; cursor:pointer; }
.toggle-btn { border-radius:8px; padding:8px 12px; color:#fff; text-decoration:none; font-weight:600; background:#334155; }
.toggle-btn.active-toggle { background:#10b981; color:#072014; }
.toggle-label { color:var(--muted); font-size:0.9rem; font-style:italic; margin-left:6px; }

/* table */
.table-wrap{overflow:auto;}
table{width:100%;border-collapse:collapse;margin-top:12px;}
th,td{padding:12px;border-top:1px solid rgba(255,255,255,0.03);color:var(--text);vertical-align:middle;}
th{color:var(--muted);text-align:left;font-weight:700;}
.actions-inline{display:flex;gap:12px;align-items:center;justify-content:flex-end;}
.link-update{color:var(--ok);background:none;border:0;padding:0;cursor:pointer;font-weight:700;text-decoration:none;font-size:.95rem;}
.link-delete{color:var(--danger);background:none;border:0;padding:0;cursor:pointer;font-weight:700;text-decoration:none;font-size:.95rem;}
.checkbox-col{width:36px;text-align:center;}
.row-hover:hover td{background:rgba(255,255,255,0.01);}

/* modal + delete styles */
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.6);display:none;align-items:center;justify-content:center;z-index:400;}
.modal-backdrop.open{display:flex;backdrop-filter: blur(6px);background: rgba(2,6,23,0.5);}
.modal{background:var(--card);border-radius:10px;padding:20px;width:520px;max-width:96%;color:var(--text);line-height:1.45;}
.delete-modal{width:480px;max-width:95%;background:linear-gradient(180deg,#0b1520,#0f172a);border:1px solid rgba(255,255,255,0.08);border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,0.7);}
.delete-header{border-bottom:1px solid rgba(255,255,255,0.05);padding-bottom:6px;margin-bottom:12px;}
.delete-header h3{margin:0;color:#fca5a5;font-size:1.25rem;}
.delete-body{line-height:1.55;font-size:0.95rem;}
.warning-text{background:rgba(239,68,68,0.1);border-left:3px solid #ef4444;padding:8px 10px;border-radius:6px;color:#f87171;margin:10px 0;}
.confirm-input{width:100%;background:#0a1220;color:#f8fafc;border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:10px;font-size:0.95rem;}
.delete-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.05);}
.btn-cancel{background:#1e293b;color:#e2e8f0;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;}
.btn-delete{background:linear-gradient(90deg,#dc2626,#b91c1c);border:none;color:white;padding:8px 18px;border-radius:8px;font-weight:600;cursor:pointer;}
.toast{position:fixed;right:18px;bottom:18px;background:#0b1520;padding:12px 16px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.6);color:#e6eef8;z-index:600;display:none;}
.toast.show{display:block;}

@keyframes fadeInScale{from{opacity:0;transform:scale(0.95);}to{opacity:1;transform:scale(1);}}
@keyframes shake{10%,90%{transform:translateX(-2px);}20%,80%{transform:translateX(4px);}30%,50%,70%{transform:translateX(-6px);}40%,60%{transform:translateX(6px);}}
.shake{animation:shake 0.4s ease;}
@media (max-width:900px){ .top-actions{flex-direction:column;align-items:stretch;} .top-actions>div{justify-content:space-between;} }
</style>
</head>
<body>
<main class="admin-main">
  <div class="admin-container">
    <div class="center-box">

      <?php if (!empty($errMsg)): ?>
        <div style="background:#3b1f1f;color:#ffdede;padding:10px;border-radius:6px;margin-bottom:12px;">
          <?= e($errMsg) ?>
        </div>
      <?php endif; ?>

      <?php if ($flash): ?><div id="pageFlash" class="toast show"><?= e($flash) ?></div><?php endif; ?>

      <div class="admin-controls">
        <div class="left-controls">
<form id="searchForm" method="get" style="display:flex;gap:8px;align-items:center;">
  <input name="q" class="search-input" placeholder="Search name, email or class..." value="<?= e($q) ?>">
  <select name="per" onchange="this.form.submit()" class="search-input">
    <?php foreach([5,10,25,50] as $p): ?>
      <option value="<?= $p ?>" <?= $per == $p ? 'selected' : '' ?>><?= $p ?>/page</option>
    <?php endforeach; ?>
  </select>
  <div class="search-buttons">
    <button type="submit" class="btn-muted">Search</button>
    <a href="students_manage.php" class="btn-muted">Clear</a>
  </div>
</form>
        </div>
      </div>

      <!-- TOP ACTIONS -->
      <div class="top-actions" aria-label="Actions">
        <div class="left-buttons">
          <button id="openAddBtn" class="add-btn">＋ Add Student</button>
          <a class="btn" href="export_students.php">Export All</a>
          <button id="bulkDeleteBtn" class="btn-danger">Delete Selected</button>
        </div>

        <div class="right-buttons">
          <?php if ($show_deleted): ?>
            <a class="toggle-btn active-toggle" href="students_manage.php">🟢 Show Active</a>
            <span class="toggle-label">Viewing deleted (<?= (int)$deletedCount ?>)</span>
          <?php else: ?>
            <a class="toggle-btn" href="students_manage.php?show_deleted=1">🔴 Show Deleted <?= $deletedCount ? "({$deletedCount})" : '' ?></a>
          <?php endif; ?>
        </div>
      </div>
      <!-- /TOP ACTIONS -->

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <h2 style="margin:0;color:#cfe8ff;">Student List</h2>
          <div style="color:var(--muted)"><?= $total ?> result<?= $total==1?'':'s' ?></div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="checkbox-col"><input id="chkAll" type="checkbox"></th>
                <th style="width:90px"><a href="<?= e(build_sort_link('id')) ?>">Student ID <?= e(sort_arrow('id')) ?></a></th>
                <th><a href="<?= e(build_sort_link('name')) ?>">Name <?= e(sort_arrow('name')) ?></a></th>
                <th><a href="<?= e(build_sort_link('email')) ?>">Email <?= e(sort_arrow('email')) ?></a></th>
                <th style="width:220px"><a href="<?= e(build_sort_link('class')) ?>">Class <?= e(sort_arrow('class')) ?></a></th>
                <th style="width:160px">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px;">No students found.</td></tr>
              <?php else: foreach ($rows as $r): $isDeleted = !empty($r['deleted_at']); ?>
                <tr class="row-hover">
                  <td class="checkbox-col"><input class="row-chk" type="checkbox" value="<?= e($r['StudentID']) ?>" <?= $isDeleted ? 'disabled' : '' ?>></td>
                  <td><?= e($r['StudentID'] ?? '-') ?></td>
                  <td>
                    <?= e($r['FullName'] ?? '-') ?>
                    <?php if ($isDeleted): ?><div style="color:var(--muted);font-size:.95rem;margin-top:6px;">Deleted at <?= e($r['deleted_at']) ?></div><?php endif; ?>
                  </td>
                  <td><?= e($r['Email'] ?? '-') ?></td>
                  <td>
                    <?php
                      // show either class name + code if available, else ClassID
                      $classLabel = $r['Class_Name'] ?? null;
                      $classCode = $r['Class_Code'] ?? null;
                      if ($classLabel) {
                          echo e($classLabel) . ($classCode ? ' <span style="color:var(--muted);font-size:.95rem;margin-left:8px;">('.e($classCode).')</span>' : '');
                      } else {
                          echo e($r['ClassID'] ?? '-');
                      }
                    ?>
                  </td>
                  <td>
                    <div class="actions-inline">
                      <?php if (!$isDeleted): ?>
                        <button class="link-update" data-id="<?= e($r['StudentID']) ?>">Update</button>
                      <?php else: ?>
                        <button class="btn-muted" data-undo-id="<?= e($r['StudentID']) ?>">Undo</button>
                      <?php endif; ?>
                      <button class="link-delete" data-id="<?= e($r['StudentID']) ?>">Delete</button>
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
                $u = 'students_manage.php?' . http_build_query($baseParams);
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

<!-- Add/Edit Student modal -->
<div id="modalBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog">
    <h3 id="modalTitle">Add Student</h3>
    <form id="modalForm">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" id="modalAction" value="add">
      <input type="hidden" name="id" id="modalId" value="0">

      <div style="margin-bottom:8px;"><label for="modal_name">Full Name</label><input id="modal_name" name="fullname" type="text" required style="width:100%;padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)"></div>
      <div style="margin-bottom:8px;"><label for="modal_email">Email</label><input id="modal_email" name="email" type="email" style="width:100%;padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)"></div>
      <div style="margin-bottom:10px;"><label for="modal_class">Class ID</label><input id="modal_class" name="class_id" type="text" placeholder="optional class id" style="width:100%;padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)"></div>

      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" id="modalCancel" class="btn-muted">Cancel</button>
        <button id="modalSubmit" class="btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- delete modal -->
<div id="deleteBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal delete-modal" role="dialog">
    <div class="delete-header"><h3>⚠️ Confirm Delete</h3></div>
    <div class="delete-body">
      <p>You are about to delete <strong id="deleteName"></strong>.</p>
      <p class="warning-text">This action will <b>soft-delete</b> the student if your table supports it. You can restore it later using Undo.</p>
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
const studentMap = <?= json_encode($map, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS) ?>;

function postJSON(url, data){
  const fd = new FormData();
  for (const k in data) {
    if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k+'[]', v));
    else fd.append(k, data[k]);
  }
  fd.append('csrf_token', csrfToken);
  return fetch(url, { method:'POST', body: fd }).then(r => r.json());
}
function showToast(msg, t=2000, cls=''){
  const s=document.getElementById('toast'); s.textContent=msg;
  s.classList.remove('success','error','show');
  if (cls) s.classList.add(cls);
  s.classList.add('show');
  setTimeout(()=>{ s.classList.remove('show'); if (cls) s.classList.remove(cls); }, t);
}

const chkAll = document.getElementById('chkAll');
const rowChecks = ()=>Array.from(document.querySelectorAll('.row-chk'));
const openAddBtn = document.getElementById('openAddBtn');
const modalBackdrop = document.getElementById('modalBackdrop');
const modalForm = document.getElementById('modalForm');
const modalAction = document.getElementById('modalAction');
const modalId = document.getElementById('modalId');
const modalName = document.getElementById('modal_name');
const modalEmail = document.getElementById('modal_email');
const modalClass = document.getElementById('modal_class');
const modalCancel = document.getElementById('modalCancel');
const modalSubmit = document.getElementById('modalSubmit');
const deleteBackdrop = document.getElementById('deleteBackdrop');
const deleteName = document.getElementById('deleteName');
const confirmDelete = document.getElementById('confirmDelete');
const deleteConfirm = document.getElementById('deleteConfirm');
const deleteCancel = document.getElementById('deleteCancel');
const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

// master checkbox
if (chkAll) chkAll.addEventListener('change', ()=> rowChecks().forEach(c=>{ if(!c.disabled) c.checked = chkAll.checked; }));

// open add
if (openAddBtn) openAddBtn.addEventListener('click', ()=>{
    modalAction.value = 'add'; modalId.value = 0; modalName.value=''; modalEmail.value=''; modalClass.value=''; modalBackdrop.classList.add('open'); modalName.focus();
});

// edit
document.querySelectorAll('.link-update').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.id;
    const d = studentMap[id] || {};
    modalAction.value = 'edit';
    modalId.value = id;
    modalName.value = d.FullName || '';
    modalEmail.value = d.Email || '';
    modalClass.value = d.ClassID || '';
    modalBackdrop.classList.add('open');
    modalName.focus();
  });
});

// cancel modal
if (modalCancel) modalCancel.addEventListener('click', ()=> modalBackdrop.classList.remove('open'));

// submit
if (modalForm) modalForm.addEventListener('submit', function(e){
  e.preventDefault();
  modalSubmit.disabled = true;
  modalSubmit.textContent = 'Saving...';
  const payload = { action: modalAction.value, fullname: modalName.value.trim(), email: modalEmail.value.trim(), class_id: modalClass.value.trim() };
  if (modalAction.value === 'edit') payload.id = modalId.value;
  postJSON('api/students.php', payload).then(resp=>{
    modalSubmit.disabled = false; modalSubmit.textContent = 'Save';
    if (resp && resp.ok) { modalBackdrop.classList.remove('open'); showToast('Saved',1200,'success'); setTimeout(()=>location.reload(),600); }
    else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'),2500,'error');
  }).catch(()=>{ modalSubmit.disabled = false; modalSubmit.textContent = 'Save'; showToast('Network error',2500,'error'); });
});

// delete flow
document.querySelectorAll('.link-delete').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.id;
    const d = studentMap[id] || {};
    deleteName.textContent = d.FullName || ('#'+id);
    confirmDelete.value = '';
    deleteConfirm.disabled = true;
    deleteConfirm.dataset.id = id;
    deleteBackdrop.classList.add('open');
    confirmDelete.focus();
  });
});
if (confirmDelete) confirmDelete.addEventListener('input', ()=>{
  deleteConfirm.disabled = (confirmDelete.value !== 'DELETE');
  if (confirmDelete.value.length >= 6 && confirmDelete.value !== 'DELETE') { confirmDelete.classList.add('shake'); setTimeout(()=>confirmDelete.classList.remove('shake'),380); }
});
if (deleteCancel) deleteCancel.addEventListener('click', ()=> deleteBackdrop.classList.remove('open'));
if (deleteConfirm) deleteConfirm.addEventListener('click', ()=>{
  const id = deleteConfirm.dataset.id;
  deleteConfirm.disabled = true; deleteConfirm.textContent = 'Deleting...';
  postJSON('api/students.php', { action: 'delete', id: id }).then(resp=>{
    if (resp && resp.ok) { deleteBackdrop.classList.remove('open'); showToast('Deleted',1200,'success'); setTimeout(()=>location.reload(),600); }
    else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'),2500,'error'); deleteConfirm.disabled = false; deleteConfirm.textContent = 'Delete permanently'; }
  }).catch(()=>{ showToast('Network error',2500,'error'); deleteConfirm.disabled = false; deleteConfirm.textContent = 'Delete permanently'; });
});

// undo
document.querySelectorAll('[data-undo-id]').forEach(btn=> btn.addEventListener('click', ()=>{
  const id = btn.dataset.undoId;
  if (!confirm('Restore this student?')) return;
  postJSON('api/students.php', { action: 'undo', id: id }).then(resp=>{
    if (resp && resp.ok) { showToast('Restored',1200,'success'); setTimeout(()=>location.reload(),600); } else showToast('Error');
  }).catch(()=>showToast('Network error'));
}));

// bulk delete
if (bulkDeleteBtn) bulkDeleteBtn.addEventListener('click', ()=>{
  const selected = rowChecks().filter(c=>c.checked).map(c=>c.value);
  if (!selected.length) return alert('Select rows first');
  if (!confirm('Delete selected students?')) return;
  postJSON('api/students.php', { action: 'bulk_delete', ids: selected }).then(resp=>{
    if (resp && resp.ok) { showToast('Deleted ' + (resp.count||selected.length),1500,'success'); setTimeout(()=>location.reload(),600); } else showToast('Error');
  }).catch(()=>showToast('Network error'));
});

// close modal on backdrop or Esc
document.querySelectorAll('.modal-backdrop').forEach(b=> b.addEventListener('click', e=> { if (e.target === b) b.classList.remove('open'); }));
document.addEventListener('keydown', e=> { if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(b=> b.classList.remove('open')); });
</script>
</body>
</html>
