<?php
session_start();
require_once '../db/db.php';

// 1. Authentication Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();
$user_id = $_SESSION['user_id'];

// 2. CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 3. Fetch User Data
$stmt = $pdo->prepare("SELECT first_name, last_name, email, id_code, class_group FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("ไม่พบข้อมูลนักเรียน");
}

// 4. Check if required fields are already filled. If so, redirect to user.php directly.
$required_fields = ['id_code', 'class_group'];
$missing_fields = [];
foreach ($required_fields as $field) {
    if (empty($student[$field])) {
        $missing_fields[] = $field;
    }
}

if (empty($missing_fields)) {
    header("Location: user.php");
    exit();
}

$error = "";

// 5. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF Token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Security Error: Invalid CSRF Token";
    } else {
        $updates = [];
        foreach ($missing_fields as $field) {
            if (!empty($_POST[$field])) {
                $updates[$field] = trim($_POST[$field]);
            }
        }

        if (empty($updates)) {
            $error = "กรุณากรอกข้อมูลที่ขาดให้ครบถ้วน";
        } else {
            // ตรวจสอบค่าซ้ำของ id_code
            if (isset($updates['id_code'])) {
                $check = $pdo->prepare("SELECT id FROM users WHERE id_code = :id_code AND id != :id");
                $check->execute([':id_code' => $updates['id_code'], ':id' => $user_id]);
                if ($check->fetch()) {
                    $error = "รหัส 5 หลักนี้ถูกใช้งานแล้ว กรุณาใช้รหัสอื่น";
                }
            }

            if (empty($error)) {
                $set_clause = [];
                foreach ($updates as $field => $value) {
                    $set_clause[] = "$field = :$field";
                }
                
                $sql = "UPDATE users SET " . implode(", ", $set_clause) . " WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $updates['id'] = $user_id;
                $stmt->execute($updates);

                // เปลี่ยนเส้นทางไปยังหน้าหลักนักเรียนหลังจากกรอกข้อมูลเสร็จ
                header("Location: user.php");
                exit();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>กรอกข้อมูลเพิ่มเติม - EcoBank</title>
    <link rel="stylesheet" href="studentcss/student.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f4f7f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); text-align: center; width: 90%; max-width: 340px; }
        h3 { color: #111827; margin-bottom: 10px; }
        p { color: #6b7280; font-size: 0.9rem; margin-bottom: 15px; }
        .input-group { margin: 15px 0; text-align: left; }
        .input-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #374151; font-size: 0.9rem; }
        .input-field { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; box-sizing: border-box; }
        .btn-submit { background: #10b981; color: #fff; border: none; padding: 10px; border-radius: 8px; cursor: pointer; width: 100%; font-size: 1rem; margin-top: 10px; }
        .btn-submit:hover { background: #059669; }
        .alert { margin: 10px 0; padding: 10px; border-radius: 8px; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
    <div class="card">
        <h3>กรอกข้อมูลเพิ่มเติม</h3>
        <p>กรุณากรอกข้อมูลรหัส 5 หลักและห้องเรียน เพื่อเริ่มใช้งานระบบจัดการธนาคารขยะ</p>

        <?php if($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <?php if(in_array('id_code', $missing_fields)): ?>
                <div class="input-group">
                    <label>รหัสประจำตัว 5 หลัก</label>
                    <input type="text" name="id_code" class="input-field" placeholder="เช่น 53706" maxlength="5" required>
                </div>
            <?php endif; ?>

            <?php if(in_array('class_group', $missing_fields)): ?>
                <div class="input-group">
                    <label>ห้องเรียน / กลุ่ม</label>
                    <input type="text" name="class_group" class="input-field" placeholder="เช่น ม.4/1" required>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn-submit">บันทึกข้อมูลและเข้าสู่ระบบ</button>
        </form>
    </div>
</body>
</html>