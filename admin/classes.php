<?php
// admin/classes.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

// helper to choose best matching column name from SHOW COLUMNS
function pick_col(array $cols, array $candidates, $default = null) {
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return $default;
}

// discover class table columns
$classCols = [];
try {
    $classCols = $pdo->query("SHOW COLUMNS FROM `class`")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {
    $classCols = [];
    $errMsg = "DB error reading `class` columns: " . $ex->getMessage();
}

// discover course table columns
$courseCols = [];
try {
    $courseCols = $pdo->query("SHOW COLUMNS FROM `course`")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {
    $courseCols = [];
    // don't overwrite existing $errMsg if present
    if (!isset($errMsg)) $errMsg = "DB error reading `course` columns: " . $ex->getMessage();
}

// discover student table columns (for COUNT)
$studentCols = [];
try {
    $studentCols = $pdo->query("SHOW COLUMNS FROM `student`")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {
    $studentCols = [];
    if (!isset($errMsg)) $errMsg = "DB error reading `student` columns: " . $ex->getMessage();
}

// pick sensible column names (candidates list covers many variants)
$class_id_col   = pick_col($classCols,   ['ClassID','class_id','id','ID'], 'ClassID');
$class_code_col = pick_col($classCols,   ['Class_Code','ClassCode','class_code','Code','code'], null);
$class_name_col = pick_col($classCols,   ['Class_Name','ClassName','class_name','Name','name'], 'Class_Name');

$course_id_col   = pick_col($courseCols, ['CourseID','course_id','id','ID'], 'CourseID');
$course_name_col = pick_col($courseCols, ['Course_Name','CourseName','course_name','Name','name'], 'Course_Name');
$course_code_col = pick_col($courseCols, ['Course_Code','CourseCode','course_code','Code','code'], null);

$student_id_col = pick_col($studentCols, ['UserID','Student_ID','StudentID','id','ID'], 'UserID');

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

// detect deleted_at column on class
$has_deleted_at = false;
try {
    $has_deleted_at = in_array('deleted_at', $classCols);
} catch (Exception $e) {
    $has_deleted_at = false;
}

// WHERE clause building
$where = [];
$params = [];
if ($q !== '') {
    $qLike = "%$q%";
    $parts = [];
    if ($class_name_col) $parts[] = "cl.`{$class_name_col}` LIKE :q";
    if ($class_code_col) $parts[] = "cl.`{$class_code_col}` LIKE :q";
    if ($course_name_col) $parts[] = "c.`{$course_name_col}` LIKE :q";
    if (!empty($parts)) {
        $where[] = '(' . implode(' OR ', $parts) . ')';
        $params[':q'] = $qLike;
    }
}
if ($has_deleted_at && !$show_deleted) {
    $where[] = "cl.`deleted_at` IS NULL";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// count total (use DISTINCT on class id)
try {
    $countSql = "
      SELECT COUNT(DISTINCT cl.`{$class_id_col}`) AS cnt
      FROM `class` cl
      LEFT JOIN `student` s ON s.`ClassID` = cl.`{$class_id_col}`
      LEFT JOIN `course` c ON c.`{$course_id_col}` = cl.`{$course_id_col}`
      $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k=>$v) $countStmt->bindValue($k,$v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $total = 0;
    $errMsg = $e->getMessage();
}

// pagination
$totalPages = max(1, (int)ceil($total / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page -1) * $per;

// build SELECT carefully using detected column names (alias to stable names)
$select = [];
$select[] = "cl.`{$class_id_col}` AS ClassID";
if ($class_code_col) $select[] = "cl.`{$class_code_col}` AS Class_Code"; else $select[] = "NULL AS Class_Code";
if ($class_name_col) $select[] = "cl.`{$class_name_col}` AS Class_Name"; else $select[] = "NULL AS Class_Name";

if ($course_id_col) $select[] = "cl.`{$course_id_col}` AS CourseID"; else $select[] = "NULL AS CourseID";
if ($course_name_col) $select[] = ($course_name_col ? "c.`{$course_name_col}` AS Course_Name" : "NULL AS Course_Name");
if ($course_code_col) $select[] = ($course_code_col ? "c.`{$course_code_col}` AS Course_Code" : "NULL AS Course_Code");

// student count — use detected student id column if available; fallback to COUNT(*) (safe)
if ($student_id_col && in_array($student_id_col, $studentCols)) {
    $select[] = "COUNT(s.`{$student_id_col}`) AS students";
} else {
    $select[] = "COUNT(s.*) AS students";
}

$selectSql = implode(", ", $select);

// order map (use alias names)
$orderMap = ['name'=>'Class_Name','code'=>'Class_Code','students'=>'students'];
$orderSql = ($orderMap[$sort] ?? $orderMap['name']) . ' ' . $dir;

try {
    // We group by all non-aggregated alias columns we selected (ClassID, Class_Code, Class_Name, CourseID, Course_Name, Course_Code)
    $groupBy = "cl.`{$class_id_col}`";
    if ($class_code_col) $groupBy .= ", cl.`{$class_code_col}`";
    if ($class_name_col) $groupBy .= ", cl.`{$class_name_col}`";
    if ($course_name_col) $groupBy .= ", c.`{$course_name_col}`";
    if ($course_code_col) $groupBy .= ", c.`{$course_code_col}`";
    if ($course_id_col) $groupBy .= ", cl.`{$course_id_col}`";

    $sql = "
      SELECT $selectSql
      FROM `class` cl
      LEFT JOIN `course` c ON c.`{$course_id_col}` = cl.`{$course_id_col}`
      LEFT JOIN `student` s ON s.`ClassID` = cl.`{$class_id_col}`
      $whereSql
      GROUP BY $groupBy
      ORDER BY $orderSql
      LIMIT :offset, :limit
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', (int)$per, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
    $errMsg = $e->getMessage();
}

// prepare JS map
$map = [];
foreach ($rows as $r) {
    $map[(int)$r['ClassID']] = [
        'Class_Code' => $r['Class_Code'] ?? null,
        'Class_Name' => $r['Class_Name'] ?? null,
        'CourseID' => $r['CourseID'] ?? null,
        'Course_Name' => $r['Course_Name'] ?? null,
        'students' => (int)($r['students'] ?? 0),
        'deleted_at' => $r['deleted_at'] ?? null
    ];
}

// deleted count
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
.admin-controls{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;}
.left-controls{display:flex;gap:8px;align-items:center;}
.search-input{padding:8px 10px;border-radius:6px;border:1px solid rgba(255,255,255,0.04);background:var(--card);color:var(--text);}
.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;border:0;cursor:pointer;}
.btn-muted{background:#374151;color:#fff;border-radius:8px;padding:8px 12px;border:0;cursor:pointer;}
.add-btn{background:linear-gradient(180deg,var(--accent1),var(--accent2));color:#fff;padding:10px 16px;border-radius:8px;font-weight:700;border:0;cursor:pointer;}
.card{background:var(--card);border-radius:10px;padding:18px;box-shadow:0 6px 18px rgba(0,0,0,0.4);}
.table-wrap{overflow:auto;}
table{width:100%;border-collapse:collapse;margin-top:12px;}
th,td{padding:12px;border-top:1px solid rgba(255,255,255,0.03);color:var(--text);vertical-align:middle;}
th{color:var(--muted);text-align:left;font-weight:700;}
.actions-inline{display:flex;gap:12px;align-items:center;justify-content:flex-end;}
.link-update{color:var(--ok);background:none;border:0;padding:0;cursor:pointer;font-weight:700;text-decoration:none;font-size:.95rem;}
.link-delete{color:var(--danger);background:none;border:0;padding:0;cursor:pointer;font-weight:700;text-decoration:none;font-size:.95rem;}
.checkbox-col{width:36px;text-align:center;}
.row-hover:hover td{background:rgba(255,255,255,0.01);}

/* modal + delete */
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.6);display:none;align-items:center;justify-content:center;z-index:400;}
.modal-backdrop.open{display:flex;backdrop-filter: blur(6px);background: rgba(2,6,23,0.5);}
.modal{background:var(--card);border-radius:10px;padding:20px;width:520px;max-width:96%;color:var(--text);line-height:1.45;}
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
              <a href="classes.php" class="btn-muted" style="text-decoration:none;">Clear</a>
            </div>
          </form>
        </div>

        <div style="display:flex;gap:8px;align-items:center;">
          <button id="openAddBtn" class="add-btn">+ Add Class</button>
          <a class="btn" href="api/classes.php?export=1">Export All</a>
          <button id="bulkDeleteBtn" class="btn-muted">Delete Selected</button>
        </div>
      </div>

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
                <th style="width:120px"><a href="<?= e(build_sort_link('code')) ?>">Code <?= e(sort_arrow('code')) ?></a></th>
                <th><a href="<?= e(build_sort_link('name')) ?>">Name <?= e(sort_arrow('name')) ?></a></th>
                <th style="min-width:200px">Course</th>
                <th style="width:80px;text-align:right"><a href="<?= e(build_sort_link('students')) ?>">Students <?= e(sort_arrow('students')) ?></a></th>
                <th style="width:160px">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px;">No classes found.</td></tr>
              <?php else: foreach ($rows as $r): $isDeleted = !empty($r['deleted_at']); ?>
                <tr class="row-hover">
                  <td class="checkbox-col"><input class="row-chk" type="checkbox" value="<?= e($r['ClassID']) ?>" <?= $isDeleted ? 'disabled' : '' ?>></td>
                  <td><strong><?= e($r['Class_Code'] ?? '-') ?></strong></td>
                  <td><?= e($r['Class_Name']) ?><?php if ($isDeleted): ?><div style="color:var(--muted);font-size:.95rem;margin-top:6px;">Deleted at <?= e($r['deleted_at']) ?></div><?php endif; ?></td>
                  <td><?= e($r['Course_Name'] ?? '-') ?></td>
                  <td style="text-align:right"><?= e((int)$r['students']) ?></td>
                  <td>
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
            if ($show_deleted) $baseParams['show_deleted'] = 1;
            $baseParams['sort'] = $sort;
            $baseParams['dir'] = strtolower($dir) === 'desc' ? 'desc' : 'asc';
            for ($p=1; $p <= max(1, $totalPages); $p++) {
                $baseParams['page'] = $p;
                $u = 'classes.php?' . http_build_query($baseParams);
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
    <h3 id="modalTitle">Add Class</h3>
    <form id="modalForm">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" id="modalAction" value="add">
      <input type="hidden" name="id" id="modalId" value="0">

      <div class="form-field">
        <label for="modal_code">Code</label>
        <input id="modal_code" name="class_code" type="text" required style="padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)">
      </div>

      <div class="form-field">
        <label for="modal_name">Name</label>
        <input id="modal_name" name="class_name" type="text" required style="padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)">
      </div>

      <div class="form-field">
        <label for="modal_course">Course ID</label>
        <input id="modal_course" name="course_id" type="text" placeholder="optional" style="padding:8px;border-radius:6px;background:#071026;border:1px solid rgba(255,255,255,0.04);color:var(--text)">
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
function showToast(msg, timeout=2200) { const s = document.getElementById('toast'); s.textContent = msg; s.classList.add('show'); setTimeout(()=> s.classList.remove('show'), timeout); }

// check/uncheck all
if (document.getElementById('chkAll')) document.getElementById('chkAll').addEventListener('change', ()=> document.querySelectorAll('.row-chk').forEach(c=>{ if(!c.disabled) c.checked = document.getElementById('chkAll').checked; }));

// open add modal
if (document.getElementById('openAddBtn')) document.getElementById('openAddBtn').addEventListener('click', ()=> {
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalId').value = 0;
    document.getElementById('modal_code').value = '';
    document.getElementById('modal_name').value = '';
    document.getElementById('modal_course').value = '';
    document.getElementById('modalBackdrop').classList.add('open');
    document.getElementById('modal_code').focus();
});

// edit
document.querySelectorAll('.link-update').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.id; const d = classMap[id] || {};
        document.getElementById('modalAction').value = 'edit';
        document.getElementById('modalId').value = id;
        document.getElementById('modal_code').value = d.Class_Code || '';
        document.getElementById('modal_name').value = d.Class_Name || '';
        document.getElementById('modal_course').value = d.CourseID || '';
        document.getElementById('modalBackdrop').classList.add('open');
        document.getElementById('modal_code').focus();
    });
});

// cancel modal
if (document.getElementById('modalCancel')) document.getElementById('modalCancel').addEventListener('click', ()=> document.getElementById('modalBackdrop').classList.remove('open'));

// submit add/edit
if (document.getElementById('modalForm')) document.getElementById('modalForm').addEventListener('submit', function(e){
    e.preventDefault();
    const act = document.getElementById('modalAction').value;
    const id = document.getElementById('modalId').value;
    const code = document.getElementById('modal_code').value.trim();
    const name = document.getElementById('modal_name').value.trim();
    const course = document.getElementById('modal_course').value.trim();
    const btn = document.getElementById('modalSubmit');
    btn.disabled = true; btn.textContent = 'Saving...';
    const payload = { action: act, class_code: code, class_name: name, course_id: course || '' };
    if (act === 'edit') payload.id = id;
    postJSON('api/classes.php', payload).then(resp=>{
        btn.disabled = false; btn.textContent = 'Save';
        if (resp && resp.ok) { document.getElementById('modalBackdrop').classList.remove('open'); showToast('Saved'); setTimeout(()=>location.reload(), 600); }
        else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
    }).catch(()=>{ btn.disabled=false; btn.textContent='Save'; showToast('Network error'); });
});

// delete flow
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
if (document.getElementById('confirmDelete')) document.getElementById('confirmDelete').addEventListener('input', ()=> {
    document.getElementById('deleteConfirm').disabled = (document.getElementById('confirmDelete').value !== 'DELETE');
});
if (document.getElementById('deleteCancel')) document.getElementById('deleteCancel').addEventListener('click', ()=> document.getElementById('deleteBackdrop').classList.remove('open'));
if (document.getElementById('deleteConfirm')) document.getElementById('deleteConfirm').addEventListener('click', ()=> {
    const id = document.getElementById('deleteConfirm').dataset.id;
    const btn = document.getElementById('deleteConfirm'); btn.disabled = true; btn.textContent = 'Deleting...';
    postJSON('api/classes.php', { action: 'delete', id: id }).then(resp=>{
        if (resp && resp.ok) { document.getElementById('deleteBackdrop').classList.remove('open'); showToast('Deleted'); setTimeout(()=>location.reload(),600); }
        else { showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown')); btn.disabled=false; btn.textContent='Delete permanently'; }
    }).catch(()=>{ showToast('Network error'); btn.disabled=false; btn.textContent='Delete permanently'; });
});

// undo
document.querySelectorAll('[data-undo-id]').forEach(btn=>{
    btn.addEventListener('click', ()=> {
        const id = btn.dataset.undoId;
        postJSON('api/classes.php', { action: 'undo', id: id }).then(resp=>{
            if (resp && resp.ok) { showToast('Restored'); setTimeout(()=>location.reload(),600); }
            else showToast('Error: ' + (resp && resp.error ? resp.error : 'Unknown'));
        }).catch(()=>showToast('Network error'));
    });
});

// bulk delete
if (document.getElementById('bulkDeleteBtn')) document.getElementById('bulkDeleteBtn').addEventListener('click', ()=> {
    const selected = Array.from(document.querySelectorAll('.row-chk')).filter(c=>c.checked).map(c=>c.value);
    if (!selected.length) return alert('Select rows first');
    if (!confirm('Delete selected classes?')) return;
    postJSON('api/classes.php', { action: 'bulk_delete', ids: selected }).then(resp=>{
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
