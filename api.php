<?php
/**
 * 教学排课系统 - API接口文件
 * 处理AJAX请求和异步操作
 */

require_once 'config.php';

// 设置内容类型为JSON
header('Content-Type: application/json; charset=utf-8');

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '只允许POST请求']);
    exit;
}

// 检查登录状态
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';
$csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';

// CSRF验证
if (!validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无效的请求']);
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    switch ($action) {
        case 'get_class_courses':
            echo json_encode(getClassCourses($db, $input));
            break;
            
        case 'get_teacher_schedule':
            echo json_encode(getTeacherSchedule($db, $input));
            break;
            
        case 'get_classroom_availability':
            echo json_encode(getClassroomAvailability($db, $input));
            break;
            
        case 'check_schedule_conflict':
            echo json_encode(checkScheduleConflictApi($db, $input));
            break;
            
        case 'get_course_suggestions':
            echo json_encode(getCourseSuggestions($db, $input));
            break;
            
        case 'bulk_import_data':
            echo json_encode(bulkImportData($db, $input));
            break;
            
        case 'export_schedule':
            echo json_encode(exportSchedule($db, $input));
            break;
            
        case 'get_statistics':
            echo json_encode(getStatistics($db, $input));
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '无效的操作']);
            break;
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器内部错误']);
}

/**
 * 获取班级课程信息
 */
function getClassCourses($db, $data) {
    $classId = intval($data['class_id'] ?? 0);
    $semester = sanitize($data['semester'] ?? getCurrentSemester());
    
    if ($classId <= 0) {
        return ['success' => false, 'message' => '无效的班级ID'];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT cca.*, 
                   c.name as course_name, c.type as course_type, c.weekly_hours,
                   t.name as teacher_name,
                   cr.name as classroom_name,
                   COUNT(s.id) as scheduled_hours
            FROM course_class_assignments cca
            JOIN courses c ON cca.course_id = c.id
            JOIN teachers t ON cca.teacher_id = t.id
            LEFT JOIN classrooms cr ON cca.classroom_id = cr.id
            LEFT JOIN schedule s ON cca.id = s.assignment_id
            WHERE cca.class_id = ? AND cca.semester = ?
            GROUP BY cca.id
            ORDER BY c.name
        ");
        $stmt->execute([$classId, $semester]);
        $courses = $stmt->fetchAll();
        
        return [
            'success' => true,
            'data' => $courses
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => '获取课程信息失败'];
    }
}

/**
 * 获取教师课程表
 */
function getTeacherSchedule($db, $data) {
    $teacherId = intval($data['teacher_id'] ?? 0);
    $semester = sanitize($data['semester'] ?? getCurrentSemester());
    $weekStart = intval($data['week_start'] ?? 1);
    $weekEnd = intval($data['week_end'] ?? 20);
    
    if ($teacherId <= 0) {
        return ['success' => false, 'message' => '无效的教师ID'];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT s.*, 
                   c.name as course_name,
                   cl.class_name, cl.grade_year,
                   m.name as major_name,
                   cr.name as classroom_name,
                   ts.start_time, ts.end_time
            FROM schedule s
            JOIN course_class_assignments cca ON s.assignment_id = cca.id
            JOIN courses c ON cca.course_id = c.id
            JOIN classes cl ON cca.class_id = cl.id
            JOIN majors m ON cl.major_id = m.id
            LEFT JOIN classrooms cr ON cca.classroom_id = cr.id
            JOIN time_slots ts ON s.time_slot = ts.slot_number
            WHERE cca.teacher_id = ? AND cca.semester = ?
            AND s.week_start <= ? AND s.week_end >= ?
            ORDER BY s.day_of_week, s.time_slot
        ");
        $stmt->execute([$teacherId, $semester, $weekEnd, $weekStart]);
        $schedule = $stmt->fetchAll();
        
        return [
            'success' => true,
            'data' => $schedule
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => '获取教师课程表失败'];
    }
}

/**
 * 获取教室可用性
 */
