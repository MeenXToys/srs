<?php
require_once __DIR__ . '/../config.php';
require_admin();

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $role = in_array($_POST['role'] ?? '', ['Student','Admin']) ? $_POST['role'] : 'Student';
    if ($email) {
        $stmt = $pdo->prepare("UPDATE `user` SET Email = ?, Role = ? WHERE UserID = ?");
        $stmt->execute([$email, $role, $id]);
        header('Location: index.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT UserID, Email, Role FROM `user` WHERE UserID = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Edit User</title><link rel="icon" href="../img/favicon.png" type="image/png"><link rel="stylesheet" href="../style.css"></head>
<body>
<?php include __DIR__ . '/../nav.php'; ?>
<div style="max-width:800px;margin:40px auto;padding:20px;">
  <h1>Edit User #<?=htmlspecialchars($user['UserID'])?></h1>
  <form method="post">
    <label>Email</label><input type="email" name="email" value="<?=htmlspecialchars($user['Email'])?>" required>
    <label>Role</label>
    <select name="role">
      <option value="Student" <?= $user['Role']==='Student' ? 'selected':'' ?>>Student</option>
      <option value="Admin" <?= $user['Role']==='Admin' ? 'selected':'' ?>>Admin</option>
    </select>
    <br><br>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
</div>
</body>
</html>
