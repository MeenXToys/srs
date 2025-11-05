<?php
require_once 'config.php';

// use the PDO connection from config.php
global $pdo;

$err = '';
$email_old = ''; // Untuk mengisi semula e-mel jika log masuk gagal

// Pengekalan Logik Log Masuk Asal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? ''); 
    $password = $_POST['password'] ?? '';
    $email_old = htmlspecialchars($email); // Simpan input email

    // Logik saringan E-mel dan Kata Laluan 
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $err = "Sila masukkan E-mel dan Kata Laluan yang sah.";
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

        // Verifikasi Kata Laluan
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
            $err = "Kredensial tidak sah. E-mel atau Kata Laluan salah.";
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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  
  <style>
/* --- Tema Warna Moden (Diambil dari contoh HTML anda) --- */
:root {
    --bg-main: #121212;
    --bg-right: #131a22; 
    --card: #1c2734; 
    --text-primary: #e0e0e0;
    --text-muted: #bbb;
    --accent-blue: #3b82f6; 
    --accent-blue-hover: #2563eb;
    --accent-yellow: #ffcc00;
    --border: #30363d;
    --danger: #f85149;
    --success: #3fb950;
    --shadow: rgba(0,0,0,0.3);
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    margin: 0;
    color: var(--text-primary);
    background-color: var(--bg-main);
    overflow: hidden;
    height: 100vh;
    display: flex;
}

.container {
    display: flex;
    height: 100%;
    width: 100%;
}

/* --- Navigasi Atas --- */
.gmi-logo-left {
    font-size: 2em;
    font-weight: bold;
    color: var(--text-primary);
    position: absolute;
    top: 29px;
    left: 63px;
    z-index: 2;
}

.navbar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    width: 100%;
    position: absolute;
    font-weight: bold;
    top: 29px;
    right: 63px;
    z-index: 10;
}

.nav-links a {
    color: var(--text-primary);
    text-decoration: none;
    margin-left: 25px;
    font-size: 1.1em;
    position: relative;
    transition: color 0.3s;
}

.nav-links a::after {
    content: "";
    position: absolute;
    bottom: -4px;
    left: 0;
    width: 0%;
    height: 2px;
    background: var(--text-primary);
    transition: width 0.3s;
}

