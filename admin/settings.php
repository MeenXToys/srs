<?php
// admin/settings.php
require_once __DIR__ . '/../config.php';
require_admin();

// safe echo
if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

// ---------- ensure helpful columns exist (Display_Name) ----------
try {
    // MySQL 8: ADD COLUMN IF NOT EXISTS. On older MySQL versions this will raise and we ignore.
    $pdo->exec("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `Display_Name` VARCHAR(120) NULL AFTER `Email`");
} catch (\Throwable $t) {
    // ignore - not critical
}

// ---------- helper to find current admin id ----------
$adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? $_SESSION['UserID'] ?? null;
if (!$adminId) {
    // fallback: pick first admin user
    $s = $pdo->query("SELECT UserID FROM `user` WHERE `Role` = 'Admin' LIMIT 1")->fetch(PDO::FETCH_COLUMN);
    $adminId = $s ? (int)$s : null;
}
if (!$adminId) {
    die("Admin user not found. Please ensure at least one user with Role='Admin' exists.");
}

// ---------- load admin record ----------
$stmt = $pdo->prepare("SELECT * FROM `user` WHERE `UserID` = :id LIMIT 1");
$stmt->execute(['id' => $adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$admin) die("Admin account not found (UserID: {$adminId}).");

// ---------- CSRF ----------
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

// ---------- flash messages ----------
$flash = null;
$errors = [];

// ---------- paths ----------
$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

// ---------- handle POSTS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, (string)$token)) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'] ?? 'save_profile';

        if ($action === 'save_profile') {
            $displayName = trim($_POST['display_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');

            if ($displayName === '') $errors[] = "Display name is required.";
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
            if ($username !== '' && !filter_var($username, FILTER_VALIDATE_EMAIL)) $errors[] = "Username must be a valid email.";

            if (empty($errors)) {
                try {
                    $s = $pdo->prepare("UPDATE `user` SET `Display_Name` = :dn, `Email` = :email, `Username` = :username WHERE `UserID` = :id");
                    $s->execute([
                        'dn' => $displayName,
                        'email' => $email,
                        'username' => $username ?: $email,
                        'id' => $adminId
                    ]);
                    // update $admin in memory & session email if used
                    $admin['Display_Name'] = $displayName;
                    $admin['Email'] = $email;
                    $admin['Username'] = $username ?: $email;
                    $_SESSION['admin_email'] = $admin['Email'] ?? $_SESSION['admin_email'] ?? null;
                    $flash = ['type' => 'success', 'msg' => 'Profile updated.'];
                } catch (Exception $ex) {
                    $errors[] = "Database error while saving profile.";
                }
            }
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new1 = $_POST['new_password'] ?? '';
            $new2 = $_POST['new_password_confirm'] ?? '';

            if ($current === '' || $new1 === '' || $new2 === '') {
                $errors[] = "All password fields are required.";
            } else {
                // ensure admin has Password_Hash column
                if (empty($admin['Password_Hash'])) {
                    $errors[] = "No password set for this account (cannot verify current).";
                } else {
                    if (!password_verify($current, $admin['Password_Hash'])) {
                        $errors[] = "Current password is incorrect.";
                    } elseif ($new1 !== $new2) {
                        $errors[] = "New passwords do not match.";
                    } elseif (strlen($new1) < 6) {
                        $errors[] = "New password must be at least 6 characters.";
                    } else {
                        $hash = password_hash($new1, PASSWORD_DEFAULT);
                        $s = $pdo->prepare("UPDATE `user` SET `Password_Hash` = :p WHERE `UserID` = :id");
                        $s->execute(['p' => $hash, 'id' => $adminId]);
                        $admin['Password_Hash'] = $hash;
                        $flash = ['type'=>'success','msg'=>'Password changed successfully.'];
                    }
                }
            }
        } elseif ($action === 'upload_avatar') {
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = "No file uploaded.";
            } else {
                $f = $_FILES['avatar'];
                if ($f['error'] !== UPLOAD_ERR_OK) $errors[] = "Upload error (code {$f['error']}).";
                else {
                    // validate mime
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $f['tmp_name']);
                    finfo_close($finfo);
                    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                    if (!isset($allowed[$mime])) {
                        $errors[] = "Unsupported image type. Use JPG, PNG or WEBP.";
                    } elseif ($f['size'] > 2 * 1024 * 1024) {
                        $errors[] = "File too large — max 2 MB.";
                    } else {
                        $ext = $allowed[$mime];
                        $filename = 'user_' . $adminId . '_' . time() . '.' . $ext;
                        $dest = $uploadsDir . '/' . $filename;
                        if (!move_uploaded_file($f['tmp_name'], $dest)) {
                            $errors[] = "Failed to move uploaded file.";
                        } else {
                            // remove old file if exists and is in uploads
                            if (!empty($admin['Profile_Image'])) {
                                $oldPath = __DIR__ . '/../' . ltrim($admin['Profile_Image'], '/');
                                if (file_exists($oldPath) && is_file($oldPath)) @unlink($oldPath);
                            }
                            // save to DB (store relative path)
                            $rel = 'uploads/' . $filename;
                            $s = $pdo->prepare("UPDATE `user` SET `Profile_Image` = :img WHERE `UserID` = :id");
                            $s->execute(['img' => $rel, 'id' => $adminId]);
                            $admin['Profile_Image'] = $rel;
                            $flash = ['type'=>'success','msg'=>'Profile image updated.'];
                        }
                    }
                }
            }
        } elseif ($action === 'remove_avatar') {
            if (!empty($admin['Profile_Image'])) {
                $oldPath = __DIR__ . '/../' . ltrim($admin['Profile_Image'], '/');
                if (file_exists($oldPath) && is_file($oldPath)) @unlink($oldPath);
                $s = $pdo->prepare("UPDATE `user` SET `Profile_Image` = NULL WHERE `UserID` = :id");
                $s->execute(['id' => $adminId]);
                $admin['Profile_Image'] = null;
                $flash = ['type'=>'success','msg'=>'Profile image removed.'];
            } else {
                $errors[] = "No profile image to remove.";
            }
        } else {
            $errors[] = "Unknown action.";
        }
    }

    // set flash into session and redirect to avoid double POST
    if ($flash) $_SESSION['flash'] = $flash;
    if ($errors) $_SESSION['flash'] = ['type'=>'error','msg'=>implode(' ', $errors)];
    header('Location: settings.php');
    exit;
}

