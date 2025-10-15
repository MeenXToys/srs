<?php
// config.php
session_start();

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'studentregisterationsystem');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Exception $e) {
    // For production, show a generic message; for dev we show the error
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

function require_login() {
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}
function require_admin() {
    require_login();
    if (!isset($_SESSION['user']['Role']) || $_SESSION['user']['Role'] !== 'Admin') {
        http_response_code(403);
        echo "Forbidden - admin only";
        exit;
    }
}
