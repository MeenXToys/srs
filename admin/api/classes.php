<?php
// api/classes.php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_admin(); // require admin privileges for all API actions

// Allow both JSON and form submissions; responses are JSON except for CSV export.
header('Cache-Control: no-store, no-cache, must-revalidate');
$method = $_SERVER['REQUEST_METHOD'];

/* small helper */
function json_out($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------------- detect columns (robust) ---------------- */
function pick_col(array $cols, array $candidates, $default = null) {
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return $default;
}

try { $classCols  = $pdo->query("SHOW COLUMNS FROM `class`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $classCols = []; }
try { $courseCols = $pdo->query("SHOW COLUMNS FROM `course`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $courseCols = []; }
try { $deptCols   = $pdo->query("SHOW COLUMNS FROM `department`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $deptCols = []; }
try { $studentCols= $pdo->query("SHOW COLUMNS FROM `student`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $studentCols = []; }

$class_id_col   = pick_col($classCols,   ['ClassID','class_id','id','ID'], 'ClassID');
$class_name_col = pick_col($classCols,   ['Class_Name','ClassName','class_name','Name','name'], 'Class_Name');
$class_sem_col  = pick_col($classCols,   ['Semester','semester','Sem','sem'], 'Semester');
$class_course_col= pick_col($classCols,  ['CourseID','course_id','Course_Id','courseid','course_id_fk'], 'CourseID'); // fallback

$course_id_col   = pick_col($courseCols, ['CourseID','course_id','id','ID'], 'CourseID');
$course_name_col = pick_col($courseCols, ['Course_Name','CourseName','course_name','Name','name'], 'Course_Name');
$course_code_col = pick_col($courseCols, ['Course_Code','CourseCode','course_code','Code','code'], 'Course_Code');
$course_dept_col = pick_col($courseCols, ['DepartmentID','department_id','DeptID','dept_id'], 'DepartmentID');

$dept_id_col   = pick_col($deptCols, ['DepartmentID','department_id','id','ID'], 'DepartmentID');
$dept_name_col = pick_col($deptCols, ['Dept_Name','DeptName','dept_name','Name','name'], 'Dept_Name');
$dept_code_col = pick_col($deptCols, ['Dept_Code','DeptCode','dept_code','Code','code'], 'Dept_Code');

$student_id_col = pick_col($studentCols, ['UserID','Student_ID','StudentID','id','ID'], 'UserID');

$has_deleted_at = in_array('deleted_at', $classCols, true);

/* ---------------- helpers ---------------- */
function require_csrf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isset($_SESSION['csrf_token'])) json_out(['ok'=>false,'error'=>'missing csrf token (session)'],403);
    $token = null;
    // token may be in form body or in header
    if (isset($_POST['csrf_token'])) $token = $_POST['csrf_token'];
    else {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $d = json_decode($raw, true);
            if (is_array($d) && isset($d['csrf_token'])) $token = $d['csrf_token'];
        }
        // also allow X-CSRF-Token header
        if (!$token && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (!$token || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
        json_out(['ok'=>false,'error'=>'invalid csrf token'],403);
    }
}

/* ---------------- GET handlers ---------------- */
if ($method === 'GET') {
    // 1) get_courses => list courses for a department (used by modal)
    if (isset($_GET['get_courses'])) {
        $dept = (int)($_GET['dept_id'] ?? 0);
        try {
            $sql = "SELECT c.`{$course_id_col}` AS CourseID,
                           " . ($course_code_col ? "c.`{$course_code_col}` AS Course_Code," : "NULL AS Course_Code,") . "
                           " . ($course_name_col ? "c.`{$course_name_col}` AS Course_Name" : "NULL AS Course_Name") . "
                    FROM `course` c
                    WHERE 1=1";
            $params = [];
            if ($dept) {
                $sql .= " AND c.`{$course_dept_col}` = :dept";
                $params[':dept'] = $dept;
            }
            $sql .= " ORDER BY " . ($course_code_col ? "c.`{$course_code_col}` ASC, " : "") . ($course_name_col ? "c.`{$course_name_col}` ASC" : "1");
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // normalize keys (CourseID, Course_Code, Course_Name)
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'CourseID' => $r['CourseID'],
                    'Course_Code' => $r['Course_Code'] ?? null,
                    'Course_Name' => $r['Course_Name'] ?? null,
                ];
            }
            json_out($out);
        } catch (Exception $e) {
            json_out(['ok'=>false,'error'=>'failed loading courses: '.$e->getMessage()],500);
        }
    }

    // 2) export => CSV export
    if (isset($_GET['export'])) {
        // Build similar SELECT as admin list
        try {
            // select columns
            $select = [];
            $select[] = "cl.`{$class_id_col}` AS ClassID";
            $select[] = ($class_name_col ? "cl.`{$class_name_col}` AS Class_Name" : "NULL AS Class_Name");
            $select[] = ($class_sem_col ? "cl.`{$class_sem_col}` AS Semester" : "NULL AS Semester");
            $select[] = ($course_id_col ? "c.`{$course_id_col}` AS CourseID" : "NULL AS CourseID");
            $select[] = ($course_code_col ? "c.`{$course_code_col}` AS Course_Code" : "NULL AS Course_Code");
            $select[] = ($course_name_col ? "c.`{$course_name_col}` AS Course_Name" : "NULL AS Course_Name");
            $select[] = ($course_dept_col ? "c.`{$course_dept_col}` AS DepartmentID" : "NULL AS DepartmentID");
            $select[] = ($dept_code_col ? "d.`{$dept_code_col}` AS Dept_Code" : "NULL AS Dept_Code");
            $select[] = ($dept_name_col ? "d.`{$dept_name_col}` AS Dept_Name" : "NULL AS Dept_Name");
            if ($student_id_col && in_array($student_id_col, $studentCols)) $select[] = "COUNT(s.`{$student_id_col}`) AS students";
            else $select[] = "COUNT(s.UserID) AS students";

            $sql = "SELECT " . implode(", ", $select) . "
                    FROM `class` cl
                    LEFT JOIN `course` c ON c.`{$course_id_col}` = cl.`{$class_course_col}`
                    LEFT JOIN `department` d ON d.`{$dept_id_col}` = c.`{$course_dept_col}`
                    LEFT JOIN `student` s ON s.`{$student_id_col}` = cl.`{$class_id_col}`
                    GROUP BY cl.`{$class_id_col}`";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // output CSV
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="classes_export.csv"');
            $out = fopen('php://output', 'w');
            // headers
            fputcsv($out, ['ClassID','Class_Name','Semester','CourseID','Course_Code','Course_Name','DepartmentID','Dept_Code','Dept_Name','Students']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['ClassID'] ?? '',
                    $r['Class_Name'] ?? '',
                    $r['Semester'] ?? '',
                    $r['CourseID'] ?? '',
                    $r['Course_Code'] ?? '',
                    $r['Course_Name'] ?? '',
                    $r['DepartmentID'] ?? '',
                    $r['Dept_Code'] ?? '',
                    $r['Dept_Name'] ?? '',
                    $r['students'] ?? 0
                ]);
            }
            fclose($out);
            exit;
        } catch (Exception $e) {
            json_out(['ok'=>false,'error'=>'export failed: '.$e->getMessage()],500);
        }
    }

    // default: return an informative JSON (or you could return 404)
    json_out(['ok'=>true,'message'=>'classes API: use get_courses=1 or export=1 or POST actions']);
}

/* ---------------- POST handlers ---------------- */
if ($method === 'POST') {
    require_csrf();

    // Accept both form-encoded and JSON body
    $data = $_POST;
    if (empty($data)) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) $data = $json;
    }

    $action = isset($data['action']) ? (string)$data['action'] : '';

    try {
        if ($action === 'add') {
            $class_name = trim((string)($data['class_name'] ?? ''));
            $course_id  = (int)($data['course_id'] ?? 0);
            $semester   = (string)($data['semester'] ?? '');

            if ($class_name === '' || !$course_id) return json_out(['ok'=>false,'error'=>'class_name and course_id are required'],400);

            $sql = "INSERT INTO `class` (`{$class_name_col}`, `{$class_course_col}`, `{$class_sem_col}`)
                    VALUES (:name, :course_id, :sem)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':name', $class_name);
            $stmt->bindValue(':course_id', $course_id);
            $stmt->bindValue(':sem', $semester);
            $stmt->execute();
            $newId = (int)$pdo->lastInsertId();
            json_out(['ok'=>true,'id'=>$newId]);
        }

        if ($action === 'edit') {
            $id = (int)($data['id'] ?? 0);
            $class_name = trim((string)($data['class_name'] ?? ''));
            $course_id  = (int)($data['course_id'] ?? 0);
            $semester   = (string)($data['semester'] ?? '');

            if (!$id || $class_name === '' || !$course_id) return json_out(['ok'=>false,'error'=>'id, class_name and course_id are required'],400);

            $sql = "UPDATE `class` SET `{$class_name_col}` = :name, `{$class_course_col}` = :course_id, `{$class_sem_col}` = :sem WHERE `{$class_id_col}` = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':name', $class_name);
            $stmt->bindValue(':course_id', $course_id);
            $stmt->bindValue(':sem', $semester);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            json_out(['ok'=>true,'id'=>$id]);
        }

        if ($action === 'delete') {
            $id = (int)($data['id'] ?? 0);
            if (!$id) return json_out(['ok'=>false,'error'=>'id required'],400);

            if ($has_deleted_at) {
                $sql = "UPDATE `class` SET `deleted_at` = NOW() WHERE `{$class_id_col}` = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':id', $id);
                $stmt->execute();
                json_out(['ok'=>true,'deleted'=>true,'id'=>$id]);
            } else {
                $sql = "DELETE FROM `class` WHERE `{$class_id_col}` = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':id', $id);
                $stmt->execute();
                json_out(['ok'=>true,'deleted'=>true,'id'=>$id]);
            }
        }

        if ($action === 'undo') {
            if (!$has_deleted_at) return json_out(['ok'=>false,'error'=>'undo not supported (no deleted_at column)'],400);
            $id = (int)($data['id'] ?? 0);
            if (!$id) return json_out(['ok'=>false,'error'=>'id required'],400);
            $sql = "UPDATE `class` SET `deleted_at` = NULL WHERE `{$class_id_col}` = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            json_out(['ok'=>true,'restored'=>true,'id'=>$id]);
        }

        if ($action === 'bulk_delete') {
            $ids = $data['ids'] ?? $data['ids[]'] ?? [];
            if (!is_array($ids)) {
                // maybe comma-separated string
                if (is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids)));
                else $ids = [];
            }
            $ids = array_map('intval', $ids);
            $ids = array_values(array_filter($ids, fn($v) => $v > 0));
            if (empty($ids)) return json_out(['ok'=>false,'error'=>'ids required'],400);

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            if ($has_deleted_at) {
                $sql = "UPDATE `class` SET `deleted_at` = NOW() WHERE `{$class_id_col}` IN ($placeholders)";
            } else {
                $sql = "DELETE FROM `class` WHERE `{$class_id_col}` IN ($placeholders)";
            }
            $stmt = $pdo->prepare($sql);
            foreach ($ids as $i => $val) $stmt->bindValue($i+1, $val, PDO::PARAM_INT);
            $stmt->execute();
            json_out(['ok'=>true,'count'=>count($ids)]);
        }

        // unknown action
        json_out(['ok'=>false,'error'=>'unknown action'],400);

    } catch (Exception $e) {
        json_out(['ok'=>false,'error'=>'exception: '.$e->getMessage()],500);
    }
}

// other methods not allowed
json_out(['ok'=>false,'error'=>'method not allowed'],405);
