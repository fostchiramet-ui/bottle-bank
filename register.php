<?php
session_start();
require_once 'db/db.php';

require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("เกิดข้อผิดพลาดด้านความปลอดภัย (CSRF Token ไม่ถูกต้อง)");
    }

    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $id_code = trim($_POST['id_code']);
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($first_name) || empty($last_name) || empty($id_code) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "รูปแบบอีเมลไม่ถูกต้อง";
    } elseif (!preg_match('/^\d{5}$/', $id_code)) {
        $error = "รหัสนักเรียนต้องเป็นตัวเลข 5 หลักเท่านั้น";
    } elseif ($password !== $confirm_password) {
        $error = "รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน";
    } elseif (
        strlen($password) < 8 || 
        !preg_match('/[A-Z]/', $password) || 
        !preg_match('/[a-z]/', $password) || 
        !preg_match('/[0-9]/', $password) || 
        !preg_match('/[\W_]/', $password)
    ) {
        $error = "รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร และประกอบด้วย ตัวอักษรภาษาอังกฤษพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข และอักขระพิเศษ อย่างน้อยอย่างละ 1 ตัว";
    } else {
        try {
            $pdo = Database::connect();
            
            $check_sql = "SELECT id FROM users WHERE id_code = ? OR email = ? LIMIT 1";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$id_code, $email]);
            
            if ($check_stmt->fetch()) {
                $error = "รหัสนักเรียนหรืออีเมลนี้ถูกใช้งานในระบบแล้ว";
            } else {
                $hashed_password = password_hash($password, PASSWORD_ARGON2ID);
                $verification_token = bin2hex(random_bytes(32));
                $token_hash = hash('sha256', $verification_token);
                
                $insert_sql = "INSERT INTO users (username, first_name, last_name, id_code, email, password, role, is_active, email_verification_token)
                               VALUES (?, ?, ?, ?, ?, ?, 'user', 0, ?)";
                $insert_stmt = $pdo->prepare($insert_sql);
                
                $inserted = $insert_stmt->execute([
                    $id_code,
                    $first_name,
                    $last_name,
                    $id_code,
                    $email,
                    $hashed_password,
                    $token_hash
                ]);
                
                if ($inserted) {
                    $verification_link = "http://" . $_SERVER['HTTP_HOST'] . "/bottle-bank/password/verify-email.php?token=" . $verification_token;
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
                        $mail->addAddress($email, $first_name . ' ' . $last_name);
                        $mail->isHTML(true);
                        $mail->Subject = "ยืนยันการสมัครสมาชิก EcoBank";
                        $mail->Body    = "<p>ขอบคุณสำหรับการสมัครสมาชิก EcoBank!</p><p>กรุณาคลิกลิงก์เพื่อยืนยันอีเมลและเปิดใช้งานบัญชีของคุณ: <a href='{$verification_link}'>{$verification_link}</a></p>";
                        $mail->send();

                        $success = "สมัครสมาชิกสำเร็จ! เราได้ส่งลิงก์ยืนยันไปยังอีเมลของคุณแล้ว กรุณาตรวจสอบและคลิกลิงก์เพื่อเปิดใช้งานบัญชี";
                    } catch (Exception $e) {
                        $error = "สมัครสมาชิกสำเร็จ แต่ไม่สามารถส่งอีเมลยืนยันได้ กรุณาติดต่อผู้ดูแลระบบ";
                    }
                } else {
                    $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง";
                }
            }
        } catch (PDOException $e) {
            $error = "เกิดข้อผิดพลาดร้ายแรง กรุณาลองใหม่อีกครั้ง";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - EcoBank</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/register.css">
</head>
<style>
    .separator { display: flex; align-items: center; text-align: center; color: #aaa; margin: 20px 0; }
    .separator::before, .separator::after { content: ''; flex: 1; border-bottom: 1px solid #ddd; }
    .separator:not(:empty)::before { margin-right: .5em; }
    .separator:not(:empty)::after { margin-left: .5em; }
    .btn-google {
        display: flex; align-items: center; justify-content: center;
        width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;
        background-color: #fff; color: #333; text-decoration: none; font-weight: 500;
        transition: background-color 0.3s, box-shadow 0.3s;
    }
    .btn-google:hover { background-color: #f7f7f7; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
    .btn-google i { color: #DB4437; margin-right: 10px; font-size: 1.2em; }
</style>
<body>

    <div class="register-card">
        <div class="logo">
            <h2><span class="txt-green">Eco</span><span class="txt-pink">Bank</span></h2>
        </div>
        <h3>สร้างบัญชีใหม่</h3>
        <p>สมัครสมาชิกoสำหรับนักเรียน เพื่อเริ่มต้นฝากขวดและสะสมแต้ม</p>

        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php elseif($success): ?>
            <div class="alert alert-success" style="background-color: #d1fae5; color: #065f46; border-color: #a7f3d0;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if(empty($success)): ?>
        <form method="POST" action="register.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="grid-2">
                <div style="position: relative; display: block; margin-bottom: 15px;">
                    <i class="fas fa-user" style="position: absolute !important; left: 15px !important; top: 50% !important; transform: translateY(-50%) !important; color: #aaa !important; z-index: 2 !important; pointer-events: none !important;"></i>
                    <input type="text" name="first_name" class="input-field" placeholder="ชื่อจริง" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required style="width: 100% !important; padding-left: 45px !important; padding-right: 15px !important; box-sizing: border-box !important;">
                </div>
                <div style="position: relative; display: block; margin-bottom: 15px;">
                    <i class="fas fa-user" style="position: absolute !important; left: 15px !important; top: 50% !important; transform: translateY(-50%) !important; color: #aaa !important; z-index: 2 !important; pointer-events: none !important;"></i>
                    <input type="text" name="last_name" class="input-field" placeholder="นามสกุล" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required style="width: 100% !important; padding-left: 45px !important; padding-right: 15px !important; box-sizing: border-box !important;">
                </div>
            </div>

            <div style="position: relative; display: block; margin-bottom: 15px;">
                <i class="fas fa-id-card" style="position: absolute !important; left: 15px !important; top: 50% !important; transform: translateY(-50%) !important; color: #aaa !important; z-index: 2 !important; pointer-events: none !important;"></i>
                <input type="text" name="id_code" class="input-field" placeholder="รหัสนักเรียน (5 หลัก)" maxlength="5" value="<?php echo isset($_POST['id_code']) ? htmlspecialchars($_POST['id_code']) : ''; ?>" required style="width: 100% !important; padding-left: 45px !important; padding-right: 15px !important; box-sizing: border-box !important;">
            </div>

            <div style="position: relative; display: block; margin-bottom: 15px;">
                <i class="fas fa-envelope" style="position: absolute !important; left: 15px !important; top: 50% !important; transform: translateY(-50%) !important; color: #aaa !important; z-index: 2 !important; pointer-events: none !important;"></i>
                <input type="email" name="email" class="input-field" placeholder="อีเมล (สำหรับยืนยันตัวตน)" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required style="width: 100% !important; padding-left: 45px !important; padding-right: 15px !important; box-sizing: border-box !important;">
            </div>

            <!-- ช่องรหัสผ่าน: เอาคำอธิบายมาไว้ด้านล่าง -->
            <div style="margin-bottom: 15px;">
                <div style="position: relative; display: block;">
                    <i class="fas fa-lock" style="position: absolute !important; left: 15px !important; top: 50% !important; transform: translateY(-50%) !important; color: #aaa !important; z-index: 2 !important; pointer-events: none !important;"></i>
                    
                    <!-- เปลี่ยน Placeholder ให้สั้นลง -->
                    <input type="password" name="password" id="reg_password" class="input-field" placeholder="รหัสผ่าน" required autocomplete="new-password" style="width: 100% !important; padding-left: 45px !important; padding-right: 45px !important; box-sizing: border-box !important;">
                    
                    <div onclick="togglePassword('reg_password', this.querySelector('i'))" style="position: absolute !important; right: 15px !important; top: 50% !important; transform: translateY(-50%) !important; z-index: 10 !important; cursor: pointer !important; width: 30px !important; height: 30px !important; display: flex !important; justify-content: center !important; align-items: center !important; pointer-events: auto !important;">
                        <i class="fas fa-eye" style="position: static !important; left: auto !important; right: auto !important; transform: none !important; color: #aaa !important; margin: 0 !important;"></i>
                    </div>
                </div>
                <!-- คำอธิบายด้านล่าง -->
                <div style="text-align: left; margin-top: 5px; font-size: 13px; color: #666; padding-left: 5px;">
                    <i class="fas fa-info-circle"></i> ต้องมีอย่างน้อย 8 ตัวอักษร, พิมพ์ใหญ่, พิมพ์เล็ก, ตัวเลข และอักขระพิเศษ
                </div>
            </div>

            <!-- ช่องยืนยันรหัสผ่าน -->
            <div style="position: relative; display: block; margin-bottom: 15px;">
                <i class="fas fa-check-circle" style="position: absolute !important; left: 15px !important; top: 50% !important; transform: translateY(-50%) !important; color: #aaa !important; z-index: 2 !important; pointer-events: none !important;"></i>
                
                <input type="password" name="confirm_password" id="reg_confirm" class="input-field" placeholder="ยืนยันรหัสผ่านอีกครั้ง" required autocomplete="new-password" style="width: 100% !important; padding-left: 45px !important; padding-right: 45px !important; box-sizing: border-box !important;">
                
                <div onclick="togglePassword('reg_confirm', this.querySelector('i'))" style="position: absolute !important; right: 15px !important; top: 50% !important; transform: translateY(-50%) !important; z-index: 10 !important; cursor: pointer !important; width: 30px !important; height: 30px !important; display: flex !important; justify-content: center !important; align-items: center !important; pointer-events: auto !important;">
                    <i class="fas fa-eye" style="position: static !important; left: auto !important; right: auto !important; transform: none !important; color: #aaa !important; margin: 0 !important;"></i>
                </div>
            </div>

            <button type="submit" class="btn-submit">สมัครสมาชิก</button>
        </form>
        
        <div class="separator">หรือ</div>

        <a href="password/google-auth.php" class="btn-google"> <i class="fab fa-google"></i> สมัครสมาชิกด้วย Google </a>
        <?php endif; ?>

        <div class="links">
            มีบัญชีผู้ใช้แล้ว? <a href="login.php">เข้าสู่ระบบที่นี่</a>
        </div>
        
        <a href="index.php" class="back-link">← กลับหน้าหลัก</a>
    </div>

    <script>
        function togglePassword(fieldId, iconElement) {
            const field = document.getElementById(fieldId);
            if (field.type === "password") {
                field.type = "text";
                iconElement.classList.remove("fa-eye");
                iconElement.classList.add("fa-eye-slash");
            } else {
                field.type = "password";
                iconElement.classList.remove("fa-eye-slash");
                iconElement.classList.add("fa-eye");
            }
        }
    </script>
</body>
</html>