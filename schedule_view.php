<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// 获取筛选参数
$classId = intval($_GET['class'] ?? 0);
$teacherId = intval($_GET['teacher'] ?? 0);
$classroomId = intval($_GET['classroom'] ?? 0);
$assignmentId = intval($_GET['assignment'] ?? 0);
$semester = sanitize($_GET['semester'] ?? getCurrentSemester());
$weekStart = intval($_GET['week_start'] ?? 1);
$weekEnd = intval($_GET['week_end'] ?? 20);

// 构建查询条件
$where = "WHERE cca.semester = ?";
$params = [$semester];

if ($classId > 0) {
    $where .= " AND cca.class_id = ?";
    $params[] = $classId;
}

if ($teacherId > 0) {
    $where .= " AND cca.teacher_id = ?";
    $params[] = $teacherId;
}

if ($classroomId > 0) {
    $where .= " AND cca.classroom_id = ?";
    $params[] = $classroomId;
}

if ($assignmentId > 0) {
    $where .= " AND cca.id = ?";
    $params[] = $assignmentId;
}

$where .= " AND s.week_start <= ? AND s.week_end >= ?";
$params[] = $weekEnd;
$params[] = $weekStart;

try {
    // 获取课程表数据
    $stmt = $db->prepare("
        SELECT s.*, 
               c.name as course_name, c.type as course_type,
               cl.class_name, cl.grade_year,
               m.name as major_name,
               t.name as teacher_name,
               cr.name as classroom_name,
               ts.start_time, ts.end_time, ts.period
        FROM schedule s
        JOIN course_class_assignments cca ON s.assignment_id = cca.id
        JOIN courses c ON cca.course_id = c.id
        JOIN classes cl ON cca.class_id = cl.id
        JOIN majors m ON cl.major_id = m.id
        JOIN teachers t ON cca.teacher_id = t.id
        LEFT JOIN classrooms cr ON cca.classroom_id = cr.id
        JOIN time_slots ts ON s.time_slot = ts.slot_number
        {$where}
        ORDER BY s.day_of_week, s.time_slot
    ");
    $stmt->execute($params);
    $scheduleData = $stmt->fetchAll();
    
    // 获取基础数据
    $classes = $db->query("SELECT c.*, m.name as major_name FROM classes c JOIN majors m ON c.major_id = m.id ORDER BY c.grade_year DESC, m.name, c.class_name")->fetchAll();
    $teachers = $db->query("SELECT * FROM teachers ORDER BY name")->fetchAll();
    $classrooms = $db->query("SELECT * FROM classrooms ORDER BY name")->fetchAll();
    $timeSlots = $db->query("SELECT * FROM time_slots ORDER BY slot_number")->fetchAll();
    
} catch (PDOException $e) {
    error_log("Get schedule data error: " . $e->getMessage());
    $scheduleData = [];
    $classes = $teachers = $classrooms = $timeSlots = [];
}

// 构建课程表矩阵
$scheduleMatrix = [];
foreach ($scheduleData as $item) {
    $scheduleMatrix[$item['day_of_week']][$item['time_slot']][] = $item;
}

// 获取当前筛选的标题信息
$filterTitle = '完整课程表';
if ($classId > 0) {
    $stmt = $db->prepare("SELECT cl.class_name, cl.grade_year, m.name as major_name FROM classes cl JOIN majors m ON cl.major_id = m.id WHERE cl.id = ?");
    $stmt->execute([$classId]);
    $classInfo = $stmt->fetch();
    if ($classInfo) {
        $filterTitle = $classInfo['grade_year'] . '级 ' . $classInfo['major_name'] . ' ' . $classInfo['class_name'] . ' 课程表';
    }
} elseif ($teacherId > 0) {
    $stmt = $db->prepare("SELECT name FROM teachers WHERE id = ?");
    $stmt->execute([$teacherId]);
    $teacherInfo = $stmt->fetch();
    if ($teacherInfo) {
        $filterTitle = $teacherInfo['name'] . ' 老师课程表';
    }
} elseif ($classroomId > 0) {
    $stmt = $db->prepare("SELECT name FROM classrooms WHERE id = ?");
    $stmt->execute([$classroomId]);
    $classroomInfo = $stmt->fetch();
    if ($classroomInfo) {
        $filterTitle = $classroomInfo['name'] . ' 教室使用表';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>课程表查看 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-table"></i> 课程表查看</h1>
                <p>查看和打印课程表</p>
            </div>
            
            <!-- 筛选器 -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <select name="semester" class="form-control">
                            <?php
                            $currentYear = date('Y');
                            for ($year = $currentYear; $year >= $currentYear - 2; $year--):
                                $semester1 = ($year - 1) . '-' . $year . '-2';
                                $semester2 = $year . '-' . ($year + 1) . '-1';
                            ?>
                                <option value="<?php echo $semester2; ?>" <?php echo $semester === $semester2 ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>-<?php echo $year + 1; ?>学年 秋季学期
                                </option>
                                <option value="<?php echo $semester1; ?>" <?php echo $semester === $semester1 ? 'selected' : ''; ?>>
                                    <?php echo $year - 1; ?>-<?php echo $year; ?>学年 春季学期
                                </option>
                            <?php endfor; ?>
                        </select>
                        
                        <select name="class" class="form-control">
                            <option value="">全部班级</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" 
                                        <?php echo $classId === $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo $class['grade_year']; ?>级 <?php echo sanitize($class['major_name']); ?> <?php echo sanitize($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="teacher" class="form-control">
                            <option value="">全部教师</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>" 
                                        <?php echo $teacherId === $teacher['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($teacher['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="classroom" class="form-control">
                            <option value="">全部教室</option>
                            <?php foreach ($classrooms as $classroom): ?>
                                <option value="<?php echo $classroom['id']; ?>" 
                                        <?php echo $classroomId === $classroom['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($classroom['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> 筛选
                        </button>
                        
                        <a href="schedule_view.php" class="btn btn-outline">
                            <i class="fas fa-refresh"></i> 重置
                        </a>
                    </div>
                </form>
                
                <div class="actions">
                    <button onclick="printSchedule()" class="btn btn-success">
                        <i class="fas fa-print"></i> 打印课表
                    </button>
                    <button onclick="exportExcel()" class="btn btn-info">
                        <i class="fas fa-file-excel"></i> 导出Excel
                    </button>
                </div>
            </div>
            
            <!-- 周次选择 -->
            <div class="week-selector">
                <label>显示周次：</label>
                <input type="number" id="weekStart" value="<?php echo $weekStart; ?>" min="1" max="30" class="week-input">
                <span>到</span>
                <input type="number" id="weekEnd" value="<?php echo $weekEnd; ?>" min="1" max="30" class="week-input">
                <button onclick="updateWeekRange()" class="btn btn-sm btn-outline">更新</button>
            </div>
            
            <!-- 课程表 -->
            <div class="schedule-container" id="scheduleContainer">
                <div class="schedule-header">
                    <h2><?php echo $filterTitle; ?></h2>
                    <div class="schedule-info">
                        <span><?php echo $semester; ?> 学期</span>
                        <span>第 <?php echo $weekStart; ?>-<?php echo $weekEnd; ?> 周</span>
                        <span>生成时间：<?php echo date('Y-m-d H:i'); ?></span>
                    </div>
                </div>
                
                <table class="schedule-table">
                    <thead>
                        <tr>
                            <th rowspan="2" class="time-header">时间</th>
                            <th colspan="7" class="days-header">星期</th>
                        </tr>
                        <tr>
                            <th class="day-header">一</th>
                            <th class="day-header">二</th>
                            <th class="day-header">三</th>
                            <th class="day-header">四</th>
                            <th class="day-header">五</th>
                            <th class="day-header">六</th>
                            <th class="day-header">日</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timeSlots as $timeSlot): ?>
                            <tr>
                                <td class="time-cell">
                                    <div class="time-info">
                                        <div class="time-slot"><?php echo $timeSlot['name']; ?></div>
                                        <div class="time-range">
                                            <?php echo date('H:i', strtotime($timeSlot['start_time'])); ?>-<?php echo date('H:i', strtotime($timeSlot['end_time'])); ?>
                                        </div>
                                    </div>
                                </td>
                                
                                <?php for ($day = 1; $day <= 7; $day++): ?>
                                    <td class="schedule-cell">
                                        <?php
                                        $courses = $scheduleMatrix[$day][$timeSlot['slot_number']] ?? [];
                                        foreach ($courses as $course):
                                        ?>
                                            <div class="course-item <?php echo $course['course_type']; ?>">
                                                <div class="course-name" title="<?php echo sanitize($course['course_name']); ?>">
                                                    <?php echo sanitize($course['course_name']); ?>
                                                </div>
                                                
                                                <?php if (!$classId): ?>
                                                    <div class="class-info">
                                                        <?php echo $course['grade_year']; ?>级<?php echo sanitize($course['major_name']); ?><?php echo sanitize($course['class_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!$teacherId): ?>
                                                    <div class="teacher-info">
                                                        <i class="fas fa-user"></i> <?php echo sanitize($course['teacher_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!$classroomId && $course['classroom_name']): ?>
                                                    <div class="classroom-info">
                                                        <i class="fas fa-door-open"></i> <?php echo sanitize($course['classroom_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="week-info">
                                                    第<?php echo $course['week_start']; ?>-<?php echo $course['week_end']; ?>周
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($courses)): ?>
                                            <div class="empty-slot">-</div>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- 统计信息 -->
                <div class="schedule-stats">
                    <div class="stat-item">
                        <strong>总课程数：</strong>
                        <span><?php echo count($scheduleData); ?></span>
                    </div>
                    <div class="stat-item">
                        <strong>专业课：</strong>
                        <span><?php echo count(array_filter($scheduleData, function($item) { return $item['course_type'] === 'professional'; })); ?></span>
                    </div>
                    <div class="stat-item">
                        <strong>公共课：</strong>
                        <span><?php echo count(array_filter($scheduleData, function($item) { return $item['course_type'] === 'public'; })); ?></span>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="scripts.js"></script>
    <script>
        // 更新周次范围
        function updateWeekRange() {
            const weekStart = document.getElementById('weekStart').value;
            const weekEnd = document.getElementById('weekEnd').value;
            
            if (weekStart > weekEnd) {
                alert('开始周次不能大于结束周次');
                return;
            }
            
            const url = new URL(window.location);
            url.searchParams.set('week_start', weekStart);
            url.searchParams.set('week_end', weekEnd);
            window.location.href = url.toString();
        }
        
        // 打印课程表
        function printSchedule() {
            const printContent = document.getElementById('scheduleContainer').innerHTML;
            const printWindow = window.open('', '', 'height=600,width=800');
            
            printWindow.document.write(`
                <html>
                <head>
                    <title>课程表</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .schedule-header { text-align: center; margin-bottom: 20px; }
                        .schedule-header h2 { margin: 0; color: #333; }
                        .schedule-info { margin-top: 10px; color: #666; }
                        .schedule-info span { margin: 0 15px; }
                        .schedule-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                        .schedule-table th, .schedule-table td { 
                            border: 1px solid #ccc; 
                            padding: 8px; 
                            text-align: center; 
                            vertical-align: top;
                            font-size: 12px;
                        }
                        .schedule-table th { background: #f5f5f5; font-weight: bold; }
                        .time-cell { background: #f9f9f9; width: 80px; }
                        .time-slot { font-weight: bold; }
                        .time-range { font-size: 10px; color: #666; }
                        .course-item { margin-bottom: 8px; padding: 4px; border-radius: 4px; }
                        .course-item.professional { background: #e3f2fd; }
                        .course-item.public { background: #f3e5f5; }
                        .course-name { font-weight: bold; font-size: 11px; }
                        .class-info, .teacher-info, .classroom-info, .week-info { 
                            font-size: 9px; 
                            color: #666; 
                            margin-top: 2px;
                        }
                        .schedule-stats { text-align: center; font-size: 12px; }
                        .stat-item { display: inline-block; margin: 0 15px; }
                        @media print {
                            body { margin: 0; }
                            .schedule-table { font-size: 10px; }
                        }
                    </style>
                </head>
                <body>${printContent}</body>
                </html>
            `);
            
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            printWindow.close();
        }
        
        // 导出Excel
        function exportExcel() {
            alert('Excel导出功能开发中...');
        }
        
        // 课程项点击事件
        document.addEventListener('click', function(e) {
            const courseItem = e.target.closest('.course-item');
            if (courseItem) {
                // 可以在这里添加课程详情显示功能
                console.log('点击了课程:', courseItem);
            }
        });
        
        // 响应式表格处理
        function handleResponsive() {
            const table = document.querySelector('.schedule-table');
            const container = document.querySelector('.schedule-container');
            
            if (window.innerWidth < 768) {
                container.style.overflowX = 'auto';
                table.style.minWidth = '800px';
            } else {
                container.style.overflowX = 'visible';
                table.style.minWidth = 'auto';
            }
        }
        
        window.addEventListener('resize', handleResponsive);
        window.addEventListener('load', handleResponsive);
    </script>
    
    <style>
        .week-selector {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .week-input {
            width: 60px;
            padding: 5px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            text-align: center;
        }
        
        .schedule-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            padding: 30px;
            overflow-x: auto;
        }
        
        .schedule-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
        }
        
        .schedule-header h2 {
            color: #1e293b;
            margin-bottom: 10px;
            font-size: 24px;
        }
        
        .schedule-info {
            display: flex;
            justify-content: center;
            gap: 30px;
            color: #64748b;
            font-size: 14px;
        }
        
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            min-width: 800px;
        }
        
        .schedule-table th,
        .schedule-table td {
            border: 1px solid #e2e8f0;
            padding: 12px 8px;
            text-align: center;
            vertical-align: top;
        }
        
        .schedule-table th {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        .time-header {
            background: linear-gradient(135deg, #1e293b, #334155) !important;
            width: 100px;
        }
        
        .day-header {
            width: calc((100% - 100px) / 7);
        }
        
        .days-header {
            background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
        }
        
        .time-cell {
            background: #f8fafc;
            font-weight: 500;
        }
        
        .time-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .time-slot {
            font-weight: 600;
            color: #1e293b;
            font-size: 13px;
        }
        
        .time-range {
            font-size: 11px;
            color: #64748b;
        }
        
        .schedule-cell {
            height: 100px;
            position: relative;
            padding: 8px;
        }
        
        .course-item {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .course-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
        }
        
        .course-item:last-child {
            margin-bottom: 0;
        }
        
        .course-item.professional {
            background: linear-gradient(135deg, #ddd6fe, #c4b5fd);
            border-color: #a78bfa;
        }
        
        .course-item.public {
            background: linear-gradient(135deg, #fecaca, #fca5a5);
            border-color: #f87171;
        }
        
        .course-name {
            font-weight: 600;
            font-size: 12px;
            color: #1e293b;
            margin-bottom: 4px;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .class-info,
        .teacher-info,
        .classroom-info,
        .week-info {
            font-size: 10px;
            color: #64748b;
            margin-bottom: 2px;
            line-height: 1.2;
            display: flex;
            align-items: center;
            gap: 2px;
        }
        
        .teacher-info i,
        .classroom-info i {
            font-size: 8px;
        }
        
        .empty-slot {
            color: #cbd5e1;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 80px;
        }
        
        .schedule-stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 10px;
            border-top: 2px solid #e2e8f0;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-item strong {
            color: #374151;
            margin-right: 8px;
        }
        
        .stat-item span {
            color: #4f46e5;
            font-weight: 600;
        }
        
        /* 打印样式 */
        @media print {
            .page-header,
            .filter-section,
            .week-selector {
                display: none !important;
            }
            
            .schedule-container {
                box-shadow: none;
                padding: 0;
            }
            
            .schedule-table {
                font-size: 10px;
            }
            
            .course-item {
                break-inside: avoid;
            }
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .schedule-info {
                flex-direction: column;
                gap: 10px;
            }
            
            .schedule-stats {
                flex-direction: column;
                gap: 15px;
            }
            
            .filter-group {
                flex-direction: column;
                gap: 10px;
            }
            
            .filter-group .form-control {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .schedule-container {
                padding: 15px;
            }
            
            .schedule-header h2 {
                font-size: 18px;
            }
            
            .week-selector {
                flex-wrap: wrap;
                gap: 8px;
            }
        }
    </style>
</body>
</html>