<?php
// admin/api/students.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php'; // adjust if your config path differs
require_admin();

// small JSON helpers
function json_ok($data = []) { echo json_encode(array_merge(['ok' => true], $data)); exit; }
function json_err($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

// only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Only POST allowed', 405);

// CSRF (pages set $_SESSION['csrf_token'])
$csrf_post = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_post)) {
    json_err('Invalid CSRF token', 403);
}

// discover student columns
$studentCols = [];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM student")->fetchAll(PDO::FETCH_COLUMN);
    if ($cols) $studentCols = $cols;
} catch (Exception $e) {
    json_err('Database error: cannot read student columns');
}

function pick_col(array $cols, array $candidates, $default = null) {
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return $default;
}

$col_id     = pick_col($studentCols, ['UserID','StudentID','id','user_id','student_id'], 'UserID');
$col_name   = pick_col($studentCols, ['FullName','fullname','Name','name','student_name'], 'FullName');
$col_email  = pick_col($studentCols, ['Email','email','EmailAddress','email_address','email_addr'], null);
$col_class  = pick_col($studentCols, ['ClassID','class_id','Class','class'], 'ClassID');
$col_deleted= pick_col($studentCols, ['deleted_at','deleted','is_deleted'], null);

function hascol($name){ global $studentCols; return $name !== null && in_array($name, $studentCols); }

$action = strtolower(trim($_POST['action'] ?? ''));
$id = isset($_POST['id']) ? trim((string)$_POST['id']) : null;
if ($id !== null && $id !== '' && !ctype_digit($id)) $id = null;

try {
    if ($action === 'add') {
        $insertCols = [];
        $placeholders = [];
        $bind = [];

        if (hascol($col_name) && array_key_exists('fullname', $_POST)) {
            $insertCols[] = "`{$col_name}`";
            $placeholders[] = ":fullname";
            $bind[':fullname'] = trim($_POST['fullname']);
        }
        if (hascol($col_email) && array_key_exists('email', $_POST)) {
            $insertCols[] = "`{$col_email}`";
            $placeholders[] = ":email";
            $bind[':email'] = trim($_POST['email']);
        }
        if (hascol($col_class) && array_key_exists('class_id', $_POST)) {
            $insertCols[] = "`{$col_class}`";
            $placeholders[] = ":class";
            $bind[':class'] = trim($_POST['class_id']) === '' ? null : trim($_POST['class_id']);
        }

        if (empty($insertCols)) json_err('No valid fields to insert');

        $sql = "INSERT INTO student (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        foreach ($bind as $k => $v) $stmt->bindValue($k, $v === '' ? null : $v);
        $stmt->execute();
        json_ok(['id' => $pdo->lastInsertId()]);

    } elseif ($action === 'edit') {
        if (!$id) json_err('Missing id for edit');
        $sets = [];
        $bind = [':id' => $id];

        if (hascol($col_name) && array_key_exists('fullname', $_POST)) {
            $sets[] = "`{$col_name}` = :fullname";
            $bind[':fullname'] = trim($_POST['fullname']);
        }
        if (hascol($col_email) && array_key_exists('email', $_POST)) {
            $sets[] = "`{$col_email}` = :email";
            $bind[':email'] = trim($_POST['email']);
        }
        if (hascol($col_class) && array_key_exists('class_id', $_POST)) {
            $sets[] = "`{$col_class}` = :class";
            $bind[':class'] = trim($_POST['class_id']) === '' ? null : trim($_POST['class_id']);
        }

        if (empty($sets)) json_err('No valid fields provided for update');

        $sql = "UPDATE student SET " . implode(', ', $sets) . " WHERE `{$col_id}` = :id";
        $stmt = $pdo->prepare($sql);
        foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        json_ok();

    } elseif ($action === 'delete') {
        if (!$id) json_err('Missing id for delete');
        if ($col_deleted && hascol($col_deleted)) {
            $sql = "UPDATE student SET `{$col_deleted}` = NOW() WHERE `{$col_id}` = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            json_ok();
        } else {
            $stmt = $pdo->prepare("DELETE FROM student WHERE `{$col_id}` = :id");
            $stmt->execute([':id' => $id]);
            json_ok();
        }

    } elseif ($action === 'undo') {
        if (!$id) json_err('Missing id for undo');
        if (!$col_deleted || !hascol($col_deleted)) json_err('Undo not supported on this table');
        $stmt = $pdo->prepare("UPDATE student SET `{$col_deleted}` = NULL WHERE `{$col_id}` = :id");
        $stmt->execute([':id' => $id]);
        json_ok();

    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) json_err('No ids provided');
        $ids = array_values(array_filter(array_map(function($v){ return ctype_digit((string)$v) ? (int)$v : null; }, $ids)));
        if (empty($ids)) json_err('No valid ids');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($col_deleted && hascol($col_deleted)) {
            $sql = "UPDATE student SET `{$col_deleted}` = NOW() WHERE `{$col_id}` IN ($placeholders)";
        } else {
            $sql = "DELETE FROM student WHERE `{$col_id}` IN ($placeholders)";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        json_ok(['count' => $stmt->rowCount()]);

    } else {
        json_err('Unknown action');
    }
} catch (PDOException $ex) {
    json_err('Database error: ' . $ex->getMessage(), 500);
} catch (Exception $ex) {
    json_err('Error: ' . $ex->getMessage(), 500);
}