function getClassroomAvailability($db, $data) {
    $classroomId = intval($data['classroom_id'] ?? 0);
    $semester = sanitize($data['semester'] ?? getCurrentSemester());
    $dayOfWeek = intval($data['day_of_week'] ?? 0);
    $weekStart = intval($data['week_start'] ?? 1);
    $weekEnd = intval($data['week_end'] ?? 20);
    
    if ($classroomId <= 0) {
        return ['success' => false, 'message' => '无效的教室ID'];
    }
    
    try {
        // 获取教室基本信息
        $stmt = $db->prepare("SELECT * FROM classrooms WHERE id = ?");
        $stmt->execute([$classroomId]);
        $classroom = $stmt->fetch();
        
        if (!$classroom) {
            return ['success' => false, 'message' => '教室不存在'];
        }
        
        // 获取占用情况
        $where = "WHERE cca.classroom_id = ? AND cca.semester = ?";
        $params = [$classroomId, $semester];
        
        if ($dayOfWeek > 0) {
            $where .= " AND s.day_of_week = ?";
            $params[] = $dayOfWeek;
        }
        
        if ($weekStart > 0 && $weekEnd > 0) {
            $where .= " AND s.week_start <= ? AND s.week_end >= ?";
            $params[] = $weekEnd;
            $params[] = $weekStart;
        }
        
        $stmt = $db->prepare("
            SELECT s.*, 
                   c.name as course_name,
                   cl.class_name, cl.grade_year,
                   m.name as major_name,
                   t.name as teacher_name,
                   ts.start_time, ts.end_time
            FROM schedule s
            JOIN course_class_assignments cca ON s.assignment_id = cca.id
            JOIN courses c ON cca.course_id = c.id
            JOIN classes cl ON cca.class_id = cl.id
            JOIN majors m ON cl.major_id = m.id
            JOIN teachers t ON cca.teacher_id = t.id
            JOIN time_slots ts ON s.time_slot = ts.slot_number
            {$where}
            ORDER BY s.day_of_week, s.time_slot
        ");
        $stmt->execute($params);
        $occupiedSlots = $stmt->fetchAll();
        
        return [
            'success' => true,
            'data' => [
                'classroom' => $classroom,
                'occupied_slots' => $occupiedSlots
            ]
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => '获取教室可用性失败'];
    }
}

/**
 * 检查排课冲突
 */
function checkScheduleConflictApi($db, $data) {
    $assignmentId = intval($data['assignment_id'] ?? 0);
    $dayOfWeek = intval($data['day_of_week'] ?? 0);
    $timeSlot = intval($data['time_slot'] ?? 0);
    $weekStart = intval($data['week_start'] ?? 1);
    $weekEnd = intval($data['week_end'] ?? 20);
    
    if ($assignmentId <= 0 || $dayOfWeek <= 0 || $timeSlot <= 0) {
        return ['success' => false, 'message' => '参数不完整'];
    }
    
    try {
        // 获取课程安排信息
        $stmt = $db->prepare("
            SELECT cca.*, cl.id as class_id
            FROM course_class_assignments cca
            JOIN classes cl ON cca.class_id = cl.id
            WHERE cca.id = ?
        ");
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch();
        
        if (!$assignment) {
            return ['success' => false, 'message' => '课程安排不存在'];
        }
        
        $conflicts = [];
        
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
            $conflicts[] = [
                'type' => 'teacher',
                'message' => "教师冲突：{$conflict['course_name']} ({$conflict['major_name']} {$conflict['class_name']})"
            ];
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
                $conflicts[] = [
                    'type' => 'classroom',
                    'message' => "教室冲突：{$conflict['course_name']} ({$conflict['major_name']} {$conflict['class_name']})"
                ];
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
            $conflicts[] = [
                'type' => 'class',
                'message' => "班级冲突：{$conflict['course_name']} ({$conflict['teacher_name']})"
            ];
        }
        
        return [
            'success' => true,
            'data' => [
                'has_conflict' => !empty($conflicts),
                'conflicts' => $conflicts
            ]
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => '冲突检查失败'];
    }
}

/**
 * 获取课程建议
 */
function getCourseSuggestions($db, $data) {
    $query = sanitize($data['query'] ?? '');
    $type = sanitize($data['type'] ?? '');
    
    if (strlen($query) < 2) {
        return ['success' => true, 'data' => []];
    }
    
    try {
        $where = "WHERE name LIKE ?";
        $params = ["%{$query}%"];
        
        if (!empty($type)) {
            $where .= " AND type = ?";
            $params[] = $type;
        }
        
        $stmt = $db->prepare("
            SELECT id, name, course_code, type, weekly_hours
            FROM courses
            {$where}
            ORDER BY name
            LIMIT 10
        ");
        $stmt->execute($params);
        $courses = $stmt->fetchAll();
        
        return [
            'success' => true,
            'data' => $courses
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => '获取课程建议失败'];
    }
}

/**
 * 批量导入数据
 */
function bulkImportData($db, $data) {
    $dataType = sanitize($data['type'] ?? '');
    $csvData = $data['csv_data'] ?? [];
    
    if (empty($dataType) || empty($csvData)) {
        return ['success' => false, 'message' => '数据不完整'];
    }
    
    try {
        $db->beginTransaction();
        
        $imported = 0;
        $errors = [];
        
        foreach ($csvData as $index => $row) {
            try {
                switch ($dataType) {
                    case 'teachers':
                        $stmt = $db->prepare("INSERT INTO teachers (name, phone, email) VALUES (?, ?, ?)");
                        $stmt->execute([
                            sanitize($row['name'] ?? ''),
                            sanitize($row['phone'] ?? ''),
                            sanitize($row['email'] ?? '')
                        ]);
                        break;
                        
                    case 'courses':
                        $stmt = $db->prepare("INSERT INTO courses (name, course_code, type, weekly_hours, total_hours, description) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            sanitize($row['name'] ?? ''),
                            sanitize($row['course_code'] ?? ''),
                            sanitize($row['type'] ?? 'professional'),
                            intval($row['weekly_hours'] ?? 0),
                            intval($row['total_hours'] ?? 0),
                            sanitize($row['description'] ?? '')
                        ]);
                        break;
                        
                    case 'classrooms':
                        $stmt = $db->prepare("INSERT INTO classrooms (name, capacity, type, equipment) VALUES (?, ?, ?, ?)");
                        $stmt->execute([
                            sanitize($row['name'] ?? ''),
                            intval($row['capacity'] ?? 0),
                            sanitize($row['type'] ?? 'ordinary'),
                            sanitize($row['equipment'] ?? '')
                        ]);
                        break;
                        
                    default:
                        throw new Exception('不支持的数据类型');
                }
                
                $imported++;
            } catch (Exception $e) {
                $errors[] = "第 " . ($index + 1) . " 行：" . $e->getMessage();
            }
        }
        
        $db->commit();
        
        logActivity('bulk_import', "Type: {$dataType}, Imported: {$imported}");
        
        return [
            'success' => true,
            'data' => [
                'imported' => $imported,
                'errors' => $errors
            ]
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'message' => '批量导入失败：' . $e->getMessage()];
    }
}

/**
 * 导出课程表
 */
function exportSchedule($db, $data) {
    $format = sanitize($data['format'] ?? 'excel');
    $classId = intval($data['class_id'] ?? 0);
    $semester = sanitize($data['semester'] ?? getCurrentSemester());
    
    try {
        // 获取课程表数据
        $where = "WHERE cca.semester = ?";
        $params = [$semester];
        
        if ($classId > 0) {
            $where .= " AND cca.class_id = ?";
            $params[] = $classId;
        }
        
        $stmt = $db->prepare("
            SELECT s.*, 
                   c.name as course_name,
                   cl.class_name, cl.grade_year,
                   m.name as major_name,
                   t.name as teacher_name,
                   cr.name as classroom_name,
                   ts.start_time, ts.end_time
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
        $schedule = $stmt->fetchAll();
        
        // 根据格式生成导出数据
        switch ($format) {
            case 'excel':
                $exportData = generateExcelData($schedule);
                break;
            case 'csv':
                $exportData = generateCsvData($schedule);
                break;
            case 'pdf':
                $exportData = generatePdfData($schedule);
                break;
            default:
                return ['success' => false, 'message' => '不支持的导出格式'];
        }
        
        return [
            'success' => true,
            'data' => $exportData
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => '导出失败：' . $e->getMessage()];
    }
}

/**
 * 获取统计信息
 */
function getStatistics($db, $data) {
    $semester = sanitize($data['semester'] ?? getCurrentSemester());
    
    try {
        $stats = [];
        
        // 课程统计
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_courses,
                SUM(CASE WHEN type = 'professional' THEN 1 ELSE 0 END) as professional_courses,
                SUM(CASE WHEN type = 'public' THEN 1 ELSE 0 END) as public_courses
            FROM courses
        ");
        $stmt->execute();
        $stats['courses'] = $stmt->fetch();
        
        // 教师统计
        $stmt = $db->query("SELECT COUNT(*) as total_teachers FROM teachers");
        $stats['teachers'] = $stmt->fetch();
        
        // 班级统计
        $stmt = $db->query("SELECT COUNT(*) as total_classes FROM classes");
        $stats['classes'] = $stmt->fetch();
        
        // 教室统计
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_classrooms,
                SUM(CASE WHEN type = 'computer_room' THEN 1 ELSE 0 END) as computer_rooms,
                SUM(CASE WHEN type = 'ordinary' THEN 1 ELSE 0 END) as ordinary_rooms
            FROM classrooms
        ");
        $stmt->execute();
        $stats['classrooms'] = $stmt->fetch();
        
        // 课程安排统计
        $stmt = $db->prepare("
            SELECT COUNT(*) as total_assignments
            FROM course_class_assignments
            WHERE semester = ?
        ");
        $stmt->execute([$semester]);
        $stats['assignments'] = $stmt->fetch();
        
        // 课程表统计
        $stmt = $db->prepare("
            SELECT COUNT(*) as total_schedules
            FROM schedule s
            JOIN course_class_assignments cca ON s.assignment_id = cca.id
            WHERE cca.semester = ?
        ");
        $stmt->execute([$semester]);
        $stats['schedules'] = $stmt->fetch();
        
        return [
            'success' => true,
            'data' => $stats
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => '获取统计信息失败'];
    }
}

/**
 * 生成Excel数据
 */
function generateExcelData($schedule) {
    // 简化版本，实际应用中可以使用PhpSpreadsheet库
    $data = [];
    $data[] = ['日期', '时间', '课程', '班级', '教师', '教室'];
    
    foreach ($schedule as $item) {
        $data[] = [
            getDayName($item['day_of_week']),
            date('H:i', strtotime($item['start_time'])) . '-' . date('H:i', strtotime($item['end_time'])),
            $item['course_name'],
            $item['grade_year'] . '级' . $item['major_name'] . $item['class_name'],
            $item['teacher_name'],
            $item['classroom_name'] ?: '-'
        ];
    }
    
    return [
        'type' => 'excel',
        'filename' => '课程表_' . date('Y-m-d') . '.xlsx',
        'data' => $data
    ];
}

/**
 * 生成CSV数据
 */
function generateCsvData($schedule) {
    $csv = "日期,时间,课程,班级,教师,教室\n";
    
    foreach ($schedule as $item) {
        $csv .= sprintf(
            "%s,%s,%s,%s,%s,%s\n",
            getDayName($item['day_of_week']),
            date('H:i', strtotime($item['start_time'])) . '-' . date('H:i', strtotime($item['end_time'])),
            $item['course_name'],
            $item['grade_year'] . '级' . $item['major_name'] . $item['class_name'],
            $item['teacher_name'],
            $item['classroom_name'] ?: '-'
        );
    }
    
    return [
        'type' => 'csv',
        'filename' => '课程表_' . date('Y-m-d') . '.csv',
        'data' => $csv
    ];
}

/**
 * 生成PDF数据
 */
function generatePdfData($schedule) {
    // 实际应用中可以使用TCPDF或类似库生成PDF
    return [
        'type' => 'pdf',
        'filename' => '课程表_' . date('Y-m-d') . '.pdf',
        'message' => 'PDF导出功能需要额外的PDF库支持'
    ];
}
?>