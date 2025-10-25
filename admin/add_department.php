<?php
// admin/add_department.php
require_once __DIR__ . '/../config.php';
require_admin();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    }

    $code = trim($_POST['dept_code'] ?? '');
    $name = trim($_POST['dept_name'] ?? '');

    if ($code === '') $errors[] = "Department code is required.";
    if ($name === '') $errors[] = "Department name is required.";

    if (empty($errors)) {
        // Prevent duplicate code
        $stmt = $pdo->prepare("SELECT 1 FROM department WHERE Dept_Code = :code LIMIT 1");
        $stmt->execute([':code' => $code]);
        if ($stmt->fetch()) {
            $errors[] = "Department code already exists.";
        } else {
            $ins = $pdo->prepare("INSERT INTO department (Dept_Code, Dept_Name) VALUES (:code, :name)");
            $ins->execute([':code' => $code, ':name' => $name]);
            $_SESSION['flash'] = "Department added.";
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Add Department — Admin</title>
  <link rel="stylesheet" href="../style.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>.form-row{margin-bottom:12px}</style>
</head>
<body>
  <?php include __DIR__ . '/../nav.php'; ?>
  <main style="max-width:800px;margin:28px auto;padding:18px;">
    <h1>Add Department</h1>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul><?php foreach($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
      <div class="form-row">
        <label>Department Code</label><br>
        <input name="dept_code" value="<?= e($_POST['dept_code'] ?? '') ?>" required>
      </div>
      <div class="form-row">
        <label>Department Name</label><br>
        <input name="dept_name" value="<?= e($_POST['dept_name'] ?? '') ?>" required>
      </div>
      <div>
        <button class="btn btn-primary" type="submit">Add Department</button>
        <a href="index.php" class="btn">Back</a>
      </div>
    </form>
  </main>
</body>
</html>
