<?php
// admin/admin_nav.php
// Sidebar + topbar with robust profile avatar loader.

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

// ---- Load current admin profile for avatar / initials ----
$adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? $_SESSION['UserID'] ?? null;
$profileImage = null;
$displayName = null;
if ($adminId && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT Display_Name, Profile_Image, Email FROM `user` WHERE `UserID` = :id LIMIT 1");
        $stmt->execute(['id' => $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $profileImage = $row['Profile_Image'] ?? null;
            $displayName = $row['Display_Name'] ?? $row['Email'] ?? null;
        }
    } catch (Throwable $t) {
        // ignore DB errors — avatar will fall back to initials
    }
}

// initials helper
function initials_for_nav($name) {
    $name = trim((string)$name);
    if ($name === '') return 'A';
    $parts = preg_split('/\s+/', $name);
    if (count($parts) >= 2) return strtoupper(substr($parts[0],0,1) . substr($parts[1],0,1));
    // fallback: first two alnum chars
    $s = preg_replace('/[^A-Za-z0-9]/','', $parts[0]);
    return strtoupper(substr($s, 0, 2) ?: 'A');
}

/**
 * Robust avatar resolver.
 * Returns a web-accessible path (string) or null if not found.
 */
function resolve_avatar_url($stored) {
    if (!$stored) return null; // IMPORTANT: early return for empty values

    $s = str_replace('\\','/', trim((string)$stored));
    // If it's an absolute web path (starts with '/'), prefer it if file exists under DOCUMENT_ROOT
    if (strpos($s, '/') === 0) {
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        if ($docRoot) {
            $candidate = realpath($docRoot . $s);
            if ($candidate && is_file($candidate)) {
                return $s;
            }
        } else {
            return $s;
        }
    }

    // Candidate 1: project relative path from admin folder (common case: 'uploads/file.jpg')
    $cand1 = realpath(__DIR__ . '/../' . $s);
    if ($cand1 && is_file($cand1)) {
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        if ($docRoot && strpos($cand1, $docRoot) === 0) {
            $web = str_replace('\\','/', substr($cand1, strlen($docRoot)) );
            if ($web === '' || $web[0] !== '/') $web = '/' . $web;
            return $web;
        }
        return '../' . ltrim($s, '/');
    }

    // Candidate 2: docroot relative (if stored like 'uploads/file.jpg' but docroot contains it)
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($docRoot) {
        $cand2 = realpath($docRoot . '/' . ltrim($s, '/'));
        if ($cand2 && is_file($cand2)) {
            $web = str_replace('\\','/', substr($cand2, strlen($docRoot)) );
            if ($web === '' || $web[0] !== '/') $web = '/' . $web;
            return $web;
        }
    }

    // Candidate 3: admin-relative fallback (../uploads/...)
    if (file_exists(__DIR__ . '/../' . ltrim($s, '/'))) {
        return '../' . ltrim($s, '/');
    }

    return null;
}

$avatarUrl = resolve_avatar_url($profileImage);

// Build clearer debug only — but avoid misleading file_exists checks when profileImage is empty
$avatarDebug = [
    'profileImage_field' => $profileImage,
    'avatarUrl' => $avatarUrl,
    'candidate_abs' => $profileImage ? @realpath(__DIR__ . '/../' . ltrim((string)$profileImage, '/')) : null,
    'docroot' => isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : null,
    'exists_project_path' => $profileImage ? @file_exists(__DIR__ . '/../' . ltrim((string)$profileImage, '/')) : false
];
?>
<link rel="stylesheet" href="../style.css">
<link rel="icon" href="../img/favicon.png" type="image/png">
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

    <!-- ✅ Profile avatar (uses resolve_avatar_url) -->
    <a href="settings.php" class="profile-avatar" title="View Profile / Settings" aria-label="Profile">
      <?php if ($avatarUrl): ?>
        <img src="<?= e($avatarUrl) ?>" alt="Profile" onerror="this.style.display='none'; this.closest('.profile-avatar').classList.add('no-img');">
        <span style="display:none"><?= e(initials_for_nav($displayName ?? ($_SESSION['admin_email'] ?? 'A'))) ?></span>
      <?php else: ?>
        <span><?= e(initials_for_nav($displayName ?? ($_SESSION['admin_email'] ?? 'A'))) ?></span>
      <?php endif; ?>
    </a>
  </div>
</header>

<!-- Avatar debug (remove if desired) -->
<!-- Avatar debug: <?= e(json_encode($avatarDebug)) ?> -->

