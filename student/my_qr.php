<?php
session_start();
require_once '../db/db.php'; 

// ตรวจสอบสิทธิ์ว่าเป็นนักเรียน (role = 'user')
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();
$user_id = $_SESSION['user_id'];

// ดึงข้อมูลนักเรียน
$stmt = $pdo->prepare("SELECT first_name, last_name, email, id_code FROM users WHERE id = :id AND role = 'user'");
$stmt->execute([':id' => $user_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("ไม่พบข้อมูลนักเรียน");
}

// ป้องกันกรณี id_code เป็น NULL ให้แปลงเป็น string ว่าง ""
$data_to_qr = $student['id_code'] ?? ''; 
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code ของฉัน - EcoBank</title>
    <link rel="stylesheet" href="studentcss/student.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" charset="UTF-8" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f4f7f6; margin: 0; padding-bottom: 80px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: #fff; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); text-align: center; width: 90%; max-width: 340px; }
        .qr-img { border: 1px solid #eaeaea; padding: 10px; border-radius: 12px; margin-top: 15px; background: #fff; width: 200px; height: 200px; display: flex; justify-content: center; align-items: center; margin-left: auto; margin-right: auto; }
        .qr-img img { display: block; max-width: 100%; height: auto; }
        h3 { color: #111827; margin-bottom: 5px; font-size: 1.25rem; }
        p { color: #6b7280; margin: 5px 0; font-size: 0.9rem; }
        .info-box { margin-top: 15px; text-align: left; background: #f9fafb; padding: 12px; border-radius: 10px; border: 1px solid #f3f4f6; }
    </style>
</head>
<body>

<div class="card">
    <h3>QR Code ประจำตัว</h3>
    <p>แสดงหน้านี้ให้เจ้าหน้าที่สแกนฝากขวด</p>
    
    <!-- พื้นที่สำหรับแสดง QR Code ด้วย JavaScript -->
    <div id="qrcode" class="qr-img"></div>
    
    <div class="info-box">
        <p><strong>ชื่อ:</strong> <?php echo htmlspecialchars($student['first_name'] . " " . $student['last_name']); ?></p>
        <p><strong>รหัส 5 หลัก:</strong> <span style="color: #10b981; font-weight: bold;"><?php echo htmlspecialchars($student['id_code'] ?? 'ยังไม่ได้กำหนด'); ?></span></p>
    </div>
</div>

<!-- เรียกใช้งาน Bottom Navigation ของนักเรียน -->
<?php include 'includes/bottom_nav.php'; ?>

<!-- โหลดไลบรารี qrcode.js สำหรับสร้าง QR Code ฝั่งเบราว์เซอร์ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    // นำค่า id_code จาก PHP มาเจนเป็น QR Code สดๆ
    var studentIdCode = "<?php echo htmlspecialchars($data_to_qr, ENT_QUOTES, 'UTF-8'); ?>";

    if (studentIdCode) {
        new QRCode(document.getElementById("qrcode"), {
            text: studentIdCode,
            width: 180,
            height: 180,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });
    } else {
        document.getElementById("qrcode").innerHTML = "<p style='color:red; font-size:0.8rem;'>ยังไม่กำหนดรหัส 5 หลัก</p>";
    }
</script>

</body>
</html>