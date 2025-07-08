<?php
/**
 * 教学排课系统 - 主入口文件
 * 处理路由和访问控制
 */

require_once 'config.php';

// 检查数据库连接
try {
    $db = Database::getInstance()->getConnection();
    // 简单的连接测试
    $db->query("SELECT 1");
} catch (Exception $e) {
    // 数据库连接失败，显示错误页面
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>系统错误 - <?php echo APP_NAME; ?></title>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 50px; }
            .error-container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); text-align: center; }
            .error-icon { font-size: 48px; color: #e74c3c; margin-bottom: 20px; }
            h1 { color: #2c3e50; margin-bottom: 20px; }
            p { color: #7f8c8d; margin-bottom: 30px; line-height: 1.6; }
            .btn { display: inline-block; background: #3498db; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1>数据库连接失败</h1>
            <p>无法连接到数据库服务器。请检查数据库配置或联系系统管理员。</p>
            <p><strong>可能的原因：</strong></p>
            <ul style="text-align: left; color: #7f8c8d;">
                <li>数据库服务器未启动</li>
                <li>数据库连接配置错误</li>
                <li>数据库用户权限不足</li>
                <li>数据库不存在</li>
            </ul>
            <a href="javascript:location.reload()" class="btn">重新尝试</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 如果用户已登录，跳转到仪表盘
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// 如果用户未登录，跳转到登录页面
header('Location: login.php');
exit;
?>