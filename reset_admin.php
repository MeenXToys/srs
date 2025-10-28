<?php
// reset_admin.php
require_once __DIR__ . '/config.php';
global $pdo;

$email = 'admin@gmail.com';
$newPassword = 'admin123'; // new admin password

try {
    // Hash the new password
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update admin account
    $stmt = $pdo->prepare("UPDATE `user` SET Password_Hash = ?, Role = 'Admin' WHERE Email = ?");
    $stmt->execute([$hash, $email]);

    echo "<h3>✅ Admin password reset successful!</h3>";
    echo "<p>Email: <b>$email</b></p>";
    echo "<p>New Password: <b>$newPassword</b></p>";
    echo "<p>Now you can log in at <a href='login.php'>login.php</a></p>";
} catch (Exception $e) {
    echo "<h3>❌ Error:</h3> " . htmlspecialchars($e->getMessage());
}
