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
            case 'add':
                $name = sanitize($_POST['name'] ?? '');
                $courseCode = sanitize($_POST['course_code'] ?? '');
                $type = $_POST['type'] ?? '';
                $weeklyHours = intval($_POST['weekly_hours'] ?? 0);
                $totalHours = intval($_POST['total_hours'] ?? 0);
                $description = sanitize($_POST['description'] ?? '');
                $requirements = sanitize($_POST['requirements'] ?? '');
                
                if (empty($name) || empty($type) || $weeklyHours <= 0) {
                    $error = '请填写必填项目';
                } else {
                    try {
                        $stmt = $db->prepare("INSERT INTO courses (name, course_code, type, weekly_hours, total_hours, description, requirements) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $courseCode, $type, $weeklyHours, $totalHours, $description, $requirements]);
                        $message = '课程添加成功！';
                        logActivity('add_course', "Course: {$name}");
                    } catch (PDOException $e) {
                        if ($e->getCode() == '23000') {
                            $error = '课程代码已存在！';
                        } else {
                            $error = '添加失败，请稍后重试';
                            error_log("Add course error: " . $e->getMessage());
                        }
                    }
                }
                break;
                
            case 'edit':
                $id = intval($_POST['id'] ?? 0);
                $name = sanitize($_POST['name'] ?? '');
                $courseCode = sanitize($_POST['course_code'] ?? '');
                $type = $_POST['type'] ?? '';
                $weeklyHours = intval($_POST['weekly_hours'] ?? 0);
                $totalHours = intval($_POST['total_hours'] ?? 0);
                $description = sanitize($_POST['description'] ?? '');
                $requirements = sanitize($_POST['requirements'] ?? '');
                
                if (empty($name) || empty($type) || $weeklyHours <= 0) {
                    $error = '请填写必填项目';
                } else {
                    try {
                        $stmt = $db->prepare("UPDATE courses SET name=?, course_code=?, type=?, weekly_hours=?, total_hours=?, description=?, requirements=? WHERE id=?");
                        $stmt->execute([$name, $courseCode, $type, $weeklyHours, $totalHours, $description, $requirements, $id]);
                        $message = '课程信息更新成功！';
                        logActivity('edit_course', "Course ID: {$id}, Name: {$name}");
                    } catch (PDOException $e) {
                        $error = '更新失败，请稍后重试';
                        error_log("Edit course error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                try {
                    // 检查是否有相关的课程安排
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM course_class_assignments WHERE course_id = ?");
                    $stmt->execute([$id]);
                    $assignmentCount = $stmt->fetch()['count'];
                    
                    if ($assignmentCount > 0) {
                        $error = '该课程已有班级安排，无法删除！';
                    } else {
                        $stmt = $db->prepare("DELETE FROM courses WHERE id = ?");
                        $stmt->execute([$id]);
                        $message = '课程删除成功！';
                        logActivity('delete_course', "Course ID: {$id}");
                    }
                } catch (PDOException $e) {
                    $error = '删除失败，请稍后重试';
                    error_log("Delete course error: " . $e->getMessage());
                }
                break;
        }
    }
}

