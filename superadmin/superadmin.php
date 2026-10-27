<?php
session_start();
require_once '../db/db.php';

// 1. Authentication & Authorization
// ตรวจสอบว่าล็อกอินแล้ว และมีสิทธิ์เป็น admin หรือ superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin'])) {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();
$user_id = $_SESSION['user_id'];

// Update Last Activity
$pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$user_id]);

// ดึงข้อมูลผู้ใช้งานปัจจุบันเพื่อแสดงใน Sidebar
$stmt = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$current_user = $stmt->fetch();

// 2. Statistics Queries
// จำนวนนักเรียนทั้งหมด
$stmt = $pdo->query("SELECT COUNT(id) FROM users WHERE role = 'user'");
$total_students = $stmt->fetchColumn();

// รายการรับฝากขยะของวันนี้
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(id) FROM bottle_entries WHERE DATE(created_at) = :today");
$stmt->execute([':today' => $today]);
$today_deposits = $stmt->fetchColumn();

// จำนวนคำร้องที่สถานะ "รอการดำเนินการ"
$stmt = $pdo->query("SELECT COUNT(id) FROM redemptions WHERE status = 'pending'");
$pending_requests = $stmt->fetchColumn();

// ปริมาณขยะรวมทั้งหมด
$stmt = $pdo->query("SELECT SUM(quantity) FROM bottle_entries");
$total_waste = $stmt->fetchColumn() ?: 0;

// 3. Recent Data Queries
// ดึงรายการรับฝากล่าสุด 5 รายการ (ดึงยอดเงินและคะแนนพฤติกรรมของนักเรียนมาแสดงด้วย)
$sql_deposits = "SELECT b.*, u.first_name, u.last_name, u.balance, u.behavior_score, a.first_name as admin_name 
                 FROM bottle_entries b 
                 LEFT JOIN users u ON b.student_id = u.id 
                 LEFT JOIN users a ON b.recorded_by = a.id 
                 ORDER BY b.created_at DESC LIMIT 5";
$stmt = $pdo->query($sql_deposits);
$recent_deposits = $stmt->fetchAll();

// ดึงคำร้องล่าสุด 5 รายการ
$sql_requests = "SELECT r.*, u.first_name, u.last_name 
                 FROM redemptions r 
                 LEFT JOIN users u ON r.student_id = u.id 
                 ORDER BY r.created_at DESC LIMIT 5";
$stmt = $pdo->query($sql_requests);
$recent_requests = $stmt->fetchAll();

// 4. Chart Data (Daily Deposits - Last 7 Days)
$sql_chart = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d') as deposit_date, SUM(quantity) as total_qty 
              FROM bottle_entries 
              WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
              GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d') 
              ORDER BY deposit_date ASC";
$stmt = $pdo->query($sql_chart);
$raw_chart_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// สร้างข้อมูลให้ครบทั้ง 7 วันย้อนหลัง (หากวันไหนไม่มียอดฝากให้เป็น 0 เพื่อให้กราฟเส้นแสดงผลได้สมบูรณ์)
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_data[] = [
        'date' => $d,
        'total_qty' => isset($raw_chart_data[$d]) ? (int)$raw_chart_data[$d] : 0
    ];
}

// 5. Online Users (Active in last 10 minutes)
$sql_online = "SELECT first_name, last_name, role, last_activity FROM users WHERE last_activity > DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY last_activity DESC";
$stmt = $pdo->query($sql_online);
$online_users = $stmt->fetchAll();

// Helper Function for XSS Prevention
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoBank - แดชบอร์ดเจ้าหน้าที่</title>
    <link rel="stylesheet" href="superadmincss/superadmin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        <span class="badge-admin">SUPERADMIN</span>
    </div>

   
    <?php include 'includes/bottom_nav.php'; ?>
        <div class="sidebar-footer"><a href="../logout.php" class="menu-item text-muted"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a></div>
