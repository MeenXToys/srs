<?php
// admin/export_students.php
require_once __DIR__ . '/../config.php';
require_admin();

function e_csv($s){
    if ($s === null) return '';
    $s = (string)$s;
    return str_replace(["\r","\n"], ['',' '], $s);
}

$studentCols = [];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM student")->fetchAll(PDO::FETCH_COLUMN);
    if ($cols) $studentCols = $cols;
} catch (Exception $e) {
    die('Database error: cannot read student columns');
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

// get ids if POST (selected export)
$ids = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_filter(array_map(function($v){ return ctype_digit((string)$v)? (int)$v : null; }, $ids)));
}

try {
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "
          SELECT s.*, c.Class_Name AS Class_Name, c.Class_Code AS Class_Code
          FROM student s
          LEFT JOIN class c ON c.ClassID = s.ClassID
          WHERE s.`{$col_id}` IN ($placeholders)
          ORDER BY s.`{$col_id}` ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
    } else {
        $sql = "
          SELECT s.*, c.Class_Name AS Class_Name, c.Class_Code AS Class_Code
          FROM student s
          LEFT JOIN class c ON c.ClassID = s.ClassID
          ORDER BY s.`{$col_id}` ASC
        ";
        $stmt = $pdo->query($sql);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('Database query error: ' . $e->getMessage());
}

$filename = 'students_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

$header = ['StudentID'];
if ($col_name) $header[] = 'FullName';
if ($col_email) $header[] = 'Email';
$header[] = 'ClassID';
$header[] = 'Class_Name';
$header[] = 'Class_Code';
if ($col_deleted) $header[] = 'deleted_at';
fputcsv($out, $header);

foreach ($rows as $r) {
    $line = [];
    $line[] = $r[$col_id] ?? '';
    $line[] = $r[$col_name] ?? '';
    if ($col_email) $line[] = $r[$col_email] ?? '';
    // ensure we read class id from the student row (s.ClassID)
    $line[] = $r[$col_class] ?? ($r['ClassID'] ?? '');
    $line[] = $r['Class_Name'] ?? '';
    $line[] = $r['Class_Code'] ?? '';
    if ($col_deleted) $line[] = $r[$col_deleted] ?? '';
    $line = array_map('e_csv', $line);
    fputcsv($out, $line);
}
fclose($out);
exit;
