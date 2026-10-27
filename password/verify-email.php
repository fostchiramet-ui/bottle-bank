<?php
session_start();
require_once '../db/db.php';

$message = '';
$msg_type = 'error';

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $token_hash = hash('sha256', $token);

    try {
        $pdo = Database::connect();
        
        // Find user by the verification token
        $stmt = $pdo->prepare("SELECT id, is_active FROM users WHERE email_verification_token = ? LIMIT 1");
        $stmt->execute([$token_hash]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user['is_active']) {
                // Account is already active
                $_SESSION['success'] = "บัญชีของคุณถูกเปิดใช้งานแล้ว สามารถเข้าสู่ระบบได้เลย";
                header("Location: ../login.php");
                exit();
            }

            // Activate the account
            $update_stmt = $pdo->prepare(
                "UPDATE users SET is_active = 1, email_verified_at = NOW(), email_verification_token = NULL WHERE id = ?"
            );
            
            if ($update_stmt->execute([$user['id']])) {
                $_SESSION['success'] = "ยืนยันอีเมลสำเร็จ! ขณะนี้คุณสามารถเข้าสู่ระบบได้แล้ว";
                header("Location: ../login.php");
                exit();
            } else {
                $message = "เกิดข้อผิดพลาดในการเปิดใช้งานบัญชีของคุณ กรุณาลองใหม่อีกครั้ง";
            }
        } else {
            // Token not found or already used
            $message = "ลิงก์ยืนยันไม่ถูกต้องหรือหมดอายุแล้ว กรุณาลองสมัครสมาชิกใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ";
        }
    } catch (Exception $e) {
        error_log("Email Verification Error: " . $e->getMessage());
        $message = "เกิดข้อผิดพลาดในระบบ กรุณาติดต่อผู้ดูแลระบบ";
    }
} else {
    $message = "ไม่พบข้อมูลสำหรับยืนยันตัวตน";
}

// If we reach here, it means there was an error. We show a page with the error.
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยืนยันอีเมล - EcoBank</title>
    <link rel="stylesheet" href="../css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="login-card">
        <div class="logo" style="margin-bottom: 20px;"><h2>Eco<span>Bank</span></h2></div>
        <h3>ผลการยืนยันอีเมล</h3>
        
        <div class="alert alert-<?php echo htmlspecialchars($msg_type); ?>"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <div class="links"><a href="../login.php">ไปที่หน้าเข้าสู่ระบบ</a> หรือ <a href="../register.php">สมัครสมาชิกใหม่</a></div>
    </div>
</body>
</html>