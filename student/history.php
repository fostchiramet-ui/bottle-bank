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

// Helper Functions (Moved up for Export usage)
function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

function thai_date($strDate) {
    $strYear = date("Y",strtotime($strDate))+543;
    $strMonth= date("n",strtotime($strDate));
    $strDay= date("j",strtotime($strDate));
    $strHour= date("H",strtotime($strDate));
    $strMinute= date("i",strtotime($strDate));
    $strMonthCut = Array("","ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค.");
    $strMonthThai=$strMonthCut[$strMonth];
    return "$strDay $strMonthThai $strYear • $strHour:$strMinute น.";
}

function getBottleInfo($type) {
    $map = [
        'pet' => ['name' => 'ขวดพลาสติก PET', 'icon' => 'fa-wine-bottle', 'style' => ''],
        'hdpe' => ['name' => 'HDPE (ขุ่น)', 'icon' => 'fa-prescription-bottle', 'style' => 'background: #fff8e1; color: #ffc107;'],
        'can' => ['name' => 'กระป๋อง', 'icon' => 'fa-box', 'style' => 'background: #f3f4f6; color: #666;'],
        'glass' => ['name' => 'แก้ว', 'icon' => 'fa-wine-glass', 'style' => 'background: #e0f2f1; color: #009688;'],
        'paper' => ['name' => 'กระดาษ', 'icon' => 'fa-newspaper', 'style' => 'background: #f3e5f5; color: #795548;']
    ];
    return $map[$type] ?? ['name' => ucfirst($type), 'icon' => 'fa-recycle', 'style' => ''];
}

// 2. ดึงข้อมูลสรุปยอดรวม (ดึงทั้ง แต้ม, เงินอ้างอิง และจำนวนชิ้น)
$sql_summary = "SELECT 
    SUM(money_earned) as total_money, 
    SUM(points_earned) as total_points, 
    SUM(quantity) as total_items 
    FROM bottle_entries 
    WHERE student_id = :uid";
$stmt = $pdo->prepare($sql_summary);
$stmt->execute([':uid' => $student_id]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// 2.5 ดึงคะแนนพฤติกรรมจากตาราง users
$sql_user_score = "SELECT behavior_score FROM users WHERE id = :uid";
$stmt_score = $pdo->prepare($sql_user_score);
$stmt_score->execute([':uid' => $student_id]);
$user_data = $stmt_score->fetch(PDO::FETCH_ASSOC);
$behavior_score = $user_data['behavior_score'] ?? 0;

// 3. ดึงข้อมูลยอดรวมแยกประเภท
$sql_types = "SELECT bottle_type, SUM(quantity) as qty FROM bottle_entries WHERE student_id = :uid GROUP BY bottle_type";
$stmt = $pdo->prepare($sql_types);
$stmt->execute([':uid' => $student_id]);
$type_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. ดึงประวัติการฝาก (พร้อม Filter ที่ปลอดภัย)
$filter_type = $_GET['type'] ?? 'all';
$allowed_types = ['pet', 'hdpe', 'can', 'glass', 'paper']; // Whitelist

// Export CSV Logic
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $sql_export = "SELECT b.created_at, b.bottle_type, b.quantity, b.money_earned, b.points_earned, u.first_name as approver_name 
                   FROM bottle_entries b 
                   LEFT JOIN users u ON b.recorded_by = u.id 
                   WHERE b.student_id = :uid";
    
    $params_export = [':uid' => $student_id];
    
    if ($filter_type !== 'all' && in_array($filter_type, $allowed_types)) {
        $sql_export .= " AND b.bottle_type = :type";
        $params_export[':type'] = $filter_type;
    }
    
    $sql_export .= " ORDER BY b.created_at DESC";
    
    $stmt = $pdo->prepare($sql_export);
    $stmt->execute($params_export);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=history_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM for Excel
    fputcsv($output, ['วันที่', 'ประเภท', 'จำนวน', 'เงิน (บาท)', 'แต้ม', 'อนุมัติโดย']);
    
    foreach ($rows as $row) {
        $info = getBottleInfo($row['bottle_type']);
        fputcsv($output, [
            thai_date($row['created_at']),
            $info['name'],
            $row['quantity'],
            $row['money_earned'],
            $row['points_earned'],
            $row['approver_name'] ?? 'เจ้าหน้าที่'
        ]);
    }
    fclose($output);
    exit();
}

