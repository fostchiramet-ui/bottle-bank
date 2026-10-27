<?php
session_start();
require_once '../db/db.php';

// 1. Authentication: เช็คสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();

// ดึงข้อมูลผู้ใช้งานปัจจุบันเพื่อแสดงใน Sidebar
$user_id = $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = :id");
$stmt_user->execute([':id' => $user_id]);
$current_user = $stmt_user->fetch();

// 2. รับค่า Filter (เดือน/ปี)
$selected_month = $_GET['month'] ?? date('m');
$selected_year = $_GET['year'] ?? date('Y');

// Export CSV Logic
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $sql_export = "SELECT b.created_at, b.bottle_type, b.quantity, b.money_earned, b.points_earned, u.first_name, u.last_name, u.id_code, a.first_name as admin_name
                   FROM bottle_entries b 
                   JOIN users u ON b.student_id = u.id 
                   LEFT JOIN users a ON b.recorded_by = a.id
                   WHERE MONTH(b.created_at) = :month AND YEAR(b.created_at) = :year
                   ORDER BY b.created_at DESC";
    
    $stmt = $pdo->prepare($sql_export);
    $stmt->execute([':month' => $selected_month, ':year' => $selected_year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=monthly_report_' . $selected_year . $selected_month . '.csv');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM for Excel
    fputcsv($output, ['วันที่/เวลา', 'รหัสนักเรียน', 'ชื่อ-นามสกุล', 'ประเภทขยะ', 'จำนวน', 'เงิน (บาท)', 'แต้ม', 'ผู้บันทึก']);
    
    foreach ($rows as $row) {
        $student_name = $row['first_name'] . ' ' . $row['last_name'];
        $date = date("d/m/Y H:i", strtotime($row['created_at']));
        fputcsv($output, [$date, $row['id_code'], $student_name, $row['bottle_type'], $row['quantity'], $row['money_earned'], $row['points_earned'], $row['admin_name']]);
    }
    fclose($output);
    exit();
}

// 3. Query ข้อมูลสรุปยอดรวมแยกตามประเภทขยะ (Group By Type)
$sql_summary = "SELECT bottle_type, SUM(quantity) as total_qty, SUM(money_earned) as total_money, SUM(points_earned) as total_points
                FROM bottle_entries 
                WHERE MONTH(created_at) = :month AND YEAR(created_at) = :year
                GROUP BY bottle_type";
$stmt_summary = $pdo->prepare($sql_summary);
$stmt_summary->execute([':month' => $selected_month, ':year' => $selected_year]);
$summary_data = $stmt_summary->fetchAll(PDO::FETCH_ASSOC);

// 4. Query รายการฝากทั้งหมดในเดือนนั้น (Detailed List)
$sql_details = "SELECT b.*, u.first_name, u.last_name, u.id_code, a.first_name as admin_name
                FROM bottle_entries b 
                JOIN users u ON b.student_id = u.id 
                LEFT JOIN users a ON b.recorded_by = a.id
                WHERE MONTH(b.created_at) = :month AND YEAR(b.created_at) = :year
                ORDER BY b.created_at DESC";
$stmt_details = $pdo->prepare($sql_details);
$stmt_details->execute([':month' => $selected_month, ':year' => $selected_year]);
$details_data = $stmt_details->fetchAll(PDO::FETCH_ASSOC);

// 5. Query ข้อมูลสถิติรายวันสำหรับทำกราฟเส้นในเดือนที่เลือก
$sql_chart = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d') as deposit_date, SUM(quantity) as total_qty 
              FROM bottle_entries 
              WHERE MONTH(created_at) = :month AND YEAR(created_at) = :year
              GROUP BY deposit_date 
              ORDER BY deposit_date ASC";
$stmt_chart = $pdo->prepare($sql_chart);
$stmt_chart->execute([':month' => $selected_month, ':year' => $selected_year]);
$raw_chart_data = $stmt_chart->fetchAll(PDO::FETCH_KEY_PAIR);

// สร้างข้อมูลให้ครบทุกวันในเดือนนั้นๆ (ใช้ date('t') แทน cal_days_in_month เพื่อแก้ปัญหา function ไม่พบ)
$days_in_month = (int)date('t', strtotime("$selected_year-$selected_month-01"));
$chart_labels = [];
$chart_data = [];

for ($d = 1; $d <= $days_in_month; $d++) {
    $date_str = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $d);
    
    // แปลงวันที่เป็นรูปแบบภาษาไทยสั้นๆ เช่น 1 ต.ค.
    $thai_months = Array("", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค.");
    $day_label = $d . ' ' . $thai_months[(int)$selected_month];
    
    $chart_labels[] = $day_label;
    $chart_data[] = isset($raw_chart_data[$date_str]) ? (int)$raw_chart_data[$date_str] : 0;
}

// Helper Functions
function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function thai_date($strDate) {
    $strYear = date("Y",strtotime($strDate))+543;
    $strMonth= date("n",strtotime($strDate));
    $strDay= date("j",strtotime($strDate));
    $strHour= date("H",strtotime($strDate));
    $strMinute= date("i",strtotime($strDate));
    $strMonthCut = Array("","ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค.");
    $strMonthThai=$strMonthCut[$strMonth];
    return "$strDay $strMonthThai $strYear $strHour:$strMinute";
}

$months = [
    '01' => 'มกราคม', '02' => 'กุมภาพันธ์', '03' => 'มีนาคม', '04' => 'เมษายน',
    '05' => 'พฤษภาคม', '06' => 'มิถุนายน', '07' => 'กรกฎาคม', '08' => 'สิงหาคม',
    '09' => 'กันยายน', '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานสรุปยอดประจำเดือน - EcoBank</title>
    <link rel="stylesheet" href="admincss/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .filter-group {
            display: flex;
            gap: 10px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .summary-box {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            text-align: center;
            border: 1px solid #eee;
        }
        .summary-box h4 { margin-bottom: 10px; color: var(--dark); font-size: 1rem; }
        .summary-box .val { font-size: 1.5rem; font-weight: 700; color: var(--green); }
        .summary-box .sub { font-size: 0.85rem; color: var(--gray); }
        
        @media print {
            .sidebar, .filter-group, .btn-print { display: none; }
            .main-content { margin-left: 0; padding: 0; }
            body { background: #fff; }
            .content-card { box-shadow: none; border: 1px solid #ccc; }
        }
    </style>
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
        <p class="admin-role">เจ้าหน้าที่</p>
        <span class="badge-admin">ADMIN</span>
    </div>

    <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
    <nav class="sidebar-menu">
        <a href="admin.php" class="menu-item <?php if($current_page == 'admin.php') echo 'active'; ?>">
            <i class="fas fa-th-large"></i> แดชบอร์ด
        </a>
        <a href="deposit.php" class="menu-item <?php if($current_page == 'deposit.php') echo 'active'; ?>">
            <i class="fas fa-plus-circle"></i> รับฝากขวด
        </a>
        <a href="requests.php" class="menu-item <?php if($current_page == 'requests.php') echo 'active'; ?>">
            <i class="fas fa-clipboard-list"></i> รายการคำขอ
        </a>
        <a href="students.php" class="menu-item <?php if($current_page == 'students.php') echo 'active'; ?>">
            <i class="fas fa-user-graduate"></i> จัดการนักเรียน
        </a>
        <a href="history.php" class="menu-item <?php if($current_page == 'history.php') echo 'active'; ?>">
            <i class="fas fa-history"></i> ประวัติการฝาก
        </a>
        <a href="report.php" class="menu-item <?php if($current_page == 'report.php') echo 'active'; ?>">
            <i class="fas fa-chart-bar"></i> รายงานสรุป
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="menu-item text-muted">
            <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
        </a>
    </div>
</aside>

    <main class="main-content">
        <div class="report-header">
            <div>
                <h1>รายงานสรุปยอดประจำเดือน</h1>
                <p class="subtitle">ข้อมูลประจำเดือน <?php echo $months[$selected_month] . ' ' . ($selected_year + 543); ?></p>
            </div>
            <div class="filter-group">
                <form method="GET" style="display: flex; gap: 10px;">
                    <select name="month" class="search-box" style="width: auto;">
                        <?php foreach ($months as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $k == $selected_month ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="year" class="search-box" style="width: auto;">
                        <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y + 543; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn-submit" style="width: auto; padding: 10px 20px;">ค้นหา</button>
                </form>
                <button onclick="window.print()" class="btn-reject-full btn-print" style="background: #fff; border-color: #ccc; color: #333;"><i class="fas fa-print"></i> พิมพ์</button>
                <a href="?export=csv&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" class="btn-approve-full btn-print" style="text-decoration: none; background: #fff; border-color: #10b981; color: #10b981;"><i class="fas fa-file-csv"></i> Export CSV</a>
            </div>
        </div>

        <div class="summary-grid">
            <?php 
            $grand_total_money = 0;
            foreach ($summary_data as $sum): 
                $grand_total_money += $sum['total_money'];
            ?>
            <div class="summary-box">
                <h4><?php echo h(ucfirst($sum['bottle_type'])); ?></h4>
                <div class="val"><?php echo number_format($sum['total_qty']); ?> <span style="font-size: 1rem;">ชิ้น</span></div>
                <div class="sub"><?php echo number_format($sum['total_money'], 2); ?> บาท</div>
            </div>
            <?php endforeach; ?>
            <div class="summary-box" style="border-color: var(--green); background: #f0fdf4;">
                <h4>ยอดเงินรวมทั้งสิ้น</h4>
                <div class="val" style="color: var(--dark);"><?php echo number_format($grand_total_money, 2); ?></div>
                <div class="sub">บาท</div>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="content-card" style="margin-bottom: 30px; padding: 20px;">
            <div class="card-header" style="border-bottom: none; padding-bottom: 10px;">
                <h3>สถิติการรับฝากรายวัน (ประจำเดือน <?php echo $months[$selected_month]; ?>)</h3>
            </div>
            <div style="height: 300px; position: relative;">
                <canvas id="wasteChart"></canvas>
            </div>
        </div>

        <div class="content-card">
            <div class="card-header"><h3>รายการฝากทั้งหมด</h3></div>
            <div class="list-group">
                <?php foreach ($details_data as $row): ?>
                <div class="list-item">
                    <div>
                        <p class="item-title"><?php echo h($row['first_name'] . ' ' . $row['last_name']); ?> (<?php echo h($row['id_code']); ?>)</p>
                        <p class="item-desc"><?php echo h(ucfirst($row['bottle_type'])); ?> (<?php echo number_format($row['quantity']); ?> ชิ้น) — <?php echo thai_date($row['created_at']); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="txt-green font-bold">+<?php echo number_format($row['money_earned'], 2); ?> ฿</p>
                        <span style="font-size: 0.8rem; color: var(--gray);">บันทึกโดย: <?php echo h($row['admin_name'] ?? '-'); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($details_data)): ?>
                    <div style="padding: 30px; text-align: center; color: #999;">ไม่พบข้อมูลในเดือนนี้</div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        const ctx = document.getElementById('wasteChart').getContext('2d');
        const chartLabels = <?php echo json_encode($chart_labels); ?>;
        const chartData = <?php echo json_encode($chart_data); ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'จำนวนขยะที่รับฝาก (ชิ้น)',
                    data: chartData,
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