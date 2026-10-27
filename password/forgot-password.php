<?php
session_start();
require_once '../db/db.php';

// Load Composer's autoloader
require __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$msg_type = '';
$reset_link_for_testing = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    }

    $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);

    if (!$email) {
        $message = "รูปแบบอีเมลไม่ถูกต้อง";
        $msg_type = "error";
    } else {
        try {
            $pdo = Database::connect();
            
            $stmt = $pdo->prepare("SELECT id, password, google_id FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Check if it's a Google-only account (has google_id, no password)
                if (!empty($user['google_id']) && is_null($user['password'])) {
                    $message = "บัญชีนี้ลงทะเบียนผ่าน Google กรุณาเข้าสู่ระบบด้วย Google โดยตรง ไม่สามารถรีเซ็ตรหัสผ่านได้";
                    $msg_type = "error";
                } else {
                    // Proceed with normal password reset for regular users
                    $token = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $token);
                    $expires_at = date('Y-m-d H:i:s', time() + 3600); // Token is valid for 1 hour

                    $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                    $stmt->execute([$email, $token_hash, $expires_at]);

                    $reset_link_for_testing = "http://" . $_SERVER['HTTP_HOST'] . "/bottle-bank/password/reset-password.php?token=" . $token;

                    // --- PHPMailer Integration ---
                    $settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'smtp_%'");
                    $smtp_config = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = $smtp_config['smtp_host'] ?? 'localhost';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $smtp_config['smtp_user'] ?? '';
                        $mail->Password   = $smtp_config['smtp_pass'] ?? '';
                        $mail->SMTPSecure = $smtp_config['smtp_secure'] ?? PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = $smtp_config['smtp_port'] ?? 587;
                        $mail->CharSet    = 'UTF-8';
                        $mail->setFrom($smtp_config['smtp_user'] ?? 'noreply@ecobank.com', 'EcoBank System');
                        $mail->addAddress($email);
                        $mail->isHTML(true);
                        $mail->Subject = "คำขอรีเซ็ตรหัสผ่านสำหรับ EcoBank";
                        $mail->Body    = "<p>กรุณาคลิกลิงก์เพื่อรีเซ็ตรหัสผ่าน: <a href='{$reset_link_for_testing}'>{$reset_link_for_testing}</a></p>";
                        $mail->AltBody = "กรุณาคัดลอกลิงก์นี้ไปวางในเบราว์เซอร์เพื่อรีเซ็ตรหัสผ่าน: {$reset_link_for_testing}";
                        $mail->send();
                    } catch (Exception $e) {
                        error_log("Mailer Error: {$mail->ErrorInfo}");
                    }
                }
            }

            // To prevent user enumeration, only show the success message if it hasn't been set to the Google error.
            if (empty($message)) {
                $message = "หากมีบัญชีที่ใช้อีเมลนี้ในระบบ เราได้ส่งลิงก์สำหรับรีเซ็ตรหัสผ่านไปให้แล้ว";
                if (!empty($reset_link_for_testing)) {
                    $message .= "<br><b>ลิงก์สำหรับทดสอบ:</b> <a href='$reset_link_for_testing' target='_blank'>คลิกที่นี่</a>";
                }
                $msg_type = "success";
            }

        } catch (Exception $e) {
            error_log("Forgot Password Error: " . $e->getMessage());
            $message = "เกิดข้อผิดพลาดบางอย่างในระบบ กรุณาลองใหม่อีกครั้ง";
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลืมรหัสผ่าน - EcoBank</title>
    <link rel="stylesheet" href="../css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="login-card">
        <div class="logo" style="margin-bottom: 20px;"><h2>Eco<span>Bank</span></h2></div>
        <h3>ลืมรหัสผ่าน?</h3>
        <p>กรอกอีเมลของคุณเพื่อรับลิงก์สำหรับรีเซ็ตรหัสผ่าน</p>

        <?php if($message): ?>
            <div class="alert alert-<?php echo $msg_type; ?>">
                <i class="fas fa-<?php echo ($msg_type == 'success') ? 'check-circle' : 'exclamation-circle'; ?>"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="forgot-password.php">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="input-group"><input type="email" name="email" class="input-field" placeholder="อีเมลของคุณ" required autofocus><i class="fas fa-envelope"></i></div>
            <button type="submit" class="btn-submit">ส่งลิงก์รีเซ็ตรหัสผ่าน</button>
        </form>

        <div class="links">จำรหัสผ่านได้แล้ว? <a href="../login.php">กลับไปหน้าเข้าสู่ระบบ</a></div>
    </div>
</body>
</html>