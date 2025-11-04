<?php
// admin/api/courses.php
// Fixed API for courses used by admin pages (admin/courses.php)
// - Correct include path for config.php
// - Safe session_start only if not started
// - Clean output buffering around require to avoid stray output breaking JSON responses
// - Consistent JSON responses (except CSV export)

ob_start(); // capture any accidental output from included files

// Resolve config path: this file lives in admin/api/, so config is two levels up
$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) {
    // If config not found, return JSON error (no additional output)
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => "Configuration file not found: {$configPath}"]);
    exit;
}

// include config (may start session there)
require_once $configPath;

// Ensure session started (avoid "session already active" notices)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear any accidental output produced by config or other includes so JSON is clean
ob_end_clean();

// Helper: JSON response
function json_resp($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// require_admin should be available from config.php. If not, provide fallback.
if (!function_exists('require_admin')) {
    function require_admin() {
        if (empty($_SESSION['user']) || (int)($_SESSION['user']['is_admin'] ?? 0) !== 1) {
            json_resp(['ok'=>false,'error'=>'Unauthorized'], 401);
        }
    }
}
require_admin();

// quick CSRF check for POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_POST['csrfToken'] ?? null;
    if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        json_resp(['ok'=>false, 'error'=>'Invalid CSRF token'], 403);
    }
}

// helper: detect deleted_at column existence (cache)
function has_deleted_at($pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $res = $pdo->query("SHOW COLUMNS FROM `course` LIKE 'deleted_at'")->fetch();
        $cached = !empty($res);
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// sanitize integer id
function intv($v) { return is_numeric($v) ? (int)$v : 0; }

// EXPORT (CSV) - GET with export=1
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    try {
        $sql = "SELECT c.CourseID, c.Course_Code, c.Course_Name, d.Dept_Code, d.Dept_Name, " . (has_deleted_at($pdo) ? "c.deleted_at" : "NULL AS deleted_at") . "
                FROM course c
                LEFT JOIN department d ON d.DepartmentID = c.DepartmentID
                ORDER BY COALESCE(d.Dept_Code, d.Dept_Name), c.Course_Code, c.Course_Name";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // CSV headers
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="courses_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8
        echo "\xEF\xBB\xBF";
        fputcsv($out, ['CourseID','Course_Code','Course_Name','Dept_Code','Dept_Name','deleted_at']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['CourseID'], $r['Course_Code'], $r['Course_Name'], $r['Dept_Code'], $r['Dept_Name'], $r['deleted_at']]);
        }
        fclose($out);
        exit;
    } catch (Exception $e) {
        json_resp(['ok'=>false,'error'=>'Export failed: ' . $e->getMessage()], 500);
    }
}

// Only POST actions allowed beyond this point
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_resp(['ok'=>false,'error'=>'Unsupported method'], 405);
}

$action = $_POST['action'] ?? '';

// Basic validation helper
function bad($msg) {
    json_resp(['ok'=>false,'error'=>$msg], 400);
}

try {
    if ($action === 'add') {
        $code = trim($_POST['course_code'] ?? '');
        $name = trim($_POST['course_name'] ?? '');
        $dept = intv($_POST['department_id'] ?? 0);

        if ($code === '' || $name === '' || $dept <= 0) bad('Missing required fields');

        // optional: uniqueness check on Course_Code within dept
        $chk = $pdo->prepare("SELECT COUNT(*) FROM course WHERE Course_Code = :code AND DepartmentID = :dept");
        $chk->execute([':code'=>$code, ':dept'=>$dept]);
        if ($chk->fetchColumn() > 0) bad('Course code already exists in that department');

        $ins = $pdo->prepare("INSERT INTO course (Course_Code, Course_Name, DepartmentID) VALUES (:code, :name, :dept)");
        $ins->execute([':code'=>$code, ':name'=>$name, ':dept'=>$dept]);
        $id = (int)$pdo->lastInsertId();
        json_resp(['ok'=>true, 'id'=>$id]);
    }

    if ($action === 'edit') {
        $id = intv($_POST['id'] ?? 0);
        $code = trim($_POST['course_code'] ?? '');
        $name = trim($_POST['course_name'] ?? '');
        $dept = intv($_POST['department_id'] ?? 0);

        if ($id <= 0 || $code === '' || $name === '' || $dept <= 0) bad('Missing required fields');

        $upd = $pdo->prepare("UPDATE course SET Course_Code = :code, Course_Name = :name, DepartmentID = :dept WHERE CourseID = :id");
        $upd->execute([':code'=>$code, ':name'=>$name, ':dept'=>$dept, ':id'=>$id]);
        json_resp(['ok'=>true, 'id'=>$id]);
    }

    if ($action === 'delete') {
        $id = intv($_POST['id'] ?? 0);
        if ($id <= 0) bad('Missing id');

        if (has_deleted_at($pdo)) {
            $stmt = $pdo->prepare("UPDATE course SET deleted_at = NOW() WHERE CourseID = :id");
            $stmt->execute([':id'=>$id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM course WHERE CourseID = :id");
            $stmt->execute([':id'=>$id]);
        }
        json_resp(['ok'=>true, 'id'=>$id]);
    }

    if ($action === 'undo') {
        $id = intv($_POST['id'] ?? 0);
        if ($id <= 0) bad('Missing id');
        if (!has_deleted_at($pdo)) bad('Undo not supported (deleted_at column missing)');
        $stmt = $pdo->prepare("UPDATE course SET deleted_at = NULL WHERE CourseID = :id");
        $stmt->execute([':id'=>$id]);
        json_resp(['ok'=>true, 'id'=>$id]);
    }

    if ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) bad('Missing ids');

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, function($v){ return $v>0; });
        if (empty($ids)) bad('No valid ids');

        // build placeholders
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if (has_deleted_at($pdo)) {
            $sql = "UPDATE course SET deleted_at = NOW() WHERE CourseID IN ($placeholders)";
        } else {
            $sql = "DELETE FROM course WHERE CourseID IN ($placeholders)";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        json_resp(['ok'=>true, 'count'=>$stmt->rowCount()]);
    }

    // unknown action
    json_resp(['ok'=>false,'error'=>'Unknown action'], 400);

} catch (PDOException $e) {
    // In development you may want the message; in production hide details.
    json_resp(['ok'=>false,'error'=>'Database error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    json_resp(['ok'=>false,'error'=>'Error: ' . $e->getMessage()], 500);
}
