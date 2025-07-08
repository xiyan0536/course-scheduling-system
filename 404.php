<?php
/**
 * 404 Not Found 错误页面
 */

http_response_code(404);

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

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>页面未找到 - <?php echo $appName; ?></title>
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
            color: #f39c12;
            margin-bottom: 30px;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }

        h1 {
            color: #2c3e50;
            font-size: 32px;
            margin-bottom: 15px;
        }

        .error-code {
            color: #f39c12;
            font-size: 64px;
            font-weight: bold;
            margin-bottom: 20px;
        }

        p {
            color: #7f8c8d;
            margin-bottom: 20px;
            line-height: 1.6;
            font-size: 16px;
        }

        .request-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #f39c12;
            word-break: break-all;
            font-family: monospace;
            color: #2c3e50;
        }

        .suggestions {
            background: #e8f5e8;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #27ae60;
        }

        .suggestions h3 {
            color: #27ae60;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .suggestions ul {
            text-align: left;
            color: #2c3e50;
            padding-left: 20px;
        }

        .suggestions li {
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

        .btn-success {
            background: linear-gradient(135deg, #27ae60, #229954);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(39, 174, 96, 0.3);
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
            
            .error-code {
                font-size: 48px;
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
            <i class="fas fa-search"></i>
        </div>
        
        <div class="error-code">404</div>
        <h1>页面未找到</h1>
        <p>抱歉，您要访问的页面不存在或已被移动。</p>
        
        <?php if (!empty($requestUri)): ?>
        <div class="request-info">
            请求的路径：<?php echo htmlspecialchars($requestUri); ?>
        </div>
        <?php endif; ?>
        
        <div class="suggestions">
            <h3>建议您：</h3>
            <ul>
                <li>检查URL拼写是否正确</li>
                <li>返回首页重新导航</li>
                <li>使用系统内的链接进行跳转</li>
                <li>如果您是通过书签访问，请更新书签</li>
            </ul>
        </div>
        
        <div class="action-buttons">
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-home"></i>
                返回首页
            </a>
            <a href="login.php" class="btn btn-success">
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
            <p>如需帮助，请联系系统管理员</p>
        </div>
    </div>

    <script>
        // 记录404错误（如果有日志系统）
        console.log('404 Error:', window.location.href);
        
        // 自动跳转倒计时（可选）
        let countdown = 10;
        const countdownElement = document.createElement('p');
        countdownElement.style.color = '#95a5a6';
        countdownElement.style.fontSize = '14px';
        countdownElement.style.marginTop = '20px';
        
        function updateCountdown() {
            if (countdown > 0) {
                countdownElement.textContent = `${countdown} 秒后自动跳转到首页`;
                countdown--;
                setTimeout(updateCountdown, 1000);
            } else {
                window.location.href = 'index.php';
            }
        }
        
        // 可以取消注释以启用自动跳转
        // document.querySelector('.footer').appendChild(countdownElement);
        // updateCountdown();
    </script>
</body>
</html>