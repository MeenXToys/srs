<?php
// admin/api/departments.php
// JSON API for departments (add, edit, soft-delete, bulk-delete, undo, fetch single)

require_once __DIR__ . '/../../config.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
}

// helper for JSON error
function error_json($msg, $code = 400){
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? $_GET['action'] ?? null;

// CSRF for POST-like actions
if ($method === 'POST' && empty($_POST['csrf_token'])) {
    error_json('Missing CSRF token', 403);
}
if ($method === 'POST' && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    error_json('Invalid CSRF token', 403);
}

// simple sanitize id
function get_int($v){ return (int)$v; }

try {
    if ($action === 'add' && $method === 'POST') {
        $code = trim($_POST['dept_code'] ?? '');
        $name = trim($_POST['dept_name'] ?? '');
        if ($code === '' || $name === '') error_json('Code and name required');

        // unique code check (including soft deleted)
        $stmt = $pdo->prepare("SELECT 1 FROM department WHERE Dept_Code = :code LIMIT 1");
        $stmt->execute([':code' => $code]);
        if ($stmt->fetch()) error_json('Department code already exists');

        $ins = $pdo->prepare("INSERT INTO department (Dept_Code, Dept_Name) VALUES (:code, :name)");
        $ins->execute([':code'=>$code, ':name'=>$name]);
        $id = (int)$pdo->lastInsertId();

        // return row
        $row = $pdo->prepare("SELECT DepartmentID, Dept_Code, Dept_Name, 0 AS students FROM department WHERE DepartmentID = :id LIMIT 1");
        $row->execute([':id'=>$id]);
        echo json_encode(['ok'=>true, 'row'=>$row->fetch(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'edit' && $method === 'POST') {
        $id = get_int($_POST['id'] ?? 0);
        $code = trim($_POST['dept_code'] ?? '');
        $name = trim($_POST['dept_name'] ?? '');
        if ($id <= 0 || $code === '' || $name === '') error_json('Invalid input');

        // unique excluding self
        $chk = $pdo->prepare("SELECT 1 FROM department WHERE Dept_Code = :code AND DepartmentID != :id LIMIT 1");
        $chk->execute([':code'=>$code, ':id'=>$id]);
        if ($chk->fetch()) error_json('Code already used by another department');

        $pdo->prepare("UPDATE department SET Dept_Code = :code, Dept_Name = :name WHERE DepartmentID = :id")
            ->execute([':code'=>$code, ':name'=>$name, ':id'=>$id]);

        // return updated row including student count
        $q = $pdo->prepare("
          SELECT d.DepartmentID, d.Dept_Code, d.Dept_Name, COUNT(s.UserID) AS students
          FROM department d
          LEFT JOIN course c ON c.DepartmentID = d.DepartmentID
          LEFT JOIN class cl ON cl.CourseID = c.CourseID
          LEFT JOIN student s ON s.ClassID = cl.ClassID
          WHERE d.DepartmentID = :id
          GROUP BY d.DepartmentID, d.Dept_Code, d.Dept_Name
          LIMIT 1
        ");
        $q->execute([':id'=>$id]);
        echo json_encode(['ok'=>true, 'row'=>$q->fetch(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'delete' && $method === 'POST') {
        // soft-delete single (mark deleted_at)
        $id = get_int($_POST['id'] ?? 0);
        if ($id <= 0) error_json('Invalid id');
        $pdo->prepare("UPDATE department SET deleted_at = NOW() WHERE DepartmentID = :id")->execute([':id'=>$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'undo' && $method === 'POST') {
        $id = get_int($_POST['id'] ?? 0);
        if ($id <= 0) error_json('Invalid id');
        $pdo->prepare("UPDATE department SET deleted_at = NULL WHERE DepartmentID = :id")->execute([':id'=>$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'bulk_delete' && $method === 'POST') {
        // expects ids[] array
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) error_json('No ids provided');
        $clean = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $pdo->prepare("UPDATE department SET deleted_at = NOW() WHERE DepartmentID IN ($placeholders)");
        $stmt->execute($clean);
        echo json_encode(['ok'=>true, 'count'=>count($clean)]);
        exit;
    }

    if ($action === 'fetch' && $method === 'GET') {
        $id = get_int($_GET['id'] ?? 0);
        if ($id <= 0) error_json('Invalid id');
        $q = $pdo->prepare("SELECT DepartmentID, Dept_Code, Dept_Name FROM department WHERE DepartmentID = :id LIMIT 1");
        $q->execute([':id'=>$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) error_json('Not found',404);
        echo json_encode(['ok'=>true, 'row'=>$row]);
        exit;
    }

    // unknown action
    error_json('Unknown action', 400);

} catch (PDOException $ex) {
    error_json('Database error: ' . $ex->getMessage(), 500);
}