$sql_history = "SELECT b.*, u.first_name as approver_name 
                FROM bottle_entries b 
                LEFT JOIN users u ON b.recorded_by = u.id 
                WHERE b.student_id = :uid";

$params = [':uid' => $student_id];

if ($filter_type !== 'all' && in_array($filter_type, $allowed_types)) {
    $sql_history .= " AND b.bottle_type = :type";
    $params[':type'] = $filter_type;
}

$sql_history .= " ORDER BY b.created_at DESC";

$stmt = $pdo->prepare($sql_history);
$stmt->execute($params);
$history_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการฝาก - EcoBank</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="studentcss/student.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS ดีไซน์ใหม่ สไตล์ Widget 2x2 Grid + เว้นระยะขอบพอดีหน้าจอ */
        .mb-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px; /* เพิ่มระยะห่างระหว่างกล่องให้อ่านง่ายขึ้น */
            margin-top: 15px;
            margin-bottom: 25px;
            padding: 0 20px; /* <--- เพิ่ม Padding ซ้ายขวา กันไม่ให้ชิดขอบจอ */
            box-sizing: border-box;
        }
        .mb-summary-card {
            padding: 20px 15px; /* เพิ่มพื้นที่ในกล่องให้ดูโปร่งขึ้น */
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            position: relative;
            overflow: hidden;
        }
        .mb-summary-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 12px;
            z-index: 2;
        }
        .mb-summary-val {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 4px;
            z-index: 2;
        }
        .mb-summary-lbl {
            font-size: 0.75rem;
            font-weight: 500;
            z-index: 2;
        }
        .bg-shape {
            position: absolute;
            right: -10px;
            bottom: -15px;
            font-size: 5rem;
            opacity: 0.15;
            z-index: 1;
            transform: rotate(-15deg);
        }
        
        /* ปรับ Section Title ให้ตรงกับ Grid */
        .mb-section-title-custom {
            padding: 0 20px;
            margin-bottom: 15px;
            font-size: 1.1rem;
            font-weight: 600;
            color: #374151;
        }
    </style>
