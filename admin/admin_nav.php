<?php
// admin/admin_nav.php
// Place this file at /admin/admin_nav.php
// (Expect config.php already included and require_admin() called by caller)

// determine current file name for active link highlighting
$currentFile = basename($_SERVER['PHP_SELF']);

// inbox unread count (reads ../messages.txt). Replace with DB if you use DB for messages.
$messagesFile = __DIR__ . '/../messages.txt';
$inboxCount = 0;
if (file_exists($messagesFile) && is_readable($messagesFile)) {
    $contents = trim(file_get_contents($messagesFile));
    if ($contents !== '') {
        preg_match_all('/^---\s*$/m', $contents, $matches);
        $sepCount = count($matches[0]);
        $inboxCount = max(1, $sepCount);
    }
}

// safe echo helper if not provided by config.php
if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
<link rel="stylesheet" href="../style.css">

<header class="admin-topbar">
  <div class="tb-left">
    <button id="adminSidebarToggle" class="sb-toggle" aria-label="Toggle sidebar">&#9776;</button>
    <a class="brand" href="index.php" title="Admin Dashboard">
      <img src="../img/favicon.png" alt="GMI" class="nav-logo-small">
      <span class="brand-text">GMI Admin</span>
    </a>
    <a class="tb-link <?= $currentFile === 'index.php' ? 'active-top' : '' ?>" href="index.php">Dashboard</a>
  </div>

  <div class="tb-right">
    <a class="tb-link <?= $currentFile === 'inbox.php' ? 'active-top' : '' ?>" href="inbox.php">
      Inbox <?php if ($inboxCount > 0): ?><span class="badge"><?= e($inboxCount) ?></span><?php endif; ?>
    </a>
    <a class="tb-link" href="../logout.php">Log Out</a>
  </div>
</header>

<nav id="adminSidebar" class="admin-sidebar" aria-label="Admin menu">
  <div class="sidebar-inner">
    <div class="sidebar-section">
      <div class="sidebar-title">DASHBOARD</div>
      <a class="sidebar-link <?= ($currentFile === 'index.php') ? 'active' : '' ?>" href="index.php">Overview</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-title">MANAGE</div>
      <a class="sidebar-link <?= ($currentFile === 'departments.php') ? 'active' : '' ?>" href="departments.php">Departments</a>
      <a class="sidebar-link <?= ($currentFile === 'courses.php') ? 'active' : '' ?>" href="courses.php">Courses</a>
      <a class="sidebar-link <?= ($currentFile === 'classes.php' || $currentFile === 'clasess.php') ? 'active' : '' ?>" href="classes.php">Classes</a>
      <a class="sidebar-link <?= ($currentFile === 'students_manage.php' || $currentFile === 'students.php') ? 'active' : '' ?>" href="students_manage.php">Students</a>
    </div>
  </div>
</nav>

