<?php
// gpa.php - Student (user) GPA management page
require_once 'config.php';
require_login(); // ensure this exists in your config.php / auth helpers


// small safe echo function
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
// NOTE: $pdo must be available globally/in scope from config.php
if (!empty($_SESSION['user']['UserID'])) {
  $uid = (int) $_SESSION['user']['UserID'];
}


// Handle POST actions (add / edit / delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      throw new Exception('Invalid CSRF token');
    }

    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_gpa' || $action === 'edit_gpa') {
        $semester = (int) ($_POST['semester'] ?? 0);
        $gpaRaw = trim($_POST['gpa'] ?? '');
        
        $gpaId = 0;
        if ($action === 'edit_gpa') {
          $gpaId = (int) ($_POST['gpaid'] ?? 0);
          if ($gpaId <= 0) throw new Exception('Invalid GPA record ID for editing.');
        }

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

        // Check for duplicate semester (only applicable for Add, or if Semester is changed in Edit)
        $existsStmt = $pdo->prepare("SELECT GPAID FROM gpa WHERE UserID = :uid AND Semester = :sem");
        $existsStmt->execute([':uid' => $uid, ':sem' => $semester]);
        
        $existingRow = $existsStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingRow) {
          // If an existing row is found, check if it's the one we are currently editing
          if ($action === 'add_gpa' || (int)$existingRow['GPAID'] !== $gpaId) {
            throw new Exception("GPA for semester {$semester} already exists. Delete it first if you want to replace it.");
          }
        }

        if ($action === 'add_gpa') {
          $ins = $pdo->prepare("INSERT INTO gpa (UserID, Semester, GPA) VALUES (:uid, :sem, :gpa)");
          $ins->execute([':uid' => $uid, ':sem' => $semester, ':gpa' => $gpa]);
          $success = 'GPA record saved successfully.';
        } elseif ($action === 'edit_gpa') {
          // Ensure row belongs to current user
          $chk = $pdo->prepare("SELECT UserID FROM gpa WHERE GPAID = :id");
          $chk->execute([':id' => $gpaId]);
          $owner = $chk->fetchColumn();
          if (!$owner || (int) $owner !== $uid)
            throw new Exception('Not authorized to edit this GPA.');
          
          // Perform update
          $upd = $pdo->prepare("UPDATE gpa SET Semester = :sem, GPA = :gpa WHERE GPAID = :id AND UserID = :uid");
          $upd->execute([':sem' => $semester, ':gpa' => $gpa, ':id' => $gpaId, ':uid' => $uid]);
          $success = 'GPA record updated successfully.';
        }

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
      $success = 'GPA record deleted successfully.';
    } else {
      throw new Exception('Unknown action.');
    }
  } catch (Exception $ex) {
    $errMsg = $ex->getMessage();
  }
}