// reload flash if any
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

// small helpers
/**
 * Produce a web URL for the avatar that works from admin pages.
 * Returning '../uploads/...' ensures admin pages load the image regardless of app subfolder.
 */
function avatar_url($rel) {
    if (!$rel) return null;
    // prefer relative-from-admin path (admin pages are in /admin)
    return '../' . ltrim($rel, '/\\');
}
function initials($s) {
    $s = trim((string)$s);
    if ($s === '') return 'A';
    $parts = preg_split('/\s+/', $s);
    if (count($parts) >= 2) return strtoupper(substr($parts[0],0,1) . substr($parts[1],0,1));
    return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$s), 0, 2));
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Settings — Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="../style.css">
<style>
:root{
  --bg:#0f1724; --card:#0b1520; --muted:#94a3b8; --text:#e6eef8;
  --accent1:#7c3aed; --accent2:#6d28d9; --danger:#ef4444;
}
html,body{background-color:#0f1724 !important;height:100%;}
body{margin:0;color:var(--text);font-family:Inter,system-ui,Arial,sans-serif;-webkit-font-smoothing:antialiased;}
.admin-main{padding:22px;}
.container{max-width:1100px;margin:0 auto;}
.header {display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}
.header h1{margin:0;font-size:1.35rem}
.card{background:var(--card);border-radius:12px;padding:18px;border:1px solid rgba(255,255,255,0.04);box-shadow:0 18px 48px rgba(0,0,0,0.6);}

/* layout */
.grid {display:grid;grid-template-columns:320px 1fr;gap:18px;align-items:start}
@media (max-width:880px){ .grid{grid-template-columns:1fr} }

/* profile box */
.profile-box {display:flex;gap:12px;align-items:center}
.avatar {
  width:96px;height:96px;border-radius:14px;overflow:hidden;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(180deg,var(--accent1),var(--accent2));font-weight:800;color:white;font-size:20px;flex-shrink:0;
  box-shadow:0 10px 30px rgba(124,58,237,0.12);
}
.avatar img{width:100%;height:100%;object-fit:cover;display:block}

/* forms */
.form-section{margin-bottom:12px}
label{display:block;color:var(--muted);font-weight:700;margin-bottom:8px}
.input, textarea{width:100%;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,0.04);background:rgba(255,255,255,0.02);color:var(--text);font-size:0.95rem}
textarea{min-height:120px;resize:vertical}