<style>
/* ---------- Admin topbar & sidebar styles ---------- */
.admin-topbar{
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:10px 16px;background:#0f172a;color:#e6eef8;border-bottom:1px solid rgba(255,255,255,0.03);
  position:sticky;top:0;z-index:120;height:56px;box-sizing:border-box;
}
.brand{display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;}
.nav-logo-small{width:34px;height:34px;border-radius:6px;}
.brand-text{font-weight:700;color:#38bdf8;}
.tb-link{color:#cbd5e1;text-decoration:none;padding:6px 10px;border-radius:6px;position:relative;}
.tb-link:hover{background:rgba(255,255,255,0.02);color:#fff;}
.tb-link.active-top{ color:#fff; box-shadow: inset 0 -3px 0 #38bdf8; }

.badge{display:inline-block;min-width:20px;padding:2px 7px;border-radius:999px;background:#ef4444;color:#fff;font-weight:700;margin-left:8px;font-size:.85rem;}

/* sidebar */
.admin-sidebar{
  width:240px;position:fixed;left:0;top:56px;bottom:0;background:#071026;color:#e6eef8;
  border-right:1px solid rgba(255,255,255,0.03);padding:14px 10px;overflow:auto;
  transform: translateX(0);transition: transform 240ms cubic-bezier(.2,.9,.2,1), box-shadow 200ms ease;z-index:110;
}
.admin-sidebar.hidden { transform: translateX(-110%); }
.admin-sidebar.open { transform: translateX(0); box-shadow: 4px 0 24px rgba(2,6,23,0.6); }

@keyframes adminSlideIn { from{ transform:translateX(-110%); opacity:0 } to{ transform:translateX(0); opacity:1 } }
@keyframes adminSlideOut { from{ transform:translateX(0); opacity:1 } to{ transform:translateX(-110%); opacity:0 } }
.admin-sidebar.open { animation: adminSlideIn 220ms ease both; }
.admin-sidebar.closing { animation: adminSlideOut 180ms ease both; }

/* links */
.sidebar-inner{ display:flex; flex-direction:column; gap:18px; }
.sidebar-title{ font-size:.85rem; color:#94a3b8; font-weight:700; margin-bottom:8px; padding-left:8px; }
.sidebar-link{ display:block; padding:10px 12px; color:#cbd5e1; text-decoration:none; border-radius:8px; margin-bottom:6px; transition: background .18s, color .18s; }
.sidebar-link:hover{ background:#0b1724; color:#facc15; }
.sidebar-link.active{ background: linear-gradient(90deg, rgba(56,189,248,0.12), rgba(255,255,255,0.02)); color:#38bdf8; font-weight:800; }

/* main content shifting - use <main class="admin-main"> ... </main> */
body.has-admin-sidebar .admin-main {
  margin-left: 240px;
  transition: margin-left 240ms cubic-bezier(.2,.9,.2,1);
  padding: 24px 28px;
  box-sizing: border-box;
}
body:not(.has-admin-sidebar) .admin-main {
  margin-left: 0;
  transition: margin-left 240ms cubic-bezier(.2,.9,.2,1);
  padding: 24px 28px;
}
.admin-main{ padding-top:20px; min-height: calc(100vh - 56px); }

@media (max-width:900px){
  body.has-admin-sidebar .admin-main, body:not(.has-admin-sidebar) .admin-main { margin-left:0; padding:18px; }
  .admin-sidebar{ top:48px; width:220px; transform: translateX(-110%); position:fixed; }
}

.sb-toggle{ background:transparent;border:0;color:#cbd5e1;font-size:20px;cursor:pointer;padding:6px;border-radius:6px; }
.sb-toggle:hover{ background:rgba(255,255,255,0.02); }
.flash{ padding:10px 12px; border-radius:8px; margin-bottom:12px; background:#0ea5e9; color:#012; }
</style>

<script>
(function(){
  const sidebar = document.getElementById('adminSidebar');
  const toggle = document.getElementById('adminSidebarToggle');
  if (!sidebar || !toggle) return;

  function isMobile(){ return window.matchMedia('(max-width:900px)').matches; }

  // initial state
  if (isMobile()) {
    document.body.classList.remove('has-admin-sidebar');
    sidebar.classList.remove('open','hidden');
  } else {
    document.body.classList.add('has-admin-sidebar');
    sidebar.classList.remove('hidden','open','closing');
  }

  toggle.addEventListener('click', function(){
    if (isMobile()) {
      if (sidebar.classList.contains('open')) {
        sidebar.classList.remove('open'); sidebar.classList.add('closing');
        setTimeout(()=> sidebar.classList.remove('closing'), 260);
      } else {
        sidebar.classList.add('open');
      }
    } else {
      if (document.body.classList.contains('has-admin-sidebar')) {
        document.body.classList.remove('has-admin-sidebar'); sidebar.classList.add('hidden');
      } else {
        document.body.classList.add('has-admin-sidebar'); sidebar.classList.remove('hidden');
      }
    }
  });

  // close overlay on outside click (mobile)
  document.addEventListener('click', function(e){
    if (!isMobile()) return;
    if (!sidebar.classList.contains('open')) return;
    if (!sidebar.contains(e.target) && e.target !== toggle) {
      sidebar.classList.remove('open'); sidebar.classList.add('closing');
      setTimeout(()=> sidebar.classList.remove('closing'), 260);
    }
  });

  // handle resize
  window.addEventListener('resize', function(){
    if (isMobile()) {
      document.body.classList.remove('has-admin-sidebar'); sidebar.classList.remove('hidden','open','closing');
    } else {
      document.body.classList.add('has-admin-sidebar'); sidebar.classList.remove('hidden','open','closing');
    }
  });
})();
</script>
