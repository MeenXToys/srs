<?php
// admin/edit_student.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: students_manage.php'); exit; }
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

$stmt = $pdo->prepare("SELECT s.*, u.Email FROM student s LEFT JOIN `user` u ON u.UserID=s.UserID WHERE s.UserID=:id LIMIT 1");
$stmt->execute([':id'=>$id]); $student = $stmt->fetch();
if (!$student) { $_SESSION['flash']="Not found"; header('Location: students_manage.php'); exit; }

$classes = $pdo->query("SELECT cl.ClassID, cl.Class_Code, c.Course_Code, c.Course_Name FROM class cl LEFT JOIN course c ON c.CourseID=cl.CourseID ORDER BY c.Course_Name, cl.Class_Code")->fetchAll();
$errors=[];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) $errors[]="Invalid CSRF.";
  else {
    $full = trim($_POST['FullName'] ?? ''); $studentid = trim($_POST['StudentID'] ?? ''); $email = trim($_POST['Email'] ?? ''); $ic = trim($_POST['IC_Number'] ?? ''); $phone = trim($_POST['Phone'] ?? ''); $classid = (int)($_POST['ClassID'] ?? 0);
    if ($full==='') $errors[]="Full name required."; if ($studentid==='') $errors[]="Student ID required."; if ($classid<=0) $errors[]="Select class.";
    if (empty($errors)) {
      $pdo->prepare("UPDATE student SET StudentID=:sid, FullName=:name, IC_Number=:ic, Phone=:phone, ClassID=:cid WHERE UserID=:uid")->execute([':sid'=>$studentid,':name'=>$full,':ic'=>$ic,':phone'=>$phone,':cid'=>$classid,':uid'=>$id]);
      if (filter_var($email,FILTER_VALIDATE_EMAIL)) $pdo->prepare("UPDATE `user` SET Email=:email WHERE UserID=:uid")->execute([':email'=>$email,':uid'=>$id]);
      $_SESSION['flash']="Updated."; header('Location: students_manage.php'); exit;
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Edit Student</title><link rel="stylesheet" href="../style.css"></head>
<body>
  <main class="admin-main" style="max-width:900px;margin:30px auto;">
    <h1>Edit Student: <?= e($student['FullName']) ?></h1>
    <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="form-row"><label>Full Name</label><br><input name="FullName" value="<?= e($_POST['FullName'] ?? $student['FullName']) ?>" required></div>
      <div class="form-row"><label>Student ID</label><br><input name="StudentID" value="<?= e($_POST['StudentID'] ?? $student['StudentID']) ?>" required></div>
      <div class="form-row"><label>Email</label><br><input name="Email" value="<?= e($_POST['Email'] ?? $student['Email']) ?>"></div>
      <div class="form-row"><label>IC Number</label><br><input name="IC_Number" value="<?= e($_POST['IC_Number'] ?? $student['IC_Number']) ?>"></div>
      <div class="form-row"><label>Phone</label><br><input name="Phone" value="<?= e($_POST['Phone'] ?? $student['Phone']) ?>"></div>
      <div class="form-row"><label>Class</label><br>
        <select name="ClassID" required><option value="">-- select --</option>
          <?php foreach($classes as $c): $sel = ((isset($_POST['ClassID']) && $_POST['ClassID']==$c['ClassID']) || (!isset($_POST['ClassID']) && $student['ClassID']==$c['ClassID'])) ? 'selected' : ''; ?>
            <option value="<?= e($c['ClassID']) ?>" <?= $sel ?>><?= e($c['Course_Code'].' - '.$c['Course_Name'].' / '.$c['Class_Code']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Save</button> <a class="btn" href="students_manage.php">Back</a></div>
    </form>
  </main>
</body>
</html>
