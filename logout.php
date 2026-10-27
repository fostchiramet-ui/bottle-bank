<?php
session_start();
require_once 'db/db.php';

// 1. จัดการ Remember Me (ถ้ามี)
if (isset($_COOKIE['remember_token'])) {
    try {
        // เชื่อมต่อฐานข้อมูลเพื่อลบ Token (Invalidate Token on Server)
        $pdo = Database::connect();
        $token = $_COOKIE['remember_token'];
        $token_hash = hash('sha256', $token);
        
        // อัปเดตให้ remember_token เป็น NULL ในฐานข้อมูล
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE remember_token = ?");
        $stmt->execute([$token_hash]);
    } catch (Exception $e) {
        // กรณีเกิดข้อผิดพลาดในการเชื่อมต่อ DB ให้ข้ามไปลบ Cookie เลย
    }
    
    // ลบ Cookie ฝั่ง Client (ตั้งเวลาติดลบ)
    setcookie('remember_token', '', time() - 3600, "/", "", false, true);
}

// 2. ล้างตัวแปร Session ทั้งหมด
$_SESSION = array();

// 3. ลบ Session Cookie (เพื่อป้องกัน Session Fixation/Hijacking)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. ทำลาย Session
session_destroy();

// 5. Redirect กลับไปหน้า Login
header("Location: index.php");
exit();
?>