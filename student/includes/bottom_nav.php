<?php
// เช็คว่าตอนนี้อยู่หน้าไหน เพื่อให้ไอคอนเมนูนั้นเป็นสีเขียว (Active)
$current_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="mb-bottom-nav">
    <a href="user.php" class="mb-nav-item <?php echo ($current_page == 'user.php') ? 'active' : ''; ?>">
        <i class="fas fa-home"></i>
        หน้าหลัก
    </a>
    <a href="history.php" class="mb-nav-item <?php echo ($current_page == 'history.php') ? 'active' : ''; ?>">
        <i class="fas fa-history"></i>
        ประวัติ
    </a>
    <a href="redeem.php" class="mb-nav-item <?php echo ($current_page == 'redeem.php') ? 'active' : ''; ?>">
        <i class="fas fa-exchange-alt"></i>
        คำร้อง
    </a>
    <a href="my_qr.php" class="mb-nav-item <?php echo ($current_page == 'my_qr.php') ? 'active' : ''; ?>">
        <i class="fas fa-qrcode"></i>
        คิวอาร์โค้ด
    </a>
    <a href="profile.php" class="mb-nav-item <?php echo ($current_page == 'profile.php') ? 'active' : ''; ?>">
        <i class="fas fa-user"></i>
        โปรไฟล์
    </a>
    <a href="../logout.php" class="mb-nav-item <?php echo ($current_page == '../logout.php') ? 'active' : ''; ?>" onclick="return confirm('ยืนยันการออกจากระบบ?');">
        <i class="fas fa-sign-out-alt"></i>
        ออกจากระบบ
    </a>
</nav>
</html>