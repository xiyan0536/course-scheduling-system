<?php
require_once 'config.php';
requireSuperAdmin(); // 只有超级管理员可以查看系统日志

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// 处理日志清理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = '无效的请求';
    } else {
        switch ($action) {
            case 'clear_old_logs':
                $days = intval($_POST['days'] ?? 30);
                try {
                    $stmt = $db->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $stmt->execute([$days]);
                    $deletedRows = $stmt->rowCount();
                    $message = "成功清理了 {$deletedRows} 条 {$days} 天前的日志记录";
                    logActivity('clear_logs', "Cleared {$deletedRows} logs older than {$days} days");
                } catch (PDOException $e) {
                    $error = '清理日志失败：' . $e->getMessage();
                }
                break;
                
            case 'export_logs':
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                try {
                    exportLogs($db, $startDate, $endDate);
                } catch (Exception $e) {
                    $error = '导出日志失败：' . $e->getMessage();
                }
                break;
        }
    }
}

// 获取筛选参数
$action = sanitize($_GET['action_filter'] ?? '');
$adminId = intval($_GET['admin_id'] ?? 0);
$startDate = sanitize($_GET['start_date'] ?? '');
$endDate = sanitize($_GET['end_date'] ?? '');
$search = sanitize($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

// 构建查询条件
$where = "WHERE 1=1";
$params = [];

if (!empty($action)) {
    $where .= " AND al.action = ?";
    $params[] = $action;
}

if ($adminId > 0) {
    $where .= " AND al.admin_id = ?";
    $params[] = $adminId;
}

if (!empty($startDate)) {
    $where .= " AND DATE(al.created_at) >= ?";
    $params[] = $startDate;
}

if (!empty($endDate)) {
    $where .= " AND DATE(al.created_at) <= ?";
    $params[] = $endDate;
}

if (!empty($search)) {
    $where .= " AND (al.action LIKE ? OR al.details LIKE ? OR al.username LIKE ? OR al.ip_address LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

try {
    // 获取总数
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM activity_logs al {$where}");
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    $totalPages = ceil($total / $limit);
    
    // 获取日志列表
    $stmt = $db->prepare("
        SELECT al.*, a.username as admin_username
        FROM activity_logs al
        LEFT JOIN admins a ON al.admin_id = a.id
        {$where}
        ORDER BY al.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    
    // 获取管理员列表用于筛选
    $adminStmt = $db->query("SELECT id, username FROM admins ORDER BY username");
    $admins = $adminStmt->fetchAll();
    
    // 获取操作类型列表
    $actionStmt = $db->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");
    $actions = $actionStmt->fetchAll();
    
    // 获取统计信息
    $statsStmt = $db->prepare("
        SELECT 
            COUNT(*) as total_logs,
            COUNT(DISTINCT admin_id) as unique_admins,
            COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_logs,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as week_logs,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as month_logs
        FROM activity_logs
        {$where}
    ");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch();
    
} catch (PDOException $e) {
    error_log("Get logs error: " . $e->getMessage());
    $logs = [];
    $admins = [];
    $actions = [];
    $stats = ['total_logs' => 0, 'unique_admins' => 0, 'today_logs' => 0, 'week_logs' => 0, 'month_logs' => 0];
    $total = 0;
    $totalPages = 1;
}

// 导出日志功能
function exportLogs($db, $startDate, $endDate) {
    $where = "WHERE 1=1";
    $params = [];
    
    if (!empty($startDate)) {
        $where .= " AND DATE(al.created_at) >= ?";
        $params[] = $startDate;
    }
    
    if (!empty($endDate)) {
        $where .= " AND DATE(al.created_at) <= ?";
        $params[] = $endDate;
    }
    
    $stmt = $db->prepare("
        SELECT al.*, a.username as admin_username
        FROM activity_logs al
        LEFT JOIN admins a ON al.admin_id = a.id
        {$where}
        ORDER BY al.created_at DESC
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    
    $filename = 'system_logs_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Cache-Control: no-cache, must-revalidate');
    
    $output = fopen('php://output', 'w');
    
    // 写入BOM以支持中文
    fwrite($output, "\xEF\xBB\xBF");
    
    // 写入表头
    fputcsv($output, ['ID', '管理员', '操作', '详情', 'IP地址', '用户代理', '时间']);
    
    // 写入数据
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'],
            $log['admin_username'] ?: $log['username'] ?: 'System',
            $log['action'],
            $log['details'],
            $log['ip_address'],
            $log['user_agent'],
            $log['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统日志 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-file-alt"></i> 系统日志</h1>
                <p>查看和管理系统操作日志</p>
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
            
            <!-- 统计概览 -->
            <div class="logs-stats">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total_logs']); ?></h3>
                        <p>总日志数</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon today">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['today_logs']; ?></h3>
                        <p>今日日志</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon week">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['week_logs']; ?></h3>
                        <p>本周日志</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon month">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['month_logs']; ?></h3>
                        <p>本月日志</p>
                    </div>
                </div>
            </div>
            
            <!-- 筛选和操作 -->
            <div class="logs-controls">
                <form method="GET" class="filter-form">
                    <div class="filter-row">
                        <input type="text" name="search" value="<?php echo $search; ?>" 
                               placeholder="搜索操作、详情、用户或IP..." class="form-control">
                        
                        <select name="action_filter" class="form-control">
                            <option value="">全部操作</option>
                            <?php foreach ($actions as $actionItem): ?>
                                <option value="<?php echo $actionItem['action']; ?>" 
                                        <?php echo $action === $actionItem['action'] ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($actionItem['action']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="admin_id" class="form-control">
                            <option value="">全部管理员</option>
                            <?php foreach ($admins as $admin): ?>
                                <option value="<?php echo $admin['id']; ?>" 
                                        <?php echo $adminId === $admin['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($admin['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-row">
                        <input type="date" name="start_date" value="<?php echo $startDate; ?>" class="form-control">
                        <input type="date" name="end_date" value="<?php echo $endDate; ?>" class="form-control">
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> 筛选
                        </button>
                        
                        <a href="system_logs.php" class="btn btn-outline">
                            <i class="fas fa-refresh"></i> 重置
                        </a>
                    </div>
                </form>
                
                <div class="actions-row">
                    <button onclick="showExportModal()" class="btn btn-success">
                        <i class="fas fa-download"></i> 导出日志
                    </button>
                    <button onclick="showClearModal()" class="btn btn-warning">
                        <i class="fas fa-trash-alt"></i> 清理日志
                    </button>
                    <button onclick="refreshLogs()" class="btn btn-info">
                        <i class="fas fa-sync-alt"></i> 刷新
                    </button>
                </div>
            </div>
            
            <!-- 日志列表 -->
            <div class="logs-container">
                <div class="logs-header">
                    <h3>
                        <i class="fas fa-list"></i> 日志记录 
                        <span class="logs-count">(<?php echo number_format($total); ?> 条记录)</span>
                    </h3>
                </div>
                
                <?php if (empty($logs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <h4>暂无日志记录</h4>
                        <p>没有找到符合条件的日志记录</p>
                    </div>
                <?php else: ?>
                    <div class="logs-table-container">
                        <table class="logs-table">
                            <thead>
                                <tr>
                                    <th>时间</th>
                                    <th>用户</th>
                                    <th>操作</th>
                                    <th>详情</th>
                                    <th>IP地址</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr class="log-row" data-log-id="<?php echo $log['id']; ?>">
                                        <td class="log-time">
                                            <div class="time-display">
                                                <span class="date"><?php echo date('m-d', strtotime($log['created_at'])); ?></span>
                                                <span class="time"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></span>
                                            </div>
                                        </td>
                                        <td class="log-user">
                                            <div class="user-info">
                                                <i class="fas fa-user"></i>
                                                <span><?php echo sanitize($log['admin_username'] ?: $log['username'] ?: 'System'); ?></span>
                                            </div>
                                        </td>
                                        <td class="log-action">
                                            <span class="action-badge <?php echo getActionClass($log['action']); ?>">
                                                <?php echo ucfirst($log['action']); ?>
                                            </span>
                                        </td>
                                        <td class="log-details">
                                            <div class="details-content" title="<?php echo sanitize($log['details']); ?>">
                                                <?php echo sanitize(mb_strimwidth($log['details'], 0, 50, '...')); ?>
                                            </div>
                                        </td>
                                        <td class="log-ip">
                                            <span class="ip-address"><?php echo $log['ip_address'] ?: '-'; ?></span>
                                        </td>
                                        <td class="log-actions">
                                            <button onclick="showLogDetails(<?php echo $log['id']; ?>)" 
                                                    class="btn btn-sm btn-info" title="查看详情">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <!-- 分页 -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php
                        $queryParams = $_GET;
                        unset($queryParams['page']);
                        
                        // 显示页码
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($page > 1):
                            $queryParams['page'] = $page - 1;
                            $url = '?' . http_build_query($queryParams);
                        ?>
                            <a href="<?php echo $url; ?>" class="page-link">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): 
                            $queryParams['page'] = $i;
                            $url = '?' . http_build_query($queryParams);
                        ?>
                            <a href="<?php echo $url; ?>" 
                               class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages):
                            $queryParams['page'] = $page + 1;
                            $url = '?' . http_build_query($queryParams);
                        ?>
                            <a href="<?php echo $url; ?>" class="page-link">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- 导出日志模态框 -->
    <div id="exportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>导出日志</h3>
                <button onclick="closeExportModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="export_logs">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="export_start_date">开始日期</label>
                        <input type="date" id="export_start_date" name="start_date" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="export_end_date">结束日期</label>
                        <input type="date" id="export_end_date" name="end_date" class="form-control">
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        导出格式为CSV，如果不指定日期范围，将导出所有日志记录。
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeExportModal()" class="btn btn-outline">取消</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-download"></i> 导出
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 清理日志模态框 -->
    <div id="clearModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>清理日志</h3>
                <button onclick="closeClearModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" onsubmit="return confirm('确定要清理旧日志吗？此操作无法撤销！')">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="clear_old_logs">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="clear_days">清理多少天前的日志</label>
                        <select id="clear_days" name="days" class="form-control">
                            <option value="30">30天前</option>
                            <option value="60">60天前</option>
                            <option value="90">90天前</option>
                            <option value="180">180天前</option>
                            <option value="365">1年前</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>警告：</strong>清理操作无法撤销，请谨慎操作！
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeClearModal()" class="btn btn-outline">取消</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-trash-alt"></i> 清理
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="scripts.js"></script>
    <script>
        // 显示导出模态框
        function showExportModal() {
            document.getElementById('exportModal').style.display = 'block';
        }
        
        function closeExportModal() {
            document.getElementById('exportModal').style.display = 'none';
        }
        
        // 显示清理模态框
        function showClearModal() {
            document.getElementById('clearModal').style.display = 'block';
        }
        
        function closeClearModal() {
            document.getElementById('clearModal').style.display = 'none';
        }
        
        // 刷新日志
        function refreshLogs() {
            window.location.reload();
        }
        
        // 显示日志详情
        function showLogDetails(logId) {
            // 这里可以实现显示完整日志详情的功能
            alert('日志详情功能开发中...');
        }
        
        // 点击外部关闭模态框
        window.addEventListener('click', function(e) {
            const exportModal = document.getElementById('exportModal');
            const clearModal = document.getElementById('clearModal');
            
            if (e.target === exportModal) {
                closeExportModal();
            }
            if (e.target === clearModal) {
                closeClearModal();
            }
        });
        
        // 自动刷新日志（可选）
        // setInterval(refreshLogs, 30000); // 30秒刷新一次
    </script>
    
    <style>
        .logs-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .logs-stats .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .stat-icon.total { background: linear-gradient(135deg, #4f46e5, #7c3aed); }
        .stat-icon.today { background: linear-gradient(135deg, #10b981, #047857); }
        .stat-icon.week { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-icon.month { background: linear-gradient(135deg, #ef4444, #dc2626); }
        
        .logs-controls {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-row:last-child {
            margin-bottom: 0;
        }
        
        .actions-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .logs-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .logs-header {
            padding: 25px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        
        .logs-header h3 {
            margin: 0;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logs-count {
            color: #64748b;
            font-weight: normal;
            font-size: 14px;
        }
        
        .logs-table-container {
            overflow-x: auto;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .logs-table th,
        .logs-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .logs-table th {
            background: #f8fafc;
            color: #374151;
            font-weight: 600;
            font-size: 14px;
        }
        
        .log-row:hover {
            background: #f8fafc;
        }
        
        .time-display {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .time-display .date {
            font-weight: 600;
            color: #1e293b;
            font-size: 13px;
        }
        
        .time-display .time {
            color: #64748b;
            font-size: 12px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .user-info i {
            color: #64748b;
        }
        
        .action-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .action-badge.login { background: #dcfce7; color: #166534; }
        .action-badge.logout { background: #dbeafe; color: #1e40af; }
        .action-badge.login_failed { background: #fef2f2; color: #dc2626; }
        .action-badge.add_course { background: #fef3c7; color: #92400e; }
        .action-badge.edit_course { background: #e0f2fe; color: #0891b2; }
        .action-badge.delete_course { background: #fecaca; color: #dc2626; }
        .action-badge { background: #f1f5f9; color: #64748b; } /* 默认样式 */
        
        .details-content {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: help;
        }
        
        .ip-address {
            font-family: monospace;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .filter-row {
                grid-template-columns: 1fr;
            }
            
            .actions-row {
                justify-content: center;
            }
            
            .logs-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .logs-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</body>
</html>

<?php
// 获取操作类型的CSS类名
function getActionClass($action) {
    $classes = [
        'login' => 'login',
        'logout' => 'logout', 
        'login_failed' => 'login_failed',
        'add_course' => 'add_course',
        'edit_course' => 'edit_course',
        'delete_course' => 'delete_course',
        'add_teacher' => 'add_course',
        'edit_teacher' => 'edit_course',
        'delete_teacher' => 'delete_course',
        // 可以根据需要添加更多操作类型
    ];
    
    return $classes[$action] ?? '';
}
?>