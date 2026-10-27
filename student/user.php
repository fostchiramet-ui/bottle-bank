<?php
ob_start();
session_start();
require_once '../db/db.php';

// 1. Authentication & Authorization
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$user_id = $_SESSION['user_id'];
$pdo = Database::connect();

// Update Last Activity
$pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$student_id]);

// 2. PDO Prepared Statements (ดึงข้อมูลนักเรียน)
$sql_user = "SELECT * FROM users WHERE id = :uid";
$stmt = $pdo->prepare($sql_user);
$stmt->execute([':uid' => $student_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. ถ้าข้อมูลยังไม่ครบ ให้บังคับเปลี่ยนเส้นทางไปหน้ากรอกข้อมูลทันที
if (empty($user['id_code']) || empty($user['class_group'])) {
    header("Location: fill_info.php"); 
    exit();
}

// ดึงข้อมูลสถิติการฝากขวด
$sql_stats = "SELECT 
    COUNT(id) as deposit_count,
    SUM(quantity) as total_items
    FROM bottle_entries 
    WHERE student_id = :uid";
$stmt = $pdo->prepare($sql_stats);
$stmt->execute([':uid' => $student_id]);
$stats = $stmt->fetch();

// ดึงรายการฝากล่าสุด
$sql_recent = "SELECT * FROM bottle_entries WHERE student_id = :uid ORDER BY created_at DESC LIMIT 3";
$stmt = $pdo->prepare($sql_recent);
$stmt->execute([':uid' => $student_id]);
$recent_deposits = $stmt->fetchAll();

// ดึงรายการคำร้องล่าสุด
$sql_req = "SELECT * FROM redemptions WHERE student_id = :uid ORDER BY created_at DESC LIMIT 3";
$stmt = $pdo->prepare($sql_req);
$stmt->execute([':uid' => $student_id]);
$recent_requests = $stmt->fetchAll();

// ดึงข้อมูลสำหรับกราฟ (ยอดฝากรายเดือน)
$sql_chart = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, SUM(quantity) as total_qty 
              FROM bottle_entries WHERE student_id = :uid 
              GROUP BY month ORDER BY month DESC LIMIT 6";
$stmt = $pdo->prepare($sql_chart);
$stmt->execute([':uid' => $student_id]);
$chart_data = array_reverse($stmt->fetchAll()); // เรียงจากเก่าไปใหม่

// Helper Functions
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function thai_date_short($strDate) {
    $strYear = date("y",strtotime($strDate))+43;
    $strMonth= date("n",strtotime($strDate));
    $strDay= date("j",strtotime($strDate));
    $strMonthCut = Array("","ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค.");
    $strMonthThai=$strMonthCut[$strMonth];
    return "$strDay $strMonthThai $strYear";
}

function getBottleIcon($type) {
    $icons = ['pet' => 'fa-wine-bottle', 'can' => 'fa-box', 'glass' => 'fa-wine-glass', 'paper' => 'fa-newspaper'];
    return $icons[$type] ?? 'fa-recycle';
}

function getStatusBadge($status) {
    $status = strtolower($status);
    if ($status == 'approved') return '<span style="font-size: 0.75rem; padding: 6px 12px; border-radius: 20px; background: #E8F5E9; color: #2E7D32; font-weight: 600;">อนุมัติแล้ว</span>';
    if ($status == 'rejected') return '<span style="font-size: 0.75rem; padding: 6px 12px; border-radius: 20px; background: #FFEBEE; color: #C62828; font-weight: 600;">ปฏิเสธ</span>';
    return '<span style="font-size: 0.75rem; padding: 6px 12px; border-radius: 20px; background: #fff8e1; color: #ffa000; font-weight: 600;">รอตรวจสอบ</span>';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าหลัก - EcoBank</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="studentcss/student.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="mb-body">

    <!-- Hero / Balance Card -->
    <section class="mb-hero">
        <div class="mb-hero-card">
            <div class="mb-hero-header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="preview-box" style="width: 60px; height: 60px; flex-shrink: 0; border: 2px solid rgba(255,255,255,0.5);">
                        <?php if (!empty($user['avatar'])): ?>
                            <img src="../uploads/avatars/<?php echo h($user['avatar']); ?>" alt="Avatar">
                        <?php else: ?>
                            <i class="fas fa-user" style="font-size: 2rem; color: rgba(255,255,255,0.7);"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p class="mb-greeting">สวัสดี 👋</p>
                        <h2 class="mb-username">
                            <?php echo h($user['first_name'] . ' ' . $user['last_name']); ?>
                        </h2>
                        <p class="mb-user-info">
                            รหัส: <?php echo h($user['id_code']); ?> 
                            <?php echo !empty($user['class_group']) ? '• ' . h($user['class_group']) : ''; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- ตรงกลาง: แสดง "แต้มสะสม" (สกุลเงินกลาง) -->
            <div class="mb-balance-section">
                <p class="mb-balance-label">แต้มสะสม (สำหรับแลกเงิน/คะแนน)</p>
                <h1 class="mb-balance-amount">
                    <?php echo number_format($user['points'] ?? 0); ?> 
                    <span class="mb-unit" style="font-size: 1.2rem; font-weight: normal;">แต้ม</span>
                </h1>
            </div>
            
            <!-- ด้านล่าง: แบ่ง 3 ช่อง (คะแนนพฤติกรรม | เงิน | ชิ้น) -->
            <div class="mb-hero-footer" style="display: flex; justify-content: space-between; align-items: center;">
                <div class="mb-stat-item">
                    <i class="fas fa-star" style="color: #fef08a; opacity: 0.9;"></i>
                    <span><?php echo number_format($user['behavior_score'] ?? 0); ?> คะแนน</span>
                </div>
                <div style="width: 1px; height: 20px; background: rgba(255,255,255,0.3);"></div>
                <div class="mb-stat-item">
                    <i class="fas fa-wallet" style="color: #a7f3d0; opacity: 0.9;"></i>
                    <span><?php echo number_format($user['balance'] ?? 0, 2); ?> บาท</span>
                </div>
                <div style="width: 1px; height: 20px; background: rgba(255,255,255,0.3);"></div>
                <div class="mb-stat-item">
                    <i class="fas fa-recycle" style="color: #bae6fd; opacity: 0.9;"></i>
                    <span><?php echo number_format($stats['total_items'] ?? 0); ?> ชิ้น</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Quick Actions -->
    <section class="mb-quick-actions">
        <a href="redeem.php" class="mb-action-btn action-money">
            <div class="mb-action-icon"><i class="fas fa-wallet"></i></div>
            <span>ขอแลกเงิน</span>
        </a>
        <a href="redeem.php" class="mb-action-btn action-points">
            <div class="mb-action-icon"><i class="fas fa-star"></i></div>
            <span>ขอแลกคะแนน</span>
        </a>
    </section>

    <!-- My Stats -->
    <section class="mb-stats-row">
        <div class="mb-stat-box">
            <span class="mb-stat-val"><?php echo number_format($stats['deposit_count'] ?? 0); ?></span>
            <span class="mb-stat-lbl">ฝากแล้ว (ครั้ง)</span>
        </div>
        <div class="mb-stat-box">
            <span class="mb-stat-val"><?php echo number_format($stats['total_items'] ?? 0); ?></span>
            <span class="mb-stat-lbl">รวมชิ้น (ชิ้น)</span>
        </div>
        <div class="mb-stat-box">
            <span class="mb-stat-val"><?php echo count($recent_requests); ?></span>
            <span class="mb-stat-lbl">คำร้อง (รายการ)</span>
        </div>
    </section>

    <!-- Chart Section -->
    <div class="mb-section-header">
        <h2>สถิติการฝาก (6 เดือนล่าสุด)</h2>
    </div>
    <section class="mb-list" style="background: #fff; border-radius: 20px; padding: 20px; margin: 0 20px 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
        <canvas id="depositChart"></canvas>
    </section>

    <!-- Recent Deposits -->
    <div class="mb-section-header">
        <h2>การฝากล่าสุด</h2>
        <a href="history.php">ดูทั้งหมด</a>
    </div>
    <section class="mb-list">
        <?php if (count($recent_deposits) > 0): ?>
            <?php foreach ($recent_deposits as $deposit): ?>
            <div class="mb-item">
                <div class="mb-icon">
                    <i class="fas <?php echo getBottleIcon($deposit['bottle_type']); ?>"></i>
                </div>
                <div class="mb-info">
                    <span class="mb-title"><?php echo h(ucfirst($deposit['bottle_type'])); ?></span>
                    <span class="mb-date">
                        <?php echo thai_date_short($deposit['created_at']); ?> • 
                        <?php echo number_format($deposit['quantity']); ?> ชิ้น
                    </span>
                </div>
                <div class="mb-amount">
                    <!-- โชว์แต้มที่ได้รับตัวใหญ่ เงินไว้เป็นตัวอ้างอิงเล็กๆ -->
                    <span class="mb-points" style="font-weight: 600; color: #10b981;">+<?php echo number_format($deposit['points_earned']); ?> แต้ม</span>
                    <span class="mb-money" style="font-size: 0.8rem; color: #9ca3af;">(≈ <?php echo number_format($deposit['money_earned'], 2); ?> ฿)</span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 20px; color: #999;">
                <p>ยังไม่มีรายการฝาก</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Recent Requests -->
    <div class="mb-section-header">
        <h2>คำร้องล่าสุด</h2>
        <a href="redeem.php">ดูทั้งหมด</a>
    </div>
    <section class="mb-list">
        <?php if (count($recent_requests) > 0): ?>
            <?php foreach ($recent_requests as $req): 
                $isMoney = ($req['type'] == 'money');
                $iconClass = $isMoney ? 'fa-money-bill-wave' : 'fa-star';
                $iconBg = $isMoney ? '#fff8e1' : '#f3e5f5';
                $iconColor = $isMoney ? '#ffc107' : '#9c27b0';
                $title = $isMoney ? 'ขอแลกเงิน' : 'ขอแลกคะแนน';
                $amountText = '- ' . number_format($req['points']) . ' แต้ม';
            ?>
            <div class="mb-item">
                <div class="mb-icon" style="background: <?php echo $iconBg; ?>; color: <?php echo $iconColor; ?>;">
                    <i class="fas <?php echo $iconClass; ?>"></i>
                </div>
                <div class="mb-info">
                    <span class="mb-title"><?php echo $title; ?></span>
                    <span class="mb-date">
                        <?php echo thai_date_short($req['created_at']); ?> • 
                        <?php echo $amountText; ?>
                    </span>
                </div>
                <div class="mb-amount">
                    <?php echo getStatusBadge($req['status']); ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 20px; color: #999;">
                <p>ยังไม่มีรายการคำร้อง</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Bottom Nav -->
   <?php include 'includes/bottom_nav.php'; ?> 

    <script>
        // Chart.js Configuration
        const ctx = document.getElementById('depositChart').getContext('2d');
        const chartData = <?php echo json_encode($chart_data); ?>;
        
        const labels = chartData.map(item => {
            const [year, month] = item.month.split('-');
            const date = new Date(year, month - 1);
            return date.toLocaleDateString('th-TH', { month: 'short', year: '2-digit' });
        });
        
        const data = chartData.map(item => item.total_qty);

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'จำนวนชิ้น',
                    data: data,
                    backgroundColor: 'rgba(84, 168, 73, 0.6)',
                    borderColor: 'rgba(84, 168, 73, 1)',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            display: false
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    </script>
</body>
</html>