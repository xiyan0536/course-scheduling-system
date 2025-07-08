<?php
require_once 'config.php';
requireLogin();

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
                $name = sanitize($_POST['name'] ?? '');
                $capacity = intval($_POST['capacity'] ?? 0);
                $type = $_POST['type'] ?? '';
                $equipment = sanitize($_POST['equipment'] ?? '');
                
                if (empty($name) || empty($type)) {
                    $error = '教室名称和类型不能为空';
                } else {
                    try {
                        $stmt = $db->prepare("INSERT INTO classrooms (name, capacity, type, equipment) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$name, $capacity, $type, $equipment]);
                        $message = '教室添加成功！';
                        logActivity('add_classroom', "Classroom: {$name}");
                    } catch (PDOException $e) {
                        if ($e->getCode() == '23000') {
                            $error = '教室名称已存在！';
                        } else {
                            $error = '添加失败，请稍后重试';
                            error_log("Add classroom error: " . $e->getMessage());
                        }
                    }
                }
                break;
                
            case 'edit':
                $id = intval($_POST['id'] ?? 0);
                $name = sanitize($_POST['name'] ?? '');
                $capacity = intval($_POST['capacity'] ?? 0);
                $type = $_POST['type'] ?? '';
                $equipment = sanitize($_POST['equipment'] ?? '');
                
                if (empty($name) || empty($type)) {
                    $error = '教室名称和类型不能为空';
                } else {
                    try {
                        $stmt = $db->prepare("UPDATE classrooms SET name=?, capacity=?, type=?, equipment=? WHERE id=?");
                        $stmt->execute([$name, $capacity, $type, $equipment, $id]);
                        $message = '教室信息更新成功！';
                        logActivity('edit_classroom', "Classroom ID: {$id}, Name: {$name}");
                    } catch (PDOException $e) {
                        $error = '更新失败，请稍后重试';
                        error_log("Edit classroom error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                try {
                    // 检查是否有相关的课程安排
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM course_class_assignments WHERE classroom_id = ?");
                    $stmt->execute([$id]);
                    $assignmentCount = $stmt->fetch()['count'];
                    
                    if ($assignmentCount > 0) {
                        $error = '该教室已有课程安排，无法删除！';
                    } else {
                        $stmt = $db->prepare("DELETE FROM classrooms WHERE id = ?");
                        $stmt->execute([$id]);
                        $message = '教室删除成功！';
                        logActivity('delete_classroom', "Classroom ID: {$id}");
                    }
                } catch (PDOException $e) {
                    $error = '删除失败，请稍后重试';
                    error_log("Delete classroom error: " . $e->getMessage());
                }
                break;
        }
    }
}

