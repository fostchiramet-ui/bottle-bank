<?php
session_start();
require_once '../db/db.php';

// 1. Authentication & Authorization
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();

// 2. CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$msg_type = '';

// 3. Handle POST Actions (Approve/Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security Error: Invalid CSRF Token";
        $msg_type = "error";
    } else {
        $request_id = filter_var($_POST['request_id'], FILTER_VALIDATE_INT);
        $action = $_POST['action'];
        $admin_note = trim($_POST['admin_note'] ?? '');

        if ($request_id && in_array($action, ['approve', 'reject'])) {
            try {
                $pdo->beginTransaction();

                // Lock row for update
                $stmt = $pdo->prepare("SELECT * FROM redemptions WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $request_id]);
                $req = $stmt->fetch();

                if (!$req) throw new Exception("ไม่พบรายการคำร้อง");
                if ($req['status'] !== 'pending') throw new Exception("รายการนี้ถูกดำเนินการไปแล้ว");

                if ($action === 'approve') {
                    // Logic อนุมัติ: เปลี่ยนสถานะ (เงินถูกหักไปแล้วตอนส่งคำร้อง)
                    $sql = "UPDATE redemptions SET status = 'approved', approver_id = :aid, updated_at = NOW() WHERE id = :rid";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':aid' => $_SESSION['user_id'], ':rid' => $request_id]);
                    
                    $message = "อนุมัติคำร้องเรียบร้อยแล้ว";
                    $msg_type = "success";

                } elseif ($action === 'reject') {
                    // Logic ปฏิเสธ: เปลี่ยนสถานะ + คืนเงิน/แต้ม (Refund)
                    $sql = "UPDATE redemptions SET status = 'rejected', approver_id = :aid, admin_note = :note, updated_at = NOW() WHERE id = :rid";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':aid' => $_SESSION['user_id'],
                        ':note' => $admin_note,
                        ':rid' => $request_id
                    ]);

                    // คืนยอดให้นักเรียน
                    if ($req['type'] === 'money') {
                        $refund = "UPDATE users SET balance = balance + :amt WHERE id = :uid";
                        $stmt = $pdo->prepare($refund);
                        $stmt->execute([':amt' => $req['amount'], ':uid' => $req['student_id']]);
                    } else {
                        $refund = "UPDATE users SET points = points + :pts WHERE id = :uid";
                        $stmt = $pdo->prepare($refund);
                        $stmt->execute([':pts' => $req['points'], ':uid' => $req['student_id']]);
                    }

                    $message = "ปฏิเสธคำร้องและคืนยอดให้ผู้ใช้เรียบร้อยแล้ว";
                    $msg_type = "success";
                }

                $pdo->commit();

            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
}

// 4. Fetch Requests
$sql = "SELECT r.*, u.first_name, u.last_name, u.id_code, a.first_name as approver_name 
        FROM redemptions r 
        JOIN users u ON r.student_id = u.id 
        LEFT JOIN users a ON r.approver_id = a.id
        ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.created_at DESC";
