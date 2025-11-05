<?php
// gpa.php - Student (user) GPA management page
require_once __DIR__ . '/config.php';
require_login(); // ensure this exists in your config.php / auth helpers
require_once __DIR__ . '/nav.php';
// small safe echo
if (!function_exists('e')) {
  function e($s)
  {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

// ensure session started
if (session_status() === PHP_SESSION_NONE)
  session_start();

// ensure CSRF token
if (!isset($_SESSION['csrf_token']))
  $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

$errMsg = null;
$success = null;
// Get logged-in user ID from session
$uid = 0;
if (!empty($_SESSION['user']['UserID'])) {
  $uid = (int) $_SESSION['user']['UserID'];
}


// Handle POST actions (add / delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      throw new Exception('Invalid CSRF token');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'add_gpa') {
      $semester = (int) ($_POST['semester'] ?? 0);
      $gpaRaw = trim($_POST['gpa'] ?? '');
      if ($semester <= 0)
        throw new Exception('Semester must be a positive integer.');
      if ($gpaRaw === '')
        throw new Exception('GPA is required.');
      // numeric parse
      if (!is_numeric($gpaRaw))
        throw new Exception('GPA must be a number.');
      $gpa = number_format((float) $gpaRaw, 2, '.', '');
      if ($gpa < 0.00 || $gpa > 4.00)
        throw new Exception('GPA must be between 0.00 and 4.00.');

      // optional: prevent duplicate same semester for same user (uncomment if desired)
      $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM gpa WHERE UserID = :uid AND Semester = :sem");
      $existsStmt->execute([':uid' => $uid, ':sem' => $semester]);
      if ($existsStmt->fetchColumn() > 0) {
        throw new Exception("GPA for semester {$semester} already exists. Delete it first if you want to replace it.");
      }

      $ins = $pdo->prepare("INSERT INTO gpa (UserID, Semester, GPA) VALUES (:uid, :sem, :gpa)");
      $ins->execute([':uid' => $uid, ':sem' => $semester, ':gpa' => $gpa]);
      $success = 'GPA saved.';
    } elseif ($action === 'delete_gpa') {
      $gpaId = (int) ($_POST['gpaid'] ?? 0);
      if ($gpaId <= 0)
        throw new Exception('Invalid GPA id.');
      // ensure row belongs to current user
      $chk = $pdo->prepare("SELECT UserID FROM gpa WHERE GPAID = :id");
      $chk->execute([':id' => $gpaId]);
      $owner = $chk->fetchColumn();
      if (!$owner || (int) $owner !== $uid)
        throw new Exception('Not authorized to delete this GPA.');
      $del = $pdo->prepare("DELETE FROM gpa WHERE GPAID = :id");
      $del->execute([':id' => $gpaId]);
      $success = 'GPA deleted.';
    } else {
      throw new Exception('Unknown action.');
    }
  } catch (Exception $ex) {
    $errMsg = $ex->getMessage();
  }
}

// Fetch user's GPA rows and computed CGPA
try {
  // list of semester rows
  $rowsStmt = $pdo->prepare("SELECT GPAID, Semester, GPA FROM gpa WHERE UserID = :uid ORDER BY Semester ASC, GPAID ASC");
  $rowsStmt->execute([':uid' => $uid]);
  $gpaRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

  // compute CGPA (average)
  $cgpaStmt = $pdo->prepare("SELECT ROUND(AVG(GPA),2) AS cgpa, COUNT(*) AS count_rows FROM gpa WHERE UserID = :uid");
  $cgpaStmt->execute([':uid' => $uid]);
  $cgpaInfo = $cgpaStmt->fetch(PDO::FETCH_ASSOC);
  $cgpa = $cgpaInfo['cgpa'] !== null ? number_format((float) $cgpaInfo['cgpa'], 2) : null;
  $gpaCount = (int) ($cgpaInfo['count_rows'] ?? 0);
} catch (Exception $e) {
  $errMsg = $e->getMessage();
  $gpaRows = [];
  $cgpa = null;
  $gpaCount = 0;
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>My GPA — Student</title>
  <link rel="stylesheet" href="style.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Bootstrap CSS (for modal) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root {
      --bg: #0f1724;
      --card: #07111a;
      --muted: #9aa8bd;
      --text: #e8f6ff;
      --accent-blue-1: #2563eb;
      --accent-blue-2: #1d4ed8;
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;
      margin: 0;
      padding: 0;
    }

    .my-container {
      width: auto;
      margin: 50px;
      padding: 18px;
      box-sizing: border-box;
    }

    .my-card {
      background: var(--card);
      border-radius: 12px;
      padding: 18px;
      box-shadow: 0 8px 28px rgba(2, 6, 23, 0.6);
    }

    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 8px;
    }

    .h1 {
      font-size: 1.6rem;
      margin: 0;
      color: #cfe8ff;
    }

    .small-muted {
      color: var(--muted);
    }

    .table-wrap {
      margin-top: 12px;
      overflow: auto;
      border-radius: 8px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th,
    td {
      padding: 12px;
      border-top: 1px solid rgba(255, 255, 255, 0.03);
      color: var(--text);
      vertical-align: middle;
    }

    th {
      color: var(--muted);
      text-align: left;
      font-weight: 700;
    }

    .btn-primary {
      background: linear-gradient(90deg, var(--accent-blue-1), var(--accent-blue-2));
      border: 0;
    }

    .toast {
      position: fixed;
      right: 18px;
      bottom: 18px;
      background: #0b1520;
      padding: 12px 16px;
      border-radius: 8px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6);
      color: #e6eef8;
      z-index: 600;
      display: none;
    }

    .toast.show {
      display: block;
    }

    .form-control,
    .form-select {
      background: rgba(255, 255, 255, 0.02);
      color: var(--text);
      border: 1px solid rgba(255, 255, 255, 0.04);
    }

    @media (max-width:720px) {
      .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }
    }

    /* Make modal background dark and text white */
    .modal-content {
      background-color: #0b1520;
      /* dark background */
      color: #ffffff;
      /* white text */
    }

    /* Optional: make form fields match dark theme */
    .modal-content .form-control {
      background-color: rgba(255, 255, 255, 0.05);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.15);
    }

    .modal-content .form-control::placeholder {
      color: rgba(255, 255, 255, 0.4);
    }

    /* Optional: close button in white */
    .modal-header .btn-close {
      filter: invert(1) grayscale(100%) brightness(200%);
    }
  </style>
