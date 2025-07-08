<?php
// 数据库配置
define('DB_HOST', 'localhost');
define('DB_NAME', 'course_scheduling');
define('DB_USER', 'course_scheduling');
define('DB_PASS', 'FnKCcc3spCJH3Fxe');
define('DB_CHARSET', 'utf8mb4');

// 应用配置
define('APP_NAME', '教学排课系统');
define('APP_VERSION', '1.0.0');
define('TIMEZONE', 'Asia/Shanghai');

// 安全配置
define('SESSION_NAME', 'course_scheduling_session');
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_HASH_ALGO', PASSWORD_DEFAULT);

// DeepSeek API 配置
define('DEEPSEEK_API_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions');
define('DEEPSEEK_API_KEY', 'sk-efa4d66f47d34fba80c861d4198df4c4'); // 请替换为实际的API密钥
define('DEEPSEEK_MODEL', 'deepseek-chat');

// 文件上传配置
define('UPLOAD_PATH', './uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// 分页配置
define('ITEMS_PER_PAGE', 20);

// 设置时区
date_default_timezone_set(TIMEZONE);

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// 数据库连接类
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}

// 工具函数
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function generateCSRFToken() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function validateCSRFToken($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function isLoggedIn() {
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_role']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireSuperAdmin() {
    requireLogin();
    if ($_SESSION['admin_role'] !== 'super_admin') {
        header('Location: dashboard.php?error=权限不足');
        exit;
    }
}

function redirect($url, $message = null) {
    if ($message) {
        $_SESSION['flash_message'] = $message;
    }
    header("Location: $url");
    exit;
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

// 错误处理
function handleError($errno, $errstr, $errfile, $errline) {
    error_log("Error [$errno]: $errstr in $errfile on line $errline");
    return true;
}

set_error_handler('handleError');

// 异常处理
function handleException($exception) {
    error_log("Uncaught exception: " . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo "系统错误，请稍后重试。";
}

set_exception_handler('handleException');

// 周数到中文的转换
function getDayName($dayNumber) {
    $days = [
        1 => '周一',
        2 => '周二', 
        3 => '周三',
        4 => '周四',
        5 => '周五',
        6 => '周六',
        7 => '周日'
    ];
    return $days[$dayNumber] ?? '';
}

// 时间段名称
function getTimeSlotName($slotNumber) {
    $slots = [
        1 => '第一节课',
        2 => '第二节课',
        3 => '第三节课', 
        4 => '第四节课',
        5 => '第五节课',
        6 => '第六节课'
    ];
    return $slots[$slotNumber] ?? '';
}

// 获取当前学期
function getCurrentSemester() {
    $year = date('Y');
    $month = date('n');
    
    if ($month >= 9 || $month <= 1) {
        return $year . '-' . ($year + 1) . '-1'; // 秋季学期
    } else {
        return ($year - 1) . '-' . $year . '-2'; // 春季学期
    }
}

// 日志记录
function logActivity($action, $details = '') {
    $logFile = './logs/activity.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $adminId = $_SESSION['admin_id'] ?? 'guest';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $logEntry = "[{$timestamp}] Admin:{$adminId} IP:{$ip} Action:{$action} Details:{$details}" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
?>