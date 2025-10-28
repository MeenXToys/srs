<?php
// admin/api/courses.php
// Handles add / edit / delete (soft) / undo / bulk_delete and export CSV for courses
// Place this at admin/api/courses.php
require_once __DIR__ . '/../../config.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');

// small json helper
function json_resp($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF check for non-GET
session_start();
$csrf = $_SESSION['csrf_token'] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $token = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? null;
    if (!$token || !$csrf || !hash_equals($csrf, $token)) {
        json_resp(['ok'=>false, 'error'=>'Invalid CSRF token']);
    }
}

// Helper: detect deleted_at column
$has_deleted_at = false;
try {
    $has_deleted_at = (bool)$pdo->query("SHOW COLUMNS FROM course LIKE 'deleted_at'")->fetch();
} catch(Exception $e) {
    $has_deleted_at = false;
}

// Export CSV if requested (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    // produce CSV of courses
    try {
        $sth = $pdo->query("SELECT c.CourseID, c.Course_Code, c.Course_Name, d.Dept_Code, d.Dept_Name, c.deleted_at FROM course c LEFT JOIN department d ON d.DepartmentID = c.DepartmentID ORDER BY d.Dept_Code, c.Course_Code");
        $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="courses_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['CourseID','Course_Code','Course_Name','Dept_Code','Dept_Name','deleted_at']);
        foreach ($rows as $r) fputcsv($out, [$r['CourseID'],$r['Course_Code'],$r['Course_Name'],$r['Dept_Code'], $r['Dept_Name'], $r['deleted_at']]);
        fclose($out);
        exit;
    } catch(Exception $e) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
        echo "CSV export failed: " . $e->getMessage();
        exit;
    }
}

// For POST actions
$action = $_POST['action'] ?? '';
if (!$action) json_resp(['ok'=>false, 'error'=>'No action specified']);

// sanitize helper
function str_or_null($v) {
    $t = trim((string)$v);
    return $t === '' ? null : $t;
}

try {
    if ($action === 'add' || $action === 'edit') {
        $course_code = str_or_null($_POST['course_code'] ?? '');
        $course_name = trim($_POST['course_name'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);
        if ($course_name === '') json_resp(['ok'=>false, 'error'=>'Course name is required']);
        if ($department_id <= 0) json_resp(['ok'=>false, 'error'=>'Select a valid department']);

        if ($action === 'add') {
            $ins = $pdo->prepare("INSERT INTO course (Course_Code, Course_Name, DepartmentID) VALUES (:code, :name, :dept)");
            $ins->execute([':code'=>$course_code, ':name'=>$course_name, ':dept'=>$department_id]);
            $newId = (int)$pdo->lastInsertId();
            $row = $pdo->prepare("SELECT CourseID, Course_Code, Course_Name, DepartmentID, (SELECT Dept_Name FROM department WHERE DepartmentID = course.DepartmentID) AS Dept_Name, (SELECT Dept_Code FROM department WHERE DepartmentID = course.DepartmentID) AS Dept_Code, " . ($has_deleted_at ? "deleted_at" : "NULL AS deleted_at") . " FROM course WHERE CourseID = :id");
            $row->execute([':id'=>$newId]);
            $course = $row->fetch(PDO::FETCH_ASSOC);
            json_resp(['ok'=>true, 'action'=>'add', 'course'=>$course]);
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_resp(['ok'=>false, 'error'=>'Missing id for edit']);
            $upd = $pdo->prepare("UPDATE course SET Course_Code = :code, Course_Name = :name, DepartmentID = :dept WHERE CourseID = :id");
            $upd->execute([':code'=>$course_code, ':name'=>$course_name, ':dept'=>$department_id, ':id'=>$id]);
            $row = $pdo->prepare("SELECT CourseID, Course_Code, Course_Name, DepartmentID, (SELECT Dept_Name FROM department WHERE DepartmentID = course.DepartmentID) AS Dept_Name, (SELECT Dept_Code FROM department WHERE DepartmentID = course.DepartmentID) AS Dept_Code, " . ($has_deleted_at ? "deleted_at" : "NULL AS deleted_at") . " FROM course WHERE CourseID = :id");
            $row->execute([':id'=>$id]);
            $course = $row->fetch(PDO::FETCH_ASSOC);
            json_resp(['ok'=>true, 'action'=>'edit', 'course'=>$course]);
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_resp(['ok'=>false, 'error'=>'Missing id']);
        if ($has_deleted_at) {
            $stmt = $pdo->prepare("UPDATE course SET deleted_at = NOW() WHERE CourseID = :id");
            $stmt->execute([':id'=>$id]);
        } else {
            // If no deleted_at, do a hard delete (we choose to not silently delete - return error)
            json_resp(['ok'=>false, 'error'=>'Soft-delete not supported (missing deleted_at)']);
        }
        $row = $pdo->prepare("SELECT CourseID, Course_Code, Course_Name, DepartmentID, (SELECT Dept_Name FROM department WHERE DepartmentID = course.DepartmentID) AS Dept_Name, (SELECT Dept_Code FROM department WHERE DepartmentID = course.DepartmentID) AS Dept_Code, deleted_at FROM course WHERE CourseID = :id");
        $row->execute([':id'=>$id]);
        $c = $row->fetch(PDO::FETCH_ASSOC);
        json_resp(['ok'=>true, 'action'=>'delete', 'course'=>$c]);

    } elseif ($action === 'undo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_resp(['ok'=>false, 'error'=>'Missing id']);
        if ($has_deleted_at) {
            $stmt = $pdo->prepare("UPDATE course SET deleted_at = NULL WHERE CourseID = :id");
            $stmt->execute([':id'=>$id]);
        } else {
            json_resp(['ok'=>false, 'error'=>'Undo not supported (missing deleted_at)']);
        }
        $row = $pdo->prepare("SELECT CourseID, Course_Code, Course_Name, DepartmentID, (SELECT Dept_Name FROM department WHERE DepartmentID = course.DepartmentID) AS Dept_Name, (SELECT Dept_Code FROM department WHERE DepartmentID = course.DepartmentID) AS Dept_Code, deleted_at FROM course WHERE CourseID = :id");
        $row->execute([':id'=>$id]);
        $c = $row->fetch(PDO::FETCH_ASSOC);
        json_resp(['ok'=>true, 'action'=>'undo', 'course'=>$c]);

    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) json_resp(['ok'=>false, 'error'=>'Invalid ids']);
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);
        if (empty($ids)) json_resp(['ok'=>false, 'error'=>'No ids provided']);
        if ($has_deleted_at) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE course SET deleted_at = NOW() WHERE CourseID IN ($in)");
            $stmt->execute($ids);
            json_resp(['ok'=>true, 'action'=>'bulk_delete', 'count'=>count($ids)]);
        } else {
            json_resp(['ok'=>false, 'error'=>'Bulk delete unsupported (missing deleted_at)']);
        }

    } else {
        json_resp(['ok'=>false, 'error'=>'Unknown action']);
    }
} catch (Exception $ex) {
    json_resp(['ok'=>false, 'error'=>$ex->getMessage()]);
}
