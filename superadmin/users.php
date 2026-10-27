<?php
session_start();
require_once '../db/db.php';

function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// 1. Strict RBAC: Check if Superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', '1'])) {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();

// AJAX: Get User Details (สำหรับดึงข้อมูลมาแสดงใน Modal แก้ไข)
if (isset($_GET['action']) && $_GET['action'] === 'get_user' && isset($_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, id_code FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($user);
        exit();
    }
}

// AJAX: Get Login History
if (isset($_GET['action']) && $_GET['action'] === 'get_login_history' && isset($_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("SELECT login_time, ip_address FROM login_logs WHERE user_id = ? ORDER BY login_time DESC LIMIT 10");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
    }
}

// AJAX: Search Users
if (isset($_GET['action']) && $_GET['action'] === 'search_users') {
    $search = $_GET['q'] ?? '';
    $sql = "SELECT * FROM users WHERE role = 'admin'";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR id_code LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }
    
    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// Export CSV Logic
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $search = $_GET['q'] ?? '';
    $sql = "SELECT id_code, first_name, last_name, email, is_active, created_at FROM users WHERE role = 'admin'";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR id_code LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }
    
    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=admins_list_' . date('Ymd') . '.csv');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM for Excel
    fputcsv($output, ['รหัสเจ้าหน้าที่', 'ชื่อ', 'นามสกุล', 'อีเมล', 'สถานะ', 'วันที่สร้าง']);

    foreach ($rows as $row) {
        $status = $row['is_active'] == 1 ? 'ใช้งาน' : 'ถูกระงับ';
        fputcsv($output, [$row['id_code'], $row['first_name'], $row['last_name'], $row['email'], $status, $row['created_at']]);
    }
    fclose($output);
    exit();
}

// 2. CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$msg_type = '';

// 3. Handle CRUD Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF Token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("CSRF validation failed.");
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            // Create Admin (Role = 2)
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $id_code = trim($_POST['id_code']);
            $password = $_POST['password'];
            
            // Generate dummy email for schema constraint if not provided
            $email = $id_code . '@ecobank.local';

            if (empty($first_name) || empty($last_name) || empty($id_code) || empty($password)) {
                throw new Exception("กรุณากรอกข้อมูลให้ครบถ้วน");
            }

            // Check duplicate ID Code
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id_code = ?");
            $stmt->execute([$id_code]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("รหัสเจ้าหน้าที่นี้มีอยู่ในระบบแล้ว");
            }

            // Security: Password Hashing
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert with role = 'admin' (2) and is_active = 1
            $sql = "INSERT INTO users (first_name, last_name, email, password, role, id_code, is_active) VALUES (?, ?, ?, ?, 'admin', ?, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$first_name, $last_name, $email, $hashed_password, $id_code]);

            $message = "เพิ่มเจ้าหน้าที่สำเร็จ";
            $msg_type = "success";

        } elseif ($action === 'update') {
            // Update User
            $id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $id_code = trim($_POST['id_code']);

            // Check duplicate ID Code (exclude self)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id_code = ? AND id != ?");
            $stmt->execute([$id_code, $id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("รหัสเจ้าหน้าที่นี้มีอยู่ในระบบแล้ว");
            }

            $sql = "UPDATE users SET first_name = ?, last_name = ?, id_code = ? WHERE id = ?";
            $params = [$first_name, $last_name, $id_code, $id];

            // Update password only if provided
            if (!empty($_POST['password'])) {
                $sql = "UPDATE users SET first_name = ?, last_name = ?, id_code = ?, password = ? WHERE id = ?";
                $params = [$first_name, $last_name, $id_code, password_hash($_POST['password'], PASSWORD_DEFAULT), $id];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $message = "แก้ไขข้อมูลสำเร็จ";
            $msg_type = "success";

        } elseif ($action === 'suspend') {
            // Soft Delete
            $id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
            
            if ($id == $_SESSION['user_id']) {
                throw new Exception("ไม่สามารถลบบัญชีตัวเองได้");
            }

            $sql = "UPDATE users SET is_active = 0 WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);

            $message = "ระงับบัญชีเจ้าหน้าที่สำเร็จ";
            $msg_type = "success";
        } elseif ($action === 'reset_password') {
            // Reset Password
            $id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
            $new_password = $_POST['new_password'];

            if ($id && !empty($new_password) && strlen($new_password) >= 6) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $id]);
                $message = "รีเซ็ตรหัสผ่านสำเร็จ";
                $msg_type = "success";
            } else {
                throw new Exception("รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร");
            }
        } elseif ($action === 'import_csv') {
            // Import CSV Logic
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['csv_file']['tmp_name'];
                $handle = fopen($file, "r");
                
                $success_count = 0;
                $fail_count = 0;
                $row = 0;

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row++;
                    if ($row == 1) continue; // ข้าม Header Row

                    // CSV Format: id_code, first_name, last_name, email (opt), password (opt)
                    $id_code = trim($data[0] ?? '');
                    $first_name = trim($data[1] ?? '');
                    $last_name = trim($data[2] ?? '');
                    
                    if (empty($id_code) || empty($first_name)) {
                        $fail_count++; continue;
                    }

                    $email = trim($data[3] ?? '');
                    if (empty($email)) $email = $id_code . '@ecobank.local';
                    
                    $password = trim($data[4] ?? '');
                    if (empty($password)) $password = $id_code; // Default password = id_code

                    try {
                        // Insert (Ignore duplicates handled by try-catch or check first)
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password, role, id_code, is_active) VALUES (?, ?, ?, ?, 'admin', ?, 1)");
                        $stmt->execute([$first_name, $last_name, $email, $hashed_password, $id_code]);
                        $success_count++;
                    } catch (Exception $e) {
                        $fail_count++;
                    }
                }
                fclose($handle);
                $message = "นำเข้าข้อมูลสำเร็จ $success_count รายการ (ไม่สำเร็จ $fail_count รายการ)";
                $msg_type = ($fail_count > 0 && $success_count == 0) ? "error" : "success";
            } else { throw new Exception("กรุณาเลือกไฟล์ CSV"); }
        }
    } catch (Exception $e) {
        $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $msg_type = "error";
    }
}

