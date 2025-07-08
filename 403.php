<?php
/**
 * 403 Forbidden 错误页面
 */

http_response_code(403);

// 尝试加载配置文件
$appName = '教学排课系统';
if (file_exists('config.php')) {
    try {
        require_once 'config.php';
        $appName = APP_NAME;
    } catch (Exception $e) {
        // 忽略配置文件错误
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>访问被拒绝 - <?php echo $appName; ?></title>
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

        .error-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 60px 40px;
            text-align: center;
            max-width: 500px;
            width: 100%;
        }

        .error-icon {
            font-size: 80px;
            color: #e74c3c;
            margin-bottom: 30px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        h1 {
            color: #2c3e50;
            font-size: 32px;
            margin-bottom: 15px;
        }

        .error-code {
            color: #e74c3c;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
        }

        p {
            color: #7f8c8d;
            margin-bottom: 20px;
            line-height: 1.6;
            font-size: 16px;
        }

        .error-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #e74c3c;
        }

        .error-details h3 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .error-details ul {
            text-align: left;
            color: #7f8c8d;
            padding-left: 20px;
        }

        .error-details li {
            margin-bottom: 8px;
        }

        .action-buttons {
            margin-top: 30px;
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(52, 152, 219, 0.3);
        }

        .btn-secondary {
            background: #ecf0f1;
            color: #2c3e50;
        }

        .btn-secondary:hover {
            background: #d5dbdb;
            transform: translateY(-2px);
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ecf0f1;
            color: #95a5a6;
            font-size: 14px;
        }

        @media (max-width: 480px) {
            .error-container {
                padding: 40px 20px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .error-icon {
                font-size: 60px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-ban"></i>
        </div>
        
        <div class="error-code">403</div>
        <h1>访问被拒绝</h1>
        <p>抱歉，您没有权限访问此资源。这可能是由于以下原因造成的：</p>
        
        <div class="error-details">
            <h3>可能的原因：</h3>
            <ul>
                <li>您没有登录系统</li>
                <li>您的账户没有足够的权限</li>
                <li>会话已过期，需要重新登录</li>
                <li>尝试访问受保护的系统文件</li>
                <li>服务器配置限制了访问</li>
            </ul>
        </div>
        
        <div class="action-buttons">
            <a href="login.php" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i>
                前往登录
            </a>
            <a href="javascript:history.back()" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                返回上页
            </a>
        </div>
        
        <div class="footer">
            <p>&copy; 2025 <?php echo $appName; ?></p>
            <p>如果问题持续存在，请联系系统管理员</p>
        </div>
    </div>

    <script>
        // 自动检测是否应该跳转到登录页面
        setTimeout(function() {
            if (confirm('是否跳转到登录页面？')) {
                window.location.href = 'login.php';
            }
        }, 5000);
    </script>
</body>
</html>