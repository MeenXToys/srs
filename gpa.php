<?php
// gpa.php (fixed session/login ordering + debug)
require_once 'config.php';
require_login();

$UserID = (int)$_SESSION['UserID'];

// --- rest of your code unchanged; error handling included ---
$errors = [];
$success = '';

// handle actions: add/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // sanitize inputs
    $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0;
    $gpa_val  = isset($_POST['gpa']) ? trim($_POST['gpa']) : '';

    if ($action === 'add' || $action === 'update') {
        // validation
        if ($semester < 1 || $semester > 20) {
            $errors[] = "Semester must be a valid number (1–20).";
        }
        // validate decimal like 0.00 - 4.00
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $gpa_val)) {
            $errors[] = "GPA must be a number with up to 2 decimals (e.g. 3.25).";
        } else {
            $gpa_num = (float)$gpa_val;
            if ($gpa_num < 0.00 || $gpa_num > 4.00) {
                $errors[] = "GPA must be between 0.00 and 4.00.";
            }
        }

        if (empty($errors)) {
            try {
                // check existing semester for this user
                $stmt = $pdo->prepare("SELECT GPAID FROM gpa WHERE UserID = :uid AND Semester = :sem LIMIT 1");
                $stmt->execute([':uid' => $UserID, ':sem' => $semester]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    // update
                    $upd = $pdo->prepare("UPDATE gpa SET GPA = :gpa WHERE GPAID = :gid");
                    $upd->execute([':gpa' => number_format($gpa_num, 2, '.', ''), ':gid' => $existing['GPAID']]);
                    $success = "Semester {$semester} GPA updated.";
                } else {
                    // insert
                    $ins = $pdo->prepare("INSERT INTO gpa (UserID, Semester, GPA) VALUES (:uid, :sem, :gpa)");
                    $ins->execute([':uid' => $UserID, ':sem' => $semester, ':gpa' => number_format($gpa_num, 2, '.', '')]);
                    $success = "Semester {$semester} GPA saved.";
                }
            } catch (Exception $e) {
                $errors[] = "Database error: " . htmlspecialchars($e->getMessage());
                error_log("gpa insert/update error for UserID {$UserID}: " . $e->getMessage());
            }
        }
    }

    if ($action === 'delete') {
        $gid = isset($_POST['gpaid']) ? (int)$_POST['gpaid'] : 0;
        if ($gid) {
            try {
                $del = $pdo->prepare("DELETE FROM gpa WHERE GPAID = :gid AND UserID = :uid");
                $del->execute([':gid' => $gid, ':uid' => $UserID]);
                $success = "Entry deleted.";
            } catch (Exception $e) {
                $errors[] = "Database error: " . htmlspecialchars($e->getMessage());
                error_log("gpa delete error for UserID {$UserID}: " . $e->getMessage());
            }
        }
    }
}

// fetch user's GPA rows
try {
    $stmt = $pdo->prepare("SELECT GPAID, Semester, GPA FROM gpa WHERE UserID = :uid ORDER BY Semester ASC");
    $stmt->execute([':uid' => $UserID]);
    $gpas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $gpas = [];
    $errors[] = "Database error: " . htmlspecialchars($e->getMessage());
    error_log("gpa fetch error for UserID {$UserID}: " . $e->getMessage());
}

// compute CGPA (simple average of recorded semester GPAs)
$cgpa = null;
if (!empty($gpas)) {
    $sum = 0;
    $count = 0;
    foreach ($gpas as $r) {
        $sum += (float)$r['GPA'];
        $count++;
    }
    $cgpa = $count ? round($sum / $count, 2) : null;
}

// prepare edit prefill when requested via GET? (edit uses same add form)
$editSemester = '';
$editGPA = '';
$editGPAID = 0;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($gpas as $r) {
        if ((int)$r['GPAID'] === $eid) {
            $editSemester = $r['Semester'];
            $editGPA = $r['GPA'];
            $editGPAID = $r['GPAID'];
            break;
        }
    }
}

