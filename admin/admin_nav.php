<?php
// admin/admin_nav.php
// Sidebar OPEN by default (fits page width perfectly), with GMI logo only.

$currentFile = basename($_SERVER['PHP_SELF']);

// Inbox unread count (reads ../messages.txt)
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

// safe echo helper
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
?>
<link rel="stylesheet" href="../style.css">

<!-- ✅ Topbar -->
<header class="admin-topbar-compact">
  <div class="top-left">
    <button id="adminSidebarToggle" class="sb-toggle" aria-label="Toggle sidebar">&#9776;</button>
    <a class="brand-compact" href="index.php" title="Admin Dashboard">
      <img src="../img/favicon.png" alt="GMI Logo" class="nav-logo-compact">
    </a>
  </div>

  <div class="top-right">
    <a class="top-link <?= $currentFile === 'inbox.php' ? 'active' : '' ?>" href="inbox.php">
      Inbox <?php if ($inboxCount > 0): ?><span class="badge-inline"><?= e($inboxCount) ?></span><?php endif; ?>
    </a>
    <a class="top-link" href="../logout.php">Log Out</a>
  </div>
</header>

<!-- ✅ Sidebar -->
<nav id="adminSidebar" class="admin-sidebar-compact" aria-label="Admin menu">
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
/* ---------- Compact admin topbar + sidebar ---------- */
.admin-topbar-compact{
  display:flex;align-items:center;justify-content:space-between;
  padding:8px 14px;background:#0f172a;color:#e6eef8;
  border-bottom:1px solid rgba(255,255,255,0.03);
  position:sticky;top:0;z-index:120;height:56px;box-sizing:border-box;
}

.top-left{display:flex;align-items:center;gap:12px;}
.brand-compact{display:flex;align-items:center;text-decoration:none;color:inherit;}
.nav-logo-compact{width:42px;height:42px;border-radius:8px;}

.top-link{color:#cbd5e1;text-decoration:none;padding:6px 8px;border-radius:6px;font-size:0.95rem;}
.top-link:hover{color:#fff;background:rgba(255,255,255,0.05);}
.top-link.active{color:#38bdf8;border-bottom:2px solid #38bdf8;}

.badge-inline{
  background:#ef4444;color:#fff;border-radius:999px;padding:2px 7px;
  font-weight:700;font-size:0.8rem;margin-left:8px;display:inline-block;
}

/* Sidebar */
.admin-sidebar-compact{
  width:240px;position:fixed;left:0;top:56px;bottom:0;
  background:#071026;color:#e6eef8;border-right:1px solid rgba(255,255,255,0.03);
  padding:12px 10px;overflow:auto;transform:translateX(0);
  transition:transform 200ms ease,box-shadow 200ms ease;z-index:110;
}
.admin-sidebar-compact.hidden{transform:translateX(-110%);}
.admin-sidebar-compact.open{transform:translateX(0);box-shadow:4px 0 24px rgba(2,6,23,0.6);}

/* Sidebar links */
.sidebar-inner{display:flex;flex-direction:column;gap:14px;}
.sidebar-title{font-size:.85rem;color:#94a3b8;font-weight:700;margin-bottom:8px;padding-left:6px;}
.sidebar-link{display:block;padding:8px 10px;color:#cbd5e1;text-decoration:none;border-radius:8px;margin-bottom:6px;transition:background .15s,color .15s;}
.sidebar-link:hover{background:#0b1724;color:#facc15;}
.sidebar-link.active{background:linear-gradient(90deg,rgba(56,189,248,0.10),rgba(255,255,255,0.01));color:#38bdf8;font-weight:700;}

/* ✅ Main content properly aligned next to sidebar */
body.has-admin-sidebar .admin-main {
  margin-left:240px; /* perfectly fits beside sidebar */
  transition:margin-left 200ms ease;
  padding:20px;
  box-sizing:border-box;
}
body:not(.has-admin-sidebar) .admin-main {
  margin-left:0;
  transition:margin-left 200ms ease;
  padding:20px;
}
.admin-main{min-height:calc(100vh - 56px);}

/* mobile adjustments */
@media (max-width:900px){
  .admin-sidebar-compact{top:48px;width:220px;transform:translateX(-110%);position:fixed;}
  body.has-admin-sidebar .admin-main,body:not(.has-admin-sidebar) .admin-main{margin-left:0;padding:12px;}
}

/* small helpers */
.sb-toggle{background:transparent;border:0;color:#cbd5e1;font-size:22px;cursor:pointer;padding:6px;border-radius:6px;}
.sb-toggle:hover{background:rgba(255,255,255,0.1);}
.flash{padding:10px 12px;border-radius:8px;margin-bottom:12px;background:#0ea5e9;color:#012;}
</style>

<script>
(function(){
  const sidebar = document.getElementById('adminSidebar');
  const toggle = document.getElementById('adminSidebarToggle');
  if (!sidebar || !toggle) return;
  function isMobile(){ return window.matchMedia('(max-width:900px)').matches; }

  // ✅ Sidebar OPEN by default for all admin pages
  if (isMobile()) {
    // On mobile, start closed for overlay
    document.body.classList.remove('has-admin-sidebar');
    sidebar.classList.remove('open');
    sidebar.classList.add('hidden');
  } else {
    // On desktop, always open + page fits beside sidebar
    document.body.classList.add('has-admin-sidebar');
    sidebar.classList.remove('hidden');
  }

  toggle.addEventListener('click', function(){
    if (isMobile()) {
      sidebar.classList.toggle('open');
      if (sidebar.classList.contains('open')) sidebar.classList.remove('hidden');
    } else {
      if (document.body.classList.contains('has-admin-sidebar')) {
        document.body.classList.remove('has-admin-sidebar');
        sidebar.classList.add('hidden');
      } else {
        document.body.classList.add('has-admin-sidebar');
        sidebar.classList.remove('hidden');
      }
    }
  });

  // click outside to close (mobile)
  document.addEventListener('click', function(e){
    if (!isMobile()) return;
    if (!sidebar.classList.contains('open')) return;
    if (!sidebar.contains(e.target) && e.target !== toggle) {
      sidebar.classList.remove('open');
      sidebar.classList.add('hidden');
    }
  });

  // resize consistency
  window.addEventListener('resize', function(){
    if (isMobile()) {
      document.body.classList.remove('has-admin-sidebar');
      sidebar.classList.remove('open');
      sidebar.classList.add('hidden');
    } else {
      document.body.classList.add('has-admin-sidebar');
      sidebar.classList.remove('hidden','open');
    }
  });
})();
</script>
