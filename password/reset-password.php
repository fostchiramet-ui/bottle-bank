<?php
session_start();
require_once '../db/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$token = $_GET['token'] ?? '';  
$is_token_valid = false;

if (empty($token)) {
    $error = "ไม่พบ Token หรือลิงก์ไม่ถูกต้อง";
} else {
    try {
        $pdo = Database::connect();
        $token_hash = hash('sha256', $token);
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$token_hash]);
        $reset_request = $stmt->fetch();

        if ($reset_request) {
            $is_token_valid = true;
        } else {
            $error = "Token ไม่ถูกต้องหรือหมดอายุแล้ว กรุณาส่งคำขอใหม่อีกครั้ง";
        }
    } catch (Exception $e) {
        $error = "เกิดข้อผิดพลาดในระบบ";
        error_log("Reset Password Error: " . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_token_valid) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    }

    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $post_token = $_POST['token'];

    if ($password !== $confirm_password) {
        $error = "รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน";
    } elseif (strlen($password) < 6) {
        $error = "รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร";
    } else {
        try {
            $pdo->beginTransaction();

            $post_token_hash = hash('sha256', $post_token);
            $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = ?");
            $stmt->execute([$post_token_hash]);
            $email = $stmt->fetchColumn();

            if ($email) {
                $new_hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                $stmt->execute([$new_hashed_password, $email]);

                $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
                $stmt->execute([$email]);

                $pdo->commit();

                $_SESSION['success'] = "เปลี่ยนรหัสผ่านสำเร็จ! กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่";
                header("Location: ../login.php");
                exit();
            } else {
                throw new Exception("Could not find email for the token.");
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาดในการอัปเดตรหัสผ่าน";
            error_log("Reset Password POST Error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งรหัสผ่านใหม่ - EcoBank</title>
    <link rel="stylesheet" href="../css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="login-card">
        <div class="logo" style="margin-bottom: 20px;"><h2>Eco<span>Bank</span></h2></div>
        <h3>ตั้งรหัสผ่านใหม่</h3>
        
        <?php if($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($is_token_valid): ?>
            <p>กรอกรหัสผ่านใหม่ของคุณด้านล่าง</p>
            <form method="POST" action="reset-password.php?token=<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="input-group"><input type="password" name="password" class="input-field" placeholder="รหัสผ่านใหม่ (อย่างน้อย 6 ตัวอักษร)" required><i class="fas fa-lock"></i></div>
                <div class="input-group"><input type="password" name="confirm_password" class="input-field" placeholder="ยืนยันรหัสผ่านใหม่" required><i class="fas fa-check-circle"></i></div>
                <button type="submit" class="btn-submit">บันทึกรหัสผ่านใหม่</button>
            </form>
        <?php else: ?>
            <div class="links"><a href="forgot-password.php">กลับไปหน้าลืมรหัสผ่าน</a></div>
        <?php endif; ?>
    </div>
</body>
</html>