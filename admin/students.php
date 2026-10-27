<?php
session_start();
require_once '../db/db.php';

// 1. Authentication & Authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin'])) {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();

// AJAX Search Handler (Move to top)
if (isset($_GET['action']) && $_GET['action'] === 'search_student') {$search = $_GET['q'] ?? '';$sql = "SELECT * FROM users WHERE role = 'user' AND (first_name LIKE ? OR last_name LIKE ? OR id_code LIKE ?) ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(["\%$search%", "%$search\%", "\%$search%"]);
    $results =$stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($results);
    exit();
}

// ดึงข้อมูลผู้ใช้งานปัจจุบันเพื่อแสดงใน Sidebar
$user_id =$_SESSION['user_id'];
$stmt_user =$pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = :id");
$stmt_user->execute([':id' =>$user_id]);
$current_user =$stmt_user->fetch();

// 2. CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';$msg_type = '';

// 3. Handle POST Request (Add Student)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {$message = "Security Error: Invalid CSRF Token";
        $msg_type = "error";
    } else {
        $action =$_POST['action'] ?? 'create';

        if ($action === 'reset_password') {
            $student_id = filter_var($_POST['student_id'], FILTER_VALIDATE_INT);
            $new_password =$_POST['new_password'];

            if ($student_id && !empty($new_password) && strlen($new_password) >= 6) {
                try {
                    $hashed_password = password_hash($new_password, PASSWORD_ARGON2ID);
                    $stmt =$pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'user'");
                    $stmt->execute([$hashed_password, $student_id]);$message = "รีเซ็ตรหัสผ่านสำเร็จ";
                    $msg_type = "success";
                } catch (PDOException $e) {$message = "เกิดข้อผิดพลาด: " . $e->getMessage();$msg_type = "error";
                }
            } else {
                $message = "รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร";
                $msg_type = "error";
            }
        } elseif ($action === 'delete') {
            // Delete Student Logic
            $student_id = filter_var($_POST['student_id'], FILTER_VALIDATE_INT);
            
            if ($student_id) {
                try {
                    $pdo->beginTransaction();
                    // ลบข้อมูลที่เกี่ยวข้องก่อน (เพื่อความสะอาดของฐานข้อมูล)
                    $pdo->prepare("DELETE FROM bottle_entries WHERE student_id = ?")->execute([$student_id]);
                    $pdo->prepare("DELETE FROM redemptions WHERE student_id = ?")->execute([$student_id]);
                    
                    // ลบผู้ใช้งาน (ต้องเป็น role 'user' เท่านั้น)
                    $stmt =$pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
                    $stmt->execute([$student_id]);
                    
                    $pdo->commit();$message = "ลบข้อมูลนักเรียนเรียบร้อยแล้ว";
                    $msg_type = "success";
                } catch (Exception $e) {
                    $pdo->rollBack();$message = "เกิดข้อผิดพลาด: " . $e->getMessage();$msg_type = "error";
                }
            }
        } elseif ($action === 'create') {
            $id_code = trim($_POST['id_code']);
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $email = trim($_POST['email']);
            $password =$_POST['password'];
            $confirm_password =$_POST['confirm_password'];

            // Input Validation
            if (empty($id_code) || empty($first_name) || empty($last_name) || empty($email) || empty($password)) {$message = "กรุณากรอกข้อมูลให้ครบถ้วน";
                $msg_type = "error";
            } elseif ($password !== $confirm_password) {$message = "รหัสผ่านไม่ตรงกัน";
                $msg_type = "error";
            } else {
               try {
                    // 1. ตรวจสอบข้อมูลซ้ำ (ตรวจสอบก่อนเริ่ม Transaction เพื่อลดภาระ DB)
                    $stmt =$pdo->prepare("SELECT COUNT(*) FROM users WHERE id_code = ? OR email = ?");
                    $stmt->execute([$id_code,$email]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception("รหัสนักเรียนหรืออีเมลนี้มีอยู่ในระบบแล้ว");
                    }

                    // 2. เริ่ม Transaction
                    $pdo->beginTransaction();

                    // 3. เตรียมข้อมูลและบันทึก
                    $hashed_password = password_hash($password, PASSWORD_ARGON2ID);$sql_user = "INSERT INTO users (first_name, last_name, email, password, role, id_code, balance, points) 
                    VALUES (?, ?, ?, ?, 'user', ?, 0, 0)";
                    $stmt = $pdo->prepare($sql_user);
                    $stmt->execute([$first_name, $last_name,$email, $hashed_password,$id_code]);
                                            
                    $pdo->commit();$message = "เพิ่มนักเรียนใหม่สำเร็จ";
                    $msg_type = "success";

                 } catch (Exception $e) {
                    // 4. ตรวจสอบก่อนสั่ง Rollback เพื่อป้องกัน Error "no active transaction"
                    if ($pdo->inTransaction()) {$pdo->rollBack();
                    }
                    $message = "เกิดข้อผิดพลาด: " . $e->getMessage();$msg_type = "error";
                }
            }
        }
    }
}