</head>

<body>
  <div class="my-container">
    <div class="my-card">
      <div class="header">
        <div>
          <h1 class="h1">My GPA</h1>
          <div class="small-muted"><?= e($gpaCount) ?> record<?= $gpaCount == 1 ? '' : 's' ?> • CGPA:
            <?= $cgpa === null ? '<span style="color:var(--muted)">—</span>' : e($cgpa) ?>
          </div>
        </div>
        <div>
          <button id="openAddBtn" class="btn btn-primary">＋ Add GPA</button>
        </div>
      </div>

      <?php if (!empty($errMsg)): ?>
        <div style="background:#3b1f1f;color:#ffdede;padding:10px;border-radius:6px;margin-bottom:12px;"><?= e($errMsg) ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($success)): ?>
        <div style="background:#073a1f;color:#dff6ea;padding:10px;border-radius:6px;margin-bottom:12px;">
          <?= e($success) ?>
        </div>
      <?php endif; ?>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:140px">No.</th>
              <th style="width:140px">Semester</th>
              <th style="text-align:right;width:140px">GPA</th>
              <th style="width:160px">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($gpaRows)): ?>
              <tr>
                <td colspan="3" style="text-align:center;color:var(--muted);padding:28px;">No GPA records found.</td>
              </tr>


              <?php
            else: ?>
              <?php
              $count = 0;
              foreach ($gpaRows as $r):
                ?>
                <tr data-gpaid="<?= e($r['GPAID']) ?>">
                  <td><?= ++$count ?></td>
                  <td><?= e($r['Semester']) ?></td>
                  <td style="text-align:right"><?= e(number_format((float) $r['GPA'], 2)) ?></td>
                  <td>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this GPA entry?');">
                      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                      <input type="hidden" name="action" value="delete_gpa">
                      <input type="hidden" name="gpaid" value="<?= e($r['GPAID']) ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:12px;color:var(--muted);font-size:.95rem;">
        Note: CGPA is calculated as the simple average of saved semester GPAs (non-weighted). For credit-weighted CGPA
        you'll need per-course grades & credits.
      </div>
    </div>
  </div>

  <!-- Add GPA Modal -->
  <div class="modal fade" id="addGpaModal" tabindex="-1" aria-labelledby="addGpaLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content">
        <form id="addGpaForm" method="post" class="modal-body p-4" autocomplete="off">
          <div class="modal-header">
            <h5 class="modal-title" id="addGpaLabel">Add GPA</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="add_gpa">

          <div class="mb-3">
            <label class="form-label">Semester (integer)</label>
            <input name="semester" id="semesterInput" type="number" min="1" required class="form-control"
              placeholder="e.g. 1">
          </div>
          <div class="mb-3">
            <label class="form-label">GPA (0.00 – 4.00)</label>
            <input name="gpa" id="gpaInput" type="number" step="0.01" min="0" max="4" required class="form-control"
              placeholder="e.g. 3.25">
          </div>

          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button id="submitAdd" class="btn btn-primary" type="submit">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="toast" class="toast"></div>

  <!-- Bootstrap JS bundle (Popper included) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const addModalEl = document.getElementById('addGpaModal');
    const addModal = addModalEl ? new bootstrap.Modal(addModalEl, { keyboard: false }) : null;
    document.getElementById('openAddBtn').addEventListener('click', () => {
      if (addModal) addModal.show();
      setTimeout(() => document.getElementById('semesterInput').focus(), 120);
    });

    // simple client validation for nicer UX
    const addForm = document.getElementById('addGpaForm');
    if (addForm) addForm.addEventListener('submit', function (e) {
      const sem = parseInt(document.getElementById('semesterInput').value || '0', 10);
      const gpa = parseFloat(document.getElementById('gpaInput').value || '');
      if (!sem || isNaN(sem) || sem <= 0) { showToast('Semester must be a positive integer', 2200, 'error'); e.preventDefault(); return; }
      if (isNaN(gpa) || gpa < 0 || gpa > 4) { showToast('GPA must be between 0.00 and 4.00', 2200, 'error'); e.preventDefault(); return; }
    });

    // toast helper
    function showToast(msg, t = 2200, cls = '') {
      const s = document.getElementById('toast');
      if (!s) return console.log(msg);
      s.textContent = msg;
      s.classList.add('show');
      if (s._hideTimer) clearTimeout(s._hideTimer);
      s._hideTimer = setTimeout(() => { s.classList.remove('show'); }, t);
    }
  </script>
</body>

</html>