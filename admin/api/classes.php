<?php
// admin/api/classes.php
require_once __DIR__ . '/../config.php';
require_admin();

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function pick_col(array $cols, array $candidates, $default = null) {
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return $default;
}

try { $courseCols = $pdo->query("SHOW COLUMNS FROM `course`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $courseCols = []; }
try { $classCols  = $pdo->query("SHOW COLUMNS FROM `class`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $classCols = []; }

$course_id_col   = pick_col($courseCols, ['CourseID','course_id','id','ID'], 'CourseID');
$course_code_col = pick_col($courseCols, ['Course_Code','CourseCode','course_code','Code','code'], null);
$course_name_col = pick_col($courseCols, ['Course_Name','CourseName','course_name','Name','name'], null);
$course_dept_col = pick_col($courseCols, ['DepartmentID','department_id','DeptID','dept_id'], 'DepartmentID');

$class_course_col = pick_col($classCols, ['CourseID','course_id','Course_Id','course','Course'], 'CourseID');
$class_id_col    = pick_col($classCols, ['ClassID','class_id','id','ID'], 'ClassID');
$class_name_col  = pick_col($classCols, ['Class_Name','class_name','Name','name'], null);
$class_sem_col   = pick_col($classCols, ['Semester','semester','Sem','sem'], 'Semester');

/* ---------------- GET: get_courses for department ---------------- */
if (isset($_GET['get_courses']) && $_GET['get_courses']) {
    $dept_id = (int)($_GET['dept_id'] ?? 0);
    if ($dept_id <= 0) json_out([]);

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `course`")->fetchAll(PDO::FETCH_COLUMN);
        $deletedWhere = in_array('deleted_at', $cols) ? "AND (deleted_at IS NULL OR deleted_at = '')" : "";
        $sql = "SELECT {$course_id_col} AS CourseID, " .
               ($course_code_col ? "{$course_code_col} AS Course_Code, " : "NULL AS Course_Code, ") .
               ($course_name_col ? "{$course_name_col} AS Course_Name " : "NULL AS Course_Name ") .
               "FROM course WHERE {$course_dept_col} = :dept $deletedWhere
                ORDER BY " . ($course_code_col ? "{$course_code_col} IS NULL, {$course_code_col} ASC, " : "") . ($course_name_col ? "{$course_name_col} ASC" : "1");
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':dept', $dept_id, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'CourseID' => $r['CourseID'],
                'Course_Code' => $r['Course_Code'] ?? null,
                'Course_Name' => $r['Course_Name'] ?? null
            ];
        }
        json_out($out);
    } catch(Exception $e) {
        json_out(['error' => 'Query failed: ' . $e->getMessage()], 500);
    }
}

/* ---------------- POST actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf_token'] ?? $_POST['csrf'] ?? null;
    $csrf = $_SESSION['csrf_token'] ?? null;
    if (!$csrf || !$sent || !hash_equals($csrf, $sent)) {
        json_out(['ok'=>false,'error'=>'Invalid CSRF token'], 403);
    }

    $action = $_POST['action'] ?? '';
    try {
        if (in_array($action, ['add','edit'])) {
            $class_name = trim((string)($_POST['class_name'] ?? ''));
            $course_id = (int)($_POST['course_id'] ?? 0);
            $semester = trim((string)($_POST['semester'] ?? ''));
            if ($class_name === '' || $course_id <= 0) json_out(['ok'=>false,'error'=>'Missing required fields']);

            if ($action === 'add') {
                $insCols = [];
                $insVals = [];
                $params = [];
                $insCols[] = "`{$class_name_col}`"; $insVals[] = ":name"; $params[':name'] = $class_name;
                $insCols[] = "`{$class_course_col}`"; $insVals[] = ":course"; $params[':course'] = $course_id;
                $insCols[] = "`{$class_sem_col}`"; $insVals[] = ":sem"; $params[':sem'] = $semester;
                $sql = "INSERT INTO `class` (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insVals) . ")";
                $stmt = $pdo->prepare($sql);
                foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
                $stmt->execute();
                json_out(['ok'=>true,'id'=>$pdo->lastInsertId()]);
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) json_out(['ok'=>false,'error'=>'Invalid id']);
                $set = [];
                $params = [':id' => $id, ':name' => $class_name, ':course' => $course_id, ':sem' => $semester];
                $set[] = "`{$class_name_col}` = :name";
                $set[] = "`{$class_course_col}` = :course";
                $set[] = "`{$class_sem_col}` = :sem";
                $sql = "UPDATE `class` SET " . implode(', ', $set) . " WHERE `{$class_id_col}` = :id";
                $stmt = $pdo->prepare($sql);
                foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
                $stmt->execute();
                json_out(['ok'=>true]);
            }
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_out(['ok'=>false,'error'=>'Invalid id']);
            $cols = $pdo->query("SHOW COLUMNS FROM `class`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('deleted_at', $cols)) {
                $stmt = $pdo->prepare("UPDATE `class` SET `deleted_at` = NOW() WHERE `{$class_id_col}` = :id");
                $stmt->bindValue(':id',$id,PDO::PARAM_INT); $stmt->execute();
            } else {
                $stmt = $pdo->prepare("DELETE FROM `class` WHERE `{$class_id_col}` = :id");
                $stmt->bindValue(':id',$id,PDO::PARAM_INT); $stmt->execute();
            }
            json_out(['ok'=>true]);
        }

        if ($action === 'undo') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_out(['ok'=>false,'error'=>'Invalid id']);
            $cols = $pdo->query("SHOW COLUMNS FROM `class`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('deleted_at', $cols)) {
                $stmt = $pdo->prepare("UPDATE `class` SET `deleted_at` = NULL WHERE `{$class_id_col}` = :id");
                $stmt->bindValue(':id',$id,PDO::PARAM_INT); $stmt->execute();
                json_out(['ok'=>true]);
            } else json_out(['ok'=>false,'error'=>'No deleted_at column to undo']);
        }

        if ($action === 'bulk_delete') {
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids)) json_out(['ok'=>false,'error'=>'Invalid ids']);
            $ids = array_map('intval', $ids);
            $ids = array_filter($ids, fn($v)=> $v>0);
            if (empty($ids)) json_out(['ok'=>false,'error'=>'No ids']);
            $placeholders = implode(',', array_fill(0,count($ids),'?'));
            $cols = $pdo->query("SHOW COLUMNS FROM `class`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('deleted_at', $cols)) {
                $sql = "UPDATE `class` SET `deleted_at` = NOW() WHERE `{$class_id_col}` IN ($placeholders)";
            } else {
                $sql = "DELETE FROM `class` WHERE `{$class_id_col}` IN ($placeholders)";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
            json_out(['ok'=>true, 'count' => count($ids)]);
        }

        json_out(['ok'=>false,'error'=>'Unknown action'], 400);

    } catch(Exception $e) {
        json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

/* ---------------- GET export (CSV) or fallback ---------------- */
if (isset($_GET['export']) && $_GET['export']) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="classes.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['ClassID','Class_Name','CourseID','Course_Name','Course_Code','Semester','Dept_ID','Dept_Name']);
    $sql = "SELECT cl.*, c.{$course_name_col} AS Course_Name, c.{$course_code_col} AS Course_Code, c.{$course_dept_col} AS DepartmentID,
                   d.{$dept_name_col} AS Dept_Name
            FROM `class` cl
            LEFT JOIN `course` c ON c.`{$course_id_col}` = cl.`{$class_course_col}`
            LEFT JOIN `department` d ON d.`{$dept_id_col}` = c.`{$course_dept_col}`";
    foreach ($pdo->query($sql) as $row) {
        fputcsv($out, [
            $row[$class_id_col] ?? '',
            $row[$class_name_col] ?? '',
            $row[$class_course_col] ?? '',
            $row[$course_name_col] ?? '',
            $row[$course_code_col] ?? '',
            $row[$class_sem_col] ?? '',
            $row[$course_dept_col] ?? '',
            $row[$dept_name_col] ?? ''
        ]);
    }
    exit;
}

json_out(['error'=>'Invalid request'], 400);