</head>
    <body class="mb-body">

    <!-- สรุปยอด 4 หมวดหมู่ (ดีไซน์ใหม่แบบ Widget 2x2 สวยงามและเว้นขอบพอดี) -->
    <section class="mb-summary-grid">
        <!-- 1. แต้มสะสม -->
        <div class="mb-summary-card" style="background: linear-gradient(135deg, #fff5f7 0%, #ffe4e6 100%); border: 1px solid #fecdd3;">
            <i class="fas fa-star bg-shape" style="color: #fda4af;"></i>
            <div class="mb-summary-icon" style="background: #e11d48; color: white; box-shadow: 0 4px 10px rgba(225, 29, 72, 0.3);">
                <i class="fas fa-star"></i>
            </div>
            <span class="mb-summary-val" style="color: #be123c;"><?php echo number_format($summary['total_points'] ?? 0); ?></span>
            <span class="mb-summary-lbl" style="color: #9f1239;">แต้มสะสม (ตัวกลาง)</span>
        </div>

        <!-- 2. คะแนนพฤติกรรม -->
        <div class="mb-summary-card" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 1px solid #fde68a;">
            <i class="fas fa-award bg-shape" style="color: #fcd34d;"></i>
            <div class="mb-summary-icon" style="background: #d97706; color: white; box-shadow: 0 4px 10px rgba(217, 119, 6, 0.3);">
                <i class="fas fa-award"></i>
            </div>
            <span class="mb-summary-val" style="color: #b45309;"><?php echo number_format($behavior_score); ?></span>
            <span class="mb-summary-lbl" style="color: #92400e;">คะแนนพฤติกรรม</span>
        </div>

        <!-- 3. ยอดเงิน -->
        <div class="mb-summary-card" style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1px solid #bbf7d0;">
            <i class="fas fa-wallet bg-shape" style="color: #86efac;"></i>
            <div class="mb-summary-icon" style="background: #16a34a; color: white; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.3);">
                <i class="fas fa-wallet"></i>
            </div>
            <span class="mb-summary-val" style="color: #15803d;"><?php echo number_format($summary['total_money'] ?? 0, 2); ?> ฿</span>
            <span class="mb-summary-lbl" style="color: #166534;">มูลค่าอ้างอิง (บาท)</span>
        </div>

        <!-- 4. จำนวนชิ้น -->
        <div class="mb-summary-card" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #bfdbfe;">
            <i class="fas fa-recycle bg-shape" style="color: #93c5fd;"></i>
            <div class="mb-summary-icon" style="background: #2563eb; color: white; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);">
                <i class="fas fa-recycle"></i>
            </div>
            <span class="mb-summary-val" style="color: #1d4ed8;"><?php echo number_format($summary['total_items'] ?? 0); ?></span>
            <span class="mb-summary-lbl" style="color: #1e3a8a;">จำนวนชิ้นที่ฝาก</span>
        </div>
    </section>

    <h3 class="mb-section-title-custom">ยอดสะสมแยกประเภท</h3>
    <section class="mb-type-grid">
        <?php foreach(['pet' => 'PET (ใส)', 'hdpe' => 'HDPE (ขุ่น)', 'can' => 'กระป๋อง', 'glass' => 'แก้ว'] as $key => $label): ?>
        <div class="mb-type-card">
            <div class="mb-type-icon"><i class="fas <?php echo getBottleInfo($key)['icon']; ?>"></i></div>
            <div>
                <span class="mb-type-val"><?php echo number_format($type_data[$key] ?? 0); ?></span>
                <span class="mb-type-lbl"><?php echo $label; ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <section class="mb-filters" style="padding: 0 20px;">
        <a href="?type=all" class="mb-filter-btn <?php echo $filter_type === 'all' ? 'active' : ''; ?>" style="text-decoration: none; display: inline-block;">ทั้งหมด</a>
        <a href="?type=pet" class="mb-filter-btn <?php echo $filter_type === 'pet' ? 'active' : ''; ?>" style="text-decoration: none; display: inline-block;">PET (ใส)</a>
        <a href="?type=hdpe" class="mb-filter-btn <?php echo $filter_type === 'hdpe' ? 'active' : ''; ?>" style="text-decoration: none; display: inline-block;">HDPE (ขุ่น)</a>
        <a href="?type=can" class="mb-filter-btn <?php echo $filter_type === 'can' ? 'active' : ''; ?>" style="text-decoration: none; display: inline-block;">กระป๋อง</a>
        <a href="?type=glass" class="mb-filter-btn <?php echo $filter_type === 'glass' ? 'active' : ''; ?>" style="text-decoration: none; display: inline-block;">แก้ว</a>
    </section>

    <section class="mb-list" style="padding: 0 20px;">
        <?php if (count($history_items) > 0): ?>
            <?php foreach ($history_items as $item): 
                $bInfo = getBottleInfo($item['bottle_type']);
            ?>
            <div class="mb-item">
                <div class="mb-icon" style="<?php echo $bInfo['style']; ?>">
                    <i class="fas <?php echo $bInfo['icon']; ?>"></i>
                </div>
                <div class="mb-info">
                    <span class="mb-title"><?php echo h($bInfo['name']); ?></span>
                    <span class="mb-date"><?php echo thai_date($item['created_at']); ?></span>
                    <div class="mb-approver"><i class="fas fa-user-check"></i> อนุมัติโดย: <?php echo h($item['approver_name'] ?? 'เจ้าหน้าที่'); ?></div>
                </div>
                <div class="mb-amount" style="text-align: right;">
                    <!-- เน้นแต้มเป็นหลัก และแสดงเงินแยกย่อยด้านล่าง -->
                    <span class="mb-points" style="display: block; font-weight: 700; font-size: 1.1rem; color: #10b981;">+<?php echo number_format($item['points_earned']); ?> แต้ม</span>
                    <span class="mb-money" style="display: block; font-size: 0.8rem; color: #9ca3af;">(≈ <?php echo number_format($item['money_earned'], 2); ?> ฿)</span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #999;">
                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 10px; opacity: 0.5;"></i>
                <p>ยังไม่มีประวัติการฝาก</p>
            </div>
        <?php endif; ?>
    </section>

    <?php include 'includes/bottom_nav.php'; ?>
</body>
</html>