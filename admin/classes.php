<?php
// admin/classes.php
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
      $code = trim($_POST['class_code'] ?? ''); $course = (int)($_POST['course_id'] ?? 0);
      if ($code===''||$course<=0) $errors[]="Class code and course required.";
      else {
        $chk=$pdo->prepare("SELECT 1 FROM class WHERE Class_Code=:c AND CourseID=:cid LIMIT 1"); $chk->execute([':c'=>$code,':cid'=>$course]);
        if ($chk->fetch()) $errors[]="Class exists for this course.";
        else { $pdo->prepare("INSERT INTO class (Class_Code, CourseID) VALUES (:c,:cid)")->execute([':c'=>$code,':cid'=>$course]); $_SESSION['flash']="Class added."; header('Location: classes.php'); exit; }
      }
    } elseif ($action==='delete') { $id=(int)($_POST['id']??0); if ($id>0){ $pdo->prepare("DELETE FROM class WHERE ClassID=:id")->execute([':id'=>$id]); $_SESSION['flash']="Deleted."; header('Location: classes.php'); exit; } }
  }
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$courses = $pdo->query("SELECT CourseID, Course_Code, Course_Name FROM course ORDER BY Course_Name")->fetchAll();
$classes = $pdo->query("SELECT cl.ClassID, cl.Class_Code, c.Course_Code, c.Course_Name FROM class cl LEFT JOIN course c ON c.CourseID = cl.CourseID ORDER BY c.Course_Name, cl.Class_Code")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Classes</title><link rel="stylesheet" href="../style.css"></head>
<body>
  <main class="admin-main" style="max-width:1100px;margin:30px auto;">
    <h1>Classes</h1>
    <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div><?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:14px;">
      <section>
        <table class="admin" style="width:100%"><thead><tr><th>ID</th><th>Class Code</th><th>Course</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (empty($classes)): ?><tr><td colspan="4" class="small-muted">No classes.</td></tr><?php else: foreach($classes as $cl): ?>
              <tr>
                <td><?= e($cl['ClassID']) ?></td>
                <td><?= e($cl['Class_Code']) ?></td>
                <td><?= e($cl['Course_Code'].' - '.$cl['Course_Name']) ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('Delete class?');" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= e($cl['ClassID']) ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </section>

      <aside>
        <h3>Add Class</h3>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="add">
          <div class="form-row"><label>Class Code</label><br><input name="class_code" required></div>
          <div class="form-row"><label>Course</label><br>
            <select name="course_id" required><option value="">-- select --</option><?php foreach($courses as $c): ?><option value="<?= e($c['CourseID']) ?>"><?= e($c['Course_Code'].' - '.$c['Course_Name']) ?></option><?php endforeach; ?></select>
          </div>
          <div><button class="btn btn-primary" type="submit">Add Class</button></div>
        </form>
      </aside>
    </div>
  </main>
</body>
</html>
