<?php
// admin/classes.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';
if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

$q = trim($_GET['q'] ?? '');
$page = max(1,(int)($_GET['page'] ?? 1));
$per = (int)($_GET['per'] ?? 10); if ($per<=0) $per=10;
$sort = $_GET['sort'] ?? 'name';
$dir = (strtolower($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
$show_deleted = ($_GET['show_deleted'] ?? '') === '1';

function build_sort_link($col){ $params=$_GET; $current=$params['sort'] ?? 'name'; $cdir=strtolower($params['dir']??'asc'); $params['dir']=($current===$col)?($cdir==='asc'?'desc':'asc'):'asc'; $params['sort']=$col; $params['page']=1; return 'classes.php?'.http_build_query($params); }
function sort_arrow($col){ $c=$_GET['sort'] ?? 'name'; $d=strtolower($_GET['dir'] ?? 'asc'); if($c!==$col) return ''; return $d==='desc'?'↓':'↑'; }

$has_deleted_at=false;
try{ $colCheck=$pdo->query("SHOW COLUMNS FROM class LIKE 'deleted_at'")->fetch(); $has_deleted_at=!empty($colCheck);}catch(Exception $e){$has_deleted_at=false;}

$where = []; $params=[];
if($q!==''){ $where[]="(cl.Class_Name LIKE :q OR cl.Class_Code LIKE :q)"; $params[':q']="%$q%"; }
if($has_deleted_at && !$show_deleted) $where[]="cl.deleted_at IS NULL";
$whereSql = $where? 'WHERE '.implode(' AND ',$where):'';

try{
  $countSql = "SELECT COUNT(DISTINCT cl.ClassID) FROM class cl LEFT JOIN student s ON s.ClassID=cl.ClassID $whereSql";
  $cstmt = $pdo->prepare($countSql);
  foreach($params as $k=>$v) $cstmt->bindValue($k,$v);
  $cstmt->execute(); $total=(int)$cstmt->fetchColumn();
}catch(Exception $e){ $total=0; $errMsg=$e->getMessage(); }

$totalPages = max(1,(int)ceil($total/$per));
if ($page>$totalPages) $page=$totalPages;
$offset = ($page-1)*$per;

try{
  $orderMap=['name'=>'cl.Class_Name','code'=>'cl.Class_Code','students'=>'students'];
  $orderSql = ($orderMap[$sort] ?? $orderMap['name']).' '.$dir;
  $sql = "
    SELECT cl.ClassID, cl.Class_Code, cl.Class_Name, cl.CourseID ".($has_deleted_at?", cl.deleted_at":" , NULL AS deleted_at").",
           COUNT(s.UserID) AS students, c.Course_Name, c.Course_Code
    FROM class cl
    LEFT JOIN course c ON c.CourseID = cl.CourseID
    LEFT JOIN student s ON s.ClassID = cl.ClassID
    $whereSql
    GROUP BY cl.ClassID, cl.Class_Code, cl.Class_Name ".($has_deleted_at?", cl.deleted_at":"").", c.Course_Name, c.Course_Code
    ORDER BY $orderSql
    LIMIT :offset, :limit
  ";
  $stmt = $pdo->prepare($sql);
  foreach($params as $k=>$v) $stmt->bindValue($k,$v);
  $stmt->bindValue(':offset',(int)$offset,PDO::PARAM_INT);
  $stmt->bindValue(':limit',(int)$per,PDO::PARAM_INT);
  $stmt->execute(); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ $rows=[]; $errMsg=$e->getMessage(); }

$map=[]; foreach($rows as $r) $map[(int)$r['ClassID']]=['Class_Code'=>$r['Class_Code'],'Class_Name'=>$r['Class_Name'],'CourseID'=>$r['CourseID'],'Course_Name'=>$r['Course_Name'],'students'=>(int)$r['students'],'deleted_at'=>$r['deleted_at'] ?? null];

$deletedCount=0; if ($has_deleted_at){ try{ $deletedCount=(int)$pdo->query("SELECT COUNT(*) FROM class WHERE deleted_at IS NOT NULL")->fetchColumn(); }catch(Exception $e){ $deletedCount=0; } }

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Classes — Admin</title><link rel="stylesheet" href="../style.css"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--card:#0b1520;--muted:#94a3b8;--text:#e6eef8;}
.center-box{max-width:1100px;margin:0 auto;padding:18px;}
.top-actions{display:flex;align-items:center;gap:12px;padding:10px;background:rgba(255,255,255,0.01);border-radius:10px;margin-bottom:14px;}
.top-actions>div{display:flex;gap:10px;align-items:center;}
.add-btn{background:linear-gradient(180deg,#7c3aed,#6d28d9);padding:10px 16px;border-radius:8px;border:0;color:#fff;font-weight:700;}
.btn{background:#2563eb;color:#fff;padding:8px 14px;border-radius:8px;border:0;}
.btn-danger{background:linear-gradient(180deg,#dc2626,#b91c1c);color:#fff;padding:8px 12px;border-radius:8px;border:0;}
.table-wrap{overflow:auto;} table{width:100%;border-collapse:collapse;margin-top:12px;} th,td{padding:12px;border-top:1px solid rgba(255,255,255,0.03);color:var(--text);} th{color:var(--muted);}
.modal-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,.6);z-index:400;}
.modal-backdrop.open{display:flex;backdrop-filter:blur(6px);}
.modal{background:var(--card);padding:18px;border-radius:10px;width:520px;max-width:96%;}
.toast{position:fixed;right:18px;bottom:18px;background:#0b1520;padding:12px;border-radius:8px;color:var(--text);display:none;}
.toast.show{display:block;}
</style>
</head>
<body>
<main class="admin-main"><div class="admin-container"><div class="center-box">
  <?php if ($flash): ?><div class="toast show"><?= e($flash) ?></div><?php endif; ?>

  <div style="margin-bottom:12px;">
    <form method="get" style="display:flex;gap:8px;align-items:center;">
      <input name="q" placeholder="Search code or name..." value="<?= e($q) ?>">
      <select name="per" onchange="this.form.submit()"><?php foreach([5,10,25,50] as $p): ?><option value="<?= $p ?>" <?= $per==$p?'selected':'' ?>><?= $p ?>/page</option><?php endforeach; ?></select>
      <button class="btn">Search</button><a class="btn" href="classes.php">Clear</a>
    </form>
  </div>

  <div class="top-actions">
    <div class="left-buttons">
      <button id="openAddBtn" class="add-btn">＋ Add Class</button>
      <a class="btn" href="export_classes.php">📤 Export All</a>
      <button id="bulkDeleteBtn" class="btn-danger">🗑 Delete Selected</button>
    </div>
    <div style="margin-left:auto">
      <?php if ($show_deleted): ?>
        <a class="btn" href="classes.php">🟢 Show Active</a> <span style="color:var(--muted);margin-left:8px;">Viewing deleted (<?= (int)$deletedCount ?>)</span>
      <?php else: ?>
        <a class="btn" href="classes.php?show_deleted=1" style="background:#ef4444">🔴 Show Deleted <?= $deletedCount? "($deletedCount)":'' ?></a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2 style="color:#cfe8ff;margin:0 0 8px 0;">Class List</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th><input id="chkAll" type="checkbox"></th><th>Code</th><th>Name</th><th>Course</th><th style="text-align:right">Students</th><th>Action</th></tr></thead>
        <tbody>
          <?php if (empty($rows)): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px;">No classes found.</td></tr>
          <?php else: foreach($rows as $r): $isDeleted = !empty($r['deleted_at']); ?>
            <tr>
              <td><input class="row-chk" type="checkbox" value="<?= e($r['ClassID']) ?>" <?= $isDeleted ? 'disabled' : '' ?>></td>
              <td><strong><?= e($r['Class_Code']) ?></strong></td>
              <td><?= e($r['Class_Name']) ?><?php if($isDeleted): ?><div style="color:var(--muted);font-size:.9rem">Deleted at <?= e($r['deleted_at']) ?></div><?php endif; ?></td>
              <td><?= e($r['Course_Name'] ?? '-') ?></td>
              <td style="text-align:right"><?= e((int)$r['students']) ?></td>
              <td>
                <?php if (!$isDeleted): ?><button class="link-update" data-id="<?= e($r['ClassID']) ?>">Update</button><?php else: ?><button class="btn" data-undo-id="<?= e($r['ClassID']) ?>">Undo</button><?php endif; ?>
                <button class="link-delete" data-id="<?= e($r['ClassID']) ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
      <div>
        <?php
        $base = [];
        if ($q!=='') $base['q']=$q; if ($per!==10) $base['per']=$per; if ($show_deleted) $base['show_deleted']=1;
        $base['sort']=$sort; $base['dir']=strtolower($dir)==='desc'?'desc':'asc';
        for($p=1;$p<=max(1,$totalPages);$p++){ $base['page']=$p; $u='classes.php?'.http_build_query($base); $cls=$p==$page?'style="font-weight:700;color:#fff;margin-right:6px;"':'style="margin-right:6px;color:var(--muted)"'; echo "<a $cls href=\"".e($u)."\">$p</a>"; }
        ?>
      </div>
      <div style="color:var(--muted)">Page <?= $page ?> of <?= $totalPages ?></div>
    </div>

  </div>
</div></div></main>

<!-- modals similar to courses -->
<div id="modalBackdrop" class="modal-backdrop"><div class="modal">
  <h3>Add Class</h3>
  <form id="modalForm">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" id="modalAction" value="add">
    <input type="hidden" name="id" id="modalId" value="0">
    <div><label>Code</label><input id="modal_code" name="class_code" required style="width:100%;padding:8px"></div>
    <div><label>Name</label><input id="modal_name" name="class_name" required style="width:100%;padding:8px"></div>
    <div><label>Course ID</label><input id="modal_course" name="course_id" style="width:100%;padding:8px"></div>
    <div style="text-align:right;margin-top:10px;"><button type="button" id="modalCancel">Cancel</button><button id="modalSubmit" class="btn" type="submit">Save</button></div>
  </form>
</div></div>

<div id="deleteBackdrop" class="modal-backdrop"><div class="modal">
  <h3>Confirm Delete</h3>
  <p>Delete <strong id="delName"></strong></p>
  <p style="color:#f87171">Type <code>DELETE</code> to confirm.</p>
  <input id="confirmDelete" placeholder="Type DELETE here" style="width:100%;padding:8px">
  <div style="text-align:right;margin-top:12px;"><button id="deleteCancel">Cancel</button><button id="deleteConfirm" class="btn-danger" disabled>Delete</button></div>
</div></div>

<div id="toast" class="toast"></div>

<script>
// reuse similar JS as courses but target api/classes.php
const csrf = <?= json_encode($csrf) ?>;
const map = <?= json_encode($map) ?>;
function postJSON(url,data){ const fd=new FormData(); for(const k in data){ if(Array.isArray(data[k])) data[k].forEach(v=>fd.append(k+'[]',v)); else fd.append(k,data[k]); } fd.append('csrf_token',csrf); return fetch(url,{method:'POST',body:fd}).then(r=>r.json()); }
function showToast(m){ const t=document.getElementById('toast'); t.textContent=m; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),2000); }

if (document.getElementById('chkAll')) document.getElementById('chkAll').addEventListener('change', ()=> document.querySelectorAll('.row-chk').forEach(c=>{ if(!c.disabled) c.checked=document.getElementById('chkAll').checked; }));

// modal
const openAddBtn = document.getElementById('openAddBtn');
const modalBackdrop = document.getElementById('modalBackdrop');
const modalForm = document.getElementById('modalForm');
const modalAction = document.getElementById('modalAction');
const modalId = document.getElementById('modalId');
const modalCode = document.getElementById('modal_code');
const modalName = document.getElementById('modal_name');
const modalCourse = document.getElementById('modal_course');
const modalCancel = document.getElementById('modalCancel');
const modalSubmit = document.getElementById('modalSubmit');

if (openAddBtn) openAddBtn.addEventListener('click', ()=>{ modalAction.value='add'; modalId.value=0; modalCode.value=''; modalName.value=''; modalCourse.value=''; modalBackdrop.classList.add('open'); modalCode.focus(); });
document.querySelectorAll('.link-update').forEach(btn=> btn.addEventListener('click', ()=>{ const id=btn.dataset.id; const d=map[id]||{}; modalAction.value='edit'; modalId.value=id; modalCode.value=d.Class_Code||''; modalName.value=d.Class_Name||''; modalCourse.value=d.CourseID||''; modalBackdrop.classList.add('open'); modalCode.focus(); }));
if (modalCancel) modalCancel.addEventListener('click', ()=> modalBackdrop.classList.remove('open'));
if (modalForm) modalForm.addEventListener('submit', e=>{ e.preventDefault(); modalSubmit.disabled=true; modalSubmit.textContent='Saving...'; const payload={ action: modalAction.value, class_code: modalCode.value.trim(), class_name: modalName.value.trim(), course_id: modalCourse.value.trim() }; if (modalAction.value==='edit') payload.id=modalId.value; postJSON('api/classes.php', payload).then(resp=>{ modalSubmit.disabled=false; modalSubmit.textContent='Save'; if(resp && resp.ok){ modalBackdrop.classList.remove('open'); showToast('Saved'); setTimeout(()=>location.reload(),600); } else showToast('Error'); }).catch(()=>{ modalSubmit.disabled=false; modalSubmit.textContent='Save'; showToast('Network error'); }); });

// delete
let deleteId = null;
document.querySelectorAll('.link-delete').forEach(btn=> btn.addEventListener('click', ()=>{ deleteId = btn.dataset.id; document.getElementById('delName').textContent = (map[deleteId] && map[deleteId].Class_Name) ? map[deleteId].Class_Name : ('#'+deleteId); document.getElementById('confirmDelete').value=''; document.getElementById('deleteConfirm').disabled=true; document.getElementById('deleteBackdrop').
