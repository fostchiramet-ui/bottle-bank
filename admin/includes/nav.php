<?php
// เช็คว่าตอนนี้อยู่หน้าไหน เพื่อให้ไอคอนเมนูนั้นเป็นสีเขียว (Active)
$current_page = basename($_SERVER['PHP_SELF']);
?>

<div class="admin-profile">
        <p class="admin-name"><?php echo h($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></p>
        <p class="admin-role">เจ้าหน้าที่</p>
        <span class="badge-admin">ADMIN</span>
    </div>

    <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
    <nav class="sidebar-menu">
        <a href="admin.php" class="menu-item <?php if($current_page == 'admin.php') echo 'active'; ?>">
            <i class="fas fa-th-large"></i> แดชบอร์ด
        </a>
        <a href="deposit.php" class="menu-item <?php if($current_page == 'deposit.php') echo 'active'; ?>">
            <i class="fas fa-plus-circle"></i> รับฝากขวด
        </a>
        <a href="requests.php" class="menu-item <?php if($current_page == 'requests.php') echo 'active'; ?>">
            <i class="fas fa-clipboard-list"></i> รายการคำขอ
        </a>
        <a href="students.php" class="menu-item <?php if($current_page == 'students.php') echo 'active'; ?>">
            <i class="fas fa-user-graduate"></i> จัดการนักเรียน
        </a>
        <a href="history.php" class="menu-item <?php if($current_page == 'history.php') echo 'active'; ?>">
            <i class="fas fa-history"></i> ประวัติการฝาก
        </a>
        <a href="report.php" class="menu-item <?php if($current_page == 'report.php') echo 'active'; ?>">
            <i class="fas fa-chart-bar"></i> รายงานสรุป
        </a>
       
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="menu-item text-muted">
            <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
        </a>
    </div>
</aside>