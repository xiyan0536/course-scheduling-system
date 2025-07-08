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
            case 'add_major':
                $name = sanitize($_POST['name'] ?? '');
                if (empty($name)) {
                    $error = '专业名称不能为空';
                } else {
                    try {
                        $stmt = $db->prepare("INSERT INTO majors (name) VALUES (?)");
                        $stmt->execute([$name]);
                        $message = '专业添加成功！';
                        logActivity('add_major', "Major: {$name}");
                    } catch (PDOException $e) {
                        $error = '添加失败，请稍后重试';
                        error_log("Add major error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'add_class':
                $majorId = intval($_POST['major_id'] ?? 0);
                $className = sanitize($_POST['class_name'] ?? '');
                $gradeYear = intval($_POST['grade_year'] ?? 0);
                $studentCount = intval($_POST['student_count'] ?? 0);
                
                if (empty($className) || $majorId <= 0 || $gradeYear <= 0) {
                    $error = '请填写完整的班级信息';
                } else {
                    try {
                        $stmt = $db->prepare("INSERT INTO classes (major_id, class_name, grade_year, student_count) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$majorId, $className, $gradeYear, $studentCount]);
                        $message = '班级添加成功！';
                        logActivity('add_class', "Class: {$gradeYear}级{$className}");
                    } catch (PDOException $e) {
                        $error = '添加失败，请稍后重试';
                        error_log("Add class error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'edit_class':
                $id = intval($_POST['id'] ?? 0);
                $majorId = intval($_POST['major_id'] ?? 0);
                $className = sanitize($_POST['class_name'] ?? '');
                $gradeYear = intval($_POST['grade_year'] ?? 0);
                $studentCount = intval($_POST['student_count'] ?? 0);
                
                if (empty($className) || $majorId <= 0 || $gradeYear <= 0) {
                    $error = '请填写完整的班级信息';
                } else {
                    try {
                        $stmt = $db->prepare("UPDATE classes SET major_id=?, class_name=?, grade_year=?, student_count=? WHERE id=?");
                        $stmt->execute([$majorId, $className, $gradeYear, $studentCount, $id]);
                        $message = '班级信息更新成功！';
                        logActivity('edit_class', "Class ID: {$id}");
                    } catch (PDOException $e) {
                        $error = '更新失败，请稍后重试';
                        error_log("Edit class error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'delete_class':
                $id = intval($_POST['id'] ?? 0);
                try {
                    // 检查是否有相关的课程安排
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM course_class_assignments WHERE class_id = ?");
                    $stmt->execute([$id]);
                    $assignmentCount = $stmt->fetch()['count'];
                    
                    if ($assignmentCount > 0) {
                        $error = '该班级已有课程安排，无法删除！';
                    } else {
                        $stmt = $db->prepare("DELETE FROM classes WHERE id = ?");
                        $stmt->execute([$id]);
                        $message = '班级删除成功！';
                        logActivity('delete_class', "Class ID: {$id}");
                    }
                } catch (PDOException $e) {
                    $error = '删除失败，请稍后重试';
                    error_log("Delete class error: " . $e->getMessage());
                }
                break;
        }
    }
}

// 获取专业列表
try {
    $stmt = $db->query("SELECT * FROM majors ORDER BY name");
    $majors = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get majors error: " . $e->getMessage());
    $majors = [];
}

// 获取班级列表
$search = sanitize($_GET['search'] ?? '');
$majorFilter = intval($_GET['major'] ?? 0);
$gradeFilter = intval($_GET['grade'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (c.class_name LIKE ? OR m.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($majorFilter > 0) {
    $where .= " AND c.major_id = ?";
    $params[] = $majorFilter;
}

if ($gradeFilter > 0) {
    $where .= " AND c.grade_year = ?";
    $params[] = $gradeFilter;
}

try {
    // 获取总数
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM classes c JOIN majors m ON c.major_id = m.id {$where}");
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    $totalPages = ceil($total / $limit);
    
    // 获取班级列表及其课程统计
    $stmt = $db->prepare("
        SELECT c.*, m.name as major_name,
               COUNT(DISTINCT cca.id) as course_count
        FROM classes c 
        JOIN majors m ON c.major_id = m.id 
        LEFT JOIN course_class_assignments cca ON c.id = cca.class_id 
        {$where} 
        GROUP BY c.id 
        ORDER BY c.grade_year DESC, m.name, c.class_name 
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $classes = $stmt->fetchAll();
    
    // 获取年级列表用于筛选
    $stmt = $db->query("SELECT DISTINCT grade_year FROM classes ORDER BY grade_year DESC");
    $grades = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Get classes error: " . $e->getMessage());
    $classes = [];
    $grades = [];
    $total = 0;
    $totalPages = 1;
}

// 获取编辑的班级信息
$editClass = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $db->prepare("SELECT * FROM classes WHERE id = ?");
        $stmt->execute([$editId]);
        $editClass = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get edit class error: " . $e->getMessage());
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>班级管理 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-users"></i> 班级管理</h1>
                <p>管理专业和班级信息</p>
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
            
            <!-- 专业管理 -->
            <div class="major-section">
                <h3><i class="fas fa-graduation-cap"></i> 专业管理</h3>
                <div class="major-list">
                    <?php foreach ($majors as $major): ?>
                        <div class="major-card">
                            <span class="major-name"><?php echo sanitize($major['name']); ?></span>
                            <span class="major-stats">
                                <?php
                                $stmt = $db->prepare("SELECT COUNT(*) as count FROM classes WHERE major_id = ?");
                                $stmt->execute([$major['id']]);
                                $classCount = $stmt->fetch()['count'];
                                echo $classCount . ' 个班级';
                                ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="major-card add-major" onclick="showAddMajorModal()">
                        <i class="fas fa-plus"></i>
                        <span>添加专业</span>
                    </div>
                </div>
            </div>
            
            <!-- 搜索和筛选 -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <input type="text" name="search" value="<?php echo $search; ?>" 
                               placeholder="搜索班级名称或专业..." class="form-control">
                        
                        <select name="major" class="form-control">
                            <option value="">全部专业</option>
                            <?php foreach ($majors as $major): ?>
                                <option value="<?php echo $major['id']; ?>" 
                                        <?php echo $majorFilter === $major['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($major['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="grade" class="form-control">
                            <option value="">全部年级</option>
                            <?php foreach ($grades as $grade): ?>
                                <option value="<?php echo $grade['grade_year']; ?>" 
                                        <?php echo $gradeFilter === $grade['grade_year'] ? 'selected' : ''; ?>>
                                    <?php echo $grade['grade_year']; ?>级
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> 搜索
                        </button>
                        
                        <a href="classes.php" class="btn btn-outline">
                            <i class="fas fa-refresh"></i> 重置
                        </a>
                    </div>
                </form>
                
                <div class="actions">
                    <button onclick="showAddClassModal()" class="btn btn-primary">
                        <i class="fas fa-plus"></i> 添加班级
                    </button>
                </div>
            </div>
            
            <!-- 班级列表 -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>班级信息</th>
                            <th>专业</th>
                            <th>年级</th>
                            <th>学生人数</th>
                            <th>课程数量</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($classes)): ?>
                            <tr>
                                <td colspan="7" class="text-center">
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>暂无班级数据</p>
                                        <button onclick="showAddClassModal()" class="btn btn-primary">添加第一个班级</button>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($classes as $class): ?>
                                <tr>
                                    <td>
                                        <div class="class-info">
                                            <div class="class-icon">
                                                <i class="fas fa-users"></i>
                                            </div>
                                            <div class="class-details">
                                                <strong><?php echo sanitize($class['class_name']); ?></strong>
                                                <small>ID: <?php echo $class['id']; ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="major-badge"><?php echo sanitize($class['major_name']); ?></span>
                                    </td>
                                    <td>
                                        <span class="grade-badge"><?php echo $class['grade_year']; ?>级</span>
                                    </td>
                                    <td>
                                        <span class="student-count">
                                            <i class="fas fa-user"></i> <?php echo $class['student_count']; ?> 人
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary"><?php echo $class['course_count']; ?></span>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($class['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="viewClassSchedule(<?php echo $class['id']; ?>)" 
                                                    class="btn btn-sm btn-info" title="查看课表">
                                                <i class="fas fa-calendar"></i>
                                            </button>
                                            <button onclick="editClass(<?php echo $class['id']; ?>)" 
                                                    class="btn btn-sm btn-outline" title="编辑">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deleteClass(<?php echo $class['id']; ?>)" 
                                                    class="btn btn-sm btn-danger" title="删除">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- 分页 -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $queryParams = $_GET;
                    for ($i = 1; $i <= $totalPages; $i++):
                        $queryParams['page'] = $i;
                        $url = '?' . http_build_query($queryParams);
                    ?>
                        <a href="<?php echo $url; ?>" 
                           class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- 添加专业模态框 -->
    <div id="majorModal" class="modal">
        <div class="modal-content small">
            <div class="modal-header">
                <h3>添加专业</h3>
                <button onclick="closeMajorModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="add_major">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="majorName">专业名称 <span class="required">*</span></label>
                        <input type="text" id="majorName" name="name" class="form-control" 
                               required placeholder="例如：计算机科学与技术">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeMajorModal()" class="btn btn-outline">取消</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> 保存
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 添加/编辑班级模态框 -->
    <div id="classModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="classModalTitle">添加班级</h3>
                <button onclick="closeClassModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="classForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" id="classFormAction" value="add_class">
                <input type="hidden" name="id" id="classId" value="">
                
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="majorSelect">所属专业 <span class="required">*</span></label>
                            <select id="majorSelect" name="major_id" class="form-control" required>
                                <option value="">请选择专业</option>
                                <?php foreach ($majors as $major): ?>
                                    <option value="<?php echo $major['id']; ?>">
                                        <?php echo sanitize($major['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="gradeYear">年级 <span class="required">*</span></label>
                            <select id="gradeYear" name="grade_year" class="form-control" required>
                                <option value="">请选择年级</option>
                                <?php
                                $currentYear = date('Y');
                                for ($year = $currentYear; $year >= $currentYear - 10; $year--):
                                ?>
                                    <option value="<?php echo $year; ?>"><?php echo $year; ?>级</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="className">班级名称 <span class="required">*</span></label>
                            <input type="text" id="className" name="class_name" class="form-control" 
                                   required placeholder="例如：1班、2班">
                        </div>
                        
                        <div class="form-group">
                            <label for="studentCount">学生人数</label>
                            <input type="number" id="studentCount" name="student_count" 
                                   class="form-control" min="0" max="200" placeholder="30">
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeClassModal()" class="btn btn-outline">取消</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> 保存
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // 显示添加专业模态框
        function showAddMajorModal() {
            document.getElementById('majorModal').style.display = 'block';
        }
        
        // 关闭专业模态框
        function closeMajorModal() {
            document.getElementById('majorModal').style.display = 'none';
        }
        
        // 显示添加班级模态框
        function showAddClassModal() {
            document.getElementById('classModalTitle').textContent = '添加班级';
            document.getElementById('classFormAction').value = 'add_class';
            document.getElementById('classId').value = '';
            document.getElementById('classForm').reset();
            document.getElementById('classModal').style.display = 'block';
        }
        
        // 编辑班级
        function editClass(id) {
            window.location.href = `classes.php?edit=${id}`;
        }
        
        <?php if ($editClass): ?>
        // 如果有编辑数据，显示编辑模态框
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('classModalTitle').textContent = '编辑班级';
            document.getElementById('classFormAction').value = 'edit_class';
            document.getElementById('classId').value = '<?php echo $editClass['id']; ?>';
            document.getElementById('majorSelect').value = '<?php echo $editClass['major_id']; ?>';
            document.getElementById('gradeYear').value = '<?php echo $editClass['grade_year']; ?>';
            document.getElementById('className').value = '<?php echo addslashes($editClass['class_name']); ?>';
            document.getElementById('studentCount').value = '<?php echo $editClass['student_count']; ?>';
            document.getElementById('classModal').style.display = 'block';
        });
        <?php endif; ?>
        
        // 删除班级
        function deleteClass(id) {
            if (confirm('确定要删除这个班级吗？删除后无法恢复！')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="delete_class">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // 查看班级课表
        function viewClassSchedule(id) {
            window.open(`schedule_view.php?class=${id}`, '_blank');
        }
        
        // 关闭班级模态框
        function closeClassModal() {
            document.getElementById('classModal').style.display = 'none';
            if (window.location.search.includes('edit=')) {
                window.location.href = 'classes.php';
            }
        }
        
        // 点击外部关闭模态框
        window.addEventListener('click', function(e) {
            const majorModal = document.getElementById('majorModal');
            const classModal = document.getElementById('classModal');
            
            if (e.target === majorModal) {
                closeMajorModal();
            }
            if (e.target === classModal) {
                closeClassModal();
            }
        });
    </script>
    
    <style>
        .major-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }
        
        .major-section h3 {
            color: #1e293b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .major-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .major-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .major-card:hover {
            border-color: #4f46e5;
            transform: translateY(-2px);
        }
        
        .major-card.add-major {
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            cursor: pointer;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #64748b;
            border-style: dashed;
        }
        
        .major-card.add-major:hover {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
        }
        
        .major-name {
            font-weight: 600;
            color: #1e293b;
        }
        
        .major-stats {
            font-size: 12px;
            color: #64748b;
        }
        
        .class-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .class-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #10b981, #047857);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .class-details strong {
            display: block;
            color: #1e293b;
        }
        
        .class-details small {
            color: #94a3b8;
            font-size: 11px;
        }
        
        .major-badge {
            background: #ddd6fe;
            color: #5b21b6;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .grade-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .student-count {
            color: #059669;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
        }
        
        .modal-content.small {
            max-width: 400px;
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
            .major-list {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</body>
</html>