$requests = $pdo->query($sql)->fetchAll();

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
    <title>จัดการคำร้องขอ - ระบบรับฝากขวด</title>
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
            <p class="admin-name"><?php echo h($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></p>
            <p class="admin-role">ผู้ดูแลระบบ</p>
            <span class="badge-admin">SUPER ADMIN</span>
        </div>

       <?php include 'includes/bottom_nav.php'; ?>
        <div class="sidebar-footer">
            <a href="../logout.php" class="menu-item text-muted">
                <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
            </a>
        </div>
    </aside>

    <main class="main-content">
        <header class="page-header">
            <h1>จัดการคำร้องขอ</h1>
            <p class="subtitle">ตรวจสอบและอนุมัติคำร้องขอแลกเงินและคะแนน</p>
        </header>

        <div class="content-card">
            <div class="card-header">
                <h3>รายการคำร้องขอ</h3>
                <input type="text" id="searchInput" class="search-box" style="width: 250px; margin-bottom: 0;" placeholder="ค้นหาชื่อ..." onkeyup="filterRequests()">
            </div>
            
            <div style="padding: 0 24px;">
                <div class="tabs-container">
                    <button class="tab-btn active" onclick="filterTab('all', this)">ทั้งหมด</button>
                    <button class="tab-btn" onclick="filterTab('pending', this)">รอดำเนินการ</button>
                    <button class="tab-btn" onclick="filterTab('approved', this)">อนุมัติแล้ว</button>
                    <button class="tab-btn" onclick="filterTab('rejected', this)">ไม่อนุมัติ</button>
                </div>
            </div>

            <div class="list-group" id="requestList">
                <?php foreach ($requests as $req): 
                    $status_class = 'bg-amber txt-amber';
                    $status_text = 'รอดำเนินการ';
                    if ($req['status'] == 'approved') { $status_class = 'bg-green txt-green'; $status_text = 'อนุมัติแล้ว'; }
                    elseif ($req['status'] == 'rejected') { $status_class = 'bg-pink txt-pink'; $status_text = 'ไม่อนุมัติ'; }
                    
                    $desc = ($req['type'] == 'money') ? "ขอแลกเงิน " . number_format($req['amount'], 2) . " บาท" : "ขอแลกคะแนน " . number_format($req['points']) . " แต้ม";
                ?>
                <div class="list-item request-item" data-status="<?php echo $req['status']; ?>" data-name="<?php echo h($req['first_name'].' '.$req['last_name']); ?>">
                    <div>
                        <p class="item-title"><?php echo h($req['first_name'].' '.$req['last_name']); ?> (<?php echo h($req['id_code']); ?>)</p>
                        <p class="item-desc"><?php echo $desc; ?> — <?php echo thai_date($req['created_at']); ?></p>
                    </div>
                    <div style="display: flex; align-items: center;">
                        <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                        
                        <?php if ($req['status'] == 'pending'): ?>
                        <div style="margin-left: 15px; display: flex; gap: 8px;">
                            <button class="btn-approve-full" onclick="confirmAction(<?php echo $req['id']; ?>, 'approve')"><i class="fas fa-check"></i> อนุมัติ</button>
                            <button class="btn-reject-full" onclick="confirmAction(<?php echo $req['id']; ?>, 'reject')"><i class="fas fa-times"></i> ปฏิเสธ</button>
                        </div>
                        <?php elseif ($req['approver_name']): ?>
                        <div class="approver-info" style="margin-left: 15px;"><i class="fas fa-user-check"></i> โดย <?php echo h($req['approver_name']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <!-- Hidden Form for Actions -->
    <form id="actionForm" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="request_id" id="formRequestId">
        <input type="hidden" name="action" id="formAction">
        <input type="hidden" name="admin_note" id="formAdminNote">
    </form>

    <script>
        function confirmAction(id, action) {
            if (action === 'approve') {
                Swal.fire({
                    title: 'ยืนยันการอนุมัติ?',
                    text: "ต้องการอนุมัติคำร้องนี้ใช่หรือไม่",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#10b981',
                    confirmButtonText: 'ยืนยัน, อนุมัติเลย!'
                }).then((result) => {
                    if (result.isConfirmed) submitForm(id, action);
                });
            } else {
                Swal.fire({
                    title: 'ระบุเหตุผลการปฏิเสธ',
                    input: 'text',
                    inputPlaceholder: 'เช่น ยอดเงินไม่ถูกต้อง',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    confirmButtonText: 'ยืนยันการปฏิเสธ'
                }).then((result) => {
                    if (result.isConfirmed) submitForm(id, action, result.value);
                });
            }
        }

        function submitForm(id, action, note = '') {
            document.getElementById('formRequestId').value = id;
            document.getElementById('formAction').value = action;
            document.getElementById('formAdminNote').value = note;
            document.getElementById('actionForm').submit();
        }

        function filterTab(status, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const items = document.querySelectorAll('.request-item');
            items.forEach(item => {
                item.style.display = (status === 'all' || item.dataset.status === status) ? 'flex' : 'none';
            });
        }

        function filterRequests() {
            const searchText = document.getElementById('searchInput').value.toLowerCase();
            document.querySelectorAll('.request-item').forEach(item => {
                const name = item.dataset.name.toLowerCase();
                item.style.display = name.includes(searchText) ? 'flex' : 'none';
            });
        }

        // SweetAlert2 Feedback
        <?php if ($message): ?>
            Swal.fire({
                title: '<?php echo ($msg_type == 'success') ? 'สำเร็จ!' : 'แจ้งเตือน'; ?>',
                text: '<?php echo $message; ?>',
                icon: '<?php echo $msg_type; ?>',
                confirmButtonText: 'ตกลง'
            });
        <?php endif; ?>
    </script>
</body>
</html>