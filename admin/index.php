<?php
require_once __DIR__ . '/../config.php';
require_admin();

$stmt = $pdo->query("SELECT u.UserID, u.Email, u.Role, u.Created_At, s.FullName FROM `user` u LEFT JOIN student s ON s.UserID = u.UserID ORDER BY u.UserID DESC");
$users = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — GMI</title>
  <link rel="icon" href="../img/favicon.png" type="image/png">
  <link rel="stylesheet" href="../style.css">
</head>
<body>
  <?php include __DIR__ . '/../nav.php'; ?>
  <div style="max-width:1100px;margin:30px auto;padding:20px;">
    <h1>Admin Panel</h1>
    <table style="width:100%;border-collapse:collapse;margin-top:18px;">
      <thead>
        <tr style="text-align:left;color:#cbd5e1;">
          <th style="padding:10px">ID</th><th>Email</th><th>Name</th><th>Role</th><th>Created</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($users as $u): ?>
        <tr style="border-top:1px solid rgba(255,255,255,0.03);">
          <td style="padding:10px;"><?=htmlspecialchars($u['UserID'])?></td>
          <td><?=htmlspecialchars($u['Email'])?></td>
          <td><?=htmlspecialchars($u['FullName'] ?? '-')?></td>
          <td><?=htmlspecialchars($u['Role'])?></td>
          <td><?=htmlspecialchars($u['Created_At'])?></td>
          <td><a href="edit_user.php?id=<?=urlencode($u['UserID'])?>">Edit</a> | <a href="delete_user.php?id=<?=urlencode($u['UserID'])?>" onclick="return confirm('Delete this user?')">Delete</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
