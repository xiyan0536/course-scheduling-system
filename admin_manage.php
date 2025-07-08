<?php
require_once 'config.php';
requireSuperAdmin();

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
                $username = sanitize($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                $role = $_POST['role'] ?? 'admin';
                
                if (empty($username) || empty($password)) {
                    $error = '用户名和密码不能为空';
                } elseif ($password !== $confirmPassword) {
                    $error = '两次输入的密码不一致';
                } elseif (strlen($password) < 6) {
                    $error = '密码长度不能少于6位';
                } else {
                    try {
                        $hashedPassword = password_hash($password, PASSWORD_HASH_ALGO);
                        $stmt = $db->prepare("INSERT INTO admins (username, password, role) VALUES (?, ?, ?)");
                        $stmt->execute([$username, $hashedPassword, $role]);
                        $message = '管理员添加成功！';
                        logActivity('add_admin', "Username: {$username}, Role: {$role}");
                    } catch (PDOException $e) {
                        if ($e->getCode() == '23000') {
                            $error = '用户名已存在！';
                        } else {
                            $error = '添加失败，请稍后重试';
                            error_log("Add admin error: " . $e->getMessage());
                        }
                    }
                }
                break;
                
            case 'edit':
                $id = intval($_POST['id'] ?? 0);
                $username = sanitize($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? 'admin';
                
                if (empty($username)) {
                    $error = '用户名不能为空';
                } elseif ($id === $_SESSION['admin_id'] && $role !== 'super_admin') {
                    $error = '不能修改自己的超级管理员权限';
                } else {
                    try {
                        if (!empty($password)) {
                            if (strlen($password) < 6) {
                                $error = '密码长度不能少于6位';
                                break;
                            }
                            $hashedPassword = password_hash($password, PASSWORD_HASH_ALGO);
                            $stmt = $db->prepare("UPDATE admins SET username=?, password=?, role=? WHERE id=?");
                            $stmt->execute([$username, $hashedPassword, $role, $id]);
                        } else {
                            $stmt = $db->prepare("UPDATE admins SET username=?, role=? WHERE id=?");
                            $stmt->execute([$username, $role, $id]);
                        }
                        $message = '管理员信息更新成功！';
                        logActivity('edit_admin', "Admin ID: {$id}, Username: {$username}");
                    } catch (PDOException $e) {
                        if ($e->getCode() == '23000') {
                            $error = '用户名已存在！';
                        } else {
                            $error = '更新失败，请稍后重试';
                            error_log("Edit admin error: " . $e->getMessage());
                        }
                    }
                }
                break;
                
            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                
                if ($id === $_SESSION['admin_id']) {
                    $error = '不能删除自己的账户';
                } else {
                    try {
                        $stmt = $db->prepare("DELETE FROM admins WHERE id = ?");
                        $stmt->execute([$id]);
                        $message = '管理员删除成功！';
                        logActivity('delete_admin', "Admin ID: {$id}");
                    } catch (PDOException $e) {
                        $error = '删除失败，请稍后重试';
                        error_log("Delete admin error: " . $e->getMessage());
                    }
                }
                break;
        }
    }
}

// 获取管理员列表
try {
    $stmt = $db->query("SELECT * FROM admins ORDER BY created_at DESC");
    $admins = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get admins error: " . $e->getMessage());
    $admins = [];
}

