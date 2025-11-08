<?php
// admin/index.php (DIKEMASKINI: Reka bentuk "Pro" berdasarkan imej rujukan)
require_once __DIR__ . '/../config.php';
require_admin();
// admin_nav.php menyediakan $pdo, $displayName, dan memuatkan style.css
require_once __DIR__ . '/admin_nav.php'; 

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

/* * ===================================================
 * HELPER FUNCTIONS
 * ===================================================
 */
function safe_count($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) { return 0; }
}
function safe_scalar($pdo, $sql, $params = [], $default = 'N/A') {
     try {
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? $val : $default;
    } catch (Exception $e) { return $default; }
}
function safe_query($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/* * ===================================================
 * PENGUMPULAN DATA DASHBOARD
 * ===================================================
 */

// 1. KAD STATISTIK UTAMA (KPIs)
$totalStudents = safe_count($pdo, "SELECT COUNT(*) FROM student WHERE deleted_at IS NULL");
$totalClasses  = safe_count($pdo, "SELECT COUNT(*) FROM class WHERE deleted_at IS NULL");
$totalCourses  = safe_count($pdo, "SELECT COUNT(*) FROM course WHERE deleted_at IS NULL");
$totalDepts    = safe_count($pdo, "SELECT COUNT(*) FROM department WHERE deleted_at IS NULL");

// Tetapkan "Goal" (Matlamat) untuk progress bar
$studentGoal = 500;
$classGoal   = 50;
$courseGoal  = 100;
$deptGoal    = 10; 

// Kira peratusan untuk progress bar
$studentPercent = ($studentGoal > 0) ? round(($totalStudents / $studentGoal) * 100) : 0;
$classPercent   = ($classGoal > 0) ? round(($totalClasses / $classGoal) * 100) : 0;
$coursePercent  = ($courseGoal > 0) ? round(($totalCourses / $courseGoal) * 100) : 0;
$deptPercent    = ($deptGoal > 0) ? round(($totalDepts / $deptGoal) * 100) : 0;


// 2. CARTA GARISAN (Student Activity)
// (Data diambil dari jadual user kerana student tiada CreatedAt)
$studentTrendData = safe_query($pdo, "
    SELECT DATE_FORMAT(CreatedAt, '%Y-%m') AS ym, COUNT(*) AS count
    FROM user
    WHERE Role = 'Student' AND CreatedAt IS NOT NULL
    GROUP BY ym
    ORDER BY ym DESC
    LIMIT 12
");
$studentTrendLabels = json_encode(array_reverse(array_column($studentTrendData, 'ym')));
$studentTrendValues = json_encode(array_reverse(array_column($studentTrendData, 'count')));


// 3. CARTA DONUT (Course Statistic)
$deptBreakdown = safe_query($pdo, "
    SELECT d.Dept_Name AS label, COUNT(c.CourseID) AS value
    FROM department d
    LEFT JOIN course c ON c.DepartmentID = d.DepartmentID AND c.deleted_at IS NULL
    WHERE d.deleted_at IS NULL
    GROUP BY d.Dept_Name
    ORDER BY value DESC
    LIMIT 6
");
$jsDeptLabels = json_encode(array_column($deptBreakdown, 'label'));
$jsDeptValues = json_encode(array_column($deptBreakdown, 'value'));


// 4. SENARAI "RECENT"
$recentStudents = safe_query($pdo, "
    SELECT s.UserID, s.StudentID, u.Display_Name, u.Email, u.Profile_Image
    FROM student s
    JOIN user u ON s.UserID = u.UserID
    WHERE s.deleted_at IS NULL
    ORDER BY s.UserID DESC
    LIMIT 5
");

$recentCourses = safe_query($pdo, "
    SELECT CourseID, Course_Name 
    FROM course 
    WHERE deleted_at IS NULL 
    ORDER BY CourseID DESC 
    LIMIT 5
");


// 5. Personalization (Nama dari admin_nav.php)
$firstName = explode(' ', $displayName ?? 'Admin')[0];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>

    <style>
        :root {
            /* Warna tambahan dari style.css anda */
            --accent-purple: #7c3aed;
            --accent-pink: #D946EF;
            --accent-green: #10b981;
            --accent-blue: var(--accent, #38bdf8);
            --accent-red: #f87171;
            --accent-orange: #fb923c;
            
            /* Menggelapkan panel sedikit */
            --panel-pro: linear-gradient(180deg, #101824 0%, #0A0F1A 100%);
        }
        
        /* Grid utama dashboard */
        .admin-pro-grid {
            display: grid;
            grid-template-columns: 2.5fr 1fr; /* Lajur utama & sidebar */
            gap: 20px;
        }
        
        /* Grid untuk kad statistik */
        .admin-pro-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr); /* 4 kad sebaris */
            gap: 20px;
            margin-bottom: 20px;
        }
        
        /* Reka bentuk kad statistik (seperti imej rujukan) */
        .admin-pro-stat-card {
            background: var(--panel-pro, #1b2330);
            border-radius: var(--card-radius, 12px);
            padding: 20px 24px;
            border: 1px solid var(--glass, rgba(255,255,255,0.04));
            box-shadow: var(--shadow-1, 0 6px 18px rgba(0,0,0,0.35));
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: 16px; /* Jarak antara ikon, teks, dan bar */
        }
        .admin-pro-stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }
        .stat-card-header {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .stat-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .stat-card-icon svg {
            width: 24px;
            height: 24px;
            color: #fff;
        }
        /* Latar belakang ikon berwarna */
        .stat-card-icon.bg-purple { background: var(--accent-purple); }
        .stat-card-icon.bg-blue   { background: var(--accent-blue); }
        .stat-card-icon.bg-green  { background: var(--accent-green); }
        .stat-card-icon.bg-pink   { background: var(--accent-pink); }
        
        .stat-card-info .label {
            font-size: 0.9rem;
            color: var(--muted, #e0e0e0);
            margin-bottom: 4px;
        }
        .stat-card-info .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.1;
        }
        
        /* Bar Kemajuan (Progress Bar) dalam kad */
        .stat-card-progress {
            background: var(--glass);
            border-radius: 99px;
            height: 8px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .stat-card-progress-inner {
            height: 100%;
            border-radius: 99px;
            transition: width 0.5s ease;
        }
        /* Warna bar kemajuan */
        .stat-card-progress-inner.bg-purple { background: var(--accent-purple); box-shadow: 0 0 10px var(--accent-purple); }
        .stat-card-progress-inner.bg-blue   { background: var(--accent-blue); box-shadow: 0 0 10px var(--accent-blue); }
        .stat-card-progress-inner.bg-green  { background: var(--accent-green); box-shadow: 0 0 10px var(--accent-green); }
        .stat-card-progress-inner.bg-pink   { background: var(--accent-pink); box-shadow: 0 0 10px var(--accent-pink); }

        /* Senarai Aktiviti (menggunakan .card dari style.css) */
        .admin-pro-list-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 16px;
        }
        .admin-pro-list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--glass, rgba(255,255,255,0.04));
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid transparent;
            transition: background 0.2s ease, border-color 0.2s ease;
        }
        .admin-pro-list-item:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.1);
        }
        .list-item-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .list-item-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--panel-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            overflow: hidden;
        }
        .list-item-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .list-item-info .name { font-weight: 600; }
        .list-item-info .meta { font-size: 0.9rem; color: var(--muted); }
        
        /* Penyesuaian Carta */
        .chart-container {
            height: 300px; /* Tetapkan ketinggian untuk carta */
            margin-top: 16px;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .admin-pro-grid { 
                grid-template-columns: 1fr; /* Susun lajur secara menegak */
            }
        }
        @media (max-width: 768px) {
            .admin-pro-stats-grid { 
                grid-template-columns: 1fr 1fr; /* 2 kad sebaris */
            }
        }
        @media (max-width: 500px) {
            .admin-pro-stats-grid { 
                grid-template-columns: 1fr; /* 1 kad sebaris */
            }
        }
    </style>
</head>
<body>
    <main class="admin-main">
    
        <div class="header-bar" style="margin-bottom: 20px;">
            <h1 style="font-size: 2rem; margin: 0; color: #fff;">Hello <?= e($firstName) ?>, Welcome back!</h1>
            <p style="color: var(--muted); margin-top: 5px; font-size: 1.1rem;">
                <?= date("l, j F Y") ?> 
            </p>
        </div>

        <div class="admin-pro-stats-grid">
            
            <div class="admin-pro-stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon bg-purple">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A1.875 1.875 0 0118 22.5h-12a1.875 1.875 0 01-1.499-2.382z" /></svg>
                    </div>
                    <div class="stat-card-info">
                        <div class="label">Total Student</div>
                        <div class="value"><?= e($totalStudents) ?> / <?= e($studentGoal) ?></div>
                    </div>
                </div>
                <div class="stat-card-progress">
                    <div class="stat-card-progress-inner bg-purple" style="width: <?= e($studentPercent) ?>%;"></div>
                </div>
            </div>

            <div class="admin-pro-stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon bg-blue">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z" /></svg>
                    </div>
                    <div class="stat-card-info">
                        <div class="label">Total Class</div>
                        <div class="value"><?= e($totalClasses) ?> / <?= e($classGoal) ?></div>
                    </div>
                </div>
                <div class="stat-card-progress">
                    <div class="stat-card-progress-inner bg-blue" style="width: <?= e($classPercent) ?>%;"></div>
                </div>
            </div>

            <div class="admin-pro-stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon bg-green">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                    </div>
                    <div class="stat-card-info">
                        <div class="label">Total Course</div>
                        <div class="value"><?= e($totalCourses) ?> / <?= e($courseGoal) ?></div>
                    </div>
                </div>
                <div class="stat-card-progress">
                    <div class="stat-card-progress-inner bg-green" style="width: <?= e($coursePercent) ?>%;"></div>
                </div>
            </div>

            <div class="admin-pro-stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon bg-pink">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h6.75M9 11.25h6.75M9 15.75h6.75M9 20.25h6.75M3.75 6.75h.008v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 11.25h.008v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 15.75h.008v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 20.25h.008v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                    </div>
                    <div class="stat-card-info">
                        <div class="label">Total Department</div>
                        <div class="value"><?= e($totalDepts) ?> / <?= e($deptGoal) ?></div>
                    </div>
                </div>
                <div class="stat-card-progress">
                    <div class="stat-card-progress-inner bg-pink" style="width: <?= e($deptPercent) ?>%;"></div>
                </div>
            </div>
            
        </div>

        <div class="admin-pro-grid">
        
            <div class="main-column">
                
                <div class="card">
                    <h3>Student Activity</h3>
                    <p class="muted" style="margin-bottom: 16px;">New student registrations over the last 12 months.</p>
                    <div class="chart-container">
                        <canvas id="studentActivityChart"></canvas>
                    </div>
                </div>

                <div class="card" style="margin-top: 20px;">
                    <h3>Recent Students</h3>
                    <div class="admin-pro-list-group">
                        <?php if (empty($recentStudents)): ?>
                            <p style="color: var(--muted);">No new students found.</p>
                        <?php else: ?>
                            <?php foreach ($recentStudents as $student): ?>
                            <div class="admin-pro-list-item">
                                <div class="list-item-info">
                                    <div class="list-item-avatar">
                                        <?php $avatarUrl = resolve_avatar_url($student['Profile_Image']); ?>
                                        <?php if ($avatarUrl): ?>
                                            <img src="<?= e($avatarUrl) ?>" alt="Avatar">
                                        <?php else: ?>
                                            <span><?= e(initials_for_nav($student['Display_Name'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="name"><?= e($student['Display_Name']) ?></div>
                                        <div class="meta"><?= e($student['Email']) ?></div>
                                    </div>
                                </div>
                                <a href="edit_student.php?id=<?= e($student['UserID']) ?>" class="btn btn-ghost">View</a>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
            
            <aside class="sidebar-column">

                <div class="card">
                    <h3>Course Statistic</h3>
                    <p class="muted" style="margin-bottom: 16px;">Distribution of courses per department.</p>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="courseStatisticChart"></canvas>
                    </div>
                </div>

                <div class="card" style="margin-top: 20px;">
                    <h3>Recent Courses</h3>
                    <div class="admin-pro-list-group">
                        <?php if (empty($recentCourses)): ?>
                            <p style="color: var(--muted);">No courses found.</p>
                        <?php else: ?>
                            <?php foreach ($recentCourses as $course): ?>
                            <div class="admin-pro-list-item">
                                <div>
                                    <div class="name"><?= e($course['Course_Name']) ?></div>
                                    <div class="meta">ID: <?= e($course['CourseID']) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </aside>
        </div>
        
    </main>

    <script>
    // Tunggu sehingga DOM sedia dan Chart.js dimuatkan
    window.addEventListener('DOMContentLoaded', () => {
        if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded.');
            return;
        }
        
        // Tetapan Am Carta
        Chart.defaults.color = 'var(--muted)';
        Chart.defaults.font.family = 'Inter, system-ui, sans-serif';

        /*
         * 1. CARTA AKTIVITI PELAJAR (Line Chart)
         */
        const studentChartCtx = document.getElementById('studentActivityChart');
        if (studentChartCtx) {
            const studentGradient = studentChartCtx.getContext('2d').createLinearGradient(0, 0, 0, 300);
            studentGradient.addColorStop(0, 'rgba(124, 58, 237, 0.4)');
            studentGradient.addColorStop(1, 'rgba(124, 58, 237, 0.01)');
            
            new Chart(studentChartCtx, {
                type: 'line',
                data: {
                    labels: <?= $studentTrendLabels ?>,
                    datasets: [{
                        label: 'New Students',
                        data: <?= $studentTrendValues ?>,
                        borderColor: 'var(--accent-purple)',
                        backgroundColor: studentGradient,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: 'var(--accent-purple)',
                        pointBorderColor: '#fff',
                        pointHoverRadius: 6,
                        pointRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            grid: { color: 'rgba(255, 255, 255, 0.05)' },
                            ticks: { precision: 0 }
                        },
                        x: { 
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        /*
         * 2. CARTA STATISTIK KURSUS (Donut Chart)
         */
        const courseChartCtx = document.getElementById('courseStatisticChart');
        if (courseChartCtx) {
            new Chart(courseChartCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= $jsDeptLabels ?>,
                    datasets: [{
                        label: 'Courses',
                        data: <?= $jsDeptValues ?>,
                        backgroundColor: [
                            'var(--accent-purple)',
                            'var(--accent-blue)',
                            'var(--accent-green)',
                            'var(--accent-pink)',
                            'var(--accent-red)',
                            'var(--accent-orange)'
                        ],
                        borderColor: 'var(--panel-pro)',
                        borderWidth: 4,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                boxWidth: 12
                            }
                        }
                    }
                }
            });
        }
    });
    </script>

</body>
</html>