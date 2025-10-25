<?php
// admin/students_manage.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];
$errors=[]; $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// Pagination & search
$perPage = 20; $page = max(1,(int)($_GET['page']??1)); $offset = ($page-1)*$perPage;
$q = trim($_GET['q'] ?? '');
$where=''; $params=[];
if ($q!==''){ $where="WHERE (s.FullName LIKE :q OR s.StudentID LIKE :q OR u.Email LIKE :q)"; $params[':q']="%$q%"; }

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) $errors[]="Invalid CSRF.";
  else {
    $action = $_POST['action'] ?? '';
    if ($action==='add') {
      $FullName = trim($_POST['FullName'] ?? ''); $StudentID = trim($_POST['StudentID'] ?? ''); $Email = trim($_POST['Email'] ?? '');
      $IC_Number = trim($_POST['IC_Number'] ?? ''); $Phone = trim($_POST['Phone'] ?? ''); $ClassID = (int)($_POST['ClassID'] ?? 0);
      if ($FullName===''||$StudentID===''||$Email===''||!filter_var($Email,FILTER_VALIDATE_EMAIL)||$ClassID<=0) $errors[]="Please fill required fields correctly.";
      if (empty($errors)) {
        $chk = $pdo->prepare("SELECT 1 FROM `user` WHERE Email = :e LIMIT 1"); $chk->execute([':e'=>$Email]);
        if ($chk->fetch()) $errors[]="Email already used.";
        else {
          try {
            $pdo->beginTransaction();
            $pw = bin2hex(random_bytes(4)); $pwHash = password_hash($pw, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO `user` (Email, Password, Role, Created_At) VALUES (:e,:p,'Student',NOW())")->execute([':e'=>$Email,':p'=>$pwHash]);
            $uid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO student (UserID, StudentID, ClassID, FullName, IC_Number, Phone) VALUES (:uid,:sid,:cid,:name,:ic,:phone)")->execute([':uid'=>$uid,':sid'=>$StudentID,':cid'=>$ClassID,':name'=>$FullName,':ic'=>$IC_Number,':phone'=>$Phone]);
            $pdo->commit();
            $_SESSION['flash']="Student added. Temp password: $pw";
            header('Location: students_manage.php'); exit;
          } catch (Exception $ex) { $pdo->rollBack(); $errors[]="DB error: ".$ex->getMessage(); }
        }
      }
    } elseif ($action==='delete') {
      $id=(int)($_POST['id']??0);
      if ($id>0) {
        if (isset($_SESSION['user']['UserID']) && (int)$_SESSION['user']['UserID'] === $id) $errors[]="You cannot delete your own account.";
        else {
          $pdo->prepare("DELETE FROM student WHERE UserID=:id")->execute([':id'=>$id]);
          $pdo->prepare("DELETE FROM `user` WHERE UserID=:id")->execute([':id'=>$id]);
          $_SESSION['flash']="Student deleted."; header('Location: students_manage.php'); exit;
        }
      } else $errors[]="Invalid ID.";
    }
  }
}

// count and fetch
$countSql = "SELECT COUNT(*) FROM student s LEFT JOIN `user` u ON u.UserID = s.UserID $where";
$stmt = $pdo->prepare($countSql);
foreach($params as $k=>$v) $stmt->bindValue($k,$v);
$stmt->execute(); $total = (int)$stmt->fetchColumn(); $pages = max(1,(int)ceil($total/$perPage));

$sql = "SELECT s.UserID, s.StudentID, s.FullName, s.IC_Number, s.Phone, cl.ClassID, cl.Class_Code, c.Course_Code, d.Dept_Code, u.Email
  FROM student s
  LEFT JOIN `user` u ON u.UserID = s.UserID
  LEFT JOIN class cl ON cl.ClassID = s.ClassID
  LEFT JOIN course c ON c.CourseID = cl.CourseID
  LEFT JOIN department d ON d.DepartmentID = c.DepartmentID
  $where
  ORDER BY s.FullName
  LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach($params as $k=>$v) $stmt->bindValue($k,$v);
$stmt->bindValue(':limit',(int)$perPage,PDO::PARAM_INT); $stmt->bindValue(':offset',(int)$offset,PDO::PARAM_INT); $stmt->execute();
$students = $stmt->fetchAll();
$classes = $pdo->query("SELECT cl.ClassID, cl.Class_Code, c.Course_Code, c.Course_Name FROM class cl LEFT JOIN course c ON c.CourseID=cl.CourseID ORDER BY c.Course_Name, cl.Class_Code")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Students</title><link rel="stylesheet" href="../style.css"></head>
<body>
  <main class="admin-main" style="max-width:1100px;margin:30px auto;">
    <h1>Students</h1>
    <?php if (!empty($flash)): ?><div class="flash"><?= e($flash) ?></div><?php endif; if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div><?php endif; ?>

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
        <table class="admin" style="width:100%"><thead><tr><th>UserID</th><th>StudentID</th><th>Name</th><th>Class/Course</th><th>Dept</th><th>Email</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (empty($students)): ?><tr><td colspan="7" class="small-muted">No students.</td></tr><?php else: foreach($students as $s): ?>
              <tr>
                <td><?= e($s['UserID']) ?></td>
                <td><?= e($s['StudentID']) ?></td>
                <td><?= e($s['FullName']) ?></td>
                <td><?= e($s['Class_Code'] ?? '-') ?> / <?= e($s['Course_Code'] ?? '-') ?></td>
                <td><?= e($s['Dept_Code'] ?? '-') ?></td>
                <td><?= e($s['Email'] ?? '-') ?></td>
                <td>
                  <a class="btn" href="edit_student.php?id=<?= urlencode($s['UserID']) ?>">Edit</a>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete student <?= e(addslashes($s['FullName'])) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= e($s['UserID']) ?>">
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
            <select name="ClassID" required><option value="">-- select --</option><?php foreach($classes as $cl): ?><option value="<?= e($cl['ClassID']) ?>"><?= e($cl['Course_Code'].' - '.$cl['Course_Name'].' / '.$cl['Class_Code']) ?></option><?php endforeach; ?></select>
          </div>
          <div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Add Student</button></div>
        </form>
      </aside>
    </div>
  </main>
</body>
</html>
