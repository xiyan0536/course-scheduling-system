<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance()->getConnection();

// 获取统计数据
try {
    // 教师数量
    $stmt = $db->query("SELECT COUNT(*) as count FROM teachers");
    $teacherCount = $stmt->fetch()['count'];
    
    // 课程数量
    $stmt = $db->query("SELECT COUNT(*) as count FROM courses");
    $courseCount = $stmt->fetch()['count'];
    
    // 班级数量
    $stmt = $db->query("SELECT COUNT(*) as count FROM classes");
    $classCount = $stmt->fetch()['count'];
    
    // 教室数量
    $stmt = $db->query("SELECT COUNT(*) as count FROM classrooms");
    $classroomCount = $stmt->fetch()['count'];
    
    // 本周课程安排数量
    $currentSemester = getCurrentSemester();
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM schedule s 
                         JOIN course_class_assignments cca ON s.assignment_id = cca.id 
                         WHERE cca.semester = ?");
    $stmt->execute([$currentSemester]);
    $scheduleCount = $stmt->fetch()['count'];
    
    // 最近的课程安排
    $stmt = $db->prepare("SELECT 
                            c.name as course_name,
                            cl.class_name,
                            m.name as major_name,
                            t.name as teacher_name,
                            cr.name as classroom_name,
                            s.day_of_week,
                            s.time_slot,
                            ts.start_time,
                            ts.end_time,
                            cca.created_at
                         FROM schedule s
                         JOIN course_class_assignments cca ON s.assignment_id = cca.id
                         JOIN courses c ON cca.course_id = c.id
                         JOIN classes cl ON cca.class_id = cl.id
                         JOIN majors m ON cl.major_id = m.id
                         JOIN teachers t ON cca.teacher_id = t.id
                         LEFT JOIN classrooms cr ON cca.classroom_id = cr.id
                         JOIN time_slots ts ON s.time_slot = ts.slot_number
                         WHERE cca.semester = ?
                         ORDER BY cca.created_at DESC
                         LIMIT 10");
    $stmt->execute([$currentSemester]);
    $recentSchedules = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    $teacherCount = $courseCount = $classCount = $classroomCount = $scheduleCount = 0;
    $recentSchedules = [];
}

$flashMessage = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>主页 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-tachometer-alt"></i> 系统概览</h1>
                <p>欢迎使用教学排课系统，<?php echo $_SESSION['admin_username']; ?>！</p>
            </div>
            
            <?php if ($flashMessage): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $flashMessage; ?>
                </div>
            <?php endif; ?>
            
            <!-- 统计卡片 -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon teacher">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $teacherCount; ?></h3>
                        <p>教师总数</p>
                    </div>
                    <a href="teachers.php" class="stat-link">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon course">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $courseCount; ?></h3>
                        <p>课程总数</p>
                    </div>
                    <a href="courses.php" class="stat-link">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon class">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $classCount; ?></h3>
                        <p>班级总数</p>
                    </div>
                    <a href="classes.php" class="stat-link">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon classroom">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $classroomCount; ?></h3>
                        <p>教室总数</p>
                    </div>
                    <a href="classrooms.php" class="stat-link">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="stat-card wide">
                    <div class="stat-icon schedule">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $scheduleCount; ?></h3>
                        <p>本学期课程安排数</p>
                    </div>
                    <a href="schedule.php" class="stat-link">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            
            <!-- 快速操作 -->
            <div class="quick-actions">
                <h2><i class="fas fa-bolt"></i> 快速操作</h2>
                <div class="action-grid">
                    <a href="schedule.php" class="action-card primary">
                        <i class="fas fa-calendar-plus"></i>
                        <h3>课程排课</h3>
                        <p>进行自动或手动排课</p>
                    </a>
                    
                    <a href="courses.php?action=add" class="action-card success">
                        <i class="fas fa-plus-circle"></i>
                        <h3>添加课程</h3>
                        <p>新增课程信息</p>
                    </a>
                    
                    <a href="teachers.php?action=add" class="action-card info">
                        <i class="fas fa-user-plus"></i>
                        <h3>添加教师</h3>
                        <p>新增教师资料</p>
                    </a>
                    
                    <a href="schedule_view.php" class="action-card warning">
                        <i class="fas fa-table"></i>
                        <h3>查看课表</h3>
                        <p>浏览完整课程表</p>
                    </a>
                </div>
            </div>
            
            <!-- 最近操作 -->
            <div class="recent-activities">
                <h2><i class="fas fa-clock"></i> 最近的课程安排</h2>
                <div class="activity-list">
                    <?php if (empty($recentSchedules)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <p>暂无课程安排记录</p>
                            <a href="schedule.php" class="btn btn-primary">开始排课</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentSchedules as $schedule): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="activity-content">
                                    <h4><?php echo sanitize($schedule['course_name']); ?></h4>
                                    <p>
                                        <span class="class-info">
                                            <?php echo sanitize($schedule['major_name'] . ' ' . $schedule['class_name']); ?>
                                        </span>
                                        <span class="teacher-info">
                                            <i class="fas fa-user"></i> <?php echo sanitize($schedule['teacher_name']); ?>
                                        </span>
                                        <?php if ($schedule['classroom_name']): ?>
                                            <span class="classroom-info">
                                                <i class="fas fa-door-open"></i> <?php echo sanitize($schedule['classroom_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                    <div class="schedule-time">
                                        <i class="fas fa-clock"></i>
                                        <?php echo getDayName($schedule['day_of_week']); ?>
                                        <?php echo date('H:i', strtotime($schedule['start_time'])); ?>-<?php echo date('H:i', strtotime($schedule['end_time'])); ?>
                                    </div>
                                </div>
                                <div class="activity-time">
                                    <small><?php echo date('m-d H:i', strtotime($schedule['created_at'])); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="view-more">
                            <a href="schedule_view.php" class="btn btn-outline">查看完整课表</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="scripts.js"></script>
    <script>
        // 实时时间显示
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleString('zh-CN', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }
        
        // 每秒更新时间
        setInterval(updateTime, 1000);
        updateTime(); // 立即显示时间
        
        // 统计卡片动画
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>