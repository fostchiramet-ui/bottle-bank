<?php
require_once 'db/db.php';

echo "<h2>กำลังตั้งค่าฐานข้อมูล...</h2>";

try {
    $pdo = Database::connect();
    echo "<span style='color:green'>✓ เชื่อมต่อฐานข้อมูลสำเร็จ</span><br>";
} catch (PDOException $e) {
    die("<span style='color:red'>✗ การเชื่อมต่อล้มเหลว: " . $e->getMessage() . "</span><br>กรุณาตรวจสอบไฟล์ db/db.php และตรวจสอบว่าได้สร้างฐานข้อมูลชื่อ bottlebank_db แล้วหรือไม่");
}

// ฟังก์ชันสำหรับเพิ่มคอลัมน์ถ้ายังไม่มี
function addColumnIfNotExists($pdo, $table, $column, $definition) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "  + เพิ่มคอลัมน์ <b>$column</b> ในตาราง <b>$table</b> สำเร็จ<br>";
        }
    } catch (PDOException $e) {
        echo "  - ผิดพลาดในการเพิ่มคอลัมน์ $column: " . $e->getMessage() . "<br>";
    }
}

// 1. สร้างตารางหลัก (ถ้ายังไม่มี)
$tables = [
    "users" => "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('user', 'admin', 'superadmin') DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "bottle_entries" => "CREATE TABLE IF NOT EXISTS bottle_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "redemptions" => "CREATE TABLE IF NOT EXISTS redemptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "waste_types" => "CREATE TABLE IF NOT EXISTS waste_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        price DECIMAL(10, 2) DEFAULT 0.00,
        points INT DEFAULT 0,
        unit VARCHAR(20) DEFAULT 'ชิ้น'
    )",
    "system_settings" => "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT
    )",
    "password_resets" => "CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        INDEX (email, token)
    )",
    "price_logs" => "CREATE TABLE IF NOT EXISTS price_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        waste_code VARCHAR(50) NOT NULL,
        old_price DECIMAL(10, 2),
        new_price DECIMAL(10, 2),
        old_points INT,
        new_points INT,
        updated_by INT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "login_logs" => "CREATE TABLE IF NOT EXISTS login_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "✓ ตรวจสอบตาราง <b>$name</b> เรียบร้อย<br>";
    } catch (PDOException $e) {
        echo "<span style='color:red'>✗ สร้างตาราง $name ไม่สำเร็จ: " . $e->getMessage() . "</span><br>";
    }
}

echo "<hr>";
echo "<h3>ตรวจสอบและอัปเดตโครงสร้างตาราง (Columns)</h3>";

// 2. อัปเดตคอลัมน์ในตาราง users
addColumnIfNotExists($pdo, 'users', 'username', 'VARCHAR(100) UNIQUE NULL AFTER id');
addColumnIfNotExists($pdo, 'users', 'first_name', 'VARCHAR(100) NULL AFTER role');
addColumnIfNotExists($pdo, 'users', 'last_name', 'VARCHAR(100) NULL AFTER first_name');
addColumnIfNotExists($pdo, 'users', 'id_code', 'VARCHAR(20) NULL AFTER last_name');
addColumnIfNotExists($pdo, 'users', 'class_group', 'VARCHAR(20) NULL AFTER id_code');
addColumnIfNotExists($pdo, 'users', 'avatar', 'VARCHAR(255) NULL AFTER class_group');
addColumnIfNotExists($pdo, 'users', 'balance', 'DECIMAL(10, 2) DEFAULT 0.00');
addColumnIfNotExists($pdo, 'users', 'points', 'INT DEFAULT 0');
addColumnIfNotExists($pdo, 'users', 'is_active', 'TINYINT(1) DEFAULT 1');
addColumnIfNotExists($pdo, 'users', 'remember_token', 'VARCHAR(64) NULL');
addColumnIfNotExists($pdo, 'users', 'last_activity', 'DATETIME NULL');
addColumnIfNotExists($pdo, 'users', 'google_id', 'VARCHAR(255) NULL UNIQUE AFTER email');
addColumnIfNotExists($pdo, 'users', 'email_verification_token', 'VARCHAR(255) NULL AFTER google_id');
addColumnIfNotExists($pdo, 'users', 'email_verified_at', 'DATETIME NULL AFTER email_verification_token');