// --- HTML / UI remains unchanged from your version below ---
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>My GPA - Student Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    /* small page-specific rules to align with your theme */
    .page-header { text-align:center; color:#e6eef8; padding:18px 12px; }
    .card { background: linear-gradient(180deg, rgba(0,0,0,0.06), rgba(0,0,0,0.12)); padding:16px; border-radius:10px; color:#e6eef8; }
    .form-row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    input[type="number"], input[type="text"] { padding:8px 10px; border-radius:6px; border:1px solid rgba(255,255,255,0.06); background:transparent; color:#e6eef8; }
    .btn { padding:8px 12px; border-radius:8px; text-decoration:none; cursor:pointer; border:none; font-weight:700; }
    .btn-primary { background:linear-gradient(90deg,#004aad,#6366f1); color:#fff; }
    .btn-danger { background:#c92a2a; color:#fff; }
    table { width:100%; border-collapse:collapse; margin-top:12px; color:#e6eef8; }
    th, td { padding:8px 10px; text-align:left; border-bottom:1px solid rgba(255,255,255,0.04); }
    .muted { color:#aab8c8; font-size:0.95rem; }
    .notice { margin:10px 0; padding:10px; border-radius:8px; }
    .notice.success { background: rgba(34,197,94,0.12); color:#bbffcf; }
    .notice.error { background: rgba(220,38,38,0.08); color:#ffd6d6; }
  </style>
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container" style="padding:20px 12px; max-width:900px; margin:0 auto;">
  <div class="page-header">
    <h1>My GPA</h1>
    <p class="muted">Add or update semester GPAs and view your current CGPA.</p>
  </div>

  <?php if ($success): ?>
    <div class="notice success"><?=htmlspecialchars($success)?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="notice error"><ul style="margin:0;padding-left:18px;">
      <?php foreach ($errors as $err): ?><li><?=htmlspecialchars($err)?></li><?php endforeach; ?>
    </ul></div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0;">Record / Update GPA</h2>

    <form method="post" class="form-row" style="align-items:center;">
      <input type="hidden" name="action" value="<?= $editGPAID ? 'update' : 'add' ?>">
      <?php if ($editGPAID): ?>
        <input type="hidden" name="gpaid" value="<?= (int)$editGPAID ?>">
      <?php endif; ?>

      <label for="semester" class="muted" style="min-width:110px;">Semester</label>
      <select name="semester" id="semester" required style="padding:8px;border-radius:6px;background:transparent;color:#e6eef8;border:1px solid rgba(255,255,255,0.06);">
        <?php
          // Show 1..12 by default - adjust max semesters if needed
          $maxSem = 12;
          for ($i=1;$i<=$maxSem;$i++) {
            $sel = ($i == $editSemester) ? 'selected' : '';
            echo "<option value=\"$i\" $sel>Semester $i</option>";
          }
        ?>
      </select>

      <label for="gpa" class="muted" style="min-width:70px;">GPA</label>
      <input id="gpa" name="gpa" type="text" required placeholder="e.g. 3.25" value="<?= htmlspecialchars($editGPA) ?>" style="width:120px;">

      <button class="btn btn-primary" type="submit"><?= $editGPAID ? 'Update GPA' : 'Save GPA' ?></button>
      <?php if ($editGPAID): ?>
        <a class="btn" href="gpa.php" style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,0.06);">Cancel</a>
      <?php endif; ?>
    </form>

    <p class="muted" style="margin-top:10px;">Note: GPA values are limited to 0.00–4.00 and saved per semester.</p>
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2 style="margin-top:0;">Your Semesters</h2>

    <?php if (empty($gpas)): ?>
      <p class="muted">You haven't added any semester GPAs yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Semester</th>
            <th>GPA</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($gpas as $r): ?>
            <tr>
              <td>Semester <?= (int)$r['Semester'] ?></td>
              <td><?= number_format((float)$r['GPA'], 2) ?></td>
              <td>
                <a class="btn" href="gpa.php?edit=<?= (int)$r['GPAID'] ?>" style="text-decoration:none;border:1px solid rgba(255,255,255,0.06);padding:6px 8px;border-radius:6px;">Edit</a>

                <form method="post" style="display:inline-block;margin-left:6px;" onsubmit="return confirm('Delete this GPA entry?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="gpaid" value="<?= (int)$r['GPAID'] ?>">
                  <button class="btn btn-danger" type="submit" style="padding:6px 8px;border-radius:6px;">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div style="margin-top:12px;">
        <strong>Current CGPA:</strong>
        <?php if ($cgpa !== null): ?>
          <span style="font-size:1.2rem; margin-left:8px;"><?= number_format((float)$cgpa, 2) ?></span>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
        <p class="muted" style="margin-top:6px;">CGPA = average of your saved semester GPAs.</p>
      </div>
    <?php endif; ?>
  </div>

  <div style="height:30px;"></div>
  <footer style="text-align:center;color:#aab8c8;">
    &copy; <?= date('Y') ?> GMI Student Registration System
  </footer>
</div>

</body>
</html>