// Export CSV Logic
if (isset($_GET['export']) && $_GET['export'] == 'csv') {$search = $_GET['search'] ?? '';$sql_export = "SELECT id_code, first_name, last_name, email, balance, points, created_at FROM users WHERE role = 'user'";
    $params_export = [];

    if ($search) {$sql_export .= " AND (first_name LIKE ? OR last_name LIKE ? OR id_code LIKE ?)";
        $params_export = ["\%$search%", "%$search\%", "\%$search%"];
    }

    $sql_export .= " ORDER BY id_code ASC";
    
    $stmt = $pdo->prepare($sql_export);
    $stmt->execute($params_export);
    $rows =$stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_list_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM for Excel
    fputcsv($output, ['รหัสนักเรียน', 'ชื่อ', 'นามสกุล', 'ชั้นเรียน', 'อีเมล', 'ยอดเงินคงเหลือ', 'แต้มสะสม', 'วันที่สมัคร']);

    foreach ($rows as$row) {
        fputcsv($output, [$row['id_code'], $row['first_name'],$row['last_name'], 
            $row['email'],$row['balance'], 
            $row['points'],$row['created_at']
        ]);
    }
    fclose($output);
    exit();
}

// 4. Handle GET Request (Search & Display)
$search = $_GET['search'] ?? '';$sql = "SELECT * FROM users WHERE role = 'user'";
$params = [];

if ($search) {$sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR id_code LIKE ?)";
    $params = ["\%$search%", "%$search\%", "\%$search%"];
}

$sql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students =$stmt->fetchAll();

