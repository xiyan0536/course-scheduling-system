<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// 处理AI辅助排课请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = '无效的请求';
    } else {
        switch ($action) {
            case 'ai_optimize':
                $classId = intval($_POST['class_id'] ?? 0);
                $semester = sanitize($_POST['semester'] ?? '');
                $requirements = sanitize($_POST['requirements'] ?? '');
                
                if ($classId <= 0 || empty($semester)) {
                    $error = '请选择班级和学期';
                } else {
                    try {
                        $result = aiOptimizeSchedule($db, $classId, $semester, $requirements);
                        if ($result['success']) {
                            $message = $result['message'];
                        } else {
                            $error = $result['message'];
                        }
                    } catch (Exception $e) {
                        $error = 'AI优化失败：' . $e->getMessage();
                        error_log("AI optimize error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'ai_suggest':
                $conflictData = $_POST['conflict_data'] ?? '';
                
                if (empty($conflictData)) {
                    $error = '请提供冲突信息';
                } else {
                    try {
                        $suggestion = aiSuggestSolution($conflictData);
                        if ($suggestion['success']) {
                            $message = $suggestion['message'];
                        } else {
                            $error = $suggestion['message'];
                        }
                    } catch (Exception $e) {
                        $error = 'AI建议获取失败：' . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// 获取基础数据
try {
    $classes = $db->query("SELECT c.*, m.name as major_name FROM classes c JOIN majors m ON c.major_id = m.id ORDER BY c.grade_year DESC, m.name, c.class_name")->fetchAll();
} catch (PDOException $e) {
    error_log("Get classes error: " . $e->getMessage());
    $classes = [];
}

// AI辅助排课优化函数
function aiOptimizeSchedule($db, $classId, $semester, $requirements) {
    // 获取班级和课程信息
    $stmt = $db->prepare("
        SELECT cca.*, c.name as course_name, c.weekly_hours, c.type as course_type,
               cl.class_name, m.name as major_name, cl.grade_year,
               t.name as teacher_name,
               cr.name as classroom_name
        FROM course_class_assignments cca
        JOIN courses c ON cca.course_id = c.id
        JOIN classes cl ON cca.class_id = cl.id
        JOIN majors m ON cl.major_id = m.id
        JOIN teachers t ON cca.teacher_id = t.id
        LEFT JOIN classrooms cr ON cca.classroom_id = cr.id
        WHERE cca.class_id = ? AND cca.semester = ?
    ");
    $stmt->execute([$classId, $semester]);
    $assignments = $stmt->fetchAll();
    
    if (empty($assignments)) {
        return ['success' => false, 'message' => '该班级没有课程安排需要优化'];
    }
    
    // 获取当前课程表
    $stmt = $db->prepare("
        SELECT s.*, c.name as course_name, t.name as teacher_name, cr.name as classroom_name
        FROM schedule s
        JOIN course_class_assignments cca ON s.assignment_id = cca.id
        JOIN courses c ON cca.course_id = c.id
        JOIN teachers t ON cca.teacher_id = t.id
        LEFT JOIN classrooms cr ON cca.classroom_id = cr.id
        WHERE cca.class_id = ? AND cca.semester = ?
        ORDER BY s.day_of_week, s.time_slot
    ");
    $stmt->execute([$classId, $semester]);
    $currentSchedule = $stmt->fetchAll();
    
    // 构建AI请求数据
    $classInfo = $assignments[0];
    $requestData = [
        'class_info' => [
            'name' => $classInfo['grade_year'] . '级 ' . $classInfo['major_name'] . ' ' . $classInfo['class_name'],
            'semester' => $semester
        ],
        'courses' => array_map(function($assignment) {
            return [
                'name' => $assignment['course_name'],
                'type' => $assignment['course_type'],
                'weekly_hours' => $assignment['weekly_hours'],
                'teacher' => $assignment['teacher_name'],
                'classroom' => $assignment['classroom_name']
            ];
        }, $assignments),
        'current_schedule' => array_map(function($schedule) {
            return [
                'course' => $schedule['course_name'],
                'day' => $schedule['day_of_week'],
                'time_slot' => $schedule['time_slot'],
                'teacher' => $schedule['teacher_name'],
                'classroom' => $schedule['classroom_name'],
                'weeks' => $schedule['week_start'] . '-' . $schedule['week_end']
            ];
        }, $currentSchedule),
        'requirements' => $requirements ?: '请优化课程安排，确保专业课连续性，教师课程均匀分布，避免时间冲突'
    ];
    
    // 调用DeepSeek API
    $aiResponse = callDeepSeekAPI($requestData);
    
    if ($aiResponse['success']) {
        // 记录AI建议
        logActivity('ai_optimize', "Class ID: {$classId}, Semester: {$semester}");
        return [
            'success' => true,
            'message' => 'AI优化建议：' . $aiResponse['suggestion']
        ];
    } else {
        return [
            'success' => false,
            'message' => 'AI服务暂时不可用：' . $aiResponse['error']
        ];
    }
}

// AI建议解决方案函数
function aiSuggestSolution($conflictData) {
    $requestData = [
        'conflict_type' => 'schedule_conflict',
        'conflict_data' => $conflictData,
        'request' => '请分析以下排课冲突并提供解决建议'
    ];
    
    $aiResponse = callDeepSeekAPI($requestData);
    
    if ($aiResponse['success']) {
        return [
            'success' => true,
            'message' => 'AI建议：' . $aiResponse['suggestion']
        ];
    } else {
        return [
            'success' => false,
            'message' => 'AI建议获取失败：' . $aiResponse['error']
        ];
    }
}

// 调用DeepSeek API
function callDeepSeekAPI($requestData) {
    if (empty(DEEPSEEK_API_KEY) || DEEPSEEK_API_KEY === 'YOUR_DEEPSEEK_API_KEY') {
        return [
            'success' => false,
            'error' => 'API密钥未配置，请在config.php中设置DEEPSEEK_API_KEY'
        ];
    }
    
    $prompt = "你是一个专业的教学排课系统助手。请根据以下信息分析并提供排课优化建议：\n\n";
    $prompt .= "班级信息：" . json_encode($requestData, JSON_UNESCAPED_UNICODE) . "\n\n";
    $prompt .= "请提供具体的排课优化建议，包括：\n";
    $prompt .= "1. 时间安排优化\n";
    $prompt .= "2. 教师课程分布建议\n";
    $prompt .= "3. 专业课连续性安排\n";
    $prompt .= "4. 可能的冲突解决方案\n\n";
    $prompt .= "请用中文回答，语言简洁明了。";
    
    $postData = [
        'model' => DEEPSEEK_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是一个专业的教学排课系统助手，擅长分析课程安排并提供优化建议。'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'max_tokens' => 1000,
        'temperature' => 0.7
    ];
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . DEEPSEEK_API_KEY
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, DEEPSEEK_API_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'error' => 'API请求失败：' . $error
        ];
    }
    
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => 'API返回错误：HTTP ' . $httpCode
        ];
    }
    
    $responseData = json_decode($response, true);
    
    if (!$responseData || !isset($responseData['choices'][0]['message']['content'])) {
        return [
            'success' => false,
            'error' => 'API响应格式错误'
        ];
    }
    
    return [
        'success' => true,
        'suggestion' => $responseData['choices'][0]['message']['content']
    ];
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI辅助排课 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-robot"></i> AI辅助排课</h1>
                <p>使用人工智能优化课程安排</p>
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
            
            <!-- AI功能介绍 -->
            <div class="ai-intro">
                <div class="intro-card">
                    <div class="intro-icon">
                        <i class="fas fa-brain"></i>
                    </div>
                    <div class="intro-content">
                        <h3>智能排课优化</h3>
                        <p>基于DeepSeek AI模型，分析课程特点、教师需求和时间约束，提供专业的排课优化建议。</p>
                        <ul>
                            <li><i class="fas fa-check"></i> 专业课连续性优化</li>
                            <li><i class="fas fa-check"></i> 教师课程均匀分布</li>
                            <li><i class="fas fa-check"></i> 冲突检测与解决</li>
                            <li><i class="fas fa-check"></i> 个性化需求适配</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- AI功能模块 -->
            <div class="ai-modules">
                <!-- 智能优化模块 -->
                <div class="ai-module">
                    <div class="module-header">
                        <i class="fas fa-magic"></i>
                        <h3>智能排课优化</h3>
                    </div>
                    
                    <div class="module-content">
                        <p>为指定班级提供AI驱动的排课优化建议</p>
                        
                        <form method="POST" class="ai-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="ai_optimize">
                            
                            <div class="form-group">
                                <label for="aiClassSelect">选择班级 <span class="required">*</span></label>
                                <select id="aiClassSelect" name="class_id" class="form-control" required>
                                    <option value="">请选择要优化的班级</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php echo $class['grade_year']; ?>级 <?php echo sanitize($class['major_name']); ?> <?php echo sanitize($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="aiSemesterSelect">学期 <span class="required">*</span></label>
                                <select id="aiSemesterSelect" name="semester" class="form-control" required>
                                    <?php
                                    $currentYear = date('Y');
                                    for ($year = $currentYear; $year >= $currentYear - 2; $year--):
                                        $semester1 = ($year - 1) . '-' . $year . '-2';
                                        $semester2 = $year . '-' . ($year + 1) . '-1';
                                    ?>
                                        <option value="<?php echo $semester2; ?>">
                                            <?php echo $year; ?>-<?php echo $year + 1; ?>学年 秋季学期
                                        </option>
                                        <option value="<?php echo $semester1; ?>">
                                            <?php echo $year - 1; ?>-<?php echo $year; ?>学年 春季学期
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="requirements">特殊要求（可选）</label>
                                <textarea id="requirements" name="requirements" class="form-control" rows="4" 
                                          placeholder="请描述任何特殊的排课要求，例如：&#10;- 某些课程需要连续安排&#10;- 特定时间段的偏好&#10;- 教师的时间限制&#10;- 教室使用要求等"></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-ai" id="optimizeBtn">
                                <i class="fas fa-robot"></i>
                                <span>开始AI优化</span>
                                <div class="loading-spinner" style="display: none;">
                                    <i class="fas fa-spinner fa-spin"></i>
                                </div>
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- 冲突解决模块 -->
                <div class="ai-module">
                    <div class="module-header">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>冲突解决建议</h3>
                    </div>
                    
                    <div class="module-content">
                        <p>提供冲突信息，获取AI解决建议</p>
                        
                        <form method="POST" class="ai-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="ai_suggest">
                            
                            <div class="form-group">
                                <label for="conflictData">冲突描述 <span class="required">*</span></label>
                                <textarea id="conflictData" name="conflict_data" class="form-control" rows="6" 
                                          placeholder="请详细描述遇到的排课冲突，例如：&#10;- 教师时间冲突：张老师周一第1节课已有安排&#10;- 教室冲突：6A09机房周二第3节课被占用&#10;- 班级冲突：2023级信息安全2班周三下午已有其他课程"
                                          required></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-warning btn-ai" id="suggestBtn">
                                <i class="fas fa-lightbulb"></i>
                                <span>获取AI建议</span>
                                <div class="loading-spinner" style="display: none;">
                                    <i class="fas fa-spinner fa-spin"></i>
                                </div>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- AI使用提示 -->
            <div class="ai-tips">
                <h3><i class="fas fa-info-circle"></i> 使用提示</h3>
                <div class="tips-grid">
                    <div class="tip-card">
                        <i class="fas fa-clock"></i>
                        <h4>最佳使用时机</h4>
                        <p>在完成基本的课程安排后，使用AI优化功能来改进时间分布和解决冲突。</p>
                    </div>
                    
                    <div class="tip-card">
                        <i class="fas fa-edit"></i>
                        <h4>详细描述需求</h4>
                        <p>提供越详细的要求和约束条件，AI给出的建议就越准确和实用。</p>
                    </div>
                    
                    <div class="tip-card">
                        <i class="fas fa-sync"></i>
                        <h4>迭代优化</h4>
                        <p>根据AI建议调整后，可以再次使用优化功能来进一步完善排课方案。</p>
                    </div>
                    
                    <div class="tip-card">
                        <i class="fas fa-shield-alt"></i>
                        <h4>数据隐私</h4>
                        <p>AI分析过程中不会存储您的具体课程数据，确保信息安全。</p>
                    </div>
                </div>
            </div>
            
            <!-- API状态检查 -->
            <div class="api-status">
                <h4><i class="fas fa-server"></i> API状态</h4>
                <div class="status-info">
                    <?php if (empty(DEEPSEEK_API_KEY) || DEEPSEEK_API_KEY === 'YOUR_DEEPSEEK_API_KEY'): ?>
                        <span class="status-badge error">
                            <i class="fas fa-times"></i> API未配置
                        </span>
                        <p>请在config.php中配置DEEPSEEK_API_KEY以启用AI功能</p>
                    <?php else: ?>
                        <span class="status-badge success">
                            <i class="fas fa-check"></i> API已配置
                        </span>
                        <p>AI功能已就绪，可以使用智能排课优化</p>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="scripts.js"></script>
    <script>
        // 表单提交时显示加载状态
        document.querySelectorAll('.ai-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                const spinner = submitBtn.querySelector('.loading-spinner');
                const btnText = submitBtn.querySelector('span');
                
                btnText.style.opacity = '0';
                spinner.style.display = 'inline-block';
                submitBtn.disabled = true;
                
                // 防止重复提交
                setTimeout(() => {
                    if (spinner.style.display !== 'none') {
                        btnText.style.opacity = '1';
                        spinner.style.display = 'none';
                        submitBtn.disabled = false;
                    }
                }, 30000); // 30秒超时
            });
        });
        
        // 班级选择变化时获取课程信息预览
        document.getElementById('aiClassSelect').addEventListener('change', function() {
            const classId = this.value;
            if (classId) {
                // 这里可以添加获取班级课程信息的AJAX请求
                console.log('Selected class:', classId);
            }
        });
        
        // 自动调整文本域高度
        document.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });
        
        // 提示文本动画
        function animateTips() {
            const tipCards = document.querySelectorAll('.tip-card');
            tipCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 200);
            });
        }
        
        // 页面加载完成后执行动画
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(animateTips, 500);
        });
    </script>
    
    <style>
        .ai-intro {
            margin-bottom: 30px;
        }
        
        .intro-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .intro-icon {
            font-size: 48px;
            opacity: 0.9;
        }
        
        .intro-content h3 {
            margin-bottom: 10px;
            font-size: 24px;
        }
        
        .intro-content p {
            margin-bottom: 15px;
            opacity: 0.9;
            line-height: 1.6;
        }
        
        .intro-content ul {
            list-style: none;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        
        .intro-content li {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .intro-content li i {
            color: #4ade80;
        }
        
        .ai-modules {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .ai-module {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .module-header {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .module-header h3 {
            margin: 0;
            font-size: 18px;
        }
        
        .module-content {
            padding: 25px;
        }
        
        .module-content p {
            color: #64748b;
            margin-bottom: 20px;
        }
        
        .ai-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .btn-ai {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 15px 25px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .btn-ai:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .btn-ai:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .loading-spinner {
            position: absolute;
        }
        
        .ai-tips {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .ai-tips h3 {
            color: #1e293b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .tips-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .tip-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #4f46e5;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.5s ease;
        }
        
        .tip-card i {
            font-size: 24px;
            color: #4f46e5;
            margin-bottom: 10px;
        }
        
        .tip-card h4 {
            color: #1e293b;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .tip-card p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
            margin: 0;
        }
        
        .api-status {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            padding: 25px;
        }
        
        .api-status h4 {
            color: #1e293b;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .status-badge {
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .status-badge.success {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-badge.error {
            background: #fef2f2;
            color: #dc2626;
        }
        
        .status-info p {
            color: #64748b;
            margin: 0;
            flex: 1;
        }
        
        .required {
            color: #ef4444;
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .ai-modules {
                grid-template-columns: 1fr;
            }
            
            .intro-card {
                flex-direction: column;
                text-align: center;
            }
            
            .intro-content ul {
                grid-template-columns: 1fr;
            }
            
            .tips-grid {
                grid-template-columns: 1fr;
            }
            
            .status-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .ai-modules {
                gap: 20px;
            }
            
            .module-content {
                padding: 20px;
            }
            
            .ai-tips {
                padding: 20px;
            }
        }
    </style>
</body>
</html>