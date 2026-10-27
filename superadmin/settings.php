<?php
session_start();
require_once '../db/db.php';

// 1. Strict RBAC: Check if Superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', '1'])) {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();

// สร้างตาราง settings หากยังไม่มี (Key-Value Pair)
$pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT
)");

// ค่าเริ่มต้น (Defaults)
$defaults = [
    'school_name' => 'โรงเรียนวัดสุทธิวราราม',
    'phone' => '',
    'contact_email' => 'admin@suthi.ac.th',
    'address' => '',
    'facebook_url' => '',
    'instagram_url' => '',
    'allow_register' => '1',
    'maintenance_mode' => '0',
    'email_notification' => '1',
    'school_logo' => '',
    'line_notify_token' => '',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_user' => '',
    'smtp_pass' => '',
    'smtp_secure' => 'tls'
];

// 2. CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$msg_type = '';

// 3. Handle POST Request (Save Settings)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security Error: Invalid CSRF Token";
        $msg_type = "error";
    } else {
        try {
            $pdo->beginTransaction();
            
            // ⭐️ แก้ไข: แยกตัวแปร :value ออกเป็น :value1 และ :value2 เพื่อไม่ให้ PDO Error
            $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :value1) 
                    ON DUPLICATE KEY UPDATE setting_value = :value2";
            $stmt = $pdo->prepare($sql);

            // บันทึกข้อมูล Text Fields
            $fields = ['school_name', 'phone', 'contact_email', 'address', 'facebook_url', 'instagram_url', 'line_notify_token', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure'];
            foreach ($fields as $field) {
                $val = trim($_POST[$field] ?? '');
                $stmt->execute([':key' => $field, ':value1' => $val, ':value2' => $val]);
            }

            // บันทึกข้อมูล Checkbox (Toggles)
            $toggles = ['allow_register', 'maintenance_mode', 'email_notification'];
            foreach ($toggles as $toggle) {
                $val = isset($_POST[$toggle]) ? '1' : '0';
                $stmt->execute([':key' => $toggle, ':value1' => $val, ':value2' => $val]);
            }

            // จัดการ File Upload (Logo)
            if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                // ตรวจสอบว่าเป็นรูปภาพจริง
                $check = getimagesize($_FILES['school_logo']['tmp_name']);
                if($check !== false) {
                    $filename = 'logo_' . time() . '_' . basename($_FILES['school_logo']['name']);
                    $targetPath = $uploadDir . $filename;
                    
                    if (move_uploaded_file($_FILES['school_logo']['tmp_name'], $targetPath)) {
                        $stmt->execute([':key' => 'school_logo', ':value1' => $filename, ':value2' => $filename]);
                    }
                }
            }

            $pdo->commit();
            $message = "บันทึกการตั้งค่าเรียบร้อยแล้ว";
            $msg_type = "success";

        } catch (Exception $e) {
            $pdo->rollBack();
            // Security: ซ่อน Error จริงจาก Database ไม่ให้ User เห็น (แต่โชว์ให้คุณรู้ตอนแก้บั๊ก)
            $error_detail = $e->getMessage();
            error_log($error_detail); 
            $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $error_detail; 
            $msg_type = "error";
        }
    }
}

