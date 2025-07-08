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
                $phone = sanitize($_POST['phone'] ?? '');
                $email = sanitize($_POST['email'] ?? '');
                
                if (empty($name)) {
                    $error = '教师姓名不能为空';
                } else {
                    try {
                        $stmt = $db->prepare("INSERT INTO teachers (name, phone, email) VALUES (?, ?, ?)");
                        $stmt->execute([$name, $phone, $email]);
                        $message = '教师添加成功！';
                        logActivity('add_teacher', "Teacher: {$name}");
                    } catch (PDOException $e) {
                        $error = '添加失败，请稍后重试';
                        error_log("Add teacher error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'edit':
                $id = intval($_POST['id'] ?? 0);
                $name = sanitize($_POST['name'] ?? '');
                $phone = sanitize($_POST['phone'] ?? '');
                $email = sanitize($_POST['email'] ?? '');
                
                if (empty($name)) {
                    $error = '教师姓名不能为空';
                } else {
                    try {
                        $stmt = $db->prepare("UPDATE teachers SET name=?, phone=?, email=? WHERE id=?");
                        $stmt->execute([$name, $phone, $email, $id]);
                        $message = '教师信息更新成功！';
                        logActivity('edit_teacher', "Teacher ID: {$id}, Name: {$name}");
                    } catch (PDOException $e) {
                        $error = '更新失败，请稍后重试';
                        error_log("Edit teacher error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                try {
                    // 检查是否有相关的课程安排
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM course_class_assignments WHERE teacher_id = ?");
                    $stmt->execute([$id]);
                    $assignmentCount = $stmt->fetch()['count'];
                    
                    if ($assignmentCount > 0) {
                        $error = '该教师已有课程安排，无法删除！';
                    } else {
                        $stmt = $db->prepare("DELETE FROM teachers WHERE id = ?");
                        $stmt->execute([$id]);
                        $message = '教师删除成功！';
                        logActivity('delete_teacher', "Teacher ID: {$id}");
                    }
                } catch (PDOException $e) {
                    $error = '删除失败，请稍后重试';
                    error_log("Delete teacher error: " . $e->getMessage());
                }
                break;
        }
    }
}

// 获取教师列表
$search = sanitize($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

try {
    // 获取总数
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM teachers {$where}");
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    $totalPages = ceil($total / $limit);
    
    // 获取教师列表及其课程统计
    $stmt = $db->prepare("
        SELECT t.*, 
               COUNT(DISTINCT cca.id) as course_count,
               COUNT(DISTINCT cca.class_id) as class_count
        FROM teachers t 
        LEFT JOIN course_class_assignments cca ON t.id = cca.teacher_id 
        {$where} 
        GROUP BY t.id 
        ORDER BY t.created_at DESC 
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $teachers = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Get teachers error: " . $e->getMessage());
    $teachers = [];
    $total = 0;
    $totalPages = 1;
}

// 获取编辑的教师信息
$editTeacher = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $db->prepare("SELECT * FROM teachers WHERE id = ?");
        $stmt->execute([$editId]);
        $editTeacher = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get edit teacher error: " . $e->getMessage());
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>教师管理 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-chalkboard-teacher"></i> 教师管理</h1>
                <p>管理系统中的所有教师信息</p>
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
            
            <!-- 搜索和操作 -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <input type="text" name="search" value="<?php echo $search; ?>" 
                               placeholder="搜索教师姓名、电话或邮箱..." class="form-control">
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> 搜索
                        </button>
                        
                        <a href="teachers.php" class="btn btn-outline">
                            <i class="fas fa-refresh"></i> 重置
                        </a>
                    </div>
                </form>
                
                <div class="actions">
                    <button onclick="showAddModal()" class="btn btn-primary">
                        <i class="fas fa-plus"></i> 添加教师
                    </button>
                    <button onclick="importTeachers()" class="btn btn-outline">
                        <i class="fas fa-upload"></i> 批量导入
                    </button>
                </div>
            </div>
            
            <!-- 统计信息 -->
            <div class="stats-summary">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $total; ?></div>
                    <div class="stat-label">总教师数</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php 
                        $stmt = $db->query("SELECT COUNT(DISTINCT teacher_id) as count FROM course_class_assignments");
                        echo $stmt->fetch()['count'];
                        ?>
                    </div>
                    <div class="stat-label">有课程安排</div>
                </div>
            </div>
            
            <!-- 教师列表 -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>教师姓名</th>
                            <th>联系电话</th>
                            <th>邮箱地址</th>
                            <th>课程数量</th>
                            <th>班级数量</th>
                            <th>添加时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teachers)): ?>
                            <tr>
                                <td colspan="7" class="text-center">
                                    <div class="empty-state">
                                        <i class="fas fa-user-tie"></i>
                                        <p>暂无教师数据</p>
                                        <button onclick="showAddModal()" class="btn btn-primary">添加第一位教师</button>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($teachers as $teacher): ?>
                                <tr>
                                    <td>
                                        <div class="teacher-info">
                                            <div class="teacher-avatar">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div class="teacher-details">
                                                <strong><?php echo sanitize($teacher['name']); ?></strong>
                                                <small class="teacher-id">ID: <?php echo $teacher['id']; ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($teacher['phone']): ?>
                                            <a href="tel:<?php echo $teacher['phone']; ?>" class="contact-link">
                                                <i class="fas fa-phone"></i> <?php echo sanitize($teacher['phone']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">未填写</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($teacher['email']): ?>
                                            <a href="mailto:<?php echo $teacher['email']; ?>" class="contact-link">
                                                <i class="fas fa-envelope"></i> <?php echo sanitize($teacher['email']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">未填写</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary"><?php echo $teacher['course_count']; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-success"><?php echo $teacher['class_count']; ?></span>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($teacher['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="viewTeacherSchedule(<?php echo $teacher['id']; ?>)" 
                                                    class="btn btn-sm btn-info" title="查看课表">
                                                <i class="fas fa-calendar"></i>
                                            </button>
                                            <button onclick="editTeacher(<?php echo $teacher['id']; ?>)" 
                                                    class="btn btn-sm btn-outline" title="编辑">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deleteTeacher(<?php echo $teacher['id']; ?>)" 
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

    <!-- 添加/编辑教师模态框 -->
    <div id="teacherModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">添加教师</h3>
                <button onclick="closeModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="teacherForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="teacherId" value="">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="teacherName">教师姓名 <span class="required">*</span></label>
                        <input type="text" id="teacherName" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="teacherPhone">联系电话</label>
                            <input type="tel" id="teacherPhone" name="phone" class="form-control" 
                                   placeholder="请输入11位手机号码">
                        </div>
                        
                        <div class="form-group">
                            <label for="teacherEmail">邮箱地址</label>
                            <input type="email" id="teacherEmail" name="email" class="form-control" 
                                   placeholder="example@email.com">
                        </div>
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
            document.getElementById('modalTitle').textContent = '添加教师';
            document.getElementById('formAction').value = 'add';
            document.getElementById('teacherId').value = '';
            document.getElementById('teacherForm').reset();
            document.getElementById('teacherModal').style.display = 'block';
        }
        
        // 编辑教师
        function editTeacher(id) {
            window.location.href = `teachers.php?edit=${id}`;
        }
        
        <?php if ($editTeacher): ?>
        // 如果有编辑数据，显示编辑模态框
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('modalTitle').textContent = '编辑教师';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('teacherId').value = '<?php echo $editTeacher['id']; ?>';
            document.getElementById('teacherName').value = '<?php echo addslashes($editTeacher['name']); ?>';
            document.getElementById('teacherPhone').value = '<?php echo addslashes($editTeacher['phone']); ?>';
            document.getElementById('teacherEmail').value = '<?php echo addslashes($editTeacher['email']); ?>';
            document.getElementById('teacherModal').style.display = 'block';
        });
        <?php endif; ?>
        
        // 删除教师
        function deleteTeacher(id) {
            if (confirm('确定要删除这位教师吗？删除后无法恢复！')) {
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
        
        // 查看教师课表
        function viewTeacherSchedule(id) {
            window.open(`schedule_view.php?teacher=${id}`, '_blank');
        }
        
        // 批量导入教师
        function importTeachers() {
            alert('批量导入功能开发中...');
        }
        
        // 关闭模态框
        function closeModal() {
            document.getElementById('teacherModal').style.display = 'none';
            if (window.location.search.includes('edit=')) {
                window.location.href = 'teachers.php';
            }
        }
        
        // 点击外部关闭模态框
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('teacherModal');
            if (e.target === modal) {
                closeModal();
            }
        });
        
        // 表单验证
        document.getElementById('teacherForm').addEventListener('submit', function(e) {
            const phone = document.getElementById('teacherPhone').value;
            const email = document.getElementById('teacherEmail').value;
            
            if (phone && !/^1[3-9]\d{9}$/.test(phone)) {
                e.preventDefault();
                alert('请输入正确的手机号码格式');
                return false;
            }
            
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                e.preventDefault();
                alert('请输入正确的邮箱地址格式');
                return false;
            }
        });
    </script>
    
    <style>
        .teacher-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .teacher-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .teacher-details strong {
            display: block;
            color: #1e293b;
        }
        
        .teacher-id {
            color: #94a3b8;
            font-size: 11px;
        }
        
        .contact-link {
            color: #4f46e5;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .contact-link:hover {
            text-decoration: underline;
        }
        
        .stats-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            min-width: 120px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #4f46e5;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748b;
        }
        
        .text-muted {
            color: #94a3b8;
            font-style: italic;
        }
        
        .btn-info {
            background: #0ea5e9;
            color: white;
        }
        
        .btn-info:hover {
            background: #0284c7;
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
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-summary {
                flex-direction: column;
            }
        }
    </style>
</body>
</html>