/* buttons */
.actions{display:flex;gap:10px;align-items:center;margin-top:12px}
.btn {background:linear-gradient(90deg,var(--accent1),var(--accent2));color:#fff;border:0;padding:10px 14px;border-radius:10px;font-weight:700;cursor:pointer}
.btn.ghost {background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--text);padding:10px 12px;border-radius:10px}
.btn.danger {background:linear-gradient(90deg,#ef4444,#dc2626);}

/* flash */
.flash{padding:12px;border-radius:10px;margin-bottom:12px}
.flash.success{background:linear-gradient(180deg,rgba(16,185,129,0.08),rgba(16,185,129,0.04));border-left:4px solid #10b981;color:#dcfce7}
.flash.error{background:rgba(255,230,230,0.04);border-left:4px solid var(--danger);color:#ffdede}

/* small */
.small{color:var(--muted);font-size:0.92rem}
.help{color:var(--muted);font-size:0.88rem;margin-top:6px}

/* divider */
.hr{height:1px;background:rgba(255,255,255,0.03);margin:12px 0;border-radius:2px}
</style>
</head>
<body>
<?php include __DIR__ . '/admin_nav.php'; ?>

<main class="admin-main">
  <div class="container">
    <div class="header">
      <div>
        <h1>Settings</h1>
        <div class="small">Manage your profile, password and profile picture</div>
      </div>
      <div>
        <a class="btn ghost" href="index.php">Back to dashboard</a>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="flash <?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="grid">
      <!-- left: profile card -->
      <div>
        <div class="card">
          <div class="profile-box">
            <div class="avatar">
              <?php
                $hasAvatar = !empty($admin['Profile_Image']) && file_exists(__DIR__ . '/../' . ltrim($admin['Profile_Image'], '/\\'));
                if ($hasAvatar):
              ?>
                <img src="<?= e(avatar_url($admin['Profile_Image'])) ?>" alt="avatar">
              <?php else: ?>
                <?= e(initials($admin['Display_Name'] ?? $admin['Email'] ?? 'A')) ?>
              <?php endif; ?>
            </div>
            <div>
              <div style="font-weight:800;font-size:1rem"><?= e($admin['Display_Name'] ?? '') ?></div>
              <div class="small" style="margin-top:6px"><?= e($admin['Email'] ?? '') ?></div>
              <div class="help" style="margin-top:8px">Recommended size: square image, at least 320×320 px. JPG/PNG/WEBP accepted up to 2MB.</div>
            </div>
          </div>

          <div class="hr"></div>

          <form method="post" enctype="multipart/form-data" class="form-section">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="upload_avatar">
            <label for="avatar">Upload new profile image</label>
            <input id="avatar" name="avatar" type="file" accept="image/png,image/jpeg,image/webp" class="input">
            <div class="actions">
              <button class="btn" type="submit">Upload</button>

              <?php if (!empty($admin['Profile_Image'])): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="remove_avatar">
                  <button class="btn danger" type="submit" onclick="return confirm('Remove profile image?')">Remove</button>
                </form>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <!-- right: forms -->
      <div>
        <div class="card">
          <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_profile">

            <div class="form-section">
              <label for="display_name">Display name</label>
              <input id="display_name" name="display_name" class="input" value="<?= e($admin['Display_Name'] ?? '') ?>" placeholder="Your full name">
            </div>

            <div class="form-section">
              <label for="email">Email (contact)</label>
              <input id="email" name="email" class="input" value="<?= e($admin['Email'] ?? '') ?>" type="email" required>
            </div>

            <div class="form-section">
              <label for="username">Username (optional — email)</label>
              <input id="username" name="username" class="input" value="<?= e($admin['Username'] ?? $admin['Email'] ?? '') ?>" placeholder="Username (email)">
              <div class="help">You can keep username same as email or set a separate login email.</div>
            </div>

            <div class="actions">
              <button class="btn" type="submit">Save Profile</button>
              <button class="btn ghost" type="button" onclick="location.reload()">Reset</button>
            </div>
          </form>
        </div>

        <div style="height:14px"></div>

        <div class="card">
          <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="form-section">
              <label for="current_password">Current password</label>
              <input id="current_password" name="current_password" class="input" type="password" autocomplete="current-password">
            </div>

            <div class="form-section">
              <label for="new_password">New password</label>
              <input id="new_password" name="new_password" class="input" type="password" autocomplete="new-password" placeholder="Min 6 characters">
            </div>

            <div class="form-section">
              <label for="new_password_confirm">Confirm new password</label>
              <input id="new_password_confirm" name="new_password_confirm" class="input" type="password" autocomplete="new-password">
            </div>

            <div class="actions">
              <button class="btn" type="submit">Change password</button>
              <button class="btn ghost" type="reset">Clear</button>
            </div>
          </form>
        </div>

      </div>
    </div>
  </div>
</main>
</body>
</html>
