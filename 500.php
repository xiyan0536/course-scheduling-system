<?php
/**
 * 500 Internal Server Error 错误页面
 */

http_response_code(500);

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
    <title>服务器错误 - <?php echo $appName; ?></title>
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
            max-width: 600px;
            width: 100%;
        }

        .error-icon {
            font-size: 80px;
            color: #e74c3c;
            margin-bottom: 30px;
            animation: shake 0.5s infinite alternate;
        }

        @keyframes shake {
            0% { transform: translateX(0); }
            100% { transform: translateX(10px); }
        }

        h1 {
            color: #2c3e50;
            font-size: 32px;
            margin-bottom: 15px;
        }

        .error-code {
            color: #e74c3c;
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

        .troubleshooting {
            background: #fff3cd;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #ffc107;
        }

        .troubleshooting h3 {
            color: #856404;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .troubleshooting ol {
            text-align: left;
            color: #856404;
            padding-left: 20px;
        }

        .troubleshooting li {
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

        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(243, 156, 18, 0.3);
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

        .error-id {
            font-family: monospace;
            background: #ecf0f1;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            color: #2c3e50;
            margin-top: 10px;
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
            <i class="fas fa-server"></i>
        </div>
        
        <div class="error-code">500</div>
        <h1>服务器内部错误</h1>
        <p>抱歉，服务器遇到了一个错误，无法完成您的请求。我们的技术团队已经收到通知。</p>
        
        <div class="error-details">
            <h3>可能的原因：</h3>
            <ul>
                <li>服务器配置错误</li>
                <li>数据库连接问题</li>
                <li>PHP脚本错误</li>
                <li>服务器资源不足</li>
                <li>第三方服务不可用</li>
            </ul>
        </div>
        
        <div class="troubleshooting">
            <h3>故障排除步骤：</h3>
            <ol>
                <li>刷新页面重试</li>
                <li>清除浏览器缓存</li>
                <li>检查网络连接</li>
                <li>稍后再试</li>
                <li>联系系统管理员</li>
            </ol>
        </div>
        
        <div class="action-buttons">
            <a href="javascript:location.reload()" class="btn btn-primary">
                <i class="fas fa-redo"></i>
                刷新页面
            </a>
            <a href="index.php" class="btn btn-warning">
                <i class="fas fa-home"></i>
                返回首页
            </a>
            <a href="javascript:history.back()" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                返回上页
            </a>
        </div>
        
        <div class="error-id">
            错误ID: <?php echo date('YmdHis') . '-' . uniqid(); ?>
        </div>
        
        <div class="footer">
            <p>&copy; 2025 <?php echo $appName; ?></p>
            <p>如果问题持续存在，请将错误ID提供给技术支持</p>
        </div>
    </div>

    <script>
        // 记录错误信息
        console.error('500 Internal Server Error at:', new Date().toISOString());
        console.error('URL:', window.location.href);
        console.error('User Agent:', navigator.userAgent);
        
        // 自动重试机制（3次）
        let retryCount = 0;
        const maxRetries = 3;
        
        function autoRetry() {
            if (retryCount < maxRetries) {
                retryCount++;
                setTimeout(function() {
                    console.log(`Auto retry attempt ${retryCount}/${maxRetries}`);
                    location.reload();
                }, 5000 * retryCount); // 递增延迟
            }
        }
        
        // 可以取消注释以启用自动重试
        // autoRetry();
    </script>
</body>
</html>