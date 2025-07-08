<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// 获取当前管理员信息
try {
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $currentAdmin = $stmt->fetch();
    
    if (!$currentAdmin) {
        redirect('logout.php');
    }
} catch (PDOException $e) {
    $error = '获取用户信息失败';
    error_log("Get profile error: " . $e->getMessage());
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = '无效的请求';
    } else {
        switch ($action) {
            case 'update_profile':
                $username = sanitize($_POST['username'] ?? '');
                
                if (empty($username)) {
                    $error = '用户名不能为空';
                } elseif ($username !== $currentAdmin['username']) {
                    // 检查用户名是否已存在
                    try {
                        $stmt = $db->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
                        $stmt->execute([$username, $_SESSION['admin_id']]);
                        if ($stmt->fetch()) {
                            $error = '用户名已存在！';
                        } else {
                            $stmt = $db->prepare("UPDATE admins SET username = ? WHERE id = ?");
                            $stmt->execute([$username, $_SESSION['admin_id']]);
                            $_SESSION['admin_username'] = $username;
                            $message = '用户名更新成功！';
                            logActivity('update_profile', "New username: {$username}");
                            
                            // 重新获取用户信息
                            $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
                            $stmt->execute([$_SESSION['admin_id']]);
                            $currentAdmin = $stmt->fetch();
                        }
                    } catch (PDOException $e) {
                        $error = '更新失败，请稍后重试';
                        error_log("Update username error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'change_password':
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    $error = '所有密码字段都不能为空';
                } elseif (!password_verify($currentPassword, $currentAdmin['password'])) {
                    $error = '当前密码错误';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = '新密码和确认密码不一致';
                } elseif (strlen($newPassword) < 6) {
                    $error = '新密码长度不能少于6位';
                } else {
                    try {
                        $hashedPassword = password_hash($newPassword, PASSWORD_HASH_ALGO);
                        $stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
                        $stmt->execute([$hashedPassword, $_SESSION['admin_id']]);
                        $message = '密码修改成功！';
                        logActivity('change_password', 'Password changed successfully');
                    } catch (PDOException $e) {
                        $error = '密码修改失败，请稍后重试';
                        error_log("Change password error: " . $e->getMessage());
                    }
                }
                break;
        }
    }
}

// 获取登录历史
try {
    $stmt = $db->prepare("
        SELECT action, details, ip_address, created_at 
        FROM activity_logs 
        WHERE admin_id = ? AND action IN ('login', 'logout', 'login_failed') 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['admin_id']]);
    $loginHistory = $stmt->fetchAll();
} catch (PDOException $e) {
    $loginHistory = [];
    error_log("Get login history error: " . $e->getMessage());
}

