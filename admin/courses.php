<?php
// admin/courses.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token']; $errors=[];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) $errors[]="Invalid CSRF.";
  else {
    $action = $_POST['action'] ?? '';
    if ($action==='add') {
      $code = trim($_POST['course_code'] ?? ''); $name = trim($_POST['course_name'] ?? ''); $dept = (int)($_POST['department_id'] ?? 0);
      if ($code===''||$name===''||$dept<=0) $errors[]="Course code, name and department required.";
      else {
        $chk = $pdo->prepare("SELECT 1 FROM course WHERE Course_Code = :c LIMIT 1"); $chk->execute([':c'=>$code]);
        if ($chk->fetch()) $errors[]="Course code exists.";
        else { $pdo->prepare("INSERT INTO course (Course_Code, Course_Name, DepartmentID) VALUES (:c,:n,:d)")->execute([':c'=>$code,':n'=>$name,':d'=>$dept]); $_SESSION['flash']="Course added."; header('Location: courses.php'); exit; }
      }
    } elseif ($action==='delete') { $id=(int)($_POST['id']??0); if ($id>0){ $pdo->prepare("DELETE FROM course WHERE CourseID=:id")->execute([':id'=>$id]); $_SESSION['flash']="Deleted."; header('Location: courses.php'); exit; } }
  }
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$departments = $pdo->query("SELECT DepartmentID, Dept_Code, Dept_Name FROM department ORDER BY Dept_Name")->fetchAll();
$courses = $pdo->query("SELECT c.CourseID, c.Course_Code, c.Course_Name, d.Dept_Code FROM course c LEFT JOIN department d ON d.DepartmentID=c.DepartmentID ORDER BY c.Course_Name")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Courses</title><link rel="stylesheet" href="../style.css"></head>
<body>
  <main class="admin-main" style="max-width:1100px;margin:30px auto;">
    <h1>Courses</h1>
    <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div><?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:14px;">
      <section>
        <table class="admin" style="width:100%"><thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Dept</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (empty($courses)): ?><tr><td colspan="5" class="small-muted">No courses.</td></tr><?php else: foreach($courses as $c): ?>
              <tr>
                <td><?= e($c['CourseID']) ?></td>
                <td><?= e($c['Course_Code']) ?></td>
                <td><?= e($c['Course_Name']) ?></td>
                <td><?= e($c['Dept_Code'] ?? '-') ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('Delete course?');" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= e($c['CourseID']) ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </section>

      <aside>
        <h3>Add Course</h3>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="add">
          <div class="form-row"><label>Course Code</label><br><input name="course_code" required></div>
          <div class="form-row"><label>Course Name</label><br><input name="course_name" required></div>
          <div class="form-row"><label>Department</label><br>
            <select name="department_id" required><option value="">-- select --</option><?php foreach($departments as $d): ?><option value="<?= e($d['DepartmentID']) ?>"><?= e($d['Dept_Code'].' - '.$d['Dept_Name']) ?></option><?php endforeach; ?></select>
          </div>
          <div><button class="btn btn-primary" type="submit">Add Course</button></div>
        </form>
      </aside>
    </div>
  </main>
</body>
</html>