// 获取课程列表
$search = sanitize($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (name LIKE ? OR course_code LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($type)) {
    $where .= " AND type = ?";
    $params[] = $type;
}

try {
    // 获取总数
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM courses {$where}");
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    $totalPages = ceil($total / $limit);
    
    // 获取课程列表
    $stmt = $db->prepare("SELECT * FROM courses {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $courses = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Get courses error: " . $e->getMessage());
    $courses = [];
    $total = 0;
    $totalPages = 1;
}

// 获取编辑的课程信息
$editCourse = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $db->prepare("SELECT * FROM courses WHERE id = ?");
        $stmt->execute([$editId]);
        $editCourse = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get edit course error: " . $e->getMessage());
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>课程管理 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-book"></i> 课程管理</h1>
                <p>管理系统中的所有课程信息</p>
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
            
            <!-- 搜索和筛选 -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <input type="text" name="search" value="<?php echo $search; ?>" 
                               placeholder="搜索课程名称或课程代码..." class="form-control">
                        
                        <select name="type" class="form-control">
                            <option value="">全部类型</option>
                            <option value="professional" <?php echo $type === 'professional' ? 'selected' : ''; ?>>专业课</option>
                            <option value="public" <?php echo $type === 'public' ? 'selected' : ''; ?>>公共课</option>
                        </select>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> 搜索
                        </button>
                        
                        <a href="courses.php" class="btn btn-outline">
                            <i class="fas fa-refresh"></i> 重置
                        </a>
                    </div>
                </form>
                
                <div class="actions">
                    <button onclick="showAddModal()" class="btn btn-primary">
                        <i class="fas fa-plus"></i> 添加课程
                    </button>
                </div>
            </div>
            
            <!-- 课程列表 -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>课程名称</th>
                            <th>课程代码</th>
                            <th>类型</th>
                            <th>周课时</th>
                            <th>总课时</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($courses)): ?>
                            <tr>
                                <td colspan="7" class="text-center">
                                    <div class="empty-state">
                                        <i class="fas fa-book-open"></i>
                                        <p>暂无课程数据</p>
                                        <button onclick="showAddModal()" class="btn btn-primary">添加第一个课程</button>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($courses as $course): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo sanitize($course['name']); ?></strong>
                                        <?php if ($course['description']): ?>
                                            <br><small class="text-muted"><?php echo sanitize($course['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo sanitize($course['course_code']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $course['type'] === 'professional' ? 'badge-primary' : 'badge-success'; ?>">
                                            <?php echo $course['type'] === 'professional' ? '专业课' : '公共课'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $course['weekly_hours']; ?> 课时</td>
                                    <td><?php echo $course['total_hours'] ?: '-'; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($course['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="editCourse(<?php echo $course['id']; ?>)" 
                                                    class="btn btn-sm btn-outline" title="编辑">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deleteCourse(<?php echo $course['id']; ?>)" 
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

    <!-- 添加/编辑课程模态框 -->
    <div id="courseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">添加课程</h3>
                <button onclick="closeModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="courseForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="courseId" value="">
                
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="courseName">课程名称 <span class="required">*</span></label>
                            <input type="text" id="courseName" name="name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="courseCode">课程代码</label>
                            <input type="text" id="courseCode" name="course_code" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="courseType">课程类型 <span class="required">*</span></label>
                            <select id="courseType" name="type" class="form-control" required>
                                <option value="">请选择类型</option>
                                <option value="professional">专业课</option>
                                <option value="public">公共课</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="weeklyHours">周课时 <span class="required">*</span></label>
                            <input type="number" id="weeklyHours" name="weekly_hours" 
                                   class="form-control" min="1" max="20" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="totalHours">总课时</label>
                            <input type="number" id="totalHours" name="total_hours" 
                                   class="form-control" min="1">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">课程描述</label>
                        <textarea id="description" name="description" class="form-control" 
                                  rows="3" placeholder="请输入课程描述..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="requirements">课程要求</label>
                        <textarea id="requirements" name="requirements" class="form-control" 
                                  rows="3" placeholder="请输入课程要求..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">取消</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> 保存
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // 显示添加模态框
        function showAddModal() {
            document.getElementById('modalTitle').textContent = '添加课程';
            document.getElementById('formAction').value = 'add';
            document.getElementById('courseId').value = '';
            document.getElementById('courseForm').reset();
            document.getElementById('courseModal').style.display = 'block';
        }
        
        // 编辑课程
        function editCourse(id) {
            // 这里应该通过AJAX获取课程信息，为简化直接重定向
            window.location.href = `courses.php?edit=${id}`;
        }
        
        <?php if ($editCourse): ?>
        // 如果有编辑数据，显示编辑模态框
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('modalTitle').textContent = '编辑课程';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('courseId').value = '<?php echo $editCourse['id']; ?>';
            document.getElementById('courseName').value = '<?php echo addslashes($editCourse['name']); ?>';
            document.getElementById('courseCode').value = '<?php echo addslashes($editCourse['course_code']); ?>';
            document.getElementById('courseType').value = '<?php echo $editCourse['type']; ?>';
            document.getElementById('weeklyHours').value = '<?php echo $editCourse['weekly_hours']; ?>';
            document.getElementById('totalHours').value = '<?php echo $editCourse['total_hours']; ?>';
            document.getElementById('description').value = '<?php echo addslashes($editCourse['description']); ?>';
            document.getElementById('requirements').value = '<?php echo addslashes($editCourse['requirements']); ?>';
            document.getElementById('courseModal').style.display = 'block';
        });
        <?php endif; ?>
        
        // 删除课程
        function deleteCourse(id) {
            if (confirm('确定要删除这个课程吗？删除后无法恢复！')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // 关闭模态框
        function closeModal() {
            document.getElementById('courseModal').style.display = 'none';
            // 如果URL中有edit参数，移除它
            if (window.location.search.includes('edit=')) {
                window.location.href = 'courses.php';
            }
        }
        
        // 点击外部关闭模态框
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('courseModal');
            if (e.target === modal) {
                closeModal();
            }
        });
    </script>
</body>
</html>