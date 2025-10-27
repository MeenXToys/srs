<?php
// config.php - improved version (replace your current file with this)
declare(strict_types=1);

ini_set('display_errors', '1'); // off in production
error_reporting(E_ALL);

// Secure session cookie params (tweak for production: secure => true)
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false, // set true when using HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// DB settings (your existing values)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'studentregistrationsystem');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Create PDO once and expose via get_pdo()
try {
    $GLOBALS['__pdo'] = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Exception $e) {
    // Use a generic message in production
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

/**
 * Return the shared PDO instance.
 */
function get_pdo(): PDO {
    return $GLOBALS['__pdo'];
}

/* ----------------------------
   Authentication helpers
   ---------------------------- */

function require_login(): void {
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if (!isset($_SESSION['user']['Role']) || $_SESSION['user']['Role'] !== 'Admin') {
        http_response_code(403);
        echo "Forbidden - admin only";
        exit;
    }
}

/* ----------------------------
   CSRF helpers
   ---------------------------- */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

/* ----------------------------
   Simple login rate-limiting (session-based)
   For production use IP+store (Redis/DB) instead.
   ---------------------------- */

function login_rate_check(): bool {
    $max_attempts = 6;
    $lock_minutes = 10;

    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }

    // Reset window after lock_minutes
    if (time() - (int)($_SESSION['first_attempt_time'] ?? 0) > $lock_minutes * 60) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }

    return ($_SESSION['login_attempts'] < $max_attempts);
}

function login_rate_increment(): void {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }
    $_SESSION['login_attempts']++;
}

function login_rate_reset(): void {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['first_attempt_time'] = time();
}