// 获取操作统计
try {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_actions,
            COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_actions,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as week_actions
        FROM activity_logs 
        WHERE admin_id = ?
    ");
    $stmt->execute([$_SESSION['admin_id']]);
    $actionStats = $stmt->fetch();
} catch (PDOException $e) {
    $actionStats = ['total_actions' => 0, 'today_actions' => 0, 'week_actions' => 0];
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人设置 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-user-cog"></i> 个人设置</h1>
                <p>管理您的账户信息和安全设置</p>
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
            
            <div class="profile-grid">
                <!-- 账户信息 -->
                <div class="profile-card">
                    <div class="card-header">
                        <h3><i class="fas fa-user"></i> 账户信息</h3>
                    </div>
                    <div class="card-body">
                        <div class="profile-avatar">
                            <div class="avatar-circle">
                                <i class="fas <?php echo $currentAdmin['role'] === 'super_admin' ? 'fa-crown' : 'fa-user-tie'; ?>"></i>
                            </div>
                            <div class="avatar-info">
                                <h4><?php echo sanitize($currentAdmin['username']); ?></h4>
                                <span class="role-badge <?php echo $currentAdmin['role']; ?>">
                                    <?php echo $currentAdmin['role'] === 'super_admin' ? '超级管理员' : '管理员'; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="account-details">
                            <div class="detail-item">
                                <label>账户ID</label>
                                <span><?php echo $currentAdmin['id']; ?></span>
                            </div>
                            <div class="detail-item">
                                <label>创建时间</label>
                                <span><?php echo date('Y-m-d H:i:s', strtotime($currentAdmin['created_at'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <label>最后更新</label>
                                <span><?php echo date('Y-m-d H:i:s', strtotime($currentAdmin['updated_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 修改用户名 -->
                <div class="profile-card">
                    <div class="card-header">
                        <h3><i class="fas fa-edit"></i> 修改用户名</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="form-group">
                                <label for="username">新用户名</label>
                                <input type="text" id="username" name="username" class="form-control" 
                                       value="<?php echo sanitize($currentAdmin['username']); ?>" required>
                                <small class="form-help">用户名只能包含字母、数字和下划线</small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> 更新用户名
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- 修改密码 -->
                <div class="profile-card">
                    <div class="card-header">
                        <h3><i class="fas fa-lock"></i> 修改密码</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="changePasswordForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="form-group">
                                <label for="current_password">当前密码</label>
                                <div class="password-input">
                                    <input type="password" id="current_password" name="current_password" 
                                           class="form-control" required>
                                    <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password">新密码</label>
                                <div class="password-input">
                                    <input type="password" id="new_password" name="new_password" 
                                           class="form-control" required minlength="6">
                                    <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="form-help">密码长度至少6位</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">确认新密码</label>
                                <div class="password-input">
                                    <input type="password" id="confirm_password" name="confirm_password" 
                                           class="form-control" required minlength="6">
                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-key"></i> 修改密码
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- 活动统计 -->
                <div class="profile-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> 活动统计</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $actionStats['total_actions']; ?></div>
                                <div class="stat-label">总操作数</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $actionStats['today_actions']; ?></div>
                                <div class="stat-label">今日操作</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $actionStats['week_actions']; ?></div>
                                <div class="stat-label">本周操作</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 登录历史 -->
                <div class="profile-card wide">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> 最近登录记录</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($loginHistory)): ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <p>暂无登录记录</p>
                            </div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($loginHistory as $record): ?>
                                    <div class="history-item">
                                        <div class="history-icon">
                                            <i class="fas <?php 
                                                echo $record['action'] === 'login' ? 'fa-sign-in-alt text-success' : 
                                                    ($record['action'] === 'logout' ? 'fa-sign-out-alt text-info' : 'fa-exclamation-triangle text-danger'); 
                                            ?>"></i>
                                        </div>
                                        <div class="history-content">
                                            <div class="history-action">
                                                <?php 
                                                echo $record['action'] === 'login' ? '登录成功' : 
                                                    ($record['action'] === 'logout' ? '退出登录' : '登录失败'); 
                                                ?>
                                            </div>
                                            <div class="history-details">
                                                IP: <?php echo $record['ip_address'] ?: '未知'; ?> • 
                                                <?php echo date('Y-m-d H:i:s', strtotime($record['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="scripts.js"></script>
    <script>
        // 密码显示/隐藏切换
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const button = field.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // 密码确认验证
        document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('新密码和确认密码不一致！');
                return false;
            }
            
            if (newPassword.length < 6) {
                e.preventDefault();
                alert('密码长度不能少于6位！');
                return false;
            }
        });
        
        // 用户名验证
        document.getElementById('username').addEventListener('input', function() {
            const username = this.value;
            const pattern = /^[a-zA-Z0-9_]+$/;
            
            if (username && !pattern.test(username)) {
                this.setCustomValidity('用户名只能包含字母、数字和下划线');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
    
    <style>
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }
        
        .profile-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .profile-card.wide {
            grid-column: 1 / -1;
        }
        
        .card-header {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            padding: 20px;
        }
        
        .card-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .profile-avatar {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .avatar-circle {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
        }
        
        .avatar-info h4 {
            margin: 0 0 8px 0;
            color: #1e293b;
            font-size: 24px;
        }
        
        .role-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .role-badge.super_admin {
            background: #fef3c7;
            color: #92400e;
        }
        
        .role-badge.admin {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .account-details {
            display: grid;
            gap: 15px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .detail-item:last-child {
            border-bottom: none;
        }
        
        .detail-item label {
            font-weight: 600;
            color: #64748b;
        }
        
        .detail-item span {
            color: #1e293b;
        }
        
        .password-input {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 5px;
        }
        
        .password-toggle:hover {
            color: #4f46e5;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: 10px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #4f46e5;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748b;
        }
        
        .history-list {
            display: grid;
            gap: 15px;
        }
        
        .history-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
        }
        
        .history-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .history-content {
            flex: 1;
        }
        
        .history-action {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 5px;
        }
        
        .history-details {
            font-size: 14px;
            color: #64748b;
        }
        
        .text-success { color: #10b981; }
        .text-info { color: #3b82f6; }
        .text-danger { color: #ef4444; }
        
        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-avatar {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</body>
</html>