// 4. Read Users (Active only)
// ตรวจสอบว่ามี column is_active หรือไม่ ถ้าไม่มีให้ดึงทั้งหมด (เพื่อป้องกัน error ในขณะที่ยังไม่ได้แก้ DB)
$users = [];
try {
    // Fetch only Admins (role = 'admin' or 2)
    $stmt = $pdo->query("SELECT *, IF(last_activity > DATE_SUB(NOW(), INTERVAL 10 MINUTE), 1, 0) as is_online FROM users WHERE role = 'admin' ORDER BY is_online DESC, created_at DESC");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $users = [];
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้ใช้งาน - Recycle Bank</title>
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
        <header class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1>จัดการเจ้าหน้าที่ (Admin)</h1>
                <p class="subtitle">เพิ่ม แก้ไข และระงับสิทธิ์การใช้งานของเจ้าหน้าที่</p>
            </div>
            <div>
                <button class="btn-primary" onclick="openImportModal()" style="background: #8b5cf6; margin-right: 10px;"><i class="fas fa-file-import"></i> Import CSV</button>
                <button class="btn-primary" onclick="openModal('create')"><i class="fas fa-plus"></i> เพิ่มเจ้าหน้าที่</button>
            </div>
        </header>

        <div class="content-card">
            <div class="card-header">
                <h3>รายชื่อเจ้าหน้าที่</h3>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" id="searchInput" class="search-box" style="width: 250px;" placeholder="ค้นหา..." onkeyup="searchUsers()">
                    <a href="?export=csv" id="exportBtn" class="btn-primary" style="background: #10b981; padding: 10px 15px; text-decoration: none; font-size: 0.9rem;"><i class="fas fa-file-csv"></i> Export</a>
                </div>
            </div>
            <div class="list-group" id="userList">
                <?php foreach ($users as $u): ?>
                <?php 
                    $is_online = $u['is_online'] == 1;
                    $status_color = $is_online ? '#10b981' : '#d1d5db';
                    $status_text = $is_online ? 'ออนไลน์' : 'ออฟไลน์';
                ?>
                <div class="list-item">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 10px; height: 10px; border-radius: 50%; background-color: <?php echo $status_color; ?>;" title="<?php echo $status_text; ?>"></div>
                        <div>
                        <p class="item-title">
                            <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                            <?php if($u['is_active'] == 0) echo '<span style="color:red; font-size:0.8rem;">(ถูกระงับ)</span>'; ?>
                        </p>
                        <p class="item-desc">รหัสเจ้าหน้าที่: <?php echo htmlspecialchars($u['id_code']); ?></p>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <button class="btn-icon" onclick="editUser(<?php echo $u['id']; ?>)"><i class="fas fa-edit"></i></button>
                        <button class="btn-icon" onclick="openResetModal(<?php echo $u['id']; ?>, '<?php echo h($u['first_name'] . ' ' . $u['last_name']); ?>')" title="รีเซ็ตรหัสผ่าน"><i class="fas fa-key"></i></button>
                        <button class="btn-icon" onclick="viewLoginHistory(<?php echo $u['id']; ?>, '<?php echo h($u['first_name'] . ' ' . $u['last_name']); ?>')" title="ประวัติการเข้าสู่ระบบ"><i class="fas fa-history"></i></button>
                        
                        <?php if($u['is_active'] == 1): ?>
                        <form method="POST" onsubmit="return confirm('ยืนยันการระงับบัญชีนี้?');" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="suspend">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="btn-icon danger" title="ระงับสิทธิ์"><i class="fas fa-ban"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <!-- User Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">จัดการเจ้าหน้าที่</h3>
                <button class="btn-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="userForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="user_id" id="userId">
                
                <div class="form-group"><label class="form-label">ชื่อจริง</label><input type="text" name="first_name" id="firstName" class="form-control" required></div>
                <div class="form-group"><label class="form-label">นามสกุล</label><input type="text" name="last_name" id="lastName" class="form-control" required></div>
                <div class="form-group"><label class="form-label">รหัสเจ้าหน้าที่ (Username)</label><input type="text" name="id_code" id="idCode" class="form-control" required></div>
                <div class="form-group">
                    <label class="form-label">รหัสผ่าน</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                    <small style="color: #6b7280; font-size: 0.8rem; display: none;" id="passwordHint">เว้นว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน</small>
                </div>
                
                <button type="submit" class="btn-submit">บันทึกข้อมูล</button>
            </form>
        </div>
    </div>

    <!-- Import Modal -->
    <div class="modal" id="importModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Import รายชื่อเจ้าหน้าที่</h3>
                <button class="btn-close" onclick="closeImportModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="import_csv">
                
                <div class="form-group">
                    <label class="form-label">เลือกไฟล์ CSV</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: #666; background: #f9fafb; padding: 10px; border-radius: 8px;">
                        <p style="font-weight: 600;">รูปแบบไฟล์ (บรรทัดแรกเป็น Header):</p>
                        <code>รหัสเจ้าหน้าที่, ชื่อ, นามสกุล, อีเมล(เลือก), รหัสผ่าน(เลือก)</code>
                        <p style="margin-top: 5px; font-size: 0.8rem;">* หากไม่ระบุรหัสผ่าน จะใช้รหัสเจ้าหน้าที่เป็นรหัสผ่านเริ่มต้น</p>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">นำเข้าข้อมูล</button>
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
            <p style="margin-bottom: 20px; color: var(--gray);">กำลังเปลี่ยนรหัสผ่านสำหรับ: <span id="resetUserName" style="font-weight: bold; color: var(--dark);"></span></p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">
                
                <div class="form-group">
                    <label class="form-label">รหัสผ่านใหม่</label>
                    <input type="password" name="new_password" class="form-control" placeholder="อย่างน้อย 6 ตัวอักษร" required minlength="6">
                </div>
                
                <button type="submit" class="btn-submit" style="background: var(--pink);">บันทึกรหัสผ่านใหม่</button>
            </form>
        </div>
    </div>

    <!-- Login History Modal -->
    <div class="modal" id="historyModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">ประวัติการเข้าสู่ระบบ</h3>
                <button class="btn-close" onclick="closeHistoryModal()">&times;</button>
            </div>
            <p style="margin-bottom: 15px; color: var(--gray);">ผู้ใช้งาน: <span id="historyUserName" style="font-weight: bold; color: var(--dark);"></span></p>
            <table class="history-table">
                <thead><tr><th>วัน/เวลา</th><th>IP Address</th></tr></thead>
                <tbody id="historyTableBody">
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function openModal(mode, data = null) {
            const modal = document.getElementById('userModal');
            const form = document.getElementById('userForm');
            const title = modal.querySelector('.modal-title');
            const passInput = document.getElementById('password');
            const passHint = document.getElementById('passwordHint');

            // Reset Form
            form.reset();
            modal.classList.add('active');

            if (mode === 'create') {
                title.textContent = 'เพิ่มเจ้าหน้าที่ใหม่';
                document.getElementById('formAction').value = 'create';
                passInput.required = true;
                passHint.style.display = 'none';
            } else {
                title.textContent = 'แก้ไขข้อมูลเจ้าหน้าที่';
                document.getElementById('formAction').value = 'update';
                document.getElementById('userId').value = data.id;
                document.getElementById('firstName').value = data.first_name;
                document.getElementById('lastName').value = data.last_name;
                document.getElementById('idCode').value = data.id_code;
                
                passInput.required = false;
                passHint.style.display = 'block';
            }
        }
        
        function searchUsers() {
            const query = document.getElementById('searchInput').value;
            const csrfToken = document.querySelector('input[name="csrf_token"]').value;
            const exportBtn = document.getElementById('exportBtn');

            // Update export button link
            exportBtn.href = `?export=csv&q=${encodeURIComponent(query)}`;
            
            fetch(`users.php?action=search_users&q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    const list = document.getElementById('userList');
                    list.innerHTML = '';
                    
                    if (data.length === 0) {
                        list.innerHTML = '<div style="padding: 20px; text-align: center; color: #999;">ไม่พบข้อมูล</div>';
                        return;
                    }
                    
                    data.forEach(u => {
                        const isActive = u.is_active == 1;
                        // Note: In AJAX search, we might not have real-time 'is_online' calculation unless query is updated.
                        // For simplicity, let's assume offline or fetch it if needed. 
                        // Or better, update the AJAX query to include is_online logic.
                        const suspendedSpan = !isActive ? '<span style="color:red; font-size:0.8rem;">(ถูกระงับ)</span>' : '';
                        let suspendForm = '';
                        
                        if (isActive) {
                            suspendForm = `<form method="POST" onsubmit="return confirm('ยืนยันการระงับบัญชีนี้?');" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="${csrfToken}">
                                <input type="hidden" name="action" value="suspend">
                                <input type="hidden" name="user_id" value="${u.id}">
                                <button type="submit" class="btn-icon danger" title="ระงับสิทธิ์"><i class="fas fa-ban"></i></button>
                            </form>`;
                        }

                        list.innerHTML += `<div class="list-item">
                            <div style="padding-left: 22px;"> <!-- Indent to align with non-ajax list -->
                                <p class="item-title">${h(u.first_name)} ${h(u.last_name)} ${suspendedSpan}</p>
                                <p class="item-desc">รหัสเจ้าหน้าที่: ${h(u.id_code)}</p>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <button class="btn-icon" onclick="editUser(${u.id})"><i class="fas fa-edit"></i></button>
                                <button class="btn-icon" onclick="openResetModal(${u.id}, '${h(u.first_name)} ${h(u.last_name)}')" title="รีเซ็ตรหัสผ่าน"><i class="fas fa-key"></i></button>
                                <button class="btn-icon" onclick="viewLoginHistory(${u.id}, '${h(u.first_name)} ${h(u.last_name)}')" title="ประวัติการเข้าสู่ระบบ"><i class="fas fa-history"></i></button>
                                ${suspendForm}
                            </div>
                        </div>`;
                    });
                });
        }

        function h(text) {
            return text ? text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;") : '';
        }

        function editUser(id) {
            fetch(`users.php?action=get_user&id=${id}`)
                .then(res => res.json())
                .then(data => {
                    if(data) openModal('update', data);
                })
                .catch(err => console.error(err));
        }

        function openImportModal() {
            document.getElementById('importModal').classList.add('active');
        }
        
        function closeImportModal() {
            document.getElementById('importModal').classList.remove('active');
        }

        function openResetModal(id, name) {
            document.getElementById('resetUserId').value = id;
            document.getElementById('resetUserName').textContent = name;
            document.getElementById('resetPasswordModal').classList.add('active');
        }
        
        function closeResetModal() {
            document.getElementById('resetPasswordModal').classList.remove('active');
        }

        function viewLoginHistory(id, name) {
            document.getElementById('historyUserName').textContent = name;
            const tbody = document.getElementById('historyTableBody');
            tbody.innerHTML = '<tr><td colspan="2" style="text-align:center;">กำลังโหลด...</td></tr>';
            
            document.getElementById('historyModal').classList.add('active');
            
            fetch(`users.php?action=get_login_history&id=${id}`)
                .then(res => res.json())
                .then(data => {
                    tbody.innerHTML = '';
                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="2" style="text-align:center; color:#999;">ไม่พบประวัติการเข้าสู่ระบบ</td></tr>';
                    } else {
                        data.forEach(log => {
                            tbody.innerHTML += `<tr><td>${log.login_time}</td><td>${log.ip_address}</td></tr>`;
                        });
                    }
                })
                .catch(err => {
                    tbody.innerHTML = '<tr><td colspan="2" style="text-align:center; color:red;">เกิดข้อผิดพลาด</td></tr>';
                });
        }

        function closeHistoryModal() { document.getElementById('historyModal').classList.remove('active'); }

        function closeModal() { document.getElementById('userModal').classList.remove('active'); }
        
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