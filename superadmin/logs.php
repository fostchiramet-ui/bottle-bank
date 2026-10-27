<?php
session_start();
require_once '../db/db.php';

// 1. Strict RBAC: Check if Superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();

// 2. Filters
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// 3. Build Query (UNION of Deposits and Redemptions)
$sql_base = "
SELECT * FROM (
    -- ประวัติการรับฝากขยะ
    SELECT 
        b.created_at as log_time, 
        CONCAT(u.first_name, ' ', u.last_name) as admin_name,
        'รับฝากขยะ' as action_type,
        CONCAT('รับฝาก ', b.bottle_type, ' จำนวน ', b.quantity, ' ', IF(b.bottle_type='paper','กก.','ชิ้น'), ' (', b.money_earned, ' บาท) สามารถแลกเงินได้ ', b.money_earned, ' บาท หรือคะแนนได้ ', b.points_earned, ' แต้ม') as details,
        'success' as status_color
    FROM bottle_entries b
    JOIN users u ON b.recorded_by = u.id
    
    UNION ALL
    
    -- ประวัติการอนุมัติ/ปฏิเสธคำร้อง
    SELECT 
        r.updated_at as log_time,
        CONCAT(u.first_name, ' ', u.last_name) as admin_name,
        CASE WHEN r.status = 'approved' THEN 'อนุมัติคำร้อง' ELSE 'ปฏิเสธคำร้อง' END as action_type,
        CONCAT(IF(r.type='money', 'แลกเงิน', 'แลกแต้ม'), ' ', IF(r.type='money', r.amount, r.points), ' ', IF(r.type='money', 'บาท', 'แต้ม')) as details,
        CASE WHEN r.status = 'approved' THEN 'success' ELSE 'danger' END as status_color
    FROM redemptions r
    JOIN users u ON r.approver_id = u.id
    WHERE r.status IN ('approved', 'rejected')
) as logs
WHERE 1=1
";

$params = [];

if ($search) {
    $sql_base .= " AND (admin_name LIKE :search OR details LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($start_date) {
    $sql_base .= " AND DATE(log_time) >= :start_date";
    $params[':start_date'] = $start_date;
}
if ($end_date) {
    $sql_base .= " AND DATE(log_time) <= :end_date";
    $params[':end_date'] = $end_date;
}

// Export CSV Logic
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $sql_export = "$sql_base ORDER BY log_time DESC";
    $stmt = $pdo->prepare($sql_export);
    foreach ($params as $key => $val) $stmt->bindValue($key, $val);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=system_logs_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM for Excel
    fputcsv($output, ['วัน/เวลา', 'เจ้าหน้าที่', 'การกระทำ', 'รายละเอียด']);
    
    foreach ($rows as $row) {
        $date = date("d/m/Y H:i", strtotime($row['log_time']));
        fputcsv($output, [$date, $row['admin_name'], $row['action_type'], $row['details']]);
    }
    fclose($output);
    exit();
}

// Count Total
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ($sql_base) as count_table");
foreach ($params as $key => $val) $stmt_count->bindValue($key, $val);
$stmt_count->execute();
$total_rows = $stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch Data
$sql_final = "$sql_base ORDER BY log_time DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql_final);
foreach ($params as $key => $val) $stmt->bindValue($key, $val);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการใช้งานระบบ - Recycle Bank</title>
    <link rel="stylesheet" href="superadmincss/superadmin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .filter-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .filter-input { padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem; }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .page-link { padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; text-decoration: none; color: var(--dark); }
        .page-link.active { background: var(--green); color: white; border-color: var(--green); }
        .log-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .log-badge.success { background: #ecfdf5; color: #059669; }
        .log-badge.danger { background: #fef2f2; color: #b91c1c; }
        
        /* Row Colors */
        tr.row-success { background-color: rgba(236, 253, 245, 0.3); }
        tr.row-danger { background-color: rgba(254, 242, 242, 0.3); }
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
            <p class="admin-role">ผู้ดูแลระบบ</p>
            <span class="badge-admin">SUPER ADMIN</span>
        </div>
        <?php include 'includes/bottom_nav.php'; ?>
        <div class="sidebar-footer"><a href="../logout.php" class="menu-item text-muted"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a></div>
    </aside>

    <main class="main-content">
        <header class="page-header">
            <h1>ประวัติการใช้งานระบบ</h1>
            <p class="subtitle">ตรวจสอบการทำงานของเจ้าหน้าที่ (Admin Audit Logs)</p>
        </header>

        <div class="content-card">
            <div class="card-header" style="display: block;">
                <form method="GET" class="filter-form">
                    <input type="text" name="search" class="filter-input" placeholder="ค้นหาชื่อเจ้าหน้าที่ / รายละเอียด..." value="<?php echo h($search); ?>" style="width: 250px;">
                    <input type="date" name="start_date" class="filter-input" value="<?php echo h($start_date); ?>">
                    <span style="color: #999;">ถึง</span>
                    <input type="date" name="end_date" class="filter-input" value="<?php echo h($end_date); ?>">
                    <button type="submit" class="btn-primary" style="padding: 8px 16px; font-size: 0.9rem;">ค้นหา</button>
                    <a href="logs.php" class="btn-reject-full" style="text-decoration: none; font-size: 0.9rem;">ล้างค่า</a>
                    <a href="?export=csv&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="btn-primary" style="text-decoration: none; font-size: 0.9rem; background: #10b981; border: none; margin-left: auto;"><i class="fas fa-file-csv"></i> Export CSV</a>
                </form>
            </div>
            
            <div class="list-group">
                <?php if (count($logs) > 0): ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th width="20%">วัน/เวลา</th>
                                <th width="20%">เจ้าหน้าที่</th>
                                <th width="15%">การกระทำ</th>
                                <th>รายละเอียด</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): 
                                $row_class = '';
                                if ($log['status_color'] == 'success') $row_class = 'row-success';
                                elseif ($log['status_color'] == 'danger') $row_class = 'row-danger';
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td style="color: #666; font-size: 0.85rem;"><?php echo thai_date($log['log_time']); ?></td>
                                <td style="font-weight: 600;"><?php echo h($log['admin_name']); ?></td>
                                <td><span class="log-badge <?php echo $log['status_color']; ?>"><?php echo h($log['action_type']); ?></span></td>
                                <td><?php echo h($log['details']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="padding: 40px; text-align: center; color: #999;">ไม่พบข้อมูลประวัติการใช้งาน</div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" 
                       class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <div style="height: 20px;"></div>
        </div>
    </main>
</body>
</html>