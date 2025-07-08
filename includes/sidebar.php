<?php
// 获取当前页面文件名
$currentPage = basename($_SERVER['PHP_SELF']);

// 菜单项配置
$menuItems = [
    [
        'title' => '系统概览',
        'icon' => 'fas fa-tachometer-alt',
        'url' => 'dashboard.php',
        'active' => $currentPage === 'dashboard.php'
    ],
    [
        'title' => '排课管理',
        'icon' => 'fas fa-calendar-alt',
        'items' => [
            [
                'title' => '课程排课',
                'icon' => 'fas fa-calendar-plus',
                'url' => 'schedule.php',
                'active' => $currentPage === 'schedule.php'
            ],
            [
                'title' => '查看课表',
                'icon' => 'fas fa-table',
                'url' => 'schedule_view.php',
                'active' => $currentPage === 'schedule_view.php'
            ],
            [
                'title' => 'AI辅助排课',
                'icon' => 'fas fa-robot',
                'url' => 'ai_schedule.php',
                'active' => $currentPage === 'ai_schedule.php'
            ]
        ]
    ],
    [
        'title' => '基础数据',
        'icon' => 'fas fa-database',
        'items' => [
            [
                'title' => '教师管理',
                'icon' => 'fas fa-chalkboard-teacher',
                'url' => 'teachers.php',
                'active' => $currentPage === 'teachers.php'
            ],
            [
                'title' => '课程管理',
                'icon' => 'fas fa-book',
                'url' => 'courses.php',
                'active' => $currentPage === 'courses.php'
            ],
            [
                'title' => '班级管理',
                'icon' => 'fas fa-users',
                'url' => 'classes.php',
                'active' => $currentPage === 'classes.php'
            ],
            [
                'title' => '教室管理',
                'icon' => 'fas fa-door-open',
                'url' => 'classrooms.php',
                'active' => $currentPage === 'classrooms.php'
            ]
        ]
    ]
];

// 如果是超级管理员，添加系统管理菜单
if ($_SESSION['admin_role'] === 'super_admin') {
    $menuItems[] = [
        'title' => '系统管理',
        'icon' => 'fas fa-cogs',
        'items' => [
            [
                'title' => '管理员管理',
                'icon' => 'fas fa-users-cog',
                'url' => 'admin_manage.php',
                'active' => $currentPage === 'admin_manage.php'
            ],
            [
                'title' => '系统日志',
                'icon' => 'fas fa-file-alt',
                'url' => 'system_logs.php',
                'active' => $currentPage === 'system_logs.php'
            ]
        ]
    ];
}
?>

<aside class="sidebar" id="sidebar">
    <nav class="nav-menu">
        <?php foreach ($menuItems as $item): ?>
            <?php if (isset($item['items'])): ?>
                <!-- 有子菜单的项目 -->
                <div class="nav-item">
                    <div class="nav-group-header" onclick="toggleSubmenu(this)">
                        <i class="<?php echo $item['icon']; ?>"></i>
                        <span><?php echo $item['title']; ?></span>
                        <i class="fas fa-chevron-down nav-arrow"></i>
                    </div>
                    <div class="nav-submenu">
                        <?php foreach ($item['items'] as $subItem): ?>
                            <a href="<?php echo $subItem['url']; ?>" 
                               class="nav-link nav-sublink <?php echo $subItem['active'] ? 'active' : ''; ?>">
                                <i class="<?php echo $subItem['icon']; ?>"></i>
                                <?php echo $subItem['title']; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- 单独的菜单项 -->
                <div class="nav-item">
                    <a href="<?php echo $item['url']; ?>" 
                       class="nav-link <?php echo $item['active'] ? 'active' : ''; ?>">
                        <i class="<?php echo $item['icon']; ?>"></i>
                        <?php echo $item['title']; ?>
                    </a>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    
    <!-- 快捷信息 -->
    <div class="sidebar-footer">
        <div class="quick-info">
            <div class="semester-info">
                <i class="fas fa-calendar-check"></i>
                <span>当前学期</span>
                <div class="semester-name"><?php echo getCurrentSemester(); ?></div>
            </div>
        </div>
    </div>
</aside>

<style>
.nav-group-header {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    color: #64748b;
    cursor: pointer;
    border-radius: 10px;
    transition: all 0.3s ease;
    font-weight: 500;
    user-select: none;
}

.nav-group-header:hover {
    background: #f1f5f9;
    color: #4f46e5;
}

.nav-group-header i:first-child {
    margin-right: 12px;
    width: 20px;
    text-align: center;
}

.nav-group-header .nav-arrow {
    margin-left: auto;
    transition: transform 0.3s ease;
    font-size: 12px;
}

.nav-group-header.expanded .nav-arrow {
    transform: rotate(180deg);
}

.nav-submenu {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
    margin-left: 15px;
}

.nav-submenu.expanded {
    max-height: 300px;
}

.nav-sublink {
    margin: 2px 0;
    font-size: 14px;
    padding: 8px 15px;
}

.nav-sublink i {
    font-size: 14px;
}

.sidebar-footer {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 20px;
    border-top: 1px solid #e2e8f0;
    background: white;
}

.quick-info {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    padding: 15px;
    border-radius: 10px;
}

.semester-info {
    text-align: center;
    color: #64748b;
}

.semester-info i {
    font-size: 20px;
    margin-bottom: 5px;
    color: #4f46e5;
    display: block;
}

.semester-info span {
    font-size: 12px;
    display: block;
    margin-bottom: 5px;
}

.semester-name {
    font-weight: 600;
    color: #1e293b;
    font-size: 13px;
}

/* 移动端菜单切换 */
.menu-toggle {
    display: none;
    background: none;
    border: none;
    color: white;
    font-size: 20px;
    cursor: pointer;
}

@media (max-width: 768px) {
    .menu-toggle {
        display: block;
    }
    
    .sidebar-footer {
        position: relative;
        border-top: none;
        margin-top: 20px;
    }
}
</style>

<script>
function toggleSubmenu(header) {
    const submenu = header.nextElementSibling;
    const arrow = header.querySelector('.nav-arrow');
    
    header.classList.toggle('expanded');
    submenu.classList.toggle('expanded');
    
    // 关闭其他展开的子菜单
    document.querySelectorAll('.nav-group-header').forEach(h => {
        if (h !== header && h.classList.contains('expanded')) {
            h.classList.remove('expanded');
            h.nextElementSibling.classList.remove('expanded');
        }
    });
}

// 页面加载时展开包含当前页面的子菜单
document.addEventListener('DOMContentLoaded', function() {
    const activeSublink = document.querySelector('.nav-sublink.active');
    if (activeSublink) {
        const submenu = activeSublink.closest('.nav-submenu');
        const header = submenu.previousElementSibling;
        
        header.classList.add('expanded');
        submenu.classList.add('expanded');
    }
});

// 移动端菜单切换
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('open');
}
</script>