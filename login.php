<?php
require_once 'config.php';

// 如果已登录，跳转到主页
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    // CSRF验证
    if (!validateCSRFToken($csrfToken)) {
        $error = '无效的请求';
    } elseif (empty($username) || empty($password)) {
        $error = '用户名和密码不能为空';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id, username, password, role FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            
            if ($admin && password_verify($password, $admin['password'])) {
                // 登录成功
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_role'] = $admin['role'];
                
                logActivity('login', "Username: {$username}");
                redirect('dashboard.php', '登录成功！');
            } else {
                $error = '用户名或密码错误';
                logActivity('login_failed', "Username: {$username}");
            }
        } catch (PDOException $e) {
            $error = '登录失败，请稍后重试';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
            backdrop-filter: blur(10px);
        }

        .login-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 24px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .login-form {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f9fafb;
        }

        .form-group input:focus {
            outline: none;
            border-color: #4f46e5;
            background: white;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .form-group i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            margin-top: 12px;
        }

        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #dc2626;
            font-size: 14px;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #6b7280;
            font-size: 12px;
            background: #f9fafb;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            margin-top: 12px;
            cursor: pointer;
            color: #9ca3af;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: #4f46e5;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
            }
            
            .login-header, .login-form {
                padding: 20px;
            }
        }

        .loading {
            display: none;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-calendar-alt"></i> <?php echo APP_NAME; ?></h1>
            <p>管理员登录</p>
        </div>
        
        <div class="login-form">
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required autocomplete="username"
                           value="<?php echo isset($_POST['username']) ? sanitize($_POST['username']) : ''; ?>">
                    <i class="fas fa-user"></i>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                </div>
                
                <button type="submit" class="btn-login" id="loginBtn">
                    <span class="btn-text">登录</span>
                    <div class="loading">
                        <i class="fas fa-spinner"></i>
                    </div>
                </button>
            </form>
        </div>
        
        <div class="footer">
            <p>&copy; 2025 <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></p>
        </div>
    </div>

    <script>
        // 密码显示/隐藏切换
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        // 表单提交loading效果
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            const btnText = btn.querySelector('.btn-text');
            const loading = btn.querySelector('.loading');
            
            btnText.style.opacity = '0';
            loading.style.display = 'block';
            btn.disabled = true;
        });

        // 页面加载时聚焦用户名输入框
        window.addEventListener('load', function() {
            document.getElementById('username').focus();
        });

        // 回车键快捷登录
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('loginForm').submit();
            }
        });
    </script>
</body>
</html>