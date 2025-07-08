<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = '无效的请求';
    } else {
        switch ($action) {
            case 'add_assignment':
                $courseId = intval($_POST['course_id'] ?? 0);
                $classId = intval($_POST['class_id'] ?? 0);
                $teacherId = intval($_POST['teacher_id'] ?? 0);
                $classroomId = intval($_POST['classroom_id'] ?? 0) ?: null;
                $semester = sanitize($_POST['semester'] ?? '');
                $remarks = sanitize($_POST['remarks'] ?? '');
                
                if ($courseId <= 0 || $classId <= 0 || $teacherId <= 0 || empty($semester)) {
                    $error = '请填写完整的课程安排信息';
                } else {
                    try {
                        $stmt = $db->prepare("INSERT INTO course_class_assignments (course_id, class_id, teacher_id, classroom_id, semester, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$courseId, $classId, $teacherId, $classroomId, $semester, $remarks]);
                        $assignmentId = $db->lastInsertId();
                        $message = '课程安排添加成功！';
                        logActivity('add_course_assignment', "Assignment ID: {$assignmentId}");
                    } catch (PDOException $e) {
                        if ($e->getCode() == '23000') {
                            $error = '该课程已分配给此班级！';
                        } else {
                            $error = '添加失败，请稍后重试';
                            error_log("Add assignment error: " . $e->getMessage());
                        }
                    }
                }
                break;
                
            case 'add_schedule':
                $assignmentId = intval($_POST['assignment_id'] ?? 0);
                $dayOfWeek = intval($_POST['day_of_week'] ?? 0);
                $timeSlot = intval($_POST['time_slot'] ?? 0);
                $weekStart = intval($_POST['week_start'] ?? 1);
                $weekEnd = intval($_POST['week_end'] ?? 20);
                
                if ($assignmentId <= 0 || $dayOfWeek <= 0 || $timeSlot <= 0) {
                    $error = '请选择有效的时间安排';
                } else {
                    try {
                        // 检查时间冲突
                        $conflicts = checkScheduleConflicts($db, $assignmentId, $dayOfWeek, $timeSlot, $weekStart, $weekEnd);
                        
                        if (!empty($conflicts)) {
                            $error = '时间冲突：' . implode(', ', $conflicts);
                        } else {
                            $stmt = $db->prepare("INSERT INTO schedule (assignment_id, day_of_week, time_slot, week_start, week_end) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$assignmentId, $dayOfWeek, $timeSlot, $weekStart, $weekEnd]);
                            $message = '课程时间安排成功！';
                            logActivity('add_schedule', "Assignment ID: {$assignmentId}, Day: {$dayOfWeek}, Slot: {$timeSlot}");
                        }
                    } catch (PDOException $e) {
                        $error = '安排失败，请稍后重试';
                        error_log("Add schedule error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'auto_schedule':
                $classId = intval($_POST['class_id'] ?? 0);
                $semester = sanitize($_POST['semester'] ?? '');
                
                if ($classId <= 0 || empty($semester)) {
                    $error = '请选择班级和学期';
                } else {
                    try {
                        $result = autoScheduleClass($db, $classId, $semester);
                        if ($result['success']) {
                            $message = "自动排课完成！成功安排 {$result['scheduled']} 个课程时间段";
                            if (!empty($result['failed'])) {
                                $message .= "，{$result['failed']} 个课程时间段因冲突无法安排";
                            }
                        } else {
                            $error = $result['message'];
                        }
                    } catch (Exception $e) {
                        $error = '自动排课失败：' . $e->getMessage();
                        error_log("Auto schedule error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'delete_schedule':
                $scheduleId = intval($_POST['schedule_id'] ?? 0);
                try {
                    $stmt = $db->prepare("DELETE FROM schedule WHERE id = ?");
                    $stmt->execute([$scheduleId]);
                    $message = '课程时间删除成功！';
                    logActivity('delete_schedule', "Schedule ID: {$scheduleId}");
                } catch (PDOException $e) {
                    $error = '删除失败，请稍后重试';
                    error_log("Delete schedule error: " . $e->getMessage());
                }
                break;
        }
    }
}

// 获取基础数据
try {
    $courses = $db->query("SELECT * FROM courses ORDER BY name")->fetchAll();
    $teachers = $db->query("SELECT * FROM teachers ORDER BY name")->fetchAll();
    $classrooms = $db->query("SELECT * FROM classrooms ORDER BY name")->fetchAll();
    $classes = $db->query("SELECT c.*, m.name as major_name FROM classes c JOIN majors m ON c.major_id = m.id ORDER BY c.grade_year DESC, m.name, c.class_name")->fetchAll();
} catch (PDOException $e) {
    error_log("Get basic data error: " . $e->getMessage());
    $courses = $teachers = $classrooms = $classes = [];
}

// 获取课程安排列表
$semester = sanitize($_GET['semester'] ?? getCurrentSemester());
$classFilter = intval($_GET['class'] ?? 0);

try {
    $where = "WHERE cca.semester = ?";
    $params = [$semester];
    
    if ($classFilter > 0) {
        $where .= " AND cca.class_id = ?";
        $params[] = $classFilter;
    }
    
    $stmt = $db->prepare("
        SELECT cca.*, 
               c.name as course_name, c.type as course_type, c.weekly_hours,
               cl.class_name, m.name as major_name,
               t.name as teacher_name,
               cr.name as classroom_name,
               COUNT(s.id) as scheduled_hours
        FROM course_class_assignments cca
        JOIN courses c ON cca.course_id = c.id
        JOIN classes cl ON cca.class_id = cl.id
        JOIN majors m ON cl.major_id = m.id
        JOIN teachers t ON cca.teacher_id = t.id
        LEFT JOIN classrooms cr ON cca.classroom_id = cr.id
        LEFT JOIN schedule s ON cca.id = s.assignment_id
        {$where}
        GROUP BY cca.id
        ORDER BY cl.grade_year DESC, m.name, cl.class_name, c.name
    ");
    $stmt->execute($params);
    $assignments = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get assignments error: " . $e->getMessage());
    $assignments = [];
}

// 检查时间冲突的函数
function checkScheduleConflicts($db, $assignmentId, $dayOfWeek, $timeSlot, $weekStart, $weekEnd) {
    $conflicts = [];
    
    // 获取当前安排的信息
    $stmt = $db->prepare("
        SELECT cca.teacher_id, cca.classroom_id, cl.id as class_id
        FROM course_class_assignments cca
        JOIN classes cl ON cca.class_id = cl.id
        WHERE cca.id = ?
    ");
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch();
    
    if (!$assignment) return ['无效的课程安排'];
    
    // 检查教师冲突
    $stmt = $db->prepare("
        SELECT c.name as course_name, cl.class_name, m.name as major_name
        FROM schedule s
        JOIN course_class_assignments cca ON s.assignment_id = cca.id
        JOIN courses c ON cca.course_id = c.id
        JOIN classes cl ON cca.class_id = cl.id
        JOIN majors m ON cl.major_id = m.id
        WHERE cca.teacher_id = ? AND s.day_of_week = ? AND s.time_slot = ?
        AND s.week_start <= ? AND s.week_end >= ?
    ");
    $stmt->execute([$assignment['teacher_id'], $dayOfWeek, $timeSlot, $weekEnd, $weekStart]);
    $teacherConflicts = $stmt->fetchAll();
    
    foreach ($teacherConflicts as $conflict) {
        $conflicts[] = "教师冲突：{$conflict['course_name']} ({$conflict['major_name']} {$conflict['class_name']})";
    }
    
    // 检查教室冲突
    if ($assignment['classroom_id']) {
        $stmt = $db->prepare("
            SELECT c.name as course_name, cl.class_name, m.name as major_name
            FROM schedule s
            JOIN course_class_assignments cca ON s.assignment_id = cca.id
            JOIN courses c ON cca.course_id = c.id
            JOIN classes cl ON cca.class_id = cl.id
            JOIN majors m ON cl.major_id = m.id
            WHERE cca.classroom_id = ? AND s.day_of_week = ? AND s.time_slot = ?
            AND s.week_start <= ? AND s.week_end >= ?
        ");
        $stmt->execute([$assignment['classroom_id'], $dayOfWeek, $timeSlot, $weekEnd, $weekStart]);
        $classroomConflicts = $stmt->fetchAll();
        
        foreach ($classroomConflicts as $conflict) {
            $conflicts[] = "教室冲突：{$conflict['course_name']} ({$conflict['major_name']} {$conflict['class_name']})";
        }
    }
    
    // 检查班级冲突
    $stmt = $db->prepare("
        SELECT c.name as course_name, t.name as teacher_name
        FROM schedule s
        JOIN course_class_assignments cca ON s.assignment_id = cca.id
        JOIN courses c ON cca.course_id = c.id
        JOIN teachers t ON cca.teacher_id = t.id
        WHERE cca.class_id = ? AND s.day_of_week = ? AND s.time_slot = ?
        AND s.week_start <= ? AND s.week_end >= ?
    ");
    $stmt->execute([$assignment['class_id'], $dayOfWeek, $timeSlot, $weekEnd, $weekStart]);
    $classConflicts = $stmt->fetchAll();
    
    foreach ($classConflicts as $conflict) {
        $conflicts[] = "班级冲突：{$conflict['course_name']} ({$conflict['teacher_name']})";
    }
    
    return $conflicts;
}

// 自动排课函数
function autoScheduleClass($db, $classId, $semester) {
    // 获取该班级需要排课的课程
    $stmt = $db->prepare("
        SELECT cca.*, c.weekly_hours, c.type as course_type
        FROM course_class_assignments cca
        JOIN courses c ON cca.course_id = c.id
        WHERE cca.class_id = ? AND cca.semester = ?
    ");
    $stmt->execute([$classId, $semester]);
    $assignments = $stmt->fetchAll();
    
    if (empty($assignments)) {
        return ['success' => false, 'message' => '该班级没有需要排课的课程'];
    }
    
    $scheduled = 0;
    $failed = 0;
    
    // 定义排课时间段（避开中午和太晚的时间）
    $timeSlots = [
        1 => [1, 2, 3, 4, 5], // 周一到周五，第1节课
        2 => [1, 2, 3, 4, 5], // 周一到周五，第2节课
        3 => [1, 2, 3, 4, 5], // 周一到周五，第3节课
        4 => [1, 2, 3, 4, 5], // 周一到周五，第4节课
        5 => [1, 2, 3, 4], // 周一到周四，第5节课
        6 => [1, 2, 3, 4], // 周一到周四，第6节课
    ];
    
    foreach ($assignments as $assignment) {
        $hoursNeeded = $assignment['weekly_hours'];
        $hoursScheduled = 0;
        
        // 获取已安排的课时
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM schedule WHERE assignment_id = ?");
        $stmt->execute([$assignment['id']]);
        $existingHours = $stmt->fetch()['count'];
        
        $hoursNeeded -= $existingHours;
        
        if ($hoursNeeded <= 0) continue;
        
        // 尝试安排课程
        $attempts = 0;
        $maxAttempts = 50; // 防止无限循环
        
        while ($hoursScheduled < $hoursNeeded && $attempts < $maxAttempts) {
            $attempts++;
            
            // 随机选择时间段
            $timeSlot = array_rand($timeSlots);
            $days = $timeSlots[$timeSlot];
            $dayOfWeek = $days[array_rand($days)];
            
            // 检查冲突
            $conflicts = checkScheduleConflicts($db, $assignment['id'], $dayOfWeek, $timeSlot, 1, 20);
            
            if (empty($conflicts)) {
                // 无冲突，安排课程
                try {
                    $stmt = $db->prepare("INSERT INTO schedule (assignment_id, day_of_week, time_slot, week_start, week_end) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$assignment['id'], $dayOfWeek, $timeSlot, 1, 20]);
                    $hoursScheduled++;
                    $scheduled++;
                } catch (PDOException $e) {
                    // 插入失败，继续尝试
                    continue;
                }
            }
        }
        
        if ($hoursScheduled < $hoursNeeded) {
            $failed += ($hoursNeeded - $hoursScheduled);
        }
    }
    
    return [
        'success' => true,
        'scheduled' => $scheduled,
        'failed' => $failed
    ];
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>课程排课 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-calendar-plus"></i> 课程排课</h1>
                <p>进行自动排课或手动安排课程时间</p>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <!-- 快速操作 -->
            <div class="quick-actions-grid">
                <div class="action-card primary" onclick="showAddAssignmentModal()">
                    <i class="fas fa-plus-circle"></i>
                    <h3>添加课程安排</h3>
                    <p>为班级分配课程和教师</p>
                </div>
                
                <div class="action-card success" onclick="showAutoScheduleModal()">
                    <i class="fas fa-magic"></i>
                    <h3>自动排课</h3>
                    <p>使用算法自动安排课程时间</p>
                </div>
                
                <div class="action-card info" onclick="window.open('schedule_view.php', '_blank')">
                    <i class="fas fa-table"></i>
                    <h3>查看课表</h3>
                    <p>浏览完整的课程表</p>
                </div>
                
                <div class="action-card warning" onclick="window.open('ai_schedule.php', '_blank')">
                    <i class="fas fa-robot"></i>
                    <h3>AI辅助排课</h3>
                    <p>使用AI智能优化排课</p>
                </div>
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
                                        <?php echo $classFilter === $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo $class['grade_year']; ?>级 <?php echo sanitize($class['major_name']); ?> <?php echo sanitize($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> 筛选
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- 课程安排列表 -->
            <div class="assignments-section">
                <h3><i class="fas fa-list"></i> 课程安排列表</h3>
                
                <?php if (empty($assignments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h4>暂无课程安排</h4>
                        <p>请先为班级添加课程安排</p>
                        <button onclick="showAddAssignmentModal()" class="btn btn-primary">添加课程安排</button>
                    </div>
                <?php else: ?>
                    <div class="assignments-grid">
                        <?php foreach ($assignments as $assignment): ?>
                            <div class="assignment-card">
                                <div class="assignment-header">
                                    <div class="course-info">
                                        <h4><?php echo sanitize($assignment['course_name']); ?></h4>
                                        <span class="course-type <?php echo $assignment['course_type']; ?>">
                                            <?php echo $assignment['course_type'] === 'professional' ? '专业课' : '公共课'; ?>
                                        </span>
                                    </div>
                                    <div class="assignment-status">
                                        <?php
                                        $progress = min(100, ($assignment['scheduled_hours'] / $assignment['weekly_hours']) * 100);
                                        $statusClass = $progress >= 100 ? 'complete' : ($progress > 0 ? 'partial' : 'pending');
                                        ?>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo $assignment['scheduled_hours']; ?>/<?php echo $assignment['weekly_hours']; ?> 课时
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="assignment-details">
                                    <div class="detail-item">
                                        <i class="fas fa-users"></i>
                                        <span><?php echo $assignment['grade_year']; ?>级 <?php echo sanitize($assignment['major_name']); ?> <?php echo sanitize($assignment['class_name']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                        <span><?php echo sanitize($assignment['teacher_name']); ?></span>
                                    </div>
                                    <?php if ($assignment['classroom_name']): ?>
                                        <div class="detail-item">
                                            <i class="fas fa-door-open"></i>
                                            <span><?php echo sanitize($assignment['classroom_name']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="assignment-progress">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                    <span class="progress-text"><?php echo round($progress); ?>% 完成</span>
                                </div>
                                
                                <div class="assignment-actions">
                                    <button onclick="showScheduleModal(<?php echo $assignment['id']; ?>)" 
                                            class="btn btn-sm btn-primary" title="安排时间">
                                        <i class="fas fa-clock"></i>
                                    </button>
                                    <button onclick="viewAssignmentSchedule(<?php echo $assignment['id']; ?>)" 
                                            class="btn btn-sm btn-info" title="查看时间表">
                                        <i class="fas fa-calendar"></i>
                                    </button>
                                    <button onclick="editAssignment(<?php echo $assignment['id']; ?>)" 
                                            class="btn btn-sm btn-outline" title="编辑">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- 添加课程安排模态框 -->
    <div id="assignmentModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3>添加课程安排</h3>
                <button onclick="closeAssignmentModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="add_assignment">
                
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="courseSelect">课程 <span class="required">*</span></label>
                            <select id="courseSelect" name="course_id" class="form-control" required>
                                <option value="">请选择课程</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo $course['id']; ?>" data-hours="<?php echo $course['weekly_hours']; ?>" data-type="<?php echo $course['type']; ?>">
                                        <?php echo sanitize($course['name']); ?> (<?php echo $course['weekly_hours']; ?>课时/周)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="classSelect">班级 <span class="required">*</span></label>
                            <select id="classSelect" name="class_id" class="form-control" required>
                                <option value="">请选择班级</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>">
                                        <?php echo $class['grade_year']; ?>级 <?php echo sanitize($class['major_name']); ?> <?php echo sanitize($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="teacherSelect">授课教师 <span class="required">*</span></label>
                            <select id="teacherSelect" name="teacher_id" class="form-control" required>
                                <option value="">请选择教师</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>">
                                        <?php echo sanitize($teacher['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="classroomSelect">教室</label>
                            <select id="classroomSelect" name="classroom_id" class="form-control">
                                <option value="">请选择教室</option>
                                <?php foreach ($classrooms as $classroom): ?>
                                    <option value="<?php echo $classroom['id']; ?>" data-type="<?php echo $classroom['type']; ?>">
                                        <?php echo sanitize($classroom['name']); ?> 
                                        (<?php echo $classroom['type'] === 'computer_room' ? '机房' : '普通教室'; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="semesterSelect">学期 <span class="required">*</span></label>
                            <select id="semesterSelect" name="semester" class="form-control" required>
                                <?php
                                $currentYear = date('Y');
                                for ($year = $currentYear; $year >= $currentYear - 2; $year--):
                                    $semester1 = ($year - 1) . '-' . $year . '-2';
                                    $semester2 = $year . '-' . ($year + 1) . '-1';
                                ?>
                                    <option value="<?php echo $semester2; ?>" <?php echo $semester === $semester2 ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>-<?php echo $year + 1; ?>学年 秋季学期
                                    </option>
                                    <option value="<?php echo $semester1; ?>">
                                        <?php echo $year - 1; ?>-<?php echo $year; ?>学年 春季学期
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="remarksInput">备注</label>
                            <input type="text" id="remarksInput" name="remarks" class="form-control" 
                                   placeholder="课程安排备注...">
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeAssignmentModal()" class="btn btn-outline">取消</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> 保存安排
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 自动排课模态框 -->
    <div id="autoScheduleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>自动排课</h3>
                <button onclick="closeAutoScheduleModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="auto_schedule">
                
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i>
                        <strong>注意：</strong>自动排课将根据系统算法为选定班级的所有课程安排时间。请确保已添加完整的课程安排。
                    </div>
                    
                    <div class="form-group">
                        <label for="autoClassSelect">选择班级 <span class="required">*</span></label>
                        <select id="autoClassSelect" name="class_id" class="form-control" required>
                            <option value="">请选择要自动排课的班级</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo $class['grade_year']; ?>级 <?php echo sanitize($class['major_name']); ?> <?php echo sanitize($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="autoSemesterSelect">学期 <span class="required">*</span></label>
                        <select id="autoSemesterSelect" name="semester" class="form-control" required>
                            <?php
                            $currentYear = date('Y');
                            for ($year = $currentYear; $year >= $currentYear - 2; $year--):
                                $semester1 = ($year - 1) . '-' . $year . '-2';
                                $semester2 = $year . '-' . ($year + 1) . '-1';
                            ?>
                                <option value="<?php echo $semester2; ?>" <?php echo $semester === $semester2 ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>-<?php echo $year + 1; ?>学年 秋季学期
                                </option>
                                <option value="<?php echo $semester1; ?>">
                                    <?php echo $year - 1; ?>-<?php echo $year; ?>学年 春季学期
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeAutoScheduleModal()" class="btn btn-outline">取消</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-magic"></i> 开始自动排课
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // 显示添加课程安排模态框
        function showAddAssignmentModal() {
            document.getElementById('assignmentModal').style.display = 'block';
        }
        
        // 关闭课程安排模态框
        function closeAssignmentModal() {
            document.getElementById('assignmentModal').style.display = 'none';
        }
        
        // 显示自动排课模态框
        function showAutoScheduleModal() {
            document.getElementById('autoScheduleModal').style.display = 'block';
        }
        
        // 关闭自动排课模态框
        function closeAutoScheduleModal() {
            document.getElementById('autoScheduleModal').style.display = 'none';
        }
        
        // 显示时间安排模态框
        function showScheduleModal(assignmentId) {
            // 这里可以实现时间安排的详细界面
            alert('时间安排功能开发中...');
        }
        
        // 查看课程安排的时间表
        function viewAssignmentSchedule(assignmentId) {
            window.open(`schedule_view.php?assignment=${assignmentId}`, '_blank');
        }
        
        // 编辑课程安排
        function editAssignment(assignmentId) {
            alert('编辑功能开发中...');
        }
        
        // 点击外部关闭模态框
        window.addEventListener('click', function(e) {
            const assignmentModal = document.getElementById('assignmentModal');
            const autoScheduleModal = document.getElementById('autoScheduleModal');
            
            if (e.target === assignmentModal) {
                closeAssignmentModal();
            }
            if (e.target === autoScheduleModal) {
                closeAutoScheduleModal();
            }
        });
        
        // 课程选择变化时的联动
        document.getElementById('courseSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const courseType = selectedOption.dataset.type;
            
            // 根据课程类型筛选合适的教室
            const classroomSelect = document.getElementById('classroomSelect');
            const classroomOptions = classroomSelect.querySelectorAll('option');
            
            classroomOptions.forEach(option => {
                if (option.value === '') return; // 跳过"请选择"选项
                
                const roomType = option.dataset.type;
                // 如果是专业课，推荐机房；如果是公共课，可以使用任何教室
                if (courseType === 'professional' && roomType === 'computer_room') {
                    option.style.background = '#e0f2fe'; // 高亮推荐选项
                } else {
                    option.style.background = '';
                }
            });
        });
    </script>
    
    <style>
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .action-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            cursor: pointer;
            text-align: center;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .action-card.primary { border-left-color: #4f46e5; }
        .action-card.success { border-left-color: #10b981; }
        .action-card.info { border-left-color: #3b82f6; }
        .action-card.warning { border-left-color: #f59e0b; }
        
        .action-card i {
            font-size: 32px;
            margin-bottom: 15px;
            color: #4f46e5;
        }
        
        .action-card h3 {
            color: #1e293b;
            margin-bottom: 8px;
            font-size: 18px;
        }
        
        .action-card p {
            color: #64748b;
            font-size: 14px;
        }
        
        .assignments-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            padding: 25px;
        }
        
        .assignments-section h3 {
            color: #1e293b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .assignment-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .assignment-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .course-info h4 {
            color: #1e293b;
            margin-bottom: 5px;
            font-size: 16px;
        }
        
        .course-type {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .course-type.professional {
            background: #ddd6fe;
            color: #5b21b6;
        }
        
        .course-type.public {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.complete {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-badge.partial {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-badge.pending {
            background: #fef2f2;
            color: #dc2626;
        }
        
        .assignment-details {
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 14px;
            color: #64748b;
        }
        
        .detail-item i {
            width: 16px;
            color: #94a3b8;
        }
        
        .assignment-progress {
            margin-bottom: 15px;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #10b981, #047857);
            transition: width 0.3s ease;
        }
        
        .progress-text {
            font-size: 12px;
            color: #64748b;
        }
        
        .assignment-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        
        .modal-content.large {
            max-width: 800px;
        }
        
        .required {
            color: #ef4444;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .assignments-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</body>
</html>