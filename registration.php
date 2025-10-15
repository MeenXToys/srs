<?php
require_once 'config.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $student_id = trim($_POST['student_id'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $ic = trim($_POST['ic'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $class = trim($_POST['class'] ?? '');

    if (!$email) $errors[] = "Valid email required.";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters.";
    if (!$name) $errors[] = "Name required.";
    if (!$student_id) $errors[] = "Student ID required.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT UserID FROM `user` WHERE Email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) throw new Exception("Email already registered.");

            $pwHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `user` (Password_Hash, Role, Email, Created_At) VALUES (?, 'Student', ?, NOW())");
            $stmt->execute([$pwHash, $email]);
            $userId = $pdo->lastInsertId();

            $classId = null;
            if ($class) {
                $stmt = $pdo->prepare("SELECT ClassID FROM `class` WHERE Class_Name = ? LIMIT 1");
                $stmt->execute([$class]);
                $row = $stmt->fetch();
                if ($row) $classId = $row['ClassID'];
            }

            $stmt = $pdo->prepare("INSERT INTO student (UserID, ClassID, FullName, IC_Number, Phone, Semester, Student_Number) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $classId, $name, $ic, $phone, $semester, $student_id]);

            $pdo->commit();

            header("Location: login.php?registered=1");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Register — GMI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <?php include 'nav.php'; ?>

  <div class="left-section">
    <div class="left-content">
      <h1>STUDENT REGISTRATION SYSTEM</h1>
    </div>
  </div>

  <div class="right-section">
    <div class="registration-form">
      <h1>REGISTRATION</h1>

      <?php if (!empty($errors)): ?>
        <div style="background:#ffe6e6;padding:10px;border-radius:8px;color:#900;margin-bottom:12px;">
          <?php foreach ($errors as $e): ?>
            <div><?=htmlspecialchars($e)?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="registration.php">
        <div class="form-row">
          <div class="form-group">
            <label>E-Mail:</label>
            <input type="email" name="email" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
          </div>
          <div class="form-group">
            <label>Password:</label>
            <input type="password" name="password" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Student ID:</label>
            <input type="text" name="student_id" required value="<?=htmlspecialchars($_POST['student_id'] ?? '')?>">
          </div>
          <div class="form-group">
            <label>Semester:</label>
            <select name="semester" required>
              <option value="">-- Select Semester --</option>
              <?php for($s=1;$s<=6;$s++): $val="Sem$s"; ?>
                <option value="<?=$val?>" <?= (($_POST['semester']??'')===$val ? 'selected':'') ?>>Semester <?=$s?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group"><label>Name:</label><input type="text" name="name" required value="<?=htmlspecialchars($_POST['name'] ?? '')?>"></div>
          <div class="form-group"><label>Department:</label>
            <select name="department">
              <option value="">-- Select Department --</option>
              <option value="EED">EED – Electrical Engineering Dept.</option>
              <option value="MED">MED – Mechanical Engineering Dept.</option>
              <option value="CID">CID – Computer & Info Dept.</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group"><label>IC Number:</label><input type="text" name="ic" value="<?=htmlspecialchars($_POST['ic'] ?? '')?>"></div>
          <div class="form-group"><label>Course:</label>
            <select name="course"><option value="">-- Select Course --</option></select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group"><label>Phone Number:</label><input type="text" name="phone" value="<?=htmlspecialchars($_POST['phone'] ?? '')?>"></div>
          <div class="form-group"><label>Class:</label>
            <select name="class"><option value="">-- Select Class --</option></select>
          </div>
        </div>

        <button class="register-button" type="submit">REGISTER</button>
      </form>
    </div>
  </div>

  <div style="clear:both;"></div>
</body>
</html>
