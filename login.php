<?php
// login.php — improved / hardened version
require_once 'config.php'; // config.php should start session and provide helpers

// If already logged in, redirect
if (isset($_SESSION['user']['UserID'])) {
    header('Location: dashboard.php');
    exit;
}

$err = '';
// Use a generic message to avoid user enumeration
$generic_error = "Invalid credentials.";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate-limit check (session-based; for production prefer per-IP store like Redis)
    if (function_exists('login_rate_check') && !login_rate_check()) {
        $err = "Too many attempts. Try again later.";
    } else {
        // CSRF protection (if available)
        if (function_exists('csrf_check')) {
            $csrf_ok = csrf_check($_POST['csrf'] ?? '');
            if (!$csrf_ok) {
                // increment rate limiter on bad CSRF too
                if (function_exists('login_rate_increment')) login_rate_increment();
                $err = "Invalid request.";
            }
        }

        if (!$err) {
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $password = $_POST['password'] ?? '';

            if (!$email || $password === '') {
                $err = "Please enter email and password.";
            } else {
                // Prepared statement to fetch user by email
                $pdo = get_pdo();
                $stmt = $pdo->prepare(
                    "SELECT u.UserID, u.Password_Hash, u.Role, u.Email, COALESCE(s.FullName, '') AS FullName
                     FROM `user` u
                     LEFT JOIN student s ON s.UserID = u.UserID
                     WHERE u.Email = ? LIMIT 1"
                );
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // Verify credentials
                if ($user && password_verify($password, $user['Password_Hash'])) {
                    // Successful login
                    if (function_exists('login_rate_reset')) login_rate_reset();

                    // Regenerate session id
                    session_regenerate_id(true);

                    // Store minimal user info in session
                    $_SESSION['user'] = [
                        'UserID'   => $user['UserID'],
                        'Email'    => $user['Email'],
                        'Role'     => $user['Role'],
                        'FullName' => $user['FullName'] ?? ''
                    ];

                    // Update last login time (non-blocking)
                    try {
                        $u = $pdo->prepare("UPDATE `user` SET Last_Login = NOW() WHERE UserID = ?");
                        $u->execute([$user['UserID']]);
                    } catch (Exception $ex) {
                        // don't reveal DB errors to user; optionally log $ex
                    }

                    header("Location: dashboard.php");
                    exit;
                } else {
                    // Wrong credentials
                    if (function_exists('login_rate_increment')) login_rate_increment();
                    // avoid telling which part was wrong
                    $err = $generic_error;
                    // small random delay to reduce timing attacks
                    usleep(random_int(15000, 50000));
                }
            }
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

      <form method="post" action="login.php" autocomplete="off" novalidate>
        <!-- CSRF token (if your config.php provides csrf_token()) -->
        <?php if (function_exists('csrf_token')): ?>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <?php endif; ?>

        <input type="email" name="email" placeholder="Email" required value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Proceed</button>
      </form>

      <a href="#">Forgot password?</a>
      <div class="register-link">Don’t have an account yet? <a href="registration.php">Register now</a></div>
    </div>
  </div>

  <div style="clear:both;"></div>
</body>
</html>