// 获取教室列表
$search = sanitize($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (name LIKE ? OR equipment LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($type)) {
    $where .= " AND type = ?";
    $params[] = $type;
}

try {
    // 获取总数
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM classrooms {$where}");
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    $totalPages = ceil($total / $limit);
    
    // 获取教室列表及其使用统计
    $stmt = $db->prepare("
        SELECT c.*, 
               COUNT(DISTINCT cca.id) as assignment_count,
               COUNT(DISTINCT s.id) as schedule_count
        FROM classrooms c 
        LEFT JOIN course_class_assignments cca ON c.id = cca.classroom_id 
        LEFT JOIN schedule s ON cca.id = s.assignment_id
        {$where} 
        GROUP BY c.id 
        ORDER BY c.created_at DESC 
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $classrooms = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Get classrooms error: " . $e->getMessage());
    $classrooms = [];
    $total = 0;
    $totalPages = 1;
}

// 获取编辑的教室信息
$editClassroom = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $db->prepare("SELECT * FROM classrooms WHERE id = ?");
        $stmt->execute([$editId]);
        $editClassroom = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get edit classroom error: " . $e->getMessage());
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>教室管理 - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-door-open"></i> 教室管理</h1>
                <p>管理系统中的所有教室和机房信息</p>
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
            <div class="classroom-stats">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $total; ?></h3>
                        <p>教室总数</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon computer">
                        <i class="fas fa-desktop"></i>
                    </div>
                    <div class="stat-info">
                        <h3>
                            <?php 
                            $stmt = $db->query("SELECT COUNT(*) as count FROM classrooms WHERE type = 'computer_room'");
                            echo $stmt->fetch()['count'];
                            ?>
                        </h3>
                        <p>机房数量</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon ordinary">
                        <i class="fas fa-chalkboard"></i>
                    </div>
                    <div class="stat-info">
                        <h3>
                            <?php 
                            $stmt = $db->query("SELECT COUNT(*) as count FROM classrooms WHERE type = 'ordinary'");
                            echo $stmt->fetch()['count'];
                            ?>
                        </h3>
                        <p>普通教室</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon capacity">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3>
                            <?php 
                            $stmt = $db->query("SELECT SUM(capacity) as total FROM classrooms");
                            echo $stmt->fetch()['total'] ?: 0;
                            ?>
                        </h3>
                        <p>总容量</p>
                    </div>
                </div>
            </div>
            
            <!-- 搜索和筛选 -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <input type="text" name="search" value="<?php echo $search; ?>" 
                               placeholder="搜索教室名称或设备..." class="form-control">
                        
                        <select name="type" class="form-control">
                            <option value="">全部类型</option>
                            <option value="computer_room" <?php echo $type === 'computer_room' ? 'selected' : ''; ?>>机房</option>
                            <option value="ordinary" <?php echo $type === 'ordinary' ? 'selected' : ''; ?>>普通教室</option>
                        </select>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> 搜索
                        </button>
                        
                        <a href="classrooms.php" class="btn btn-outline">
                            <i class="fas fa-refresh"></i> 重置
                        </a>
                    </div>
                </form>
                
                <div class="actions">
                    <button onclick="showAddModal()" class="btn btn-primary">
                        <i class="fas fa-plus"></i> 添加教室
                    </button>
                    <button onclick="checkAvailability()" class="btn btn-outline">
                        <i class="fas fa-clock"></i> 查看空闲时间
                    </button>
                </div>
            </div>
            
            <!-- 教室列表 -->
            <div class="classroom-grid">
                <?php if (empty($classrooms)): ?>
                    <div class="empty-state-full">
                        <i class="fas fa-door-open"></i>
                        <h3>暂无教室数据</h3>
                        <p>请添加第一个教室开始使用系统</p>
                        <button onclick="showAddModal()" class="btn btn-primary">添加教室</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($classrooms as $classroom): ?>
                        <div class="classroom-card">
                            <div class="classroom-header">
                                <div class="classroom-icon <?php echo $classroom['type']; ?>">
                                    <i class="fas <?php echo $classroom['type'] === 'computer_room' ? 'fa-desktop' : 'fa-chalkboard'; ?>"></i>
                                </div>
                                <div class="classroom-title">
                                    <h4><?php echo sanitize($classroom['name']); ?></h4>
                                    <span class="classroom-type">
                                        <?php echo $classroom['type'] === 'computer_room' ? '机房' : '普通教室'; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="classroom-info">
                                <div class="info-item">
                                    <i class="fas fa-users"></i>
                                    <span>容量：<?php echo $classroom['capacity'] ?: '未设置'; ?> 人</span>
                                </div>
                                
                                <?php if ($classroom['equipment']): ?>
                                    <div class="info-item">
                                        <i class="fas fa-tools"></i>
                                        <span>设备：<?php echo sanitize($classroom['equipment']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="info-item">
                                    <i class="fas fa-calendar-check"></i>
                                    <span>课程安排：<?php echo $classroom['assignment_count']; ?> 个</span>
                                </div>
                            </div>
                            
                            <div class="classroom-status">
                                <?php if ($classroom['schedule_count'] > 0): ?>
                                    <span class="status-badge busy">使用中</span>
                                <?php else: ?>
                                    <span class="status-badge free">空闲</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="classroom-actions">
                                <button onclick="viewClassroomSchedule(<?php echo $classroom['id']; ?>)" 
                                        class="btn btn-sm btn-info" title="查看使用情况">
                                    <i class="fas fa-calendar"></i>
                                </button>
                                <button onclick="editClassroom(<?php echo $classroom['id']; ?>)" 
                                        class="btn btn-sm btn-outline" title="编辑">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="deleteClassroom(<?php echo $classroom['id']; ?>)" 
                                        class="btn btn-sm btn-danger" title="删除">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- 分页 -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $queryParams = $_GET;
                    for ($i = 1; $i <= $totalPages; $i++):
                        $queryParams['page'] = $i;
                        $url = '?' . http_build_query($queryParams);
                    ?>
                        <a href="<?php echo $url; ?>" 
                           class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- 添加/编辑教室模态框 -->
    <div id="classroomModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">添加教室</h3>
                <button onclick="closeModal()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="classroomForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="classroomId" value="">
                
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="classroomName">教室名称 <span class="required">*</span></label>
                            <input type="text" id="classroomName" name="name" class="form-control" 
                                   required placeholder="例如：6A09、A101">
                        </div>
                        
                        <div class="form-group">
                            <label for="classroomType">教室类型 <span class="required">*</span></label>
                            <select id="classroomType" name="type" class="form-control" required>
                                <option value="">请选择类型</option>
                                <option value="computer_room">机房</option>
                                <option value="ordinary">普通教室</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="capacity">容量（人数）</label>
                        <input type="number" id="capacity" name="capacity" class="form-control" 
                               min="1" max="200" placeholder="30">
                    </div>
                    
                    <div class="form-group">
                        <label for="equipment">设备配置</label>
                        <textarea id="equipment" name="equipment" class="form-control" 
                                  rows="3" placeholder="请描述教室的设备配置，如：投影仪、音响、电脑等..."></textarea>
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
            document.getElementById('modalTitle').textContent = '添加教室';
            document.getElementById('formAction').value = 'add';
            document.getElementById('classroomId').value = '';
            document.getElementById('classroomForm').reset();
            document.getElementById('classroomModal').style.display = 'block';
        }
        
        // 编辑教室
        function editClassroom(id) {
            window.location.href = `classrooms.php?edit=${id}`;
        }
        
        <?php if ($editClassroom): ?>
        // 如果有编辑数据，显示编辑模态框
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('modalTitle').textContent = '编辑教室';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('classroomId').value = '<?php echo $editClassroom['id']; ?>';
            document.getElementById('classroomName').value = '<?php echo addslashes($editClassroom['name']); ?>';
            document.getElementById('classroomType').value = '<?php echo $editClassroom['type']; ?>';
            document.getElementById('capacity').value = '<?php echo $editClassroom['capacity']; ?>';
            document.getElementById('equipment').value = '<?php echo addslashes($editClassroom['equipment']); ?>';
            document.getElementById('classroomModal').style.display = 'block';
        });
        <?php endif; ?>
        
        // 删除教室
        function deleteClassroom(id) {
            if (confirm('确定要删除这个教室吗？删除后无法恢复！')) {
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
        
        // 查看教室使用情况
        function viewClassroomSchedule(id) {
            window.open(`schedule_view.php?classroom=${id}`, '_blank');
        }
        
        // 查看空闲时间
        function checkAvailability() {
            window.open('classroom_availability.php', '_blank');
        }
        
        // 关闭模态框
        function closeModal() {
            document.getElementById('classroomModal').style.display = 'none';
            if (window.location.search.includes('edit=')) {
                window.location.href = 'classrooms.php';
            }
        }
        
        // 点击外部关闭模态框
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('classroomModal');
            if (e.target === modal) {
                closeModal();
            }
        });
    </script>
    
    <style>
        .classroom-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .classroom-stats .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .classroom-stats .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
        }
        
        .classroom-stats .stat-icon.computer { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
        .classroom-stats .stat-icon.ordinary { background: linear-gradient(135deg, #10b981, #047857); }
        .classroom-stats .stat-icon.capacity { background: linear-gradient(135deg, #f59e0b, #d97706); }
        
        .classroom-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .classroom-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .classroom-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .classroom-header {
            padding: 20px 20px 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .classroom-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .classroom-icon.computer_room {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
        }
        
        .classroom-icon.ordinary {
            background: linear-gradient(135deg, #10b981, #047857);
        }
        
        .classroom-title h4 {
            margin: 0;
            color: #1e293b;
            font-size: 18px;
        }
        
        .classroom-type {
            font-size: 12px;
            color: #64748b;
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 4px;
            margin-top: 4px;
            display: inline-block;
        }
        
        .classroom-info {
            padding: 15px 20px;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 14px;
            color: #64748b;
        }
        
        .info-item:last-child {
            margin-bottom: 0;
        }
        
        .info-item i {
            width: 16px;
            color: #94a3b8;
        }
        
        .classroom-status {
            position: absolute;
            top: 15px;
            right: 15px;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.busy {
            background: #fef2f2;
            color: #dc2626;
        }
        
        .status-badge.free {
            background: #dcfce7;
            color: #166534;
        }
        
        .classroom-actions {
            padding: 15px 20px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        
        .empty-state-full {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .empty-state-full i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .empty-state-full h3 {
            margin-bottom: 10px;
            color: #374151;
        }
        
        .required {
            color: #ef4444;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .classroom-grid {
                grid-template-columns: 1fr;
            }
            
            .classroom-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .classroom-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</body>
</html>