</aside>

    <main class="main-content">
        <header class="page-header">
            <div>
                <h1>แดชบอร์ดเจ้าหน้าที่</h1>
                <p class="subtitle">ภาพรวมระบบธนาคารขยะ วัดสุทธิวราราม</p>
            </div>
        </header>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon-wrapper bg-green txt-green"><i class="fas fa-users"></i></div>
                <h2><?php echo number_format($total_students); ?></h2>
                <p>นักเรียนทั้งหมด</p>
            </div>
            <div class="stat-card">
                <div class="icon-wrapper bg-pink txt-pink"><i class="fas fa-chart-line"></i></div>
                <h2><?php echo number_format($today_deposits); ?></h2>
                <p>รายการฝากวันนี้</p>
            </div>
            <div class="stat-card">
                <div class="icon-wrapper bg-amber txt-amber"><i class="fas fa-clock"></i></div>
                <h2><?php echo number_format($pending_requests); ?></h2>
                <p>คำร้องรอดำเนินการ</p>
            </div>
            <div class="stat-card">
                <div class="icon-wrapper bg-emerald txt-emerald"><i class="fas fa-file-alt"></i></div>
                <h2><?php echo number_format($total_waste); ?></h2>
                <p>ขวดรวมทั้งหมด (ชิ้น)</p>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="content-card" style="margin-bottom: 30px; padding: 20px;">
            <div class="card-header" style="border-bottom: none; padding-bottom: 10px;">
                <h3>สถิติการรับฝากรายวัน (7 วันล่าสุด)</h3>
            </div>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="dailyDepositChart"></canvas>
            </div>
        </div>

        <!-- Online Users Section -->
        <div class="content-card" style="margin-bottom: 30px;">
            <div class="card-header">
                <h3>ผู้ใช้งานที่กำลังออนไลน์ (<?php echo count($online_users); ?>)</h3>
            </div>
            <div class="list-group" style="max-height: 200px; overflow-y: auto;">
                <?php foreach ($online_users as $online): ?>
                <div class="list-item" style="padding: 10px 24px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 10px; height: 10px; background: #10b981; border-radius: 50%;"></div>
                        <p class="item-title" style="margin: 0;"><?php echo h($online['first_name'] . ' ' . $online['last_name']); ?></p>
                    </div>
                    <div>
                        <span style="font-size: 0.8rem; color: var(--gray); margin-right: 10px;"><?php echo h(ucfirst($online['role'])); ?></span>
                        <span style="font-size: 0.75rem; color: #999;"><?php echo date('H:i', strtotime($online['last_activity'])); ?> น.</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tables-grid">
            <div class="content-card">
                <div class="card-header">
                    <h3>รายการฝากล่าสุด</h3>
                </div>
                <div class="list-group">
                    <?php foreach ($recent_deposits as $deposit): ?>
                    <div class="list-item">
                        <div>
                            <p class="item-title"><?php echo h($deposit['first_name'] . ' ' . $deposit['last_name']); ?></p>
                            <p class="item-desc"><?php echo h(ucfirst($deposit['bottle_type'])); ?> — <?php echo number_format($deposit['quantity']); ?> ชิ้น (ผู้รับฝาก: <?php echo h($deposit['admin_name'] ?? '-'); ?>)</p>
                        </div>
                        <div class="text-right">
                            <p class="font-bold" style="color: #e11d48; font-size: 1.05rem; margin: 0;">+<?php echo number_format($deposit['points_earned']); ?> แต้ม</p>
                            <div style="font-size: 0.85rem; color: var(--gray); margin-top: 2px;">
                                <span style="color: #10b981;"><?php echo number_format($deposit['balance'] ?? 0, 2); ?> ฿</span> &#124; 
                                <span style="color: #d97706;"><?php echo number_format($deposit['behavior_score'] ?? 0); ?> คะแนน</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">
                    <h3>คำร้องล่าสุด</h3>
                </div>
                <div class="list-group">
                    <?php foreach ($recent_requests as $request): ?>
                    <div class="list-item">
                        <div>
                            <p class="item-title"><?php echo h($request['first_name'] . ' ' . $request['last_name']); ?></p>
                            <p class="item-desc">
                                <?php 
                                    if ($request['type'] == 'money') echo 'ขอแลกเงิน ' . number_format($request['amount'], 2) . ' บาท';
                                    else echo 'ขอแลกคะแนน ' . number_format($request['points']) . ' แต้ม';
                                ?>
                            </p>
                        </div>
                        <?php 
                            $status_class = 'bg-amber txt-amber';
                            $status_text = 'รอการดำเนินการ';
                            if ($request['status'] == 'approved') {
                                $status_class = 'bg-green txt-green';
                                $status_text = 'อนุมัติแล้ว';
                            } elseif ($request['status'] == 'rejected') {
                                $status_class = 'bg-pink txt-pink';
                                $status_text = 'ไม่อนุมัติ';
                            }
                        ?>
                        <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Chart.js Configuration
        const ctx = document.getElementById('dailyDepositChart').getContext('2d');
        const chartData = <?php echo json_encode($chart_data); ?>;
        
        const labels = chartData.map(item => {
            const parts = item.date.split('-');
            const date = new Date(parts[0], parts[1] - 1, parts[2]);
            return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
        });
        
        const data = chartData.map(item => Number(item.total_qty));

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'จำนวนขยะที่รับฝาก (ชิ้น)',
                    data: data,
                    backgroundColor: 'rgba(84, 168, 73, 0.2)',
                    borderColor: 'rgba(84, 168, 73, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: 'rgba(84, 168, 73, 1)',
                    pointRadius: 4,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>