<?php
// config.php

#Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
#Database configuration
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
    // Show minimal info in production; full info for dev
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

// ✅ Require user login
function require_login() {
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

// ✅ Require admin privilege
function require_admin() {
    require_login();

    // Be flexible with case (Admin / admin)
    $role = $_SESSION['user']['Role'] ?? '';
    if (strcasecmp($role, 'Admin') !== 0) {
        http_response_code(403);
        echo "Forbidden — Admin access only.";
        exit;
    }
}