// fetch user's GPA rows
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
  <title>Calculate GPA</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="style.css"> 

    <?php include 'nav.php'; ?>

  <style>
    /* New Color Palette Variables */
    :root {
      --bg: #0b1318; /* Used for body background gradient start & form control background */
      --card: #0f1720; /* Used for my-card background */
      --muted: #9fb0c6; /* Used for small-muted text */
      --text: #e9f1fa; /* Used for main text */
      --accent: #3db7ff; /* Primary accent blue */
      --accent2: #7c5cff; /* Secondary accent purple */
      --success: #22c55e; /* Success green */
      --danger: #ef4444; /* Danger red */
      --edit: #f59e0b; /* Edit orange */
      --radius: 14px;
      --shadow: 0 14px 40px rgba(2, 6, 23, 0.6);
      --border: rgba(255, 255, 255, 0.04); /* Adjusted border for new palette */
    }

    /* Base/Container (CSS unchanged from original except for adding --edit variable) */
    body {
      background: linear-gradient(180deg, #071022, #07121a 60%); 
      color: var(--text);
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;
      margin: 0;
      padding: 0;
      line-height: 1.5;
    }

    .my-container {
      max-width: 900px;
      margin: 50px auto; 
      padding: 0 15px;
      box-sizing: border-box;
    }

    .my-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 24px;
      box-shadow: var(--shadow);
    }

    /* Header & CGPA Display */
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      margin-bottom: 20px;
      padding-bottom: 15px;
      border-bottom: 1px solid var(--border);
    }

    .h1 {
      font-size: 2rem;
      margin: 0;
      color: var(--text); /* Use text variable */
      font-weight: 600;
    }

    .cgpa-display {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--accent); /* Use primary accent for CGPA */
      margin-top: 4px;
    }

    .small-muted {
      color: var(--muted);
      font-size: 0.9rem;
    }

    /* Table Styling */
    .table-wrap {
      margin-top: 15px;
      overflow: auto;
      border-radius: 8px;
      border: 1px solid var(--border);
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th,
    td {
      padding: 14px 12px;
      color: var(--text);
      vertical-align: middle;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }
    
    tbody tr:last-child td {
      border-bottom: none;
    }

    th {
      color: var(--muted);
      font-size: 0.9rem;
      align-content: center;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      background-color: rgba(255, 255, 255, 0.03); /* Subtle dark header */
    }
    
    tbody tr:nth-child(even) {
      background-color: rgba(255, 255, 255, 0.01); 
    }
    
    tbody tr:hover {
      background-color: rgba(255, 255, 255, 0.03); 
    }

    .gpa-col { text-align: right !important; }
    .semester-col { text-align: center !important; }
    /* Ensure enough width for two buttons and prevent content wrapping */
    .action-col { 
      width: 140px; 
      white-space: nowrap; 
    }


    /* Buttons */
    .btn-primary {
      /* Updated gradient/color to match new accents */
      background: linear-gradient(90deg, var(--accent), var(--accent2));
      border: 0;
      padding: 8px 16px;
      font-weight: 700;
      transition: all 0.2s;
      box-shadow: 0 4px 15px rgba(61, 183, 255, 0.2);
    }
    .btn-primary:hover {
      opacity: 0.9;
      transform: translateY(-2px);
    }
    
    .btn-edit {
      background-color: var(--edit);
      border: 1px solid var(--edit);
      color: #000;
      font-weight: 600;
    }


    .btn-danger {
      background-color: var(--danger);
      border: 1px solid var(--danger);
    }
    
    /* New side-by-side styling */
    .btn-edit,
    .btn-danger {
      padding: 6px 10px; /* Reduced padding */
      font-size: 0.8rem; /* Smaller font size */
      border-radius: 6px;
      display: inline-block; /* Crucial: allows them to sit next to each other */
      margin: 0; /* Reset default margins */
    }
    .btn-edit {
      width: 100px;
      margin-right: 5px; /* Spacing between Edit and Delete */
    }


    /* Message Boxes / Forms / Modal (Unchanged) */
    .alert-error {
      background: rgba(239, 68, 68, 0.15); color: var(--danger); 
      border: 1px solid rgba(239, 68, 68, 0.3); padding: 12px;
      border-radius: 6px; margin-bottom: 15px;
    }
    .alert-success {
      background: rgba(34, 197, 94, 0.15); color: var(--success);
      border: 1px solid rgba(34, 197, 94, 0.3); padding: 12px;
      border-radius: 6px; margin-bottom: 15px;
    }


    .form-control,
    .form-select {
      background: var(--bg); color: var(--text); border: 1px solid var(--border);
    }
    .form-control:focus,
    .form-select:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 0.25rem rgba(61, 183, 255, 0.25);
      background: var(--bg); color: var(--text);
    }

    .modal-content {
      background-color: var(--card); color: #ffffff;
      border: 1px solid var(--border);
    }
    .modal-header {
      border-bottom: 1px solid var(--border);
    }
    .modal-header .btn-close {
      filter: invert(1) grayscale(100%) brightness(200%);
    }
    .btn-secondary {
      background-color: var(--muted); border-color: var(--muted);
      color: #000; font-weight: 600;
    }

    /* Toast */
    .toast {
      position: fixed; right: 18px; bottom: 18px;
      background: var(--card); padding: 12px 16px;
      border-radius: 8px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6);
      color: var(--text); z-index: 600;
      display: none; border: 1px solid var(--border);
    }
    .toast.show { display: block; }

    @media (max-width:720px) {
      .my-container { margin: 20px 0; }
      .header { flex-direction: column; align-items: flex-start; gap: 10px; }
      .h1 { font-size: 1.75rem; }
      th, td { padding: 10px 8px; font-size: 0.9rem; }
      .action-col { width: auto; white-space: nowrap; }
    }
  </style>
</head>