// ทำให้คอลัมน์ password สามารถเป็น NULL ได้ สำหรับการ login ผ่าน Google
try {
    $pdo->exec("ALTER TABLE `users` MODIFY `password` VARCHAR(255) NULL");
    echo "  + แก้ไขคอลัมน์ <b>password</b> ในตาราง <b>users</b> ให้รองรับค่า NULL สำเร็จ<br>";
} catch (PDOException $e) {
    echo "  - ผิดพลาดในการแก้ไขคอลัมน์ password: " . $e->getMessage() . "<br>";
}

// 3. อัปเดตคอลัมน์ในตาราง bottle_entries
addColumnIfNotExists($pdo, 'bottle_entries', 'student_id', 'INT NOT NULL');
addColumnIfNotExists($pdo, 'bottle_entries', 'bottle_type', 'VARCHAR(50)');
addColumnIfNotExists($pdo, 'bottle_entries', 'bottle_size', 'VARCHAR(20) AFTER bottle_type');
addColumnIfNotExists($pdo, 'bottle_entries', 'quantity', 'INT');
addColumnIfNotExists($pdo, 'bottle_entries', 'money_earned', 'DECIMAL(10, 2) DEFAULT 0.00');
addColumnIfNotExists($pdo, 'bottle_entries', 'points_earned', 'INT DEFAULT 0');
addColumnIfNotExists($pdo, 'bottle_entries', 'recorded_by', 'INT');

// 4. อัปเดตคอลัมน์ในตาราง redemptions
addColumnIfNotExists($pdo, 'redemptions', 'student_id', 'INT NOT NULL');
addColumnIfNotExists($pdo, 'redemptions', 'type', "ENUM('money', 'points')");
addColumnIfNotExists($pdo, 'redemptions', 'amount', 'DECIMAL(10, 2) DEFAULT 0.00');
addColumnIfNotExists($pdo, 'redemptions', 'points', 'INT DEFAULT 0');
addColumnIfNotExists($pdo, 'redemptions', 'status', "ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
addColumnIfNotExists($pdo, 'redemptions', 'approver_id', 'INT');
addColumnIfNotExists($pdo, 'redemptions', 'admin_note', 'TEXT');

echo "<hr>";

// 5. เพิ่มข้อมูลเริ่มต้น (Seed Data)
// เพิ่ม Super Admin ถ้ายังไม่มีผู้ใช้งานเลย
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
if ($stmt->fetchColumn() == 0) {
    $password = password_hash('admin123', PASSWORD_ARGON2ID);
    $sql = "INSERT INTO users (email, password, role, first_name, last_name, id_code) 
            VALUES ('admin@suthi.ac.th', '$password', 'superadmin', 'Super', 'Admin', 'ADMIN001')";
    $pdo->exec($sql);
    echo "<span style='color:blue'>★ สร้างบัญชี Super Admin เริ่มต้นสำเร็จ (Email: admin@suthi.ac.th / Pass: admin123)</span><br>";
}

// เพิ่มประเภทขยะเริ่มต้น
$stmt = $pdo->query("SELECT COUNT(*) FROM waste_types");
if ($stmt->fetchColumn() == 0) {
    $waste_types = [
        ['pet', 'ขวดพลาสติก PET', 2.00, 10, 'ชิ้น'],
        ['can', 'กระป๋องอลูมิเนียม', 3.00, 15, 'ชิ้น'],
        ['glass', 'ขวดแก้ว', 1.50, 8, 'ชิ้น'],
        ['paper', 'กระดาษ', 1.00, 5, 'กิโลกรัม']
    ];
    $insert = $pdo->prepare("INSERT INTO waste_types (code, name, price, points, unit) VALUES (?, ?, ?, ?, ?)");
    foreach ($waste_types as $w) {
        $insert->execute($w);
    }
    echo "<span style='color:blue'>★ เพิ่มข้อมูลประเภทขยะเริ่มต้นสำเร็จ</span><br>";
}

echo "<br><h3>✅ การตั้งค่าฐานข้อมูลเสร็จสมบูรณ์!</h3>";
echo "<a href='index.php'>กลับสู่หน้าหลัก</a>";
?>