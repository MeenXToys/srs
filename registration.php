<?php
// registration.php - aligned with V4 database schema
require_once __DIR__ . '/config.php';
global $pdo;

$errors = [];
$old = [];

// Load dropdown options
try {
    $departments = $pdo->query("
    SELECT DepartmentID, Dept_Code, Dept_Name
    FROM department
    WHERE deleted_at IS NULL
    ORDER BY Dept_Name 
")->fetchAll();
    $courses     = $pdo->query("SELECT CourseID, Course_Code, Course_Name, DepartmentID FROM course ORDER BY Course_Name")->fetchAll();
    $semesters   = $pdo->query("SELECT DISTINCT Semester FROM class ORDER BY Semester")->fetchAll();
    $classes     = $pdo->query("SELECT ClassID, Class_Name, Semester, CourseID FROM class ORDER BY Class_Name")->fetchAll();
} catch (Exception $e) {
    die("❌ Database error loading dropdowns: " . htmlspecialchars($e->getMessage()));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['email']      = trim($_POST['email'] ?? '');
    $old['password']   = $_POST['password'] ?? '';
    $old['student_id'] = trim($_POST['student_id'] ?? '');
    $old['semester']   = trim($_POST['semester'] ?? '');
    $old['name']       = trim($_POST['name'] ?? '');
    $old['department'] = trim($_POST['department'] ?? '');
    $old['course']     = trim($_POST['course'] ?? '');
    $old['ic']         = trim($_POST['ic'] ?? '');
    $old['phone']      = trim($_POST['phone'] ?? '');
    $old['class']      = trim($_POST['class'] ?? '');

    // Validation
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email required.";
    if (strlen($old['password']) < 6) $errors[] = "Password must be at least 6 characters.";
    if ($old['name'] === '') $errors[] = "Name required.";
    if ($old['student_id'] === '') $errors[] = "Student ID required.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Check email uniqueness
            $stmt = $pdo->prepare("SELECT UserID FROM `user` WHERE Email = ?");
            $stmt->execute([$old['email']]);
            if ($stmt->fetch()) throw new Exception("Email already registered.");

            // Check IC uniqueness
            if ($old['ic'] !== '') {
                $stmt = $pdo->prepare("SELECT UserID FROM student WHERE IC_Number = ?");
                $stmt->execute([$old['ic']]);
                if ($stmt->fetch()) throw new Exception("IC number already registered.");
            }

            // Insert new user
            $pwHash = password_hash($old['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `user` (Password_Hash, Role, Email, Created_At) VALUES (?, 'Student', ?, NOW())");
            $stmt->execute([$pwHash, $old['email']]);
            $userId = $pdo->lastInsertId();

            // Validate ClassID
            $classId = null;
            if (!empty($old['class'])) {
                $stmt = $pdo->prepare("SELECT ClassID FROM class WHERE ClassID = ? LIMIT 1");
                $stmt->execute([$old['class']]);
                if ($r = $stmt->fetch()) $classId = $r['ClassID'];
            }

            // Insert into student (matches V4 schema)
            $stmt = $pdo->prepare("
                INSERT INTO student (UserID, StudentID, ClassID, FullName, IC_Number, Phone)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $old['student_id'],
                $classId,
                $old['name'],
                $old['ic'] ?: null,
                $old['phone'] ?: null
            ]);

            $pdo->commit();
            header("Location: login.php?registered=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

// Helper to echo old values
function old($k, $d = '') { global $old; return htmlspecialchars($old[$k] ?? $d, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Register — GMI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
  
  <style>
    /* 1. Pastikan body adalah kontena penuh */
    html, body {
        min-height: 100vh;
        height: 100%;
        margin: 0;
        padding: 0;
    }
    body {
        /* Jadikan body Flex Container untuk menempatkan nav dan wrapper utama */
        display: flex;
        flex-direction: column;
    }

    .right-section {
      padding-top: 5px !important;  
    /* 2. Kontena Baru untuk bahagian Kiri dan Kanan */
    .main-content-wrapper {
        display: flex; /* KUNCI: Aktifkan Flexbox */
        flex-direction: row;
        flex-grow: 1; /* Biarkan ia mengisi ruang yang tinggal */
    }

    /* 3. Pastikan seksyen kiri dan kanan mempunyai lebar dan meregang penuh dalam wrapper */
    .left-section, .right-section {
        width: 50%;
        /* Pastikan gaya CSS lain (background, padding, dll.) berada dalam style.css asal anda */
    }
    
    /* 4. Buang float untuk seksyen Kiri/Kanan jika ada, atau pastikan ia diletakkan di dalam wrapper Flexbox */
    /* Jika .left-section dan .right-section anda menggunakan float di style.css, anda mungkin perlu mengalih keluar float:left/right di sana */
    /* Jika .right-section adalah yang mengandungi banyak kandungan, ia akan menentukan ketinggian, dan .left-section akan mengikutinya. */
  </style>
</head>
<body>
<?php include 'nav.php'; ?>

<div class="main-content-wrapper">
    <div class="left-section">
      <div class="left-content">
        <h1>STUDENT REGISTRATION SYSTEM</h1>
      </div>
    </div>

    <div class="right-section">
      <div class="registration-form">
        <h1>REGISTRATION</h1>

        <?php if ($errors): ?>
          <div style="background:#ffe6e6;padding:10px;border-radius:8px;color:#900;margin-bottom:12px;">
            <?php foreach ($errors as $e): ?><div><?=htmlspecialchars($e)?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" action="registration.php" novalidate>
          <div class="form-row">
            <div class="form-group">
              <label>Email:</label>
              <input type="email" name="email" required value="<?= old('email') ?>">
            </div>
            <div class="form-group">
              <label>Password:</label>
              <input type="password" name="password" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Student ID:</label>
              <input type="text" name="student_id" required value="<?= old('student_id') ?>">
            </div>
            <div class="form-group">
              <label>Semester:</label>
              <select name="semester" required>
                <option value="">-- Select Semester --</option>
                <?php foreach ($semesters as $s): $val = $s['Semester']; ?>
                  <option value="<?= htmlspecialchars($val) ?>" <?= old('semester') == $val ? 'selected' : '' ?>>Semester <?= htmlspecialchars($val) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Name:</label>
              <input type="text" name="name" required value="<?= old('name') ?>">
            </div>
            <div class="form-group">
              <label>Department:</label>
              <select name="department" id="department-select" required>
                <option value="">-- Select Department --</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['DepartmentID'] ?>" <?= old('department') == $d['DepartmentID'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['Dept_Code'] . ' - ' . $d['Dept_Name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>IC Number:</label>
              <input type="text" name="ic" value="<?= old('ic') ?>">
            </div>
            <div class="form-group">
              <label>Course:</label>
              <select name="course" id="course-select" required>
                <option value="">-- Select Course --</option>
                <?php foreach ($courses as $c): ?>
                  <option value="<?= $c['CourseID'] ?>" data-dept="<?= $c['DepartmentID'] ?>" <?= old('course') == $c['CourseID'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['Course_Code'] . ' - ' . $c['Course_Name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Phone Number:</label>
              <input type="text" name="phone" value="<?= old('phone') ?>">
            </div>
            <div class="form-group">
              <label>Class:</label>
              <select name="class" id="class-select" required>
                <option value="">-- Select Class --</option>
                <?php foreach ($classes as $cl): ?>
                  <option value="<?= $cl['ClassID'] ?>" data-course="<?= $cl['CourseID'] ?>" data-sem="<?= $cl['Semester'] ?>" <?= old('class') == $cl['ClassID'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cl['Class_Name'] . ' (Sem ' . $cl['Semester'] . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <button class="register-button" type="submit">REGISTER</button>
        </form>
      </div>
    </div>
</div> <script>
// Filter courses and classes dynamically
document.addEventListener('DOMContentLoaded', function(){
  const deptSel = document.getElementById('department-select');
  const courseSel = document.getElementById('course-select');
  const classSel = document.getElementById('class-select');

  function filterCourses(){
    const dept = deptSel.value;
    Array.from(courseSel.options).forEach(opt => {
      if (!opt.value) return;
      opt.hidden = dept && opt.dataset.dept !== dept;
    });
    if (courseSel.selectedOptions.length && courseSel.selectedOptions[0].hidden) courseSel.value = '';
    filterClasses();
  }

  function filterClasses(){
    const course = courseSel.value;
    Array.from(classSel.options).forEach(opt => {
      if (!opt.value) return;
      opt.hidden = course && opt.dataset.course !== course;
    });
    if (classSel.selectedOptions.length && classSel.selectedOptions[0].hidden) classSel.value = '';
  }

  deptSel.addEventListener('change', filterCourses);
  courseSel.addEventListener('change', filterClasses);
});
</script>
</body>
</html>