// 4. Fetch Current Settings
$stmt = $pdo->query("SELECT * FROM system_settings");
$db_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$config = array_merge($defaults, $db_settings);

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าระบบ - Recycle Bank</title>
    <link rel="stylesheet" href="superadmincss/superadmin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            <h1>ตั้งค่าระบบ</h1>
            <p class="subtitle">จัดการการตั้งค่าทั่วไปของระบบธนาคารขยะรีไซเคิล</p>
        </header>

        <div class="content-card">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="card-header">
                    <h3><i class="fas fa-sliders-h"></i> การตั้งค่าทั่วไป</h3>
                </div>
                <div style="padding: 30px;">
                    
                    <div class="form-section">
                        <h4><i class="fas fa-school txt-green"></i> ข้อมูลโรงเรียน</h4>
                        <div class="settings-grid">
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">ตราสัญลักษณ์โรงเรียน</label>
                                <div class="file-upload-wrapper">
                                    <div class="preview-box" id="logoPreview">
                                        <?php if(!empty($config['school_logo'])): ?>
                                            <img src="../uploads/<?php echo h($config['school_logo']); ?>" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else: ?>
                                            <i class="fas fa-image"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex: 1;">
                                        <input type="file" name="school_logo" id="logoInput" class="form-control" accept="image/*" onchange="previewLogo()">
                                        <p class="upload-hint"><i class="fas fa-info-circle"></i> รองรับไฟล์ PNG, JPG ขนาดไม่เกิน 2MB</p>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group"><label class="form-label">ชื่อโรงเรียน</label><input type="text" name="school_name" class="form-control" value="<?php echo h($config['school_name']); ?>" required></div>
                            <div class="form-group" style="grid-column: 1 / -1;"><label class="form-label">เบอร์ติดต่อ</label><input type="text" name="phone" class="form-control" value="<?php echo h($config['phone']); ?>"></div>
                            <div class="form-group"><label class="form-label">อีเมลติดต่อ</label><input type="email" name="contact_email" class="form-control" value="<?php echo h($config['contact_email']); ?>" required></div>
                            <div class="form-group" style="grid-column: 1 / -1;"><label class="form-label">ที่อยู่</label><textarea name="address" class="form-control" rows="3"><?php echo h($config['address']); ?></textarea></div>
                            <div class="form-group" style="grid-column: 1 / -1;"><label class="form-label">Facebook Page URL</label><input type="text" name="facebook_url" class="form-control" value="<?php echo h($config['facebook_url']); ?>"></div>
                            <div class="form-group" style="grid-column: 1 / -1;"><label class="form-label">Instagram URL</label><input type="text" name="instagram_url" class="form-control" value="<?php echo h($config['instagram_url']); ?>"></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4><i class="fab fa-line txt-green"></i> การแจ้งเตือน Line Notify</h4>
                        <div class="settings-grid">
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Line Notify Token</label>
                                <input type="text" name="line_notify_token" class="form-control" placeholder="ระบุ Line Notify Token" value="<?php echo h($config['line_notify_token'] ?? ''); ?>">
                                <p class="upload-hint"><i class="fas fa-info-circle"></i> รับ Token ได้ที่ <a href="https://notify-bot.line.me/my/" target="_blank" style="color: var(--green);">notify-bot.line.me</a> (ใช้สำหรับแจ้งเตือนเมื่อมีการฝากขยะ)</p>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4><i class="fas fa-paper-plane txt-amber"></i> ตั้งค่าอีเมล (SMTP)</h4>
                        <p class="setting-desc" style="margin-left:0; margin-bottom:15px;">ตั้งค่าเซิร์ฟเวอร์สำหรับส่งอีเมล เช่น การรีเซ็ตรหัสผ่าน (แนะนำให้ใช้กับ PHPMailer)</p>
                        <div class="settings-grid">
                            <div class="form-group"><label class="form-label">SMTP Host</label><input type="text" name="smtp_host" class="form-control" placeholder="e.g., smtp.gmail.com" value="<?php echo h($config['smtp_host'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">SMTP Port</label><input type="number" name="smtp_port" class="form-control" placeholder="e.g., 587" value="<?php echo h($config['smtp_port'] ?? '587'); ?>"></div>
                            <div class="form-group"><label class="form-label">SMTP Username</label><input type="text" name="smtp_user" class="form-control" placeholder="your-email@example.com" value="<?php echo h($config['smtp_user'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">SMTP Password</label><input type="password" name="smtp_pass" class="form-control" placeholder="••••••••" value="<?php echo h($config['smtp_pass'] ?? ''); ?>"></div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Encryption</label>
                                <select name="smtp_secure" class="form-control">
                                    <option value="tls" <?php echo (($config['smtp_secure'] ?? 'tls') == 'tls') ? 'selected' : ''; ?>>TLS</option>
                                    <option value="ssl" <?php echo (($config['smtp_secure'] ?? '') == 'ssl') ? 'selected' : ''; ?>>SSL</option>
                                    <option value="none" <?php echo (($config['smtp_secure'] ?? '') == 'none') ? 'selected' : ''; ?>>None</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4><i class="fas fa-cogs txt-pink"></i> การทำงานของระบบ</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                            <div class="setting-card">
                                <div class="setting-info"><div class="setting-header"><div class="setting-icon bg-green txt-green"><i class="fas fa-user-plus"></i></div><h5 style="margin: 0;">เปิดรับสมัครสมาชิก</h5></div><p class="setting-desc">อนุญาตให้นักเรียนสมัครสมาชิกใหม่ได้ด้วยตนเอง</p></div>
                                <label class="toggle-switch"><input type="checkbox" name="allow_register" <?php echo ($config['allow_register'] == '1') ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>
                            <div class="setting-card">
                                <div class="setting-info"><div class="setting-header"><div class="setting-icon bg-amber txt-amber"><i class="fas fa-tools"></i></div><h5 style="margin: 0;">โหมดปิดปรับปรุง</h5></div><p class="setting-desc">ปิดการใช้งานระบบชั่วคราวสำหรับผู้ใช้ทั่วไป</p></div>
                                <label class="toggle-switch"><input type="checkbox" name="maintenance_mode" <?php echo ($config['maintenance_mode'] == '1') ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>
                            <div class="setting-card">
                                <div class="setting-info"><div class="setting-header"><div class="setting-icon bg-pink txt-pink"><i class="fas fa-bell"></i></div><h5 style="margin: 0;">การแจ้งเตือนผ่านอีเมล</h5></div><p class="setting-desc">ส่งอีเมลแจ้งเตือนเมื่อมีการทำรายการฝาก</p></div>
                                <label class="toggle-switch"><input type="checkbox" name="email_notification" <?php echo ($config['email_notification'] == '1') ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>
                        </div>
                    </div>

                    <div style="text-align: right; margin-top: 20px;">
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> บันทึกการตั้งค่า</button>
                    </div>
                </div>
            </form>
        </div>
    </main>
    <script>
        function previewLogo() {
            const input = document.getElementById('logoInput');
            const previewBox = document.getElementById('logoPreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewBox.innerHTML = '';
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.width = '100%';
                    img.style.height = '100%';
                    img.style.objectFit = 'cover';
                    previewBox.appendChild(img);
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        <?php if ($message): ?>
            Swal.fire({
                title: '<?php echo ($msg_type == 'success') ? 'สำเร็จ!' : 'แจ้งเตือน'; ?>',
                text: '<?php echo $message; ?>',
                icon: '<?php echo $msg_type; ?>',
                confirmButtonText: 'ตกลง'
            });
        <?php endif; ?>
    </script>
</body>
</html>