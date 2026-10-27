<?php
ob_start();
session_start();
require_once 'db/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $pdo = Database::connect();
    $token = $_COOKIE['remember_token'];
    $token_hash = hash('sha256', $token);
    
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, role, is_active FROM users WHERE remember_token = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$token_hash]);
    $user = $stmt->fetch();

    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['role'] = $user['role'];

        switch ($user['role']) {
            case 'superadmin': header("Location: superadmin/superadmin.php"); break;
            case 'admin': header("Location: admin/admin.php"); break;
            case 'student': header("Location: student/user.php"); break;
            default: header("Location: index.html");
        }
        exit();
    }
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    }

    $id_code = trim($_POST['id_code']);
    $password = $_POST['password'];

    if (empty($id_code) || empty($password)) {
        $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
    } else {
        $pdo = Database::connect();
        $sql = "SELECT id, first_name, last_name, password, role, is_active, email_verified_at, google_id FROM users WHERE id_code = ? OR email = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_code, $id_code]);
        $user = $stmt->fetch();

        if ($user && !is_null($user['password']) && password_verify($password, $user['password'])) {
            if ($user['is_active'] == 0) {
                if ($user['email_verified_at'] === null && $user['google_id'] === null) {
                    $error = "บัญชีของคุณยังไม่ได้ยืนยันอีเมล กรุณาตรวจสอบกล่องจดหมายเพื่อทำการยืนยัน";
                } else {
                    $error = "บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ";
                }
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['role'] = $user['role'];

                $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$user['id']]);

                $log_stmt = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
                $log_stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);

                if (isset($_POST['remember'])) {
                    $token = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $token);
                    $upd = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                    $upd->execute([$token_hash, $user['id']]);
                    setcookie('remember_token', $token, time() + (86400 * 30), "/", "", false, true);
                }

                switch ($user['role']) {
                    case 'superadmin': header("Location: superadmin/superadmin.php"); break;
                    case 'admin': header("Location: admin/admin.php"); break;
                    case 'user':
                        $stmt = $pdo->prepare("SELECT id_code FROM users WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        $student = $stmt->fetch();
                        if (empty($student['id_code'])) {
                            header("Location: student/fill_info.php");
                        } else {
                            header("Location: student/user.php");
                        }
                        break;  
                    default: header("Location: index.php"); break;
                }
                exit();
            }
        } else {
            $error = "รหัสประจำตัวหรืออีเมลหรือรหัสผ่านไม่ถูกต้อง";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - SuthiBank</title>
    <link rel="stylesheet" href="css/login.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    <div class="login-card">
        <div class="logo" style="margin-bottom: 20px;">
            <h2>Eco<span>Bank</span></h2>
        </div>
        <h3>ยินดีต้อนรับกลับ</h3>
        <p>เข้าสู่ระบบเพื่อจัดการบัญชีธนาคารขยะของคุณ</p>

        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <!-- ช่องรหัสประจำตัว -->
            <div style="position: relative !important; display: block !important; margin-bottom: 15px;">
                <i class="fas fa-id-card" style="position: absolute !important; left: 15px !important; top: 50% !important; transform: translateY(-50%) !important; color: #aaa !important; z-index: 2 !important; pointer-events: none !important;"></i>
                <input type="text" name="id_code" class="input-field" placeholder="รหัสประจำตัว 5 หลัก / อีเมล" id="id_code" autocomplete="off" required autofocus style="width: 100% !important; padding-left: 45px !important; padding-right: 15px !important; box-sizing: border-box !important;">
            </div>

            <!-- ช่องรหัสผ่าน พร้อมปุ่มตาขวาสุด -->
            <div style="position: relative !important; display: block !important; margin-bottom: 15px;">
                <i class="fas fa-lock" style="position: absolute !important; left: 15px !important; top: 50% !important; transform: translateY(-50%) !important; color: #aaa !important; z-index: 2 !important; pointer-events: none !important;"></i>
                <input type="password" name="password" class="input-field" placeholder="รหัสผ่าน" id="password" autocomplete="current-password" required style="width: 100% !important; padding-left: 45px !important; padding-right: 45px !important; box-sizing: border-box !important;">
                
                <!-- ปุ่มลูกตา บังคับขวา และอยู่บนสุดคลิกได้ 100% -->
                <i class="fas fa-eye" onclick="togglePassword('password', this)" style="position: absolute !important; right: 15px !important; left: auto !important; top: 50% !important; transform: translateY(-50%) !important; color: #aaa !important; z-index: 10 !important; cursor: pointer !important; padding: 5px !important; pointer-events: auto !important;"></i>
            </div>

            <div class="remember-me">
                <input type="checkbox" name="remember" id="remember">
                <label for="remember">จำฉันไว้ในระบบ</label>
            </div>

            <button type="submit" class="btn-submit">เข้าสู่ระบบ</button>
        </form>

        <div class="separator"><span>หรือ</span></div>

        <a href="password/google-auth.php" class="btn-google">
            <i class="fab fa-google"></i>
            เข้าสู่ระบบด้วย Google
        </a>

        <div class="links">
            ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก</a> <span style="margin: 0 5px;">|</span> <a href="password/forgot-password.php">ลืมรหัสผ่าน?</a>
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