// 获取编辑的管理员信息
$editAdmin = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$editId]);
        $editAdmin = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get edit admin error: " . $e->getMessage());
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员管理 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-users-cog"></i> 管理员管理</h1>
                <p>管理系统管理员账户</p>
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
            
            <!-- 安全提示 -->
            <div class="security-notice">
                <div class="notice-content">
                    <i class="fas fa-shield-alt"></i>
                    <div>
                        <h4>安全提示</h4>
                        <p>管理员账户拥有系统的完整访问权限。请谨慎添加和管理管理员，确保只有可信任的人员获得访问权限。</p>
                    </div>
                </div>
            </div>
            
            <!-- 操作按钮 -->
            <div class="admin-actions">
                <button onclick="showAddModal()" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> 添加管理员
                </button>
                <button onclick="viewSystemLogs()" class="btn btn-outline">
                    <i class="fas fa-file-alt"></i> 查看系统日志
                </button>
            </div>
            
            <!-- 管理员列表 -->
            <div class="admins-container">
                <h3><i class="fas fa-list"></i> 管理员列表</h3>
                
                <div class="admins-grid">
                    <?php foreach ($admins as $admin): ?>
                        <div class="admin-card <?php echo $admin['id'] === $_SESSION['admin_id'] ? 'current-user' : ''; ?>">
                            <div class="admin-avatar">
                                <i class="fas <?php echo $admin['role'] === 'super_admin' ? 'fa-crown' : 'fa-user-tie'; ?>"></i>
                            </div>
                            
                            <div class="admin-info">
                                <h4><?php echo sanitize($admin['username']); ?></h4>
                                <div class="admin-role">
                                    <span class="role-badge <?php echo $admin['role']; ?>">
                                        <?php echo $admin['role'] === 'super_admin' ? '超级管理员' : '管理员'; ?>
                                    </span>
                                </div>
                                
                                <div class="admin-meta">
                                    <div class="meta-item">
                                        <i class="fas fa-calendar-plus"></i>
                                        <span>创建时间：<?php echo date('Y-m-d H:i', strtotime($admin['created_at'])); ?></span>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <i class="fas fa-clock"></i>
                                        <span>最后更新：<?php echo date('Y-m-d H:i', strtotime($admin['updated_at'])); ?></span>
                                    </div>
                                </div>
                                
                                <?php if ($admin['id'] === $_SESSION['admin_id']): ?>
                                    <div class="current-user-badge">
                                        <i class="fas fa-user"></i> 当前用户
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="admin-actions-menu">
                                <?php if ($admin['id'] !== $_SESSION['admin_id']): ?>
                                    <button onclick="editAdmin(<?php echo $admin['id']; ?>)" 
                                            class="btn btn-sm btn-outline" title="编辑">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteAdmin(<?php echo $admin['id']; ?>)" 
                                            class="btn btn-sm btn-danger" title="删除">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <button onclick="editAdmin(<?php echo $admin['id']; ?>)" 
                                            class="btn btn-sm btn-outline" title="编辑个人信息">
                                        <i class="fas fa-user-cog"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- 添加/编辑管理员模态框 -->
    <div id="adminModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">添加管理员</h3>
                <button onclick="closeModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="adminForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="adminId" value="">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="adminUsername">用户名 <span class="required">*</span></label>
                        <input type="text" id="adminUsername" name="username" class="form-control" 
                               required autocomplete="off" placeholder="请输入用户名">
                        <small class="form-help">用户名只能包含字母、数字和下划线</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="adminPassword">密码 <span class="required" id="passwordRequired">*</span></label>
                        <input type="password" id="adminPassword" name="password" class="form-control" 
                               autocomplete="new-password" placeholder="请输入密码">
                        <small class="form-help">密码长度至少6位，编辑时留空表示不修改密码</small>
                    </div>
                    
                    <div class="form-group" id="confirmPasswordGroup">
                        <label for="confirmPassword">确认密码 <span class="required">*</span></label>
                        <input type="password" id="confirmPassword" name="confirm_password" class="form-control" 
                               autocomplete="new-password" placeholder="请再次输入密码">
                    </div>
                    
                    <div class="form-group">
                        <label for="adminRole">角色权限 <span class="required">*</span></label>
                        <select id="adminRole" name="role" class="form-control" required>
                            <option value="admin">管理员</option>
                            <option value="super_admin">超级管理员</option>
                        </select>
                        <small class="form-help">
                            <strong>管理员：</strong>可以管理课程、教师、班级、教室和排课<br>
                            <strong>超级管理员：</strong>拥有所有权限，包括管理其他管理员
                        </small>
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
            document.getElementById('modalTitle').textContent = '添加管理员';
            document.getElementById('formAction').value = 'add';
            document.getElementById('adminId').value = '';
            document.getElementById('adminForm').reset();
            document.getElementById('passwordRequired').style.display = 'inline';
            document.getElementById('adminPassword').required = true;
            document.getElementById('confirmPasswordGroup').style.display = 'block';
            document.getElementById('confirmPassword').required = true;
            document.getElementById('adminModal').style.display = 'block';
        }
        
        // 编辑管理员
        function editAdmin(id) {
            window.location.href = `admin_manage.php?edit=${id}`;
        }
        
        <?php if ($editAdmin): ?>
        // 如果有编辑数据，显示编辑模态框
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('modalTitle').textContent = '编辑管理员';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('adminId').value = '<?php echo $editAdmin['id']; ?>';
            document.getElementById('adminUsername').value = '<?php echo addslashes($editAdmin['username']); ?>';
            document.getElementById('adminRole').value = '<?php echo $editAdmin['role']; ?>';
            
            // 编辑时密码不是必填
            document.getElementById('passwordRequired').style.display = 'none';
            document.getElementById('adminPassword').required = false;
            document.getElementById('confirmPasswordGroup').style.display = 'none';
            document.getElementById('confirmPassword').required = false;
            
            document.getElementById('adminModal').style.display = 'block';
        });
        <?php endif; ?>
        
        // 删除管理员
        function deleteAdmin(id) {
            if (confirm('确定要删除这个管理员吗？删除后该账户将无法登录系统！')) {
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
        
        // 查看系统日志
        function viewSystemLogs() {
            window.open('system_logs.php', '_blank');
        }
        
        // 关闭模态框
        function closeModal() {
            document.getElementById('adminModal').style.display = 'none';
            if (window.location.search.includes('edit=')) {
                window.location.href = 'admin_manage.php';
            }
        }
        
        // 点击外部关闭模态框
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('adminModal');
            if (e.target === modal) {
                closeModal();
            }
        });
        
        // 表单验证
        document.getElementById('adminForm').addEventListener('submit', function(e) {
            const password = document.getElementById('adminPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const isAdd = document.getElementById('formAction').value === 'add';
            
            if (isAdd || password) {
                if (password.length < 6) {
                    e.preventDefault();
                    alert('密码长度不能少于6位');
                    return false;
                }
                
                if (isAdd && password !== confirmPassword) {
                    e.preventDefault();
                    alert('两次输入的密码不一致');
                    return false;
                }
            }
        });
    </script>
    
    <style>
        .security-notice {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 1px solid #f59e0b;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .notice-content {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .notice-content i {
            font-size: 24px;
            color: #d97706;
            margin-top: 2px;
        }
        
        .notice-content h4 {
            color: #92400e;
            margin-bottom: 5px;
            font-size: 16px;
        }
        
        .notice-content p {
            color: #a16207;
            margin: 0;
            line-height: 1.5;
        }
        
        .admin-actions {
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
        }
        
        .admins-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            padding: 30px;
        }
        
        .admins-container h3 {
            color: #1e293b;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .admins-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .admin-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 25px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .admin-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .admin-card.current-user {
            border-color: #4f46e5;
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
        }
        
        .admin-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .admin-card.current-user .admin-avatar {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }
        
        .admin-info h4 {
            color: #1e293b;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .admin-role {
            margin-bottom: 15px;
        }
        
        .role-badge {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .role-badge.admin {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .role-badge.super_admin {
            background: #fef3c7;
            color: #92400e;
        }
        
        .admin-meta {
            margin-bottom: 15px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
            font-size: 13px;
            color: #64748b;
        }
        
        .meta-item i {
            width: 14px;
            color: #94a3b8;
        }
        
        .current-user-badge {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 15px;
        }
        
        .admin-actions-menu {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 8px;
        }
        
        .required {
            color: #ef4444;
        }
        
        .form-help {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
            display: block;
        }
        
        @media (max-width: 768px) {
            .admins-grid {
                grid-template-columns: 1fr;
            }
            
            .admin-actions {
                flex-direction: column;
            }
            
            .notice-content {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</body>
</html>