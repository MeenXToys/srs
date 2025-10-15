<?php
require_once __DIR__ . '/../config.php';
require_admin();

$id = intval($_GET['id'] ?? 0);
if ($id) {
    $stmt = $pdo->prepare("DELETE FROM `user` WHERE UserID = ?");
    $stmt->execute([$id]);
}
header('Location: index.php');
exit;