// Helper Function
function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการนักเรียน - ระบบรับฝากขวด</title>
    <link rel="stylesheet" href="../superadmin/superadmincss/superadmin.css">
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
            <p class="admin-name"><?php echo h($_SESSION['first_name'] . ' ' .$_SESSION['last_name']); ?></p>
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
        <header class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1>จัดการนักเรียน</h1>
                <p class="subtitle">รายชื่อนักเรียนและสมาชิกในระบบ</p>
            </div>
            <button class="btn-submit" style="width: auto; padding: 12px 24px;" onclick="openModal()">
                <i class="fas fa-plus"></i> เพิ่มนักเรียน
            </button>
        </header>

        <div class="content-card">
            <div class="card-header">
                <h3>รายชื่อนักเรียนทั้งหมด</h3>
                <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" name="search" class="search-box" style="width: 250px; margin-bottom: 0;" placeholder="ค้นหาชื่อ หรือ รหัสนักเรียน..." value="<?php echo h($search); ?>" id="searchInput" onkeyup="searchStudents()">
                    <button type="submit" class="btn-icon"><i class="fas fa-search"></i></button>
                    <a href="?export=csv&search=<?php echo urlencode($search); ?>" class="btn-submit" style="width: auto; padding: 10px 15px; background: #10b981; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 5px;"><i class="fas fa-file-csv"></i> Export CSV</a>
                </form>
            </div>
            <div class="list-group" id="studentList">
                <?php if (count($students) > 0): ?>
                    <?php foreach ($students as$st): ?>
                    <div class="list-item">
                        <div style="display: flex; align-items: center;">
                            <div class="student-avatar"><i class="fas fa-user"></i></div>
                            <div>
                                <p class="item-title"><?php echo h($st['first_name'] . ' ' .$st['last_name']); ?></p>
                                <p class="item-desc">รหัส: <?php echo h($st['id_code']); ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-bold" style="color: #e11d48; font-size: 1.15rem; margin: 0;"><?php echo number_format($st['points']); ?> แต้ม</p>
                            <div style="font-size: 0.85rem; color: var(--gray); margin-bottom: 5px;">
                                <span style="color: #10b981;"><?php echo number_format($st['balance'], 2); ?> ฿</span> | 
                                <span style="color: #d97706;"><?php echo number_format($st['behavior_score'] ?? 0); ?> คะแนน</span>
                            </div>
                            <div style="margin-top: 5px; display: flex; justify-content: flex-end; gap: 5px;">
                                <button class="btn-icon" onclick="openResetModal(<?php echo $st['id']; ?>, '<?php echo h($st['first_name'] . ' ' .$st['last_name']); ?>')" title="รีเซ็ตรหัสผ่าน"><i class="fas fa-key"></i></button>
                                <button class="btn-icon danger" onclick="confirmDelete(<?php echo $st['id']; ?>, '<?php echo h($st['first_name'] . ' ' .$st['last_name']); ?>')" title="ลบนักเรียน"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>  
                    <p style="text-align: center; color: var(--gray); padding: 30px 0;">ไม่พบข้อมูลนักเรียน</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add Student Modal -->
    <div class="modal" id="addStudentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">เพิ่มนักเรียนใหม่</h3>
                <button class="btn-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create">
                
                <div class="form-group"><label class="form-label">รหัสประจำตัว</label><input type="text" name="id_code" class="form-control" required></div>
                <div class="form-group"><label class="form-label">ชื่อจริง</label><input type="text" name="first_name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">นามสกุล</label><input type="text" name="last_name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">อีเมล</label><input type="email" name="email" class="form-control" required></div>
                <div class="form-group"><label class="form-label">รหัสผ่าน</label><input type="password" name="password" class="form-control" required></div>
                <div class="form-group"><label class="form-label">ยืนยันรหัสผ่าน</label><input type="password" name="confirm_password" class="form-control" required></div>
                
                <button type="submit" class="btn-submit">บันทึกข้อมูล</button>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal" id="resetPasswordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">รีเซ็ตรหัสผ่าน</h3>
                <button class="btn-close" onclick="closeResetModal()">&times;</button>
            </div>
            <p style="margin-bottom: 20px; color: var(--gray);">กำลังเปลี่ยนรหัสผ่านสำหรับ: <span id="resetStudentName" style="font-weight: bold; color: var(--dark);"></span></p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="student_id" id="resetStudentId">
                
                <div class="form-group">
                    <label class="form-label">รหัสผ่านใหม่</label>
                    <input type="password" name="new_password" class="form-control" placeholder="อย่างน้อย 6 ตัวอักษร" required minlength="6">
                </div>
                
                <button type="submit" class="btn-submit" style="background: var(--pink);">บันทึกรหัสผ่านใหม่</button>
            </form>
        </div>
    </div>

    <!-- Hidden Delete Form -->
    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="student_id" id="deleteStudentId">
    </form>

    <script>
        function openModal() {
            document.getElementById('addStudentModal').classList.add('active');
        }
        function closeModal() {
            document.getElementById('addStudentModal').classList.remove('active');
        }
        
        function searchStudents() {
            const query = document.getElementById('searchInput').value;
            
            fetch(`students.php?action=search_student&q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    const list = document.getElementById('studentList');
                    list.innerHTML = '';
                    
                    if (data.length === 0) {
                        list.innerHTML = '<p style="text-align: center; color: var(--gray); padding: 30px 0;">ไม่พบข้อมูลนักเรียน</p>';
                        return;
                    }
                    
                    data.forEach(st => {
                        list.innerHTML += `
                        <div class="list-item">
                            <div style="display: flex; align-items: center;">
                                <div class="student-avatar"><i class="fas fa-user"></i></div>
                                <div><p class="item-title">${st.first_name} ${st.last_name}</p><p class="item-desc">รหัส: ${st.id_code}</p></div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold" style="color: #e11d48; font-size: 1.15rem; margin: 0;">${st.points} แต้ม</p>
                                <div style="font-size: 0.85rem; color: var(--gray); margin-bottom: 5px;">
                                    <span style="color: #10b981;">${parseFloat(st.balance).toFixed(2)} ฿</span> | 
                                    <span style="color: #d97706;">${st.behavior_score || 0} คะแนน</span>
                                </div>
                                <div style="margin-top: 5px; display: flex; justify-content: flex-end; gap: 5px;">
                                    <button class="btn-icon" onclick="openResetModal(${st.id}, '${st.first_name} ${st.last_name}')" title="รีเซ็ตรหัสผ่าน"><i class="fas fa-key"></i></button>
                                    <button class="btn-icon danger" onclick="confirmDelete(${st.id}, '${st.first_name} ${st.last_name}')" title="ลบนักเรียน"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                        </div>`;
                    });
                });
        }
        
        function openResetModal(id, name) {
            document.getElementById('resetStudentId').value = id;
            document.getElementById('resetStudentName').textContent = name;
            document.getElementById('resetPasswordModal').classList.add('active');
        }
        
        function closeResetModal() {
            document.getElementById('resetPasswordModal').classList.remove('active');
        }

        function confirmDelete(id, name) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: `คุณต้องการลบนักเรียน "${name}" ใช่หรือไม่? ข้อมูลการฝากและแต้มทั้งหมดจะถูกลบด้วย`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'ใช่, ลบเลย',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('deleteStudentId').value = id;
                    document.getElementById('deleteForm').submit();
                }
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