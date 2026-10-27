
 
 <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
        <nav class="sidebar-menu">
            <a href="superadmin.php" class="menu-item <?php echo ($current_page == 'superadmin.php') ? 'active' : ''; ?>">
                <i class="fas fa-th-large"></i> แดชบอร์ด
            </a>
            <a href="deposit.php" class="menu-item <?php echo ($current_page == 'deposit.php') ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i> บันทึกฝาก
            </a>
            <a href="history.php" class="menu-item <?php echo ($current_page == 'history.php') ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> ประวัติการฝาก
            </a>
            <a href="logs.php" class="menu-item <?php echo ($current_page == 'logs.php') ? 'active' : ''; ?>">
                <i class="fas fa-list-alt"></i> ประวัติการใช้งาน
            </a>
            <a href="requests.php" class="menu-item <?php echo ($current_page == 'requests.php') ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i> รายการคำขอ
            </a>
            <a href="students.php" class="menu-item <?php echo ($current_page == 'students.php') ? 'active' : ''; ?>">
                <i class="fas fa-user-graduate"></i> ข้อมูลนักเรียน
            </a>
            <a href="users.php" class="menu-item <?php echo ($current_page == 'users.php') ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> จัดการผู้ใช้
            </a>
            <a href="pricing.php" class="menu-item <?php echo ($current_page == 'pricing.php') ? 'active' : ''; ?>">
                <i class="fas fa-tags"></i> ตั้งค่าราคา
            </a>
             <a href="settings.php" class="menu-item <?php echo ($current_page == 'settings.php') ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i> ตั้งค่าระบบ
            </a>
            <a href="backup.php" class="menu-item <?php echo ($current_page == 'backup.php') ? 'active' : ''; ?>">
                <i class="fas fa-database"></i> สำรองข้อมูล
            </a>
            
        </nav>