.nav-links a:hover { color: #fff; }
.nav-links a:hover::after { width: 100%; }

.nav-links a.current-page {
    color: #fff;
    /* Simulate underline/active state */
}
.nav-links a.current-page::after {
    width: 100%;
    background: var(--text-primary);
}


.nav-links a.contact-button {
    background-color: #3a3f47 ;
    color: black !important;
    padding: 10px 20px;
    border-radius: 50px;
    text-decoration: none;
    margin-left: 25px;
    font-weight: bold;
    transition: background 0.3s, transform 0.2s;
}

.nav-links a.contact-button:hover {
    background-color: #fff;
    transform: scale(1.05);
}


/* --- Left Section (Latar Belakang) --- */
.left-section {
    width: 50%;
    background-image: linear-gradient(rgba(19, 26, 34, 0.8), rgba(19, 26, 34, 0.7)), url('img/login.png'); /* Guna imej yang sesuai */
    background-size: cover;
    background-position: center;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    padding: 40px;
}

.left-content {
    color: white;
    text-align: left;
    max-width: 450px;
    position: relative;
    z-index: 2;
    animation: fadeInLeft 1s ease;
}

.left-content h1 {
    font-size: 3.2em;
    font-weight: bold;
    line-height: 1.2;
    margin: 0;
    letter-spacing: 2px;
}

/* --- Right Section (Borang) --- */
.right-section {
    width: 50%;
    background-color: var(--bg-right);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    position: relative;
}

.login-form {
    width: 100%;
    max-width: 420px;
    background: var(--card);
    border-radius: 10px;
    padding: 35px 30px;
    text-align: center;
    box-shadow: 0 6px 16px var(--shadow);
    animation: fadeInUp 1s ease;
}

.login-form h2 {
    font-size: 1.8em;
    color: #fff;
    margin-bottom: 25px;
    letter-spacing: 2px;
    text-align: left; /* Align title left */
}

/* --- Input Styling --- */
.login-form input {
    width: 100%;
    padding: 15px;
    margin-bottom: 20px;
    border: none;
    border-radius: 6px;
    background-color: #e0e0e0; /* Input background terang */
    color: black;
    font-size: 1em;
    box-sizing: border-box;
    transition: box-shadow 0.3s ease;
}

.login-form input:focus {
    outline: none;
    box-shadow: 0 0 8px var(--accent-blue);
}

/* --- Button Proceed --- */
.login-form button {
    width: 100%;
    padding: 15px;
    border: none;
    border-radius: 6px;
    background-color: var(--accent-blue);
    color: white;
    font-size: 1.1em;
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.3s, transform 0.2s;
}

.login-form button:hover {
    background-color: var(--accent-blue-hover);
    transform: translateY(-2px);
}

/* --- Mesej Status (Ralat/Berjaya) --- */
.status-message {
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 0.9em;
    font-weight: 500;
    text-align: left;
    display: flex;
    align-items: center;
}
.status-message i {
    margin-right: 10px;
    font-size: 1.1em;
}
.status-message.error {
    color: var(--danger);
    background: rgba(248, 81, 73, 0.15);
    border: 1px solid var(--danger);
}
.status-message.success {
    color: var(--success); 
    background: rgba(63, 185, 80, 0.15); 
    border: 1px solid var(--success);
}


/* --- Pautan Bawah --- */
.forgot-link a {
    color: var(--text-muted);
    text-decoration: none;
    margin-top: 15px;
    display: block;
    font-size: 0.9em;
    transition: color 0.3s;
}

.forgot-link a:hover {
    color: var(--accent-yellow);
}

.register-link {
    margin-top: 12px;
    font-size: 0.95em;
    color: var(--text-muted);
}

.register-link a {
    color: var(--accent-blue);
    text-decoration: none;
    font-weight: bold;
    margin-left: 5px;
    transition: color 0.3s;
}

.register-link a:hover {
    color: var(--accent-yellow);
}

/* --- Animations (Dikekalkan dari contoh anda) --- */
@keyframes fadeInLeft {
    from { opacity: 0; transform: translateX(-50px); }
    to { opacity: 1; transform: translateX(0); }
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(40px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive */
@media (max-width: 900px) {
    body {
        overflow-y: auto;
    }
    .container {
        flex-direction: column;
    }
    .left-section, .right-section {
        width: 100%;
        padding: 40px 20px;
    }
    .left-section {
        height: 250px;
        min-height: 250px;
    }
    .left-content h1 {
        font-size: 2.5em;
    }
    .gmi-logo-left {
        top: 20px;
        left: 20px;
    }
    .navbar {
        display: none;
    }
    .login-form {
        max-width: 100%;
    }
}
  </style>
</head>

<body>
  <div class="container">
    <div class="left-section">
      <div class="gmi-logo-left">GMI</div> 
      <div class="left-content">
        <h1>STUDENT<br>REGISTRATION<br>SYSTEM</h1>
      </div>
    </div>

    <div class="right-section">
      <div class="navbar">
        <div class="nav-links">
          <a href="index.php">Main Menu</a>
          <a href="registration.php">Register</a>
          <a href="login.php" class="current-page">Log In</a>
          <a href="#" class="contact-button">Contact Us</a>
        </div>
      </div>

      <div class="login-form">
        <h2>LOG IN</h2>
        
        <?php 
        // Mesej Pendaftaran Berjaya (Jika datang dari registration.php?registered=1)
        if (isset($_GET['registered']) && $_GET['registered'] == 1): ?>
          <div class="status-message success">
            <i class="fas fa-check-circle"></i> Succesfull Login! Please Log In.
          </div>
        <?php endif; ?>

        <?php 
        // Mesej Ralat Log Masuk
        if ($err): ?>
          <div class="status-message error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?>
          </div>
        <?php endif; ?>
        
        <form method="post" action="login.php">
          <input type="email" name="email" placeholder="Email" required value="<?= $email_old ?>">
          <input type="password" name="password" placeholder="Password" required>
          <button type="submit">Proceed</button>
        </form>
        
        <div class="forgot-link">
          <a href="#">Forgot password?</a>
        </div>
        <div class="register-link">
          Don’t have an account yet? <a href="registration.php">Register now</a>
        </div>
      </div>
    </div>
  </div>
</body>

</html>