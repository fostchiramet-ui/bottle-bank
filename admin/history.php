<?php
session_start();
require_once '../db/db.php';

// 1. Authentication: เช็คสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'admin'])) {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();

// ดึงข้อมูลผู้ใช้งานปัจจุบันเพื่อแสดงใน Sidebar
$user_id = $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = :id");
$stmt_user->execute([':id' => $user_id]);
$current_user = $stmt_user->fetch();

// 2. รับค่า Filter และ Search
$search = $_GET['search'] ?? '';
$waste_type = $_GET['waste_type'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// สร้าง WHERE Clause แบบ Dynamic
$where = ["1=1"];
$params = [];

if ($search) {
    $where[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.id_code LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($waste_type) {
    $where[] = "b.bottle_type = :waste_type";
    $params[':waste_type'] = $waste_type;
}
if ($start_date) {
    $where[] = "DATE(b.created_at) >= :start_date";
    $params[':start_date'] = $start_date;
}
if ($end_date) {
    $where[] = "DATE(b.created_at) <= :end_date";
    $params[':end_date'] = $end_date;
}

$where_sql = implode(" AND ", $where);

// 3. Query ข้อมูลสถิติ (ตาม Filter)
$sql_stats = "SELECT SUM(b.quantity) as total_qty, SUM(b.money_earned) as total_money 
              FROM bottle_entries b 
              JOIN users u ON b.student_id = u.id 
              WHERE $where_sql";
$stmt_stats = $pdo->prepare($sql_stats);
foreach ($params as $key => $val) {
    $stmt_stats->bindValue($key, $val);
}
$stmt_stats->execute();
$stats = $stmt_stats->fetch();

// 4. Pagination: หาจำนวนรายการทั้งหมด
$sql_count = "SELECT COUNT(*) FROM bottle_entries b JOIN users u ON b.student_id = u.id WHERE $where_sql";
$stmt_count = $pdo->prepare($sql_count);
foreach ($params as $key => $val) {
    $stmt_count->bindValue($key, $val);
}
$stmt_count->execute();
$total_rows = $stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// 5. Query ดึงรายการประวัติ (Deposits) พร้อมดึง behavior_score ของนักเรียนมาแสดงแบบ Real-time
$sql = "SELECT b.*, u.first_name, u.last_name, u.id_code, u.behavior_score, a.first_name as admin_name 
        FROM bottle_entries b 
        JOIN users u ON b.student_id = u.id 
        LEFT JOIN users a ON b.recorded_by = a.id 
        WHERE $where_sql 
        ORDER BY b.created_at DESC 
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$deposits = $stmt->fetchAll();

// Helper Functions
function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function thai_date($strDate) {
    $strYear = date("Y",strtotime($strDate))+543;
    $strMonth= date("n",strtotime($strDate));
    $strDay= date("j",strtotime($strDate));
    $strMonthCut = Array("","ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค.");
    $strMonthThai=$strMonthCut[$strMonth];
    return "$strDay $strMonthThai $strYear";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการรับฝาก - ระบบรับฝากขวด</title>
    <link rel="stylesheet" href="../superadmin/superadmincss/superadmin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .filter-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-input {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        .page-link {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            text-decoration: none;
            color: var(--dark);
        }
        .page-link.active {
            background: var(--green);
            color: white;
            border-color: var(--green);
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
        <header class="page-header">
            <h1>ประวัติการรับฝาก</h1>
            <p class="subtitle">รายละเอียดการรับฝากขยะรีไซเคิลทั้งหมด</p>
        </header>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon-wrapper bg-green txt-green"><i class="fas fa-box"></i></div>
                <h2><?php echo number_format($stats['total_qty'] ?? 0); ?></h2>
                <p>จำนวนชิ้นรวม (ตามตัวกรอง)</p>
            </div>
            <div class="stat-card">
                <div class="icon-wrapper bg-emerald txt-emerald"><i class="fas fa-money-bill-wave"></i></div>
                <h2><?php echo number_format($stats['total_money'] ?? 0, 2); ?> ฿</h2>
                <p>ยอดเงินรวม (ตามตัวกรอง)</p>
            </div>
        </div>

        <div class="content-card">
            <div class="card-header" style="display: block;">
                <form method="GET" class="filter-form">
                    <input type="text" name="search" class="filter-input" placeholder="ค้นหาชื่อ/รหัส..." value="<?php echo h($search); ?>">
                    <select name="waste_type" class="filter-input">
                        <option value="">ทุกประเภท</option>
                        <option value="pet" <?php if($waste_type=='pet') echo 'selected'; ?>>ขวดใส (PET)</option>
                        <option value="can" <?php if($waste_type=='can') echo 'selected'; ?>>กระป๋อง</option>
                        <option value="glass" <?php if($waste_type=='glass') echo 'selected'; ?>>ขวดแก้ว</option>
                        <option value="paper" <?php if($waste_type=='paper') echo 'selected'; ?>>กระดาษ</option>
                    </select>
                    <input type="date" name="start_date" class="filter-input" value="<?php echo h($start_date); ?>">
                    <span style="color: #999;">ถึง</span>
                    <input type="date" name="end_date" class="filter-input" value="<?php echo h($end_date); ?>">
                    <button type="submit" class="btn-primary" style="padding: 8px 16px; font-size: 0.9rem;">ค้นหา</button>
                    <a href="history.php" class="btn-reject-full" style="text-decoration: none; font-size: 0.9rem;">ล้างค่า</a>
                    <a href="?export=csv&search=<?php echo urlencode($search); ?>&waste_type=<?php echo urlencode($waste_type); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="btn-primary" style="text-decoration: none; font-size: 0.9rem; background: #10b981; border: none; margin-left: auto;"><i class="fas fa-file-csv"></i> Export CSV</a>
                </form>
            </div>
            <div class="list-group">
                <?php if (count($deposits) > 0): ?>
                    <?php foreach ($deposits as $row): ?>
                    <div class="list-item">
                        <div>
                            <p class="item-title"><?php echo h($row['first_name'] . ' ' . $row['last_name']); ?> (<?php echo h($row['id_code']); ?>)</p>
                            <p class="item-desc"><?php echo h(ucfirst($row['bottle_type'])); ?> (<?php echo number_format($row['quantity']); ?> ชิ้น) — <?php echo thai_date($row['created_at']); ?> | ผู้รับฝาก: <?php echo h($row['admin_name'] ?? '-'); ?></p>
                        </div>
                        <div class="text-right">
                            <!-- แต้มเด่นชัดอยู่ด้านบน -->
                            <p class="txt-pink font-bold" style="font-size: 1.15rem; margin: 0;">+<?php echo number_format($row['points_earned']); ?> แต้ม</p>
                            <!-- ยอดเงินและคะแนนพฤติกรรมอยู่ด้านล่างแบบ Real-time -->
                            <div style="font-size: 0.85rem; color: var(--gray); margin-top: 2px;">
                                <span style="color: #10b981;"><?php echo number_format($row['money_earned'], 2); ?> ฿</span> | 
                                <span style="color: #d97706;"><?php echo number_format($row['behavior_score'] ?? 0); ?> คะแนน</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding: 30px; text-align: center; color: #999;">ไม่พบข้อมูลประวัติการฝาก</div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&waste_type=<?php echo urlencode($waste_type); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" 
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