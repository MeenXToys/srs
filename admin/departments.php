<?php
// admin/departments.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];
$errors=[];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) $errors[]="Invalid CSRF.";
  else {
    $action = $_POST['action'] ?? '';
    if ($action==='add') {
      $code = trim($_POST['dept_code'] ?? ''); $name = trim($_POST['dept_name'] ?? '');
      if ($code===''||$name==='') $errors[]="Code and name required.";
      else {
        $chk = $pdo->prepare("SELECT 1 FROM department WHERE Dept_Code = :code LIMIT 1"); $chk->execute([':code'=>$code]);
        if ($chk->fetch()) $errors[]="Department code exists.";
        else { $pdo->prepare("INSERT INTO department (Dept_Code, Dept_Name) VALUES (:code,:name)")->execute([':code'=>$code,':name'=>$name]); $_SESSION['flash']="Department added."; header('Location: departments.php'); exit; }
      }
    } elseif ($action==='delete') { $id=(int)($_POST['id']??0); if ($id>0){ $pdo->prepare("DELETE FROM department WHERE DepartmentID=:id")->execute([':id'=>$id]); $_SESSION['flash']="Deleted."; header('Location: departments.php'); exit; } }
  }
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$departments = $pdo->query("SELECT DepartmentID, Dept_Code, Dept_Name FROM department ORDER BY Dept_Name")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Departments</title><link rel="stylesheet" href="../style.css"></head>
<body>
  <?php /* admin_nav already rendered */ ?>
  <main class="admin-main" style="max-width:1100px;margin:30px auto;">
    <h1>Departments</h1>
    <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div><?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:14px;">
      <section>
        <table class="admin" style="width:100%"><thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (empty($departments)): ?><tr><td colspan="4" class="small-muted">No departments.</td></tr><?php else: foreach($departments as $d): ?>
              <tr>
                <td><?= e($d['DepartmentID']) ?></td>
                <td><?= e($d['Dept_Code']) ?></td>
                <td><?= e($d['Dept_Name']) ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('Delete department?');" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= e($d['DepartmentID']) ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </section>

      <aside>
        <h3>Add Department</h3>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="add">
          <div class="form-row"><label>Code</label><br><input name="dept_code" required></div>
          <div class="form-row"><label>Name</label><br><input name="dept_name" required></div>
          <div><button class="btn btn-primary" type="submit">Add</button></div>
        </form>
      </aside>
    </div>
  </main>
</body>
</html>
