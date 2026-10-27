<?php
session_start();
require_once '../db/db.php';

// 1. Strict RBAC: Check if Superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', '1'])) {
    header("Location: ../login.php");
    exit();
}

// 2. เชื่อมต่อฐานข้อมูลก่อนใช้งานทุกอย่าง
$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// 3. สร้างตารางถ้ายังไม่มี
$pdo->exec("CREATE TABLE IF NOT EXISTS waste_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10, 2) DEFAULT 0.00,
    points INT DEFAULT 0,
    behavior_points INT DEFAULT 0,
    unit VARCHAR(20) DEFAULT 'ชิ้น'
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS price_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    waste_code VARCHAR(50) NOT NULL,
    old_price DECIMAL(10, 2),
    new_price DECIMAL(10, 2),
    old_points INT,
    new_points INT,
    old_behavior INT DEFAULT 0,
    new_behavior INT DEFAULT 0,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 4. เพิ่มคอลัมน์ที่ขาด (สำหรับตารางเก่าที่มีอยู่แล้ว)
$columnsToCheck = [
    'waste_types' => [
        'behavior_points' => "INT DEFAULT 0"
    ],
    'price_logs' => [
        'old_behavior' => "INT DEFAULT 0",
        'new_behavior' => "INT DEFAULT 0"
    ]
];

foreach ($columnsToCheck as $table => $columns) {
    $existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($columns as $column => $definition) {
        if (!in_array($column, $existing, true)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

// 5. Seed ถ้าว่าง
if ($pdo->query("SELECT COUNT(*) FROM waste_types")->fetchColumn() == 0) {
    $seed = [
        ['pet', 'ขวดพลาสติก PET', 2.00, 10, 5, 'ชิ้น'],
        ['plastic', 'ขวดพลาสติก ทั่วไป', 2.00, 10, 5, 'ชิ้น'],
        ['glass', 'ขวดแก้ว', 1.50, 8, 4, 'ชิ้น'],
        ['can', 'กระป๋องอลูมิเนียม', 3.00, 15, 8, 'ชิ้น'],
        ['paper', 'กระดาษ', 1.00, 5, 2, 'กิโลกรัม']
    ];
    $stmt = $pdo->prepare("INSERT INTO waste_types (code, name, price, points, behavior_points, unit) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($seed as $s) $stmt->execute($s);
}

// 6. CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$msg_type = '';

// 7. Handle POST (Bulk Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security Error: Invalid CSRF Token";
        $msg_type = "error";
    } else {
        $prices = $_POST['price'] ?? [];
        $points = $_POST['points'] ?? [];
        $behavior_points = $_POST['behavior_points'] ?? [];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE waste_types SET price = :price, points = :points, behavior_points = :behavior WHERE code = :code");
            $log_stmt = $pdo->prepare("INSERT INTO price_logs (waste_code, old_price, new_price, old_points, new_points, old_behavior, new_behavior, updated_by) VALUES (:code, :old_p, :new_p, :old_pts, :new_pts, :old_beh, :new_beh, :uid)");
            $old_stmt = $pdo->prepare("SELECT price, points, behavior_points FROM waste_types WHERE code = ?");

            $codes = $pdo->query("SELECT code FROM waste_types")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($codes as $code) {
                $point = isset($points[$code]) ? intval($points[$code]) : 0;
                $price = isset($prices[$code]) ? floatval($prices[$code]) : 0.00;
                $behavior = isset($behavior_points[$code]) ? intval($behavior_points[$code]) : 0;

                if ($point < 0 || $price < 0 || $behavior < 0) {
                    throw new Exception("ข้อมูลต้องไม่ติดลบ");
                }

                $old_stmt->execute([$code]);
                $old = $old_stmt->fetch();

                if ($old && (
                    (float)$old['price'] != $price ||
                    (int)$old['points'] != $point ||
                    (int)$old['behavior_points'] != $behavior
                )) {
                    $stmt->execute([
                        ':price' => $price,
                        ':points' => $point,
                        ':behavior' => $behavior,
                        ':code' => $code
                    ]);

                    $log_stmt->execute([
                        ':code' => $code,
                        ':old_p' => $old['price'],
                        ':new_p' => $price,
                        ':old_pts' => $old['points'],
                        ':new_pts' => $point,
                        ':old_beh' => $old['behavior_points'],
                        ':new_beh' => $behavior,
                        ':uid' => $_SESSION['user_id']
                    ]);
                }
            }

            $pdo->commit();
            $message = "บันทึกข้อมูลเรียบร้อยแล้ว";
            $msg_type = "success";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// 8. Fetch Data
$stmt = $pdo->query("SELECT * FROM waste_types ORDER BY id ASC");
$waste_types = $stmt->fetchAll();

// 9. Fetch History Logs
$log_sql = "SELECT l.*, u.first_name, u.last_name, w.name as waste_name
            FROM price_logs l
            LEFT JOIN users u ON l.updated_by = u.id
            LEFT JOIN waste_types w ON l.waste_code COLLATE utf8mb4_general_ci = w.code COLLATE utf8mb4_general_ci
            ORDER BY l.updated_at DESC LIMIT 20";
$logs = $pdo->query($log_sql)->fetchAll();

function getIcon($code) {
    $icons = [
        'pet' => 'fa-recycle',
        'plastic' => 'fa-bottle-water',
        'glass' => 'fa-wine-bottle',
        'can' => 'fa-box',
        'paper' => 'fa-newspaper'
    ];
    return $icons[$code] ?? 'fa-trash';
}

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

function thai_date($strDate) {
    $strYear = date("Y", strtotime($strDate)) + 543;
    $strMonth = date("n", strtotime($strDate));
    $strDay = date("j", strtotime($strDate));
    $strHour = date("H", strtotime($strDate));
    $strMinute = date("i", strtotime($strDate));
    $strMonthCut = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    return "$strDay " . $strMonthCut[$strMonth] . " $strYear $strHour:$strMinute";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าราคารับซื้อ</title>
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
            <p class="admin-name"><?php echo h(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')); ?></p>
            <p class="admin-role">ผู้ดูแลระบบ</p>
            <span class="badge-admin">SUPER ADMIN</span>
        </div>

        <?php include 'includes/bottom_nav.php'; ?>
        <div class="sidebar-footer"><a href="../logout.php" class="menu-item text-muted"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a></div>
    </aside>

    <main class="main-content">
        <header class="page-header">
            <h1>ตั้งค่าราคารับซื้อ</h1>
            <p class="subtitle">กำหนดแต้ม ราคาเงิน และคะแนนพฤติกรรม</p>
        </header>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <div class="stats-grid">
                <?php foreach ($waste_types as $type): ?>
                <div class="content-card" style="padding: 24px;">
                    <div class="icon-wrapper bg-green txt-green"><i class="fas <?php echo h(getIcon($type['code'])); ?>"></i></div>
                    <h3><?php echo h($type['name']); ?></h3>

                    <div style="margin-top: 15px;">
                        <label style="display:block; margin-bottom: 5px; font-size: 0.9rem;">แต้มต่อ<?php echo h($type['unit']); ?></label>
                        <input type="number" step="1" min="0" name="points[<?php echo h($type['code']); ?>]" class="search-box points-input" data-code="<?php echo h($type['code']); ?>" value="<?php echo h($type['points']); ?>" required>
                    </div>

                    <div style="margin-top: 10px;">
                        <label style="display:block; margin-bottom: 5px; font-size: 0.9rem;">ราคา (บาท) ต่อ<?php echo h($type['unit']); ?></label>
                        <input type="number" step="0.01" min="0" name="price[<?php echo h($type['code']); ?>]" class="search-box price-input" data-code="<?php echo h($type['code']); ?>" value="<?php echo h($type['price']); ?>" required>
                    </div>

                    <div style="margin-top: 10px;">
                        <label style="display:block; margin-bottom: 5px; font-size: 0.9rem;">คะแนนพฤติกรรมต่อ<?php echo h($type['unit']); ?></label>
                        <input type="number" step="1" min="0" name="behavior_points[<?php echo h($type['code']); ?>]" class="search-box behavior-input" data-code="<?php echo h($type['code']); ?>" value="<?php echo h($type['behavior_points'] ?? 0); ?>" required>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <button type="submit" class="btn-primary" style="padding: 12px 40px;">บันทึกการเปลี่ยนแปลง</button>
            </div>
        </form>

        <div class="content-card" style="margin-top: 40px;">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> ประวัติการแก้ไข</h3>
            </div>
            <div class="list-group">
                <?php if (count($logs) > 0): ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>วัน/เวลา</th>
                                <th>ประเภทขยะ</th>
                                <th>แต้ม</th>
                                <th>ราคา (บาท)</th>
                                <th>คะแนนพฤติกรรม</th>
                                <th>ผู้แก้ไข</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="color: #666; font-size: 0.85rem;"><?php echo h(thai_date($log['updated_at'])); ?></td>
                                <td style="font-weight: 600;"><?php echo h($log['waste_name'] ?? $log['waste_code']); ?></td>
                                <td><?php echo number_format((float)$log['old_points']); ?> ➝ <?php echo number_format((float)$log['new_points']); ?></td>
                                <td><?php echo number_format((float)$log['old_price'], 2); ?> ➝ <?php echo number_format((float)$log['new_price'], 2); ?></td>
                                <td><?php echo number_format((float)($log['old_behavior'] ?? 0)); ?> ➝ <?php echo number_format((float)($log['new_behavior'] ?? 0)); ?></td>
                                <td><?php echo h(trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''))); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="padding: 30px; text-align: center; color: #999;">ยังไม่มีประวัติการแก้ไข</div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        <?php if ($message): ?>
            Swal.fire({
                title: <?php echo json_encode($msg_type === 'success' ? 'สำเร็จ!' : 'แจ้งเตือน', JSON_UNESCAPED_UNICODE); ?>,
                text: <?php echo json_encode($message, JSON_UNESCAPED_UNICODE); ?>,
                icon: <?php echo json_encode($msg_type); ?>,
                confirmButtonText: 'ตกลง'
            });
        <?php endif; ?>
    </script>
</body>
</html>