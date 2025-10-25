<?php
// admin/api/students.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';
require_admin();
function json_out($d){ echo json_encode($d); exit; }
function sp($k){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : null; }
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && !isset($_GET['export'])) json_out(['ok'=>false,'error'=>'Invalid method']);
if ($method === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) json_out(['ok'=>false,'error'=>'Invalid CSRF token']);
}
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    if ($action === 'add') {
        $email = sp('email') ?: ''; $name = sp('fullname') ?: ''; $class = (int)sp('class_id');
        if ($email==='' || $name==='') json_out(['ok'=>false,'error'=>'email/fullname required']);
        // optional: validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok'=>false,'error'=>'invalid email']);
        $chk = $pdo->prepare("SELECT 1 FROM student WHERE Email = :email LIMIT 1"); $chk->execute([':email'=>$email]);
        if ($chk->fetch()) json_out(['ok'=>false,'error'=>'email exists']);
        $stmt = $pdo->prepare("INSERT INTO student (Email, FullName, ClassID) VALUES (:email, :name, :class)");
        $stmt->execute([':email'=>$email,':name'=>$name,':class'=>($class?:null)]);
        json_out(['ok'=>true,'id'=>$pdo->lastInsertId()]);
    }

    if ($action === 'edit') {
        $id=(int)($_POST['id']??0); $email=sp('email')?:''; $name=sp('fullname')?:''; $class=(int)sp('class_id');
        if ($id<=0 || $email==='' || $name==='') json_out(['ok'=>false,'error'=>'invalid input']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok'=>false,'error'=>'invalid email']);
        $stmt=$pdo->prepare("UPDATE student SET Email=:email, FullName=:name, ClassID=:class WHERE UserID=:id");
        $stmt->execute([':email'=>$email,':name'=>$name,':class'=>$class?:null,':id'=>$id]);
        json_out(['ok'=>true]);
    }

    if ($action === 'delete') {
        $id=(int)($_POST['id']??0); if ($id<=0) json_out(['ok'=>false,'error'=>'invalid id']);
        $hasDeleted = (bool)$pdo->query("SHOW COLUMNS FROM student LIKE 'deleted_at'")->fetch();
        if ($hasDeleted) $pdo->prepare("UPDATE student SET deleted_at = NOW() WHERE UserID = :id")->execute([':id'=>$id]);
        else $pdo->prepare("DELETE FROM student WHERE UserID = :id")->execute([':id'=>$id]);
        json_out(['ok'=>true]);
    }

    if ($action === 'undo') {
        $id=(int)($_POST['id']??0); if ($id<=0) json_out(['ok'=>false,'error'=>'invalid id']);
        $pdo->prepare("UPDATE student SET deleted_at = NULL WHERE UserID = :id")->execute([':id'=>$id]);
        json_out(['ok'=>true]);
    }

    if ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? []; if (!is_array($ids) || empty($ids)) json_out(['ok'=>false,'error'=>'no ids']);
        $ids=array_map('intval',$ids); $in=implode(',', array_fill(0,count($ids),'?'));
        $hasDeleted = (bool)$pdo->query("SHOW COLUMNS FROM student LIKE 'deleted_at'")->fetch();
        if ($hasDeleted) { $stmt=$pdo->prepare("UPDATE student SET deleted_at = NOW() WHERE UserID IN ($in)"); $stmt->execute($ids); }
        else { $stmt=$pdo->prepare("DELETE FROM student WHERE UserID IN ($in)"); $stmt->execute($ids); }
        json_out(['ok'=>true,'count'=>count($ids)]);
    }

    if (isset($_GET['export']) || $action === 'export') {
        $showDeleted = ($_GET['show_deleted'] ?? '') === '1';
        $sql = "SELECT UserID, Email, FullName, ClassID, Created_At" . ($showDeleted ? ", deleted_at" : "") . " FROM student";
        if (!$showDeleted && $pdo->query("SHOW COLUMNS FROM student LIKE 'deleted_at'")->fetch()) $sql .= " WHERE deleted_at IS NULL";
        $sql .= " ORDER BY FullName ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="students.csv"');
        $out=fopen('php://output','w'); fputcsv($out, array_keys($rows[0] ?? ['UserID','Email','FullName','ClassID','Created_At']));
        foreach($rows as $r) fputcsv($out,$r); fclose($out); exit;
    }

    json_out(['ok'=>false,'error'=>'unknown action']);
} catch (Exception $e) { json_out(['ok'=>false,'error'=>$e->getMessage()]); }
