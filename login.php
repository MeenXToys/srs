<?php
require_once 'config.php';

// use the PDO connection from config.php
global $pdo;

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
  $password = $_POST['password'] ?? '';

  if (!$email || !$password) {
    $err = "Please enter email and password.";
  } else {
    // prepare query
    $stmt = $pdo->prepare("
            SELECT 
                u.UserID, 
                u.Password_Hash, 
                u.Role, 
                u.Email, 
                s.FullName 
            FROM `user` u 
            LEFT JOIN student s ON s.UserID = u.UserID 
            WHERE u.Email = ? 
            LIMIT 1
        ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['Password_Hash'])) {
      $_SESSION['user'] = [
        'UserID' => $user['UserID'],
        'Email' => $user['Email'],
        'Role' => $user['Role'],
        'FullName' => $user['FullName'] ?? ''
      ];

      // update last login time
      $update = $pdo->prepare("UPDATE `user` SET Last_Login = NOW() WHERE UserID = ?");
      $update->execute([$user['UserID']]);

      // redirect based on role
      if ($user['Role'] === 'Admin') {
        header("Location: admin/index.php");
      } else {
        header("Location: dashboard.php");
      }
      exit;
    } else {
      $err = "Invalid credentials.";
    }
  }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Log In — GMI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
</head>

<body>
  <?php include 'nav.php'; ?>

  <div class="left-section">
    <div class="left-content">
      <h1>STUDENT<br>REGISTRATION<br>SYSTEM</h1>
    </div>
  </div>

  <div class="right-section">
    <div class="login-form">
      <h2>LOG IN</h2>
      <?php if ($err): ?>
        <div style="background:#ffe6e6;padding:8px;border-radius:8px;color:#900;margin-bottom:12px;">
          <?= htmlspecialchars($err) ?>
        </div>
      <?php endif; ?>
      <form method="post" action="login.php">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Proceed</button>
      </form>
      <div>
        <a href="#">Forgot password?</a>
        <div class="register-link">Don’t have an account yet? <a href="registration.php">Register now</a></div>
      </div>
    </div>

    <div style="clear:both;"></div>
</body>

</html>