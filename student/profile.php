<?php
session_start();
require_once '../db/db.php';

// 1. Authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$pdo = Database::connect();

// 2. CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$msg_type = '';

// 3. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security Error: Invalid CSRF Token";
        $msg_type = "error";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_info') {
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $email = trim($_POST['email']);

            if (empty($first_name) || empty($last_name)) {
                $message = "กรุณากรอกชื่อและนามสกุล";
                $msg_type = "error";
            } else {
                try {
                    // Check email duplicate if changed
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $student_id]);
                    if ($stmt->fetch()) {
                        throw new Exception("อีเมลนี้ถูกใช้งานแล้ว");
                    }

                    $sql = "UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$first_name, $last_name, $email, $student_id]);

                    // Update Session Name
                    $_SESSION['first_name'] = $first_name;
                    $_SESSION['last_name'] = $last_name;
                    $_SESSION['name'] = $first_name . ' ' . $last_name;

                    $message = "บันทึกข้อมูลส่วนตัวสำเร็จ";
                    $msg_type = "success";
                } catch (Exception $e) {
                    $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                    $msg_type = "error";
                }
            }
        } elseif ($action === 'update_avatar') {
            try {
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../uploads/avatars/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $file = $_FILES['avatar'];
                    $file_size = $file['size'];
                    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

                    if (!in_array($file_ext, $allowed_ext)) {
                        throw new Exception("รองรับไฟล์รูปภาพ (JPG, PNG, GIF) เท่านั้น");
                    }

                    if ($file_size > 2 * 1024 * 1024) { // 2MB limit
                        throw new Exception("ขนาดไฟล์ต้องไม่เกิน 2MB");
                    }

                    // Fetch old avatar to delete it later
                    $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
                    $stmt->execute([$student_id]);
                    $old_avatar = $stmt->fetchColumn();

                    // Generate unique filename
                    $new_file_name = 'avatar_' . $student_id . '_' . uniqid() . '.' . $file_ext;
                    $target_path = $upload_dir . $new_file_name;

                    if (move_uploaded_file($file['tmp_name'], $target_path)) {
                        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                        $stmt->execute([$new_file_name, $student_id]);

                        if ($old_avatar && file_exists($upload_dir . $old_avatar)) {
                            unlink($upload_dir . $old_avatar);
                        }
                        $message = "อัปเดตรูปโปรไฟล์สำเร็จ"; $msg_type = "success";
                    } else { throw new Exception("ไม่สามารถอัปโหลดไฟล์ได้"); }
                } else { throw new Exception("กรุณาเลือกไฟล์รูปภาพ"); }
            } catch (Exception $e) {
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage(); $msg_type = "error";
            }
        } elseif ($action === 'change_password') {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if (empty($current_password) || empty($new_password)) {
                $message = "กรุณากรอกรหัสผ่านให้ครบถ้วน";
                $msg_type = "error";
            } elseif ($new_password !== $confirm_password) {
                $message = "รหัสผ่านใหม่ไม่ตรงกัน";
                $msg_type = "error";
            } elseif (strlen($new_password) < 6) {
                $message = "รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร";
                $msg_type = "error";
            } else {
                try {
                    // Verify current password
                    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                    $stmt->execute([$student_id]);
                    $user_auth = $stmt->fetch();

                    if ($user_auth && password_verify($current_password, $user_auth['password'])) {
                        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$new_hash, $student_id]);

                        $message = "เปลี่ยนรหัสผ่านสำเร็จ";
                        $msg_type = "success";
                    } else {
                        $message = "รหัสผ่านปัจจุบันไม่ถูกต้อง";
                        $msg_type = "error";
                    }
                } catch (Exception $e) {
                    $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                    $msg_type = "error";
                }
            }
        }
    }
}

