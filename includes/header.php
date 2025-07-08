<header class="header">
    <div class="logo">
        <i class="fas fa-calendar-alt"></i>
        <?php echo APP_NAME; ?>
    </div>
    
    <div class="user-info">
        <div class="time">
            <span id="current-time"></span>
        </div>
        
        <div class="user-menu">
            <button class="dropdown-toggle" onclick="toggleUserMenu()">
                <i class="fas fa-user"></i>
                <?php echo $_SESSION['admin_username']; ?>
                <i class="fas fa-chevron-down"></i>
            </button>
            
            <div class="dropdown-menu" id="userMenu" style="display: none;">
                <a href="profile.php" class="dropdown-item">
                    <i class="fas fa-user-cog"></i> 个人设置
                </a>
                <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                    <a href="admin_manage.php" class="dropdown-item">
                        <i class="fas fa-users-cog"></i> 管理员管理
                    </a>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a href="logout.php" class="dropdown-item">
                    <i class="fas fa-sign-out-alt"></i> 退出登录
                </a>
            </div>
        </div>
    </div>
</header>

<style>
.dropdown-menu {
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    min-width: 180px;
    padding: 8px 0;
    z-index: 1000;
    margin-top: 5px;
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    color: #374151;
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 14px;
}

.dropdown-item:hover {
    background: #f1f5f9;
    color: #4f46e5;
}

.dropdown-divider {
    height: 1px;
    background: #e5e7eb;
    margin: 8px 0;
}
</style>

<script>
function toggleUserMenu() {
    const menu = document.getElementById('userMenu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

// 点击外部关闭菜单
document.addEventListener('click', function(e) {
    const userMenu = document.getElementById('userMenu');
    const dropdownToggle = document.querySelector('.dropdown-toggle');
    
    if (!dropdownToggle.contains(e.target) && !userMenu.contains(e.target)) {
        userMenu.style.display = 'none';
    }
});

// 实时时间更新
function updateTime() {
    const now = new Date();
    const timeString = now.toLocaleString('zh-CN', {
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    
    const timeElement = document.getElementById('current-time');
    if (timeElement) {
        timeElement.textContent = timeString;
    }
}

setInterval(updateTime, 1000);
updateTime();
</script>