<!-- ✅ Sidebar -->
<nav id="adminSidebar" class="admin-sidebar-compact" aria-label="Admin menu">
  <div class="sidebar-inner">
    <div class="sidebar-section">
      <div class="sidebar-title">DASHBOARD</div>
      <a class="sidebar-link <?= ($currentFile === 'index.php') ? 'active' : '' ?>" href="index.php">
        <span class="icon" aria-hidden>🏠</span> <span class="link-text">Overview</span>
      </a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-title">MANAGE</div>

      <a class="sidebar-link <?= ($currentFile === 'departments.php') ? 'active' : '' ?>" href="departments.php">
        <span class="icon" aria-hidden>🏢</span> <span class="link-text">Departments</span>
      </a>

      <a class="sidebar-link <?= ($currentFile === 'courses.php') ? 'active' : '' ?>" href="courses.php">
        <span class="icon" aria-hidden>📚</span> <span class="link-text">Courses</span>
      </a>

      <a class="sidebar-link <?= ($currentFile === 'classes.php' || $currentFile === 'clasess.php') ? 'active' : '' ?>" href="classes.php">
        <span class="icon" aria-hidden>🏫</span> <span class="link-text">Classes</span>
      </a>

      <a class="sidebar-link <?= ($currentFile === 'students_manage.php' || $currentFile === 'students.php') ? 'active' : '' ?>" href="students_manage.php">
        <span class="icon" aria-hidden>👩‍🎓</span> <span class="link-text">Students</span>
      </a>

      <a class="sidebar-link <?= ($currentFile === 'inbox.php') ? 'active' : '' ?>" href="inbox.php">
        <span class="icon" aria-hidden>📥</span> <span class="link-text">Inbox</span>
        <?php if ($inboxCount > 0): ?><span class="badge-inline" style="margin-left:auto"><?= e($inboxCount) ?></span><?php endif; ?>
      </a>

      <a class="sidebar-link <?= ($currentFile === 'settings.php') ? 'active' : '' ?>" href="settings.php">
        <span class="icon" aria-hidden>⚙️</span> <span class="link-text">Settings</span>
      </a>

    </div>

    <div class="sidebar-section">
      <div class="sidebar-title">TOOLS</div>
      <a class="sidebar-link" href="reports.php">
        <span class="icon" aria-hidden>📈</span> <span class="link-text">Reports</span>
      </a>
      <a class="sidebar-link" href="export.php">
        <span class="icon" aria-hidden>⬇️</span> <span class="link-text">Export</span>
      </a>
    </div>
  </div>
</nav>

<style>
/* ---------- Compact admin topbar + sidebar (emoji-enhanced links) ---------- */
.admin-topbar-compact{
  display:flex;align-items:center;justify-content:space-between;
  padding:8px 14px;background:#0f172a;color:#e6eef8;
  border-bottom:1px solid rgba(255,255,255,0.03);
  position:sticky;top:0;z-index:120;height:56px;box-sizing:border-box;
}

.top-left{display:flex;align-items:center;gap:12px;}
.brand-compact{display:flex;align-items:center;text-decoration:none;color:inherit;}
.nav-logo-compact{width:42px;height:42px;border-radius:8px;object-fit:cover;}

.top-right{display:flex;align-items:center;gap:12px;}
.top-link{color:#cbd5e1;text-decoration:none;padding:6px 8px;border-radius:6px;font-size:0.95rem;}
.top-link:hover{color:#fff;background:rgba(255,255,255,0.05);}
.top-link.active{color:#38bdf8;border-bottom:2px solid #38bdf8;}

/* avatar placed beside logout */
.profile-avatar {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:36px;
  height:36px;
  border-radius:50%;
  background:linear-gradient(180deg,#1e293b,#0f172a);
  color:#e6eef8;
  font-weight:700;
  margin-left:8px;
  text-decoration:none;
  border:1px solid rgba(255,255,255,0.06);
  overflow:hidden;
  transition:transform .15s ease, box-shadow .15s ease;
}
.profile-avatar img {
  width:100%;
  height:100%;
  object-fit:cover;
  border-radius:50%;
  display:block;
}
.profile-avatar span { display:inline-block; line-height:1; font-size:13px; }
.profile-avatar:hover {
  transform:translateY(-2px);
  box-shadow:0 0 0 2px rgba(56,189,248,0.18);
}

/* show initials if image fails */
.profile-avatar.no-img span { display:inline-block !important; }
.profile-avatar.no-img img { display:none !important; }

/* Badge inline */
.badge-inline{
  background:#ef4444;color:#fff;border-radius:999px;padding:2px 7px;
  font-weight:700;font-size:0.8rem;margin-left:8px;display:inline-block;
}

/* Sidebar */
.admin-sidebar-compact{
  width:260px;position:fixed;left:0;top:56px;bottom:0;
  background:#071026;color:#e6eef8;border-right:1px solid rgba(255,255,255,0.03);
  padding:14px 12px;overflow:auto;transform:translateX(0);
  transition:transform 200ms ease,box-shadow 200ms ease;z-index:110;
}
.admin-sidebar-compact.hidden{transform:translateX(-110%);}
.admin-sidebar-compact.open{transform:translateX(0);box-shadow:4px 0 24px rgba(2,6,23,0.6);}

/* Sidebar links */
.sidebar-inner{display:flex;flex-direction:column;gap:14px;}
.sidebar-title{font-size:.85rem;color:#94a3b8;font-weight:700;margin-bottom:8px;padding-left:6px;}
.sidebar-link{display:flex;align-items:center;padding:10px 12px;color:#cbd5e1;text-decoration:none;border-radius:10px;margin-bottom:6px;transition:background .15s,color .15s,transform .12s;gap:10px;}
.sidebar-link:hover{background:#0b1724;color:#facc15;transform:translateY(-2px);box-shadow:0 10px 30px rgba(0,0,0,0.45);}
.sidebar-link.active{background:linear-gradient(90deg,rgba(56,189,248,0.10),rgba(255,255,255,0.01));color:#38bdf8;font-weight:700;}

/* icon helper - emoji or small image */
.sidebar-link .icon{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,0.02);font-size:18px;}
.sidebar-link .link-text{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* icon hover accent */
.sidebar-link:hover .icon{background:linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));transform:translateY(-1px);}

/* ✅ Main content properly aligned next to sidebar */
body.has-admin-sidebar .admin-main {
  margin-left:260px; /* perfectly fits beside sidebar */
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
