<?php
// admin/students_manage.php (robust version that detects column names)
// Place at admin/students_manage.php and overwrite existing file.

require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

// Ensure CSRF token
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

// fallback e()
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Helpers
$dbName = defined('DB_NAME') ? DB_NAME : $pdo->query('SELECT DATABASE()')->fetchColumn();

function get_table_columns(PDO $pdo, string $db, string $table): array {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table");
    $stmt->execute([':db' => $db, ':table' => $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function pick_col(array $cols, array $preferred) {
    foreach ($preferred as $p) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $p) === 0) return $c;
        }
    }
    // try partial matches (e.g., contains 'email')
    foreach ($preferred as $p) {
        foreach ($cols as $c) {
            if (stripos($c, $p) !== false) return $c;
        }
    }
    return null;
}

// Read schema columns (if table missing, return empty array)
$studentCols = in_array('student', $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)) ? get_table_columns($pdo, $dbName, 'student') : [];
$classCols   = in_array('class', $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)) ? get_table_columns($pdo, $dbName, 'class') : [];
$courseCols  = in_array('course', $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)) ? get_table_columns($pdo, $dbName, 'course') : [];
$deptCols    = in_array('department', $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)) ? get_table_columns($pdo, $dbName, 'department') : [];
$userCols    = in_array('user', $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)) ? get_table_columns($pdo, $dbName, 'user') : [];

// Pick useful columns with flexible names
$studentUserFK = pick_col($studentCols, ['UserID','user_id','User_Id','userid']);
$studentStudentID = pick_col($studentCols, ['StudentID','student_id','studentno','student_number','Student_No']);
$studentFullName = pick_col($studentCols, ['FullName','full_name','name','student_name']);
$studentIC = pick_col($studentCols, ['IC_Number','IC','ic_number','ic']);
$studentPhone = pick_col($studentCols, ['Phone','phone','Tel','telephone']);
$studentClassFK = pick_col($studentCols, ['ClassID','class_id','Class_Id','classid']);

$classIdCol = pick_col($classCols, ['ClassID','class_id','id']);
$classCodeCol = pick_col($classCols, ['Class_Code','ClassCode','Code','ClassName','Name']);
$classCourseFK = pick_col($classCols, ['CourseID','course_id','Course_Id','CourseId']);

$courseIdCol = pick_col($courseCols, ['CourseID','course_id','id']);
$courseCodeCol = pick_col($courseCols, ['Course_Code','CourseCode','Code']);
$courseNameCol = pick_col($courseCols, ['Course_Name','CourseName','Name']);

$deptIdCol = pick_col($deptCols, ['DepartmentID','department_id','id']);
$deptCodeCol = pick_col($deptCols, ['Dept_Code','DeptCode','Code']);
$deptNameCol = pick_col($deptCols, ['Dept_Name','DeptName','Name']);

$userIdCol = pick_col($userCols, ['UserID','user_id','id']);
$userEmailCol = pick_col($userCols, ['Email','email','user_email']);

// Validate required minimal columns
$schemaErrors = [];
if (!$studentUserFK) $schemaErrors[] = "student.UserFK (UserID) column not found.";
if (!$studentFullName) $schemaErrors[] = "student.FullName column not found.";
if (!$studentClassFK) $schemaErrors[] = "student.ClassFK (ClassID) column not found.";
if (!$userEmailCol) $schemaErrors[] = "user.Email column not found.";

if (!empty($schemaErrors)) {
    // show friendly page with errors
    ?>
    <!doctype html>
    <html lang="en">
    <head><meta charset="utf-8"><title>Students — Schema error</title><link rel="stylesheet" href="../style.css"></head>
    <body>
      <main class="admin-main" style="max-width:1100px;margin:30px auto;">
        <h1>Students — Schema issue</h1>
        <div class="alert alert-danger">
          <p>The admin students page cannot run because required columns are missing from your database schema. Detected issues:</p>
          <ul>
            <?php foreach($schemaErrors as $se) echo '<li>' . e($se) . '</li>'; ?>
          </ul>
          <p>Please check your database tables (student / class / course / department / user) and ensure they contain the necessary columns, or share the output of <code>SHOW CREATE TABLE student;</code> etc. with the developer.</p>
        </div>
        <p><a class="btn" href="index.php">Back to Dashboard</a></p>
      </main>
    </body>
    </html>
    <?php
    exit;
}

