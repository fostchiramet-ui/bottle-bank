<?php
session_start();
require_once '../db/db.php';

// 1. Strict RBAC: Check if Superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', '1'])) {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();

// Handle Backup Download
if (isset($_POST['backup'])) {
    $tables = [];
    $sql = "SHOW TABLES";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $output = "-- Database Backup: bottlebank_db\n";
    $output .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
    $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // Structure
        $stmt = $pdo->prepare("SHOW CREATE TABLE `$table`");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $output .= "\n\n" . $row[1] . ";\n\n";

        // Data
        $stmt = $pdo->prepare("SELECT * FROM `$table`");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $output .= "INSERT INTO `$table` VALUES(";
            $first = true;
            foreach ($row as $value) {
                if (!$first) $output .= ", ";
                if ($value === null) {
                    $output .= 'NULL';
                } else {
                    $value = addslashes($value);
                    $value = str_replace("\n", "\\n", $value);
                    $output .= "'" . $value . "'";
                }
                $first = false;
            }
            $output .= ");\n";
        }
    }
    
    $output .= "\nSET FOREIGN_KEY_CHECKS=1;";

    $filename = 'backup_bottlebank_' . date('Y-m-d_H-i-s') . '.sql';
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($output));
    echo $output;
    exit();
}

// Get Current User for Sidebar
$user_id = $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = :id");
$stmt_user->execute([':id' => $user_id]);
$current_user = $stmt_user->fetch();

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สำรองข้อมูล - Recycle Bank</title>
    <link rel="stylesheet" href="superadmincss/superadmin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">

    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon"><i class="fas fa-leaf"></i></div>
            <div class="logo-text">
                <span class="txt-pink">Eco</span><span class="txt-green">Bank</span>
                <small>ระบบเจ้าหน้าที่</small>
            </div>
        </div>
        <div class="admin-profile">
            <p class="admin-name"><?php echo h($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></p>
            <p class="admin-role">ผู้ดูแลระบบ</p>
            <span class="badge-admin">SUPER ADMIN</span>
        </div>
        
        <?php include 'includes/bottom_nav.php'; ?>
        <div class="sidebar-footer"><a href="../logout.php" class="menu-item text-muted"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a></div>
    </aside>

    <main class="main-content">
        <header class="page-header">
            <h1>สำรองข้อมูล (Backup)</h1>
            <p class="subtitle">ดาวน์โหลดไฟล์สำรองฐานข้อมูลเพื่อความปลอดภัย</p>
        </header>

        <div class="content-card" style="text-align: center; padding: 50px 20px;">
            <div style="font-size: 4rem; color: var(--green); margin-bottom: 20px;">
                <i class="fas fa-database"></i>
            </div>
            <h2 style="margin-bottom: 10px;">สำรองฐานข้อมูล</h2>
            <p style="color: #666; margin-bottom: 30px; max-width: 500px; margin-left: auto; margin-right: auto;">
                ระบบจะทำการสร้างไฟล์ SQL (.sql) ซึ่งประกอบด้วยโครงสร้างตารางและข้อมูลทั้งหมดในปัจจุบัน<br>
                คุณสามารถนำไฟล์นี้ไปใช้กู้คืนระบบ (Restore) ได้ในกรณีฉุกเฉิน
            </p>
            
            <form method="POST">
                <button type="submit" name="backup" class="btn-primary" style="padding: 15px 30px; font-size: 1.1rem;">
                    <i class="fas fa-download"></i> ดาวน์โหลดไฟล์ Backup
                </button>
            </form>
            
            <div style="margin-top: 30px; font-size: 0.9rem; color: #999;">
                <i class="fas fa-info-circle"></i> ไฟล์ที่ได้จะเป็นนามสกุล .sql (UTF-8 Encoded)
            </div>
        </div>
    </main>
</body>
</html>