// 4. Fetch User Data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$user = $stmt->fetch();

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขข้อมูลส่วนตัว - EcoBank</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="studentcss/student.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Custom CSS สำหรับความหรูหราและ Responsive */
        .profile-header {
            padding: 30px 20px 60px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border-radius: 0 0 30px 30px;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3);
            text-align: center;
            position: relative;
        }
        .profile-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .profile-container {
            padding: 0 20px;
            margin-top: -45px; /* ดึง Card ขึ้นไปทับ Header เล็กน้อย */
            position: relative;
            z-index: 10;
        }
        .pro-card {
            background: white;
            border-radius: 20px;
            padding: 25px 20px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.06);
            margin-bottom: 25px;
        }
        .avatar-wrapper {
            position: relative;
            width: 100px;
            height: 100px;
            margin: 0 auto 15px;
        }
        .avatar-preview-box {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .avatar-preview-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .camera-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background: #10b981;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: transform 0.2s;
        }
        .camera-btn:hover {
            transform: scale(1.1);
        }
        .pro-input {
            width: 100%;
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            font-family: 'Kanit', sans-serif;
            font-size: 1rem;
            outline: none;
            box-sizing: border-box; /* สำคัญมาก กัน input ทะลุจอ */
            background: #f9fafb;
            transition: border 0.3s, background 0.3s;
        }
        .pro-input:focus {
            border-color: #10b981;
            background: #ffffff;
        }
        .pro-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #4b5563;
            font-size: 0.9rem;
        }
        .pro-btn {
            width: 100%;
            padding: 14px;
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Kanit', sans-serif;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-green {
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        .btn-pink {
            background: linear-gradient(135deg, #f43f5e, #e11d48);
            box-shadow: 0 4px 15px rgba(225, 29, 72, 0.3);
        }
        .pro-btn:active {
            transform: translateY(2px);
        }
        .grid-2-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
    </style>
</head>
<body class="mb-body" style="background: #f4f7f6; padding-bottom: 80px;">

    <!-- Header โค้งสวยงาม -->
    <div class="profile-header">
        <div class="profile-nav">
            <a href="user.php" style="color: white; font-size: 1.3rem; text-decoration: none;"><i class="fas fa-arrow-left"></i></a>
            <h2 style="margin: 0; font-size: 1.25rem; font-weight: 600;">โปรไฟล์ส่วนตัว</h2>
            <div style="width: 20px;"></div> <!-- ตัวถ่วงน้ำหนักให้ Flex ตรงกลาง -->
        </div>
    </div>

    <div class="profile-container">
        
        <!-- Section: อัปเดตรูปโปรไฟล์ -->
        <div class="pro-card" style="text-align: center;">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_avatar">
                
                <div class="avatar-wrapper">
                    <div class="avatar-preview-box">
                        <?php if (!empty($user['avatar'])): ?>
                            <img id="avatarPreview" src="../uploads/avatars/<?php echo h($user['avatar']); ?>" alt="Avatar">
                            <i id="avatarIcon" class="fas fa-user" style="display: none;"></i>
                        <?php else: ?>
                            <img id="avatarPreview" src="" alt="Avatar" style="display: none;">
                            <i id="avatarIcon" class="fas fa-user" style="font-size: 3rem; color: #cbd5e1;"></i>
                        <?php endif; ?>
                    </div>
                    <!-- ปุ่มรูปกล้องสำหรับเลือกไฟล์ซ่อนทับ Input -->
                    <label for="avatarInput" class="camera-btn">
                        <i class="fas fa-camera" style="font-size: 0.9rem;"></i>
                    </label>
                </div>
                
                <input type="file" name="avatar" id="avatarInput" accept="image/*" onchange="previewFile()" style="display: none;" required>
                <p style="font-size: 0.8rem; color: #9ca3af; margin: 0 0 15px;">รองรับไฟล์ JPG, PNG (ไม่เกิน 2MB)</p>
                
                <button type="submit" class="pro-btn btn-green">อัปเดตรูปโปรไฟล์</button>
            </form>
        </div>

        <!-- Section: ข้อมูลทั่วไป -->
        <div class="pro-card">
            <h3 style="margin: 0 0 20px 0; color: #10b981; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-id-card"></i> ข้อมูลทั่วไป
            </h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_info">
                
                <div style="margin-bottom: 15px;">
                    <label class="pro-label">รหัสนักเรียน</label>
                    <input type="text" name="id_code" value="<?php echo h($user['id_code']); ?>" class="pro-input" readonly style="background: #e5e7eb; color: #6b7280; cursor: not-allowed;" title="ไม่สามารถแก้ไขรหัสได้">
                </div>

                <div class="grid-2-col" style="margin-bottom: 15px;">
                    <div>
                        <label class="pro-label">ชื่อจริง</label>
                        <input type="text" name="first_name" value="<?php echo h($user['first_name']); ?>" required class="pro-input">
                    </div>
                    <div>
                        <label class="pro-label">นามสกุล</label>
                        <input type="text" name="last_name" value="<?php echo h($user['last_name']); ?>" required class="pro-input">
                    </div>
                </div>

                <div style="margin-bottom: 25px;">
                    <label class="pro-label">อีเมล</label>
                    <input type="email" name="email" value="<?php echo h($user['email']); ?>" class="pro-input">
                </div>

                <button type="submit" class="pro-btn btn-green">บันทึกข้อมูลส่วนตัว</button>
            </form>
        </div>

        <!-- Section: เปลี่ยนรหัสผ่าน -->
        <div class="pro-card">
            <h3 style="margin: 0 0 20px 0; color: #e11d48; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-lock"></i> เปลี่ยนรหัสผ่าน
            </h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="change_password">
                
                <div style="margin-bottom: 15px;">
                    <label class="pro-label">รหัสผ่านปัจจุบัน</label>
                    <input type="password" name="current_password" required class="pro-input" placeholder="••••••••">
                </div>

                <div style="margin-bottom: 15px;">
                    <label class="pro-label">รหัสผ่านใหม่ (อย่างน้อย 6 ตัวอักษร)</label>
                    <input type="password" name="new_password" required class="pro-input" placeholder="••••••••">
                </div>

                <div style="margin-bottom: 25px;">
                    <label class="pro-label">ยืนยันรหัสผ่านใหม่</label>
                    <input type="password" name="confirm_password" required class="pro-input" placeholder="••••••••">
                </div>

                <button type="submit" class="pro-btn btn-pink">อัปเดตรหัสผ่าน</button>
            </form>
        </div>

    </div>

    <!-- Bottom Nav -->
    <?php include 'includes/bottom_nav.php'; ?>

    <script>
        // ระบบ Preview รูปก่อนกดอัปโหลด
        function previewFile() {
            const preview = document.getElementById('avatarPreview');
            const icon = document.getElementById('avatarIcon');
            const file = document.getElementById('avatarInput').files[0];
            const reader = new FileReader();

            reader.addEventListener("load", function () {
                if (icon) icon.style.display = 'none';
                preview.style.display = 'block';
                preview.src = reader.result;
            }, false);

            if (file) {
                reader.readAsDataURL(file);
            }
        }

        // แจ้งเตือนสวยๆ ด้วย SweetAlert2
        <?php if ($message): ?>
            Swal.fire({
                title: '<?php echo ($msg_type == 'success') ? 'สำเร็จ!' : 'ข้อผิดพลาด'; ?>',
                text: '<?php echo $message; ?>',
                icon: '<?php echo $msg_type; ?>',
                confirmButtonColor: '<?php echo ($msg_type == 'success') ? '#10b981' : '#e11d48'; ?>',
                confirmButtonText: 'ตกลง',
                customClass: {
                    popup: 'swal2-custom-popup'
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>