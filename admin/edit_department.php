<?php
// admin/edit_department.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: departments.php'); exit; }

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];
$errors = [];

$stmt = $pdo->prepare("SELECT DepartmentID, Dept_Code, Dept_Name FROM department WHERE DepartmentID = :id LIMIT 1");
$stmt->execute([':id'=>$id]);
$dept = $stmt->fetch();
if (!$dept) { $_SESSION['flash']="Department not found."; header('Location: departments.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) $errors[] = "Invalid CSRF token.";
  else {
    $code = trim($_POST['dept_code'] ?? '');
    $name = trim($_POST['dept_name'] ?? '');
    if ($code === '' || $name === '') $errors[] = "Code and name required.";
    else {
      // check code uniqueness (exclude self)
      $chk = $pdo->prepare("SELECT 1 FROM department WHERE Dept_Code = :code AND DepartmentID != :id LIMIT 1");
      $chk->execute([':code'=>$code, ':id'=>$id]);
      if ($chk->fetch()) $errors[] = "Department code already used by another department.";
      else {
        $pdo->prepare("UPDATE department SET Dept_Code = :code, Dept_Name = :name WHERE DepartmentID = :id")
            ->execute([':code'=>$code, ':name'=>$name, ':id'=>$id]);
        $_SESSION['flash'] = "Department updated.";
        header('Location: departments.php');
        exit;
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Department</title>
  <link rel="stylesheet" href="../style.css">
</head>
<body>
  <main class="admin-main">
    <div class="admin-container" style="max-width:700px;">
      <h1>Edit Department</h1>

      <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div><?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <div class="form-row">
          <label for="dept_code">Code</label>
          <input id="dept_code" name="dept_code" type="text" value="<?= e($_POST['dept_code'] ?? $dept['Dept_Code']) ?>" required>
        </div>
        <div class="form-row">
          <label for="dept_name">Name</label>
          <input id="dept_name" name="dept_name" type="text" value="<?= e($_POST['dept_name'] ?? $dept['Dept_Name']) ?>" required>
        </div>
        <div style="margin-top:12px;">
          <button class="btn" type="submit">Save</button>
          <a class="btn" href="departments.php" style="background:#94a3b8;">Cancel</a>
        </div>
      </form>
    </div>
  </main>
</body>
</html>