<body>
  <div class="my-container">
    <div class="my-card">
      <div class="header">
        <div>
          <h1 class="h1">My GPA</h1>
          <div class="small-muted">
            <?= e($gpaCount) ?> record<?= $gpaCount == 1 ? '' : 's' ?> • CGPA:
            <span class="cgpa-display">
              <?= $cgpa === null ? '<span style="color:var(--muted);font-weight:400;">—</span>' : e($cgpa) ?>
            </span>
          </div>
        </div>
        <div>
          <button id="openAddBtn" class="btn btn-primary">＋ Add GPA</button>
        </div>
      </div>

      <?php if (!empty($errMsg)): ?>
        <div class="alert-error"><?= e($errMsg) ?></div>
      <?php endif; ?>
      <?php if (!empty($success)): ?>
        <div class="alert-success">
          <?= e($success) ?>
        </div>
      <?php endif; ?>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:50px">No.</th>
              <th class="semester-col" style="width:140px">Semester</th>
              <th class="gpa-col" style="width:140px">GPA</th>
              <th class="action-col"style="text-align:center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($gpaRows)): ?>
              <tr>
                <td colspan="4" style="text-align:center;color:var(--muted);padding:28px;border-bottom:none;">No GPA records found.</td>
              </tr>
            <?php else: ?>
              <?php $count = 0; foreach ($gpaRows as $r): ?>
                <tr data-gpaid="<?= e($r['GPAID']) ?>" data-semester="<?= e($r['Semester']) ?>" data-gpa="<?= e(number_format((float) $r['GPA'], 2, '.', '')) ?>">
                  <td><?= ++$count ?></td>
                  <td class="semester-col"><?= e($r['Semester']) ?></td>
                  <td class="gpa-col"><?= e(number_format((float) $r['GPA'], 2)) ?></td>
                  <td class="action-col" style="text-align:center;">
                        <button type="button" class="btn btn-sm btn-edit openEditBtn">Edit</button>
                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this GPA entry?');">
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

      <div style="margin-top:16px;color:var(--muted);font-size:.9rem;">
        Note: CGPA is calculated as the simple average of saved semester GPAs (non-weighted). For credit-weighted CGPA
        you'll need per-course grades & credits.
      </div>
    </div>
  </div>

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


        <div class="modal fade" id="editGpaModal" tabindex="-1" aria-labelledby="editGpaLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content">
        <form id="editGpaForm" method="post" class="modal-body p-4" autocomplete="off">
          <div class="modal-header">
            <h5 class="modal-title" id="editGpaLabel">Edit GPA Record</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="edit_gpa">
            <input type="hidden" name="gpaid" id="editGpaidInput" value=""> 

          <div class="mb-3">
            <label class="form-label">Semester (integer)</label>
            <input name="semester" id="editSemesterInput" type="number" min="1" required class="form-control"
              placeholder="e.g. 1">
          </div>
          <div class="mb-3">
            <label class="form-label">GPA (0.00 – 4.00)</label>
            <input name="gpa" id="editGpaInput" type="number" step="0.01" min="0" max="4" required class="form-control"
              placeholder="e.g. 3.25">
          </div>

          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button id="submitEdit" class="btn btn-primary" type="submit">Update</button>
            </div>
        </form>
      </div>
    </div>
  </div>


  <div id="toast" class="toast"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Add Modal Logic (Unchanged)
    const addModalEl = document.getElementById('addGpaModal');
    const addModal = addModalEl ? new bootstrap.Modal(addModalEl, { keyboard: false }) : null;
    document.getElementById('openAddBtn').addEventListener('click', () => {
      if (addModal) addModal.show();
      setTimeout(() => document.getElementById('semesterInput').focus(), 120);
    });

    // Edit Modal Logic (NEW)
    const editModalEl = document.getElementById('editGpaModal');
    const editModal = editModalEl ? new bootstrap.Modal(editModalEl, { keyboard: false }) : null;
    
    // 1. Event listener for all "Edit" buttons
    document.querySelectorAll('.openEditBtn').forEach(button => {
        button.addEventListener('click', function() {
            // Get the parent table row (tr)
            const row = this.closest('tr');
            
            // Extract data from the row's data attributes
            const gpaId = row.dataset.gpaid;
            const semester = row.dataset.semester;
            const gpa = row.dataset.gpa;

            // Populate the modal form fields
            document.getElementById('editGpaidInput').value = gpaId;
            document.getElementById('editSemesterInput').value = semester;
            document.getElementById('editGpaInput').value = gpa;
            
            // Show the modal
            if (editModal) editModal.show();
            
            // Focus on the first input
            setTimeout(() => document.getElementById('editSemesterInput').focus(), 120);
        });
    });


    // simple client validation for Add GPA (Original)
    const addForm = document.getElementById('addGpaForm');
    if (addForm) addForm.addEventListener('submit', function (e) {
      const sem = parseInt(document.getElementById('semesterInput').value || '0', 10);
      const gpa = parseFloat(document.getElementById('gpaInput').value || '');
      if (!sem || isNaN(sem) || sem <= 0) { showToast('Semester must be a positive integer', 2200, 'error'); e.preventDefault(); return; }
      if (isNaN(gpa) || gpa < 0 || gpa > 4) { showToast('GPA must be between 0.00 and 4.00', 2200, 'error'); e.preventDefault(); return; }
    });
    
    // simple client validation for Edit GPA (NEW)
    const editForm = document.getElementById('editGpaForm');
    if (editForm) editForm.addEventListener('submit', function (e) {
      const sem = parseInt(document.getElementById('editSemesterInput').value || '0', 10);
      const gpa = parseFloat(document.getElementById('editGpaInput').value || '');
      if (!sem || isNaN(sem) || sem <= 0) { showToast('Semester must be a positive integer', 2200, 'error'); e.preventDefault(); return; }
      if (isNaN(gpa) || gpa < 0 || gpa > 4) { showToast('GPA must be between 0.00 and 4.00', 2200, 'error'); e.preventDefault(); return; }
    });


    // toast helper (Unchanged)
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