// Pagination & search params
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$q = trim($_GET['q'] ?? '');

// Build WHERE clause for search using detected columns
$where = '';
$params = [];
if ($q !== '') {
    $like = "%$q%";
    $conds = [];
    if ($studentFullName) { $conds[] = "s.`$studentFullName` LIKE :q"; $params[':q'] = $like; }
    if ($studentStudentID) { $conds[] = "s.`$studentStudentID` LIKE :q"; $params[':q'] = $like; }
    if ($userEmailCol) { $conds[] = "u.`$userEmailCol` LIKE :q"; $params[':q'] = $like; }
    if (!empty($conds)) $where = 'WHERE ' . implode(' OR ', $conds);
}

// Handle POST actions (add / delete)
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            // collect inputs
            $FullName = trim($_POST['FullName'] ?? '');
            $StudentID = trim($_POST['StudentID'] ?? '');
            $Email = trim($_POST['Email'] ?? '');
            $IC_Number = trim($_POST['IC_Number'] ?? '');
            $Phone = trim($_POST['Phone'] ?? '');
            $ClassID = (int)($_POST['ClassID'] ?? 0);

            if ($FullName === '') $errors[] = "Full name is required.";
            if ($StudentID === '') $errors[] = "Student ID is required.";
            if ($Email === '' || !filter_var($Email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
            if ($ClassID <= 0) $errors[] = "Please select a class.";

            if (empty($errors)) {
                // check email uniqueness
                $chk = $pdo->prepare("SELECT 1 FROM `user` WHERE `$userEmailCol` = :email LIMIT 1");
                $chk->execute([':email' => $Email]);
                if ($chk->fetch()) {
                    $errors[] = "Email already in use.";
                } else {
                    try {
                        $pdo->beginTransaction();
                        $tempPw = bin2hex(random_bytes(4));
                        $pwHash = password_hash($tempPw, PASSWORD_DEFAULT);
                        // insert into user
                        $insUser = $pdo->prepare("INSERT INTO `user` (`$userEmailCol`, `Password`, `Role`, `Created_At`) VALUES (:email, :pw, 'Student', NOW())");
                        $insUser->execute([':email' => $Email, ':pw' => $pwHash]);
                        $newUserId = (int)$pdo->lastInsertId();
                        // prepare student insert columns dynamically
                        $insCols = [];
                        $insParams = [];
                        if ($studentUserFK) { $insCols[] = "`$studentUserFK`"; $insParams[':uid'] = $newUserId; }
                        if ($studentStudentID) { $insCols[] = "`$studentStudentID`"; $insParams[':sid'] = $StudentID; }
                        if ($studentClassFK) { $insCols[] = "`$studentClassFK`"; $insParams[':cid'] = $ClassID; }
                        if ($studentFullName) { $insCols[] = "`$studentFullName`"; $insParams[':name'] = $FullName; }
                        if ($studentIC) { $insCols[] = "`$studentIC`"; $insParams[':ic'] = $IC_Number; }
                        if ($studentPhone) { $insCols[] = "`$studentPhone`"; $insParams[':phone'] = $Phone; }
                        if (empty($insCols)) throw new Exception("No writable columns found in student table.");
                        $sqlCols = implode(',', $insCols);
                        $placeholders = implode(',', array_keys($insParams));
                        // Placeholders currently like :uid,:sid,:cid... but ensure order matches columns
                        // Build placeholders in same order as insCols
                        $orderedPlaceholders = [];
                        foreach ($insCols as $col) {
                            $clean = trim($col, '`');
                            if ($clean === $studentUserFK) $orderedPlaceholders[] = ':uid';
                            elseif ($clean === $studentStudentID) $orderedPlaceholders[] = ':sid';
                            elseif ($clean === $studentClassFK) $orderedPlaceholders[] = ':cid';
                            elseif ($clean === $studentFullName) $orderedPlaceholders[] = ':name';
                            elseif ($clean === $studentIC) $orderedPlaceholders[] = ':ic';
                            elseif ($clean === $studentPhone) $orderedPlaceholders[] = ':phone';
                            else $orderedPlaceholders[] = ':' . $clean;
                        }
                        $sqlPlaceholders = implode(',', $orderedPlaceholders);

                        $insSql = "INSERT INTO `student` ($sqlCols) VALUES ($sqlPlaceholders)";
                        $stmt = $pdo->prepare($insSql);
                        $stmt->execute($insParams);
                        $pdo->commit();
                        $_SESSION['flash'] = "Student added. Temporary password: {$tempPw}";
                        header('Location: students_manage.php');
                        exit;
                    } catch (Exception $ex) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = "Database error: " . $ex->getMessage();
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) $errors[] = "Invalid ID.";
            // prevent deleting self
            if (isset($_SESSION['user']['UserID']) && (int)$_SESSION['user']['UserID'] === $id) $errors[] = "You cannot delete your own account.";
            if (empty($errors)) {
                try {
                    // delete from student then user using detected columns
                    $delStmt = $pdo->prepare("DELETE FROM `student` WHERE `$studentUserFK` = :id");
                    $delStmt->execute([':id' => $id]);
                    // Delete user row (use user id column)
                    $userIdCol = $userIdCol ?? $studentUserFK;
                    $delUser = $pdo->prepare("DELETE FROM `user` WHERE `$userIdCol` = :id");
                    $delUser->execute([':id' => $id]);
                    $_SESSION['flash'] = "Student deleted.";
                    header('Location: students_manage.php');
                    exit;
                } catch (Exception $ex) {
                    $errors[] = "Delete failed: " . $ex->getMessage();
                }
            }
        }
    }
}

// Count total
$countSql = "SELECT COUNT(*) FROM `student` s LEFT JOIN `user` u ON u.`$userIdCol` = s.`$studentUserFK` $where";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k=>$v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

// Build SELECT list dynamically
$selectList = [];
// student fields
$selectList[] = "s.`$studentUserFK` AS UserID";
if ($studentStudentID) $selectList[] = "s.`$studentStudentID` AS StudentID"; else $selectList[] = "s.`$studentUserFK` AS StudentID";
$selectList[] = "s.`$studentFullName` AS FullName";
if ($studentIC) $selectList[] = "s.`$studentIC` AS IC_Number";
if ($studentPhone) $selectList[] = "s.`$studentPhone` AS Phone";
// class
$selectList[] = "cl.`$classIdCol` AS ClassID";
$selectList[] = ($classCodeCol ? "cl.`$classCodeCol` AS Class_Code" : "cl.`$classIdCol` AS Class_Code");
// course
if ($courseCodeCol) $selectList[] = "c.`$courseCodeCol` AS Course_Code";
if ($courseNameCol) $selectList[] = "c.`$courseNameCol` AS Course_Name";
// dept
if ($deptCodeCol) $selectList[] = "d.`$deptCodeCol` AS Dept_Code";
// user email
$selectList[] = "u.`$userEmailCol` AS Email";

$selectSql = "SELECT " . implode(', ', $selectList) . "
FROM `student` s
LEFT JOIN `user` u ON u.`$userIdCol` = s.`$studentUserFK`
LEFT JOIN `class` cl ON cl.`$classIdCol` = s.`$studentClassFK`
LEFT JOIN `course` c ON c.`$courseIdCol` = cl.`$classCourseFK`
LEFT JOIN `department` d ON d.`$deptIdCol` = c.`$courseIdCol`";

// Note: if some joins used null identifiers, the query may break; we validated required columns earlier.
$selectSql .= " $where ORDER BY s.`$studentFullName` LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($selectSql);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

try {
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    $students = [];
    $errors[] = "Failed to load students: " . $ex->getMessage();
}

