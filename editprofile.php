<?php
include 'db_connect.php';
require_once 'config.php';
require_login();

$userId = $_SESSION['user']['UserID'];
$msg = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? null;
    $ic = trim($_POST['ic'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $fullname = trim($_POST['name'] ?? '');

    if (!$email) $errors[] = "Valid email required.";
    if (!$fullname) $errors[] = "Name required.";

    if (empty($errors)) {
        try {
            if ($password) {
                $pwHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE `user` SET Email = ?, Password_Hash = ? WHERE UserID = ?");
                $stmt->execute([$email, $pwHash, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE `user` SET Email = ? WHERE UserID = ?");
                $stmt->execute([$email, $userId]);
            }

            $stmt = $pdo->prepare("UPDATE student SET FullName = ?, IC_Number = ?, Phone = ?, Semester = ? WHERE UserID = ?");
            $stmt->execute([$fullname, $ic, $phone, $semester, $userId]);

            $msg = "Profile updated successfully.";
            $_SESSION['user']['Email'] = $email;

        } catch (Exception $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$stmt = $pdo->prepare("
    SELECT 
        u.Email,
        s.FullName,
        s.IC_Number,
        s.Phone,
        c.Class_Name,
        c.Semester
    FROM `user` u
    LEFT JOIN student s ON s.UserID = u.UserID
    LEFT JOIN class c ON s.ClassID = c.ClassID
    WHERE u.UserID = ?
");

$stmt->execute([$userId]);
$profile = $stmt->fetch();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Profile — GMI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <?php include 'nav.php'; ?>

  <div class="left-section">
    <div class="left-content">
      <h1>EDIT PROFILE</h1>
    </div>
  </div>

  <div class="right-section">
    <div class="login-form">
      <h2>EDIT PROFILE</h2>
      <?php if ($msg): ?>
        <div style="background:#062a10;color:#6bff99;padding:8px;border-radius:8px;margin-bottom:10px;"><?=htmlspecialchars($msg)?></div>
      <?php endif; ?>
      <?php if (!empty($errors)): ?>
        <div style="background:#ffe6e6;padding:8px;border-radius:8px;color:#900;margin-bottom:8px;">
          <?php foreach ($errors as $e): ?>
            <div><?=htmlspecialchars($e)?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post">
        <div class="form-row">
          <div class="form-group"><label>Email:</label><input type="email" name="email" required value="<?=htmlspecialchars($profile['Email'] ?? '')?>"></div>
          <div class="form-group"><label>Password (leave blank to keep)</label><input type="password" name="password"></div>
        </div>

        <div class="form-row">
          <div class="form-group"><label>Name:</label><input type="text" name="name" required value="<?=htmlspecialchars($profile['FullName'] ?? '')?>"></div>
          <div class="form-group"><label>Semester:</label>
            <select name="semester">
              <option value="">-- Select --</option>
              <?php for($i=1;$i<=6;$i++): $val="Sem$i"; ?>
                <option value="<?=$val?>" <?= ( ($profile['Semester']??'')===$val ? 'selected':'') ?>>Semester <?=$i?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group"><label>IC Number:</label><input type="text" name="ic" value="<?=htmlspecialchars($profile['IC_Number'] ?? '')?>"></div>
          <div class="form-group"><label>Phone:</label><input type="tel" name="phone" value="<?=htmlspecialchars($profile['Phone'] ?? '')?>"></div>
        </div>

        <button class="register-button" type="submit">SAVE CHANGES</button>
      </form>
    </div>
  </div>

  <div style="clear:both;"></div>
</body>
</html>


