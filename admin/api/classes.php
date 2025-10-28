<?php
// admin/api/classes.php
require_once __DIR__ . '/../../config.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// CSRF check for POST
function check_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $bodyToken = $_POST['csrf_token'] ?? $_POST['csrf'] ?? '';
        if (!$bodyToken || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $bodyToken)) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']);
            exit;
        }
    }
}

// pick_col helper
function pick_col(array $cols, array $candidates, $default = null) {
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return $default;
}

// GET: get_courses for department
if (isset($_GET['get_courses'])) {
    $dept = (int)($_GET['dept_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT CourseID, Course_Code, Course_Name FROM course WHERE DepartmentID = :dept AND (deleted_at IS NULL OR deleted_at IS NOT NULL) ORDER BY Course_Code IS NULL, Course_Code ASC, Course_Name ASC");
        $stmt->execute([':dept'=>$dept]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// GET export CSV
if (isset($_GET['export'])) {
    try {
        $sql = "SELECT cl.ClassID, cl.Class_Code, cl.Class_Name, cl.Semester, c.Course_Code, c.Course_Name, d.Dept_Code, d.Dept_Name FROM `class` cl LEFT JOIN `course` c ON c.CourseID = cl.CourseID LEFT JOIN `department` d ON d.DepartmentID = c.DepartmentID ORDER BY cl.Semester ASC, d.Dept_Code ASC, c.Course_Code ASC, cl.Class_Code ASC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=classes_export_' . date('Ymd') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ClassID','Class_Code','Class_Name','Semester','Course_Code','Course_Name','Dept_Code','Dept_Name']);
        foreach ($rows as $r) fputcsv($out, [$r['ClassID'],$r['Class_Code'],$r['Class_Name'],$r['Semester'],$r['Course_Code'],$r['Course_Name'],$r['Dept_Code'],$r['Dept_Name']]);
        fclose($out);
    } catch (Exception $e) {
        echo "Error: " . e($e->getMessage());
    }
    exit;
}

// require CSRF for POST
check_csrf();

$action = $_POST['action'] ?? '';

try {
    if ($action === 'add') {
        $class_code = trim($_POST['class_code'] ?? '');
        $class_name = trim($_POST['class_name'] ?? '');
        $course_id = (int)($_POST['course_id'] ?? 0);
        $semester = trim($_POST['semester'] ?? '');

        if (!$course_id) { echo json_encode(['ok'=>false,'error'=>'course_id required']); exit; }
        if ($class_code === '' || $class_name === '') { echo json_encode(['ok'=>false,'error'=>'Code and Name required']); exit; }

        $sql = "INSERT INTO `class` (Class_Code, Class_Name, CourseID, Semester) VALUES (:code, :name, :course, :sem)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':code'=>$class_code, ':name'=>$class_name, ':course'=>$course_id, ':sem'=>$semester]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $class_code = trim($_POST['class_code'] ?? '');
        $class_name = trim($_POST['class_name'] ?? '');
        $course_id = (int)($_POST['course_id'] ?? 0);
        $semester = trim($_POST['semester'] ?? '');

        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        if (!$course_id) { echo json_encode(['ok'=>false,'error'=>'course_id required']); exit; }

        $sql = "UPDATE `class` SET Class_Code = :code, Class_Name = :name, CourseID = :course, Semester = :sem WHERE ClassID = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':code'=>$class_code, ':name'=>$class_name, ':course'=>$course_id, ':sem'=>$semester, ':id'=>$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $hasDeletedAt = (bool)$pdo->query("SHOW COLUMNS FROM `class` LIKE 'deleted_at'")->fetch();
        if ($hasDeletedAt) {
            $stmt = $pdo->prepare("UPDATE `class` SET deleted_at = NOW() WHERE ClassID = :id");
            $stmt->execute([':id'=>$id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM `class` WHERE ClassID = :id");
            $stmt->execute([':id'=>$id]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'undo') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $hasDeletedAt = (bool)$pdo->query("SHOW COLUMNS FROM `class` LIKE 'deleted_at'")->fetch();
        if ($hasDeletedAt) {
            $stmt = $pdo->prepare("UPDATE `class` SET deleted_at = NULL WHERE ClassID = :id");
            $stmt->execute([':id'=>$id]);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'error'=>'undo not supported (no deleted_at)']);
        }
        exit;
    }

    if ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) { echo json_encode(['ok'=>false,'error'=>'ids required']); exit; }
        $idsFiltered = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0,count($idsFiltered),'?'));
        $hasDeletedAt = (bool)$pdo->query("SHOW COLUMNS FROM `class` LIKE 'deleted_at'")->fetch();
        if ($hasDeletedAt) {
            $sql = "UPDATE `class` SET deleted_at = NOW() WHERE ClassID IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($idsFiltered);
        } else {
            $sql = "DELETE FROM `class` WHERE ClassID IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($idsFiltered);
        }
        echo json_encode(['ok'=>true,'count'=>count($idsFiltered)]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
}