// Load classes for add form (list with course info if available)
$classes = [];
try {
    $classSelect = "SELECT cl.`$classIdCol` AS ClassID";
    if ($classCodeCol) $classSelect .= ", cl.`$classCodeCol` AS Class_Code";
    if ($courseCodeCol) $classSelect .= ", c.`$courseCodeCol` AS Course_Code";
    if ($courseNameCol) $classSelect .= ", c.`$courseNameCol` AS Course_Name";
    $classSelect .= " FROM `class` cl LEFT JOIN `course` c ON c.`$courseIdCol` = cl.`$classCourseFK` ORDER BY c.`$courseNameCol` IS NULL, c.`$courseNameCol` ASC, cl.`$classIdCol` ASC";
    $classes = $pdo->query($classSelect)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    // ignore; classes may be empty
    $classes = [];
}

// flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<main class="admin-main">
  <div class="admin-container">
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Students — Admin</title>
  <link rel="stylesheet" href="../style.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .form-row{margin-bottom:10px}
    .btn-danger{background:#ef4444;color:#fff;padding:6px 8px;border-radius:6px;border:none}
    .small-muted{color:#94a3b8}
    table.admin th, table.admin td{ padding:10px 12px; border-top:1px solid rgba(255,255,255,0.03); color:#cbd5e1; }
    table.admin thead th{ color:#94a3b8; text-align:left; }
  </style>
</head>
<body>
  <main class="admin-main" style="max-width:1100px;margin:30px auto;">
    <h1>Students</h1>

    <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
    <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div><?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <form method="get" style="display:flex;gap:8px;align-items:center;">
        <input name="q" placeholder="Search name / student id / email" value="<?= e($q) ?>">
        <button class="btn" type="submit">Search</button>
        <a class="btn" href="students_manage.php">Reset</a>
      </form>

      <div>
        <a class="btn" href="export_all.php">Export All CSV</a>
        <a class="btn" href="index.php">Back</a>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 380px;gap:14px;">
      <section>
        <table class="admin" style="width:100%">
          <thead>
            <tr>
              <th>UserID</th><th>StudentID</th><th>Name</th><th>Class / Course</th><th>Dept</th><th>Email</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($students)): ?>
              <tr><td colspan="7" class="small-muted">No students.</td></tr>
            <?php else: foreach($students as $s): ?>
              <tr>
                <td><?= e($s['UserID'] ?? '') ?></td>
                <td><?= e($s['StudentID'] ?? '-') ?></td>
                <td><?= e($s['FullName'] ?? '-') ?></td>
                <td><?= e($s['Class_Code'] ?? '-') ?> / <?= e($s['Course_Code'] ?? ($s['Course_Name'] ?? '-')) ?></td>
                <td><?= e($s['Dept_Code'] ?? '-') ?></td>
                <td><?= e($s['Email'] ?? '-') ?></td>
                <td>
                  <a class="btn" href="edit_student.php?id=<?= urlencode($s['UserID'] ?? '') ?>">Edit</a>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete student <?= e(addslashes($s['FullName'] ?? '')) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= e($s['UserID'] ?? '') ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>

        <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
          <?php if ($page>1): ?><a class="btn" href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>">&laquo; Prev</a><?php endif; ?>
          <span>Page <?= $page ?> / <?= $pages ?></span>
          <?php if ($page < $pages): ?><a class="btn" href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>">Next &raquo;</a><?php endif; ?>
        </div>
      </section>

      <aside>
        <h3>Add Student</h3>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="add">

          <div class="form-row"><label>Full name</label><br><input name="FullName" required></div>
          <div class="form-row"><label>Student ID</label><br><input name="StudentID" required></div>
          <div class="form-row"><label>Email</label><br><input name="Email" type="email" required></div>
          <div class="form-row"><label>IC Number</label><br><input name="IC_Number"></div>
          <div class="form-row"><label>Phone</label><br><input name="Phone"></div>
          <div class="form-row"><label>Class</label><br>
            <select name="ClassID" required>
              <option value="">-- select --</option>
              <?php foreach($classes as $cl): $val = $cl['ClassID'] ?? ''; $label = trim(($cl['Class_Code'] ?? '') . ' ' . ($cl['Course_Code'] ?? ' ' . ($cl['Course_Name'] ?? ''))); ?>
                <option value="<?= e($val) ?>"><?= e($label ?: $val) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Add Student</button></div>
        </form>
      </aside>
    </div>
  </main>
</body>
</html>
</div>
</main>
