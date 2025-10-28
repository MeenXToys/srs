<?php
// admin/export_departments.php
require_once __DIR__ . '/../config.php';
require_admin();

// Accept optional ids[] via POST or GET
$ids = $_REQUEST['ids'] ?? [];
$where = "";
$params = [];
if (!empty($ids) && is_array($ids)) {
    $clean = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $where = " WHERE d.DepartmentID IN ($placeholders) ";
    $params = $clean;
}

// export either all or selected (only non-deleted)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="departments-'.date('Ymd-His').'.csv"');

$out = fopen('php://output','w');
fputcsv($out, ['DepartmentID','Dept_Code','Dept_Name','Students','Deleted_At']);

$sql = "
 SELECT d.DepartmentID, d.Dept_Code, d.Dept_Name, d.deleted_at,
        COUNT(s.UserID) AS students
 FROM department d
 LEFT JOIN course c ON c.DepartmentID = d.DepartmentID
 LEFT JOIN class cl ON cl.CourseID = c.CourseID
 LEFT JOIN student s ON s.ClassID = cl.ClassID
 $where
 GROUP BY d.DepartmentID, d.Dept_Code, d.Dept_Name, d.deleted_at
 ORDER BY d.Dept_Name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [$row['DepartmentID'], $row['Dept_Code'], $row['Dept_Name'], $row['students'], $row['deleted_at']]);
}
fclose($out);
exit;
