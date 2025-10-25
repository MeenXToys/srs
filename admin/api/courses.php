<?php
// admin/api/courses.php
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
        $code = sp('course_code') ?: ''; $name = sp('course_name') ?: ''; $dept = (int)sp('department_id');
        if ($code==='' || $name==='') json_out(['ok'=>false,'error'=>'code/name required']);
        $chk = $pdo->prepare("SELECT 1 FROM course WHERE Course_Code = :code LIMIT 1"); $chk->execute([':code'=>$code]);
        if ($chk->fetch()) json_out(['ok'=>false,'error'=>'code exists']);
        $stmt = $pdo->prepare("INSERT INTO course (Course_Code, Course_Name, DepartmentID) VALUES (:code, :name, :dept)");
        $stmt->execute([':code'=>$code,':name'=>$name,':dept'=>($dept ?: null)]);
        json_out(['ok'=>true,'id'=>$pdo->lastInsertId()]);
    }

    if ($action === 'edit') {
        $id=(int)($_POST['id']??0); $code=sp('course_code')?:''; $name=sp('course_name')?:''; $dept=(int)sp('department_id');
        if ($id<=0 || $code==='' || $name==='') json_out(['ok'=>false,'error'=>'invalid input']);
        $stmt=$pdo->prepare("UPDATE course SET Course_Code=:code, Course_Name=:name, DepartmentID=:dept WHERE CourseID=:id");
        $stmt->execute([':code'=>$code,':name'=>$name,':dept'=>$dept?:null,':id'=>$id]);
        json_out(['ok'=>true]);
    }

    if ($action === 'delete') {
        $id=(int)($_POST['id']??0); if ($id<=0) json_out(['ok'=>false,'error'=>'invalid id']);
        $hasDeleted = (bool)$pdo->query("SHOW COLUMNS FROM course LIKE 'deleted_at'")->fetch();
        if ($hasDeleted) $pdo->prepare("UPDATE course SET deleted_at = NOW() WHERE CourseID = :id")->execute([':id'=>$id]);
        else $pdo->prepare("DELETE FROM course WHERE CourseID = :id")->execute([':id'=>$id]);
        json_out(['ok'=>true]);
    }

    if ($action === 'undo') {
        $id=(int)($_POST['id']??0); if ($id<=0) json_out(['ok'=>false,'error'=>'invalid id']);
        $pdo->prepare("UPDATE course SET deleted_at = NULL WHERE CourseID = :id")->execute([':id'=>$id]);
        json_out(['ok'=>true]);
    }

    if ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? []; if (!is_array($ids) || empty($ids)) json_out(['ok'=>false,'error'=>'no ids']);
        $ids = array_map('intval',$ids); $in = implode(',', array_fill(0,count($ids),'?'));
        $hasDeleted = (bool)$pdo->query("SHOW COLUMNS FROM course LIKE 'deleted_at'")->fetch();
        if ($hasDeleted) { $stmt=$pdo->prepare("UPDATE course SET deleted_at = NOW() WHERE CourseID IN ($in)"); $stmt->execute($ids); }
        else { $stmt=$pdo->prepare("DELETE FROM course WHERE CourseID IN ($in)"); $stmt->execute($ids); }
        json_out(['ok'=>true,'count'=>count($ids)]);
    }

    if (isset($_GET['export']) || $action === 'export') {
        $showDeleted = ($_GET['show_deleted'] ?? '') === '1';
        $sql = "SELECT CourseID, Course_Code, Course_Name, DepartmentID, created_at" . ($showDeleted ? ", deleted_at" : "") . " FROM course";
        if (!$showDeleted && $pdo->query("SHOW COLUMNS FROM course LIKE 'deleted_at'")->fetch()) $sql .= " WHERE deleted_at IS NULL";
        $sql .= " ORDER BY Course_Name ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="courses.csv"');
        $out = fopen('php://output','w'); fputcsv($out, array_keys($rows[0] ?? ['CourseID','Course_Code','Course_Name','DepartmentID','created_at']));
        foreach($rows as $r) fputcsv($out,$r); fclose($out); exit;
    }

    json_out(['ok'=>false,'error'=>'unknown action']);
} catch (Exception $e) {
    json_out(['ok'=>false,'error'=>$e->getMessage()]);
}
