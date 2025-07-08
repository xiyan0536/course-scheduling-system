<?php
require_once 'config.php';

// 记录退出日志
if (isLoggedIn()) {
    logActivity('logout', "Username: {$_SESSION['admin_username']}");
}

// 清除所有会话数据
session_unset();
session_destroy();

// 重新启动会话
session_start();

// 设置退出成功消息
$_SESSION['flash_message'] = '您已成功退出系统！';

// 重定向到登录页面
header('Location: login.php');
exit;
?>