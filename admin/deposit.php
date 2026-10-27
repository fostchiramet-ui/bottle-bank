<?php
session_start();
require_once '../db/db.php';

// 1. Strict RBAC: Check if Superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin'])) {
    header("Location: ../login.php");
    exit();
}

$pdo = Database::connect();

// Helper Function
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// 3. AJAX Search API (Handle GET request with 'search' parameter)
if (isset($_GET['search_student'])) {
    $search = $_GET['search_student'];
    try {
        // SQL Injection Prevention: ใช้ Prepared Statement
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, id_code, class_group, balance, points FROM users WHERE role = 'user' AND (first_name LIKE ? OR last_name LIKE ? OR id_code LIKE ?) LIMIT 10");
        $stmt->execute(["%$search%", "%$search%", "%$search%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($results);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// 3.1 AJAX Get History API
if (isset($_GET['get_history'])) {
    $student_id = filter_var($_GET['get_history'], FILTER_VALIDATE_INT);
    if ($student_id) {
        // SQL Injection Prevention: ใช้ Prepared Statement
        $stmt = $pdo->prepare("SELECT id, bottle_type, bottle_size, quantity, money_earned, points_earned, created_at FROM bottle_entries WHERE student_id = :sid ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([':sid' => $student_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $type_map = [
            'pet' => 'ขวดใส (PET)',
            'can' => 'กระป๋อง',
            'glass' => 'ขวดแก้ว',
            'paper' => 'กระดาษ'
        ];

        foreach ($history as &$row) {
            $row['date_fmt'] = date('d/m/y H:i', strtotime($row['created_at']));
            $row['type_th'] = $type_map[$row['bottle_type']] ?? ucfirst($row['bottle_type']);
            $row['is_editable'] = (time() - strtotime($row['created_at']) <= 86400);
        }
        
        header('Content-Type: application/json');
        echo json_encode($history);
        exit();
    }
}

// ดึงข้อมูลผู้ใช้งานปัจจุบันเพื่อแสดงใน Sidebar
$user_id = $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = :id");
$stmt_user->execute([':id' => $user_id]);
$current_user = $stmt_user->fetch();

// Fetch Rates for JS Real-time Calculation
try {
    $stmt_rates = $pdo->query("SELECT code, price, points FROM waste_types");   
    $rates_db = $stmt_rates->fetchAll(PDO::FETCH_ASSOC);
    $rates_js = [];
    foreach ($rates_db as $r) {
        $rates_js[$r['code']] = ['price' => (float)$r['price'], 'points' => (int)$r['points']];
    }
} catch (Exception $e) {
    $rates_js = [];
}

if (empty($rates_js)) {
    $rates_js = [
        'pet' => ['price' => 2, 'points' => 10],
        'can' => ['price' => 3, 'points' => 15],
        'glass' => ['price' => 1.5, 'points' => 8],
        'paper' => ['price' => 1, 'points' => 5],
    ];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$msg_type = '';
$receipt_json = 'null';

// 4. Handle Deposit Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security Error: Invalid CSRF Token";
        $msg_type = "error";
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_deposit') {
        $deposit_id = filter_var($_POST['deposit_id'], FILTER_VALIDATE_INT);
        $new_qty = filter_var($_POST['quantity'], FILTER_VALIDATE_INT);
        $new_type = $_POST['bottle_type'];
        $new_size = $_POST['bottle_size'] ?? '';

        if (!$deposit_id || !$new_qty || $new_qty <= 0) {
            $message = "ข้อมูลไม่ถูกต้อง";
            $msg_type = "error";
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM bottle_entries WHERE id = ? FOR UPDATE");
                $stmt->execute([$deposit_id]);
                $old = $stmt->fetch();

                if (!$old) throw new Exception("ไม่พบรายการฝาก");
                if (time() - strtotime($old['created_at']) > 86400) throw new Exception("หมดเวลาแก้ไขรายการนี้แล้ว (เกิน 24 ชม.)");

                $rates = [
                    'pet' => ['price' => 2, 'points' => 10],
                    'can' => ['price' => 3, 'points' => 15],
                    'glass' => ['price' => 1.5, 'points' => 8],
                    'paper' => ['price' => 1, 'points' => 5],
                ];

                if (!isset($rates[$new_type])) throw new Exception("ประเภทขยะไม่ถูกต้อง");
                
                $rate = $rates[$new_type];
                $new_money = $new_qty * $rate['price'];
                $new_points = $new_qty * $rate['points'];

                $diff_money = $new_money - $old['money_earned'];
                $diff_points = $new_points - $old['points_earned'];

                // อัปเดตตารางประวัติ (ยังเก็บ money_earned ไว้เป็นอ้างอิง)
                $sql_update_entry = "UPDATE bottle_entries SET bottle_type = ?, bottle_size = ?, quantity = ?, money_earned = ?, points_earned = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql_update_entry);
                $stmt->execute([$new_type, $new_size, $new_qty, $new_money, $new_points, $deposit_id]);

                // อัปเดตตารางนักเรียน (ระบบใหม่ บวกเฉพาะ "แต้ม" เท่านั้น ไม่บวกเงินแล้ว)
                $sql_update_user = "UPDATE users SET points = points + ? WHERE id = ?";
                $stmt = $pdo->prepare($sql_update_user);
                $stmt->execute([$diff_points, $old['student_id']]);

                $pdo->commit();
                $message = "แก้ไขรายการสำเร็จ";
                $msg_type = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    } elseif (isset($_POST['student_id'])) {
        $student_id = filter_var($_POST['student_id'], FILTER_VALIDATE_INT);
        $items = [];
        if (!empty($_POST['items_json'])) {
            $items = json_decode($_POST['items_json'], true);
        } elseif (isset($_POST['bottle_type']) && isset($_POST['quantity'])) {
            $items[] = [
                'type' => $_POST['bottle_type'],
                'size' => $_POST['bottle_size'] ?? '',
                'qty' => $_POST['quantity']
            ];
        }

        if (!$student_id || empty($items)) {
            $message = "กรุณาเลือกนักเรียนและเพิ่มรายการฝากอย่างน้อย 1 รายการ";
            $msg_type = "error";
        } else {
            $rates = [
                'pet' => ['price' => 2, 'points' => 10],
                'can' => ['price' => 3, 'points' => 15],
                'glass' => ['price' => 1.5, 'points' => 8],
                'paper' => ['price' => 1, 'points' => 5],
            ];

            try {
                $pdo->beginTransaction();
                $total_money = 0;
                $total_points = 0;
                $count = 0;

                foreach ($items as $item) {
                    $type = $item['type'];
                    $size = $item['size'];
                    $qty = intval($item['qty']);

                    if (!isset($rates[$type]) || $qty <= 0) continue;

                    $rate = $rates[$type];
                    $money = $qty * $rate['price'];
                    $points = $qty * $rate['points'];

                    $sql_insert = "INSERT INTO bottle_entries (student_id, bottle_type, bottle_size, quantity, money_earned, points_earned, recorded_by) VALUES (:sid, :type, :size, :qty, :money, :points, :admin)";
                    $stmt = $pdo->prepare($sql_insert);
                    $stmt->execute([
                        ':sid' => $student_id,
                        ':type' => $type,
                        ':size' => $size,
                        ':qty' => $qty,
                        ':money' => $money,
                        ':points' => $points,
                        ':admin' => $_SESSION['user_id']
                    ]);

                    $total_money += $money;
                    $total_points += $points;
                    $count++;
                }

                if ($count > 0) {
                    // อัปเดตตารางนักเรียน (ระบบใหม่ บวกเฉพาะ "แต้ม" เท่านั้น ไม่บวกเงินแล้ว)
                    $sql_update = "UPDATE users SET points = points + :points WHERE id = :sid";
                    $stmt = $pdo->prepare($sql_update);
                    $stmt->execute([
                        ':points' => $total_points,
                        ':sid' => $student_id
                    ]);

                    $pdo->commit();

                    $stmt_st = $pdo->prepare("SELECT first_name, last_name, id_code, class_group, balance, points FROM users WHERE id = :sid");
                    $stmt_st->execute([':sid' => $student_id]);
                    $st_data = $stmt_st->fetch();
                    
                    $receipt_data = [
                        'school_name' => 'EcoBank วัดสุทธิวราราม',
                        'date' => date('d/m/Y H:i'),
                        'student_name' => $st_data['first_name'] . ' ' . $st_data['last_name'],
                        'student_id' => $st_data['id_code'],
                        'class_group' => $st_data['class_group'] ?? '-',
                        'admin_name' => $current_user['first_name'],
                        'items' => [],
                        'total_money' => number_format($total_money, 2),
                        'total_points' => number_format($total_points),
                        'balance_money' => number_format($st_data['balance'], 2),
                        'balance_points' => number_format($st_data['points'])
                    ];

                    $type_map = [
                        'pet' => 'ขวดใส (PET)',
                        'can' => 'กระป๋อง',
                        'glass' => 'ขวดแก้ว',
                        'paper' => 'กระดาษ'
                    ];

                    foreach ($items as $item) {
                        $receipt_data['items'][] = [
                            'name' => $type_map[$item['type']] ?? $item['type'],
                            'size' => $item['size'],
                            'qty' => $item['qty']
                        ];
                    }
                    $receipt_json = json_encode($receipt_data);

                    $message = "บันทึกข้อมูลสำเร็จ $count รายการ (+$total_points แต้ม)";
                    $msg_type = "success";
                } else {
                    $pdo->rollBack();
                    $message = "ไม่พบรายการที่ถูกต้อง";
                    $msg_type = "error";
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รับฝากขวด - SuthiBank</title>
    <link rel="stylesheet" href="../superadmin/superadmincss/superadmin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        .qr-actions {
            margin-top: 15px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .btn-scan-image {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #ffffff;
            color: #374151;
            padding: 10px 20px;
            border-radius: 20px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #e5e7eb;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            width: 100%;
        }
        .btn-scan-image:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
        }
        .btn-scan-image i {
            color: #10b981;
            font-size: 1.2rem;
        }
        /* กำหนดขนาดกล้องและบังคับ Aspect Ratio ผ่าน CSS */
        #reader {
            border-radius: 12px;
            overflow: hidden;
            border: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            background: #000;
            width: 100%;
        }
        #reader video {
            border-radius: 12px;
            width: 100% !important;
            height: auto !important;
            object-fit: cover !important;
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
            <h1>บันทึกการฝากขวด</h1>
            <p class="subtitle">รับฝากขวดจากนักเรียนและบันทึกคะแนน</p>
        </header>

        <div class="tables-grid" style="grid-template-columns: 1fr 2fr; align-items: start;">
            <div style="display: flex; flex-direction: column; gap: 24px;">
                <div class="content-card">
                    <div class="card-header">
                        <h3>ค้นหานักเรียน</h3>
                    </div>
                    <div style="padding: 20px;">
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="searchInput" class="search-box" placeholder="พิมพ์ชื่อ หรือ รหัสนักเรียน..." onkeyup="searchStudent()">
                            <button type="button" class="btn-icon" onclick="startQRScanner()" style="background: var(--dark); color: white; flex-shrink: 0;" title="สแกน QR Code"><i class="fas fa-qrcode"></i></button>
                        </div>
                        <div id="studentList" class="student-list" style="margin-top: 15px;">
                            <p style="color: #999; text-align: center; font-size: 0.9rem;">ผลการค้นหาจะปรากฏที่นี่</p>
                        </div>
                    </div>
                </div>
                
                <div class="content-card" id="historyCard" style="display: none;">
                    <div class="card-header">
                        <h3>ประวัติล่าสุด (5 รายการ)</h3>
                    </div>
                    <div class="list-group" id="historyList"></div>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">
                    <h3>รายละเอียดการฝาก</h3>
                </div>
                <div style="padding: 24px;">
                    <form method="POST" id="depositForm" onsubmit="return prepareSubmission()">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="student_id" id="selected_student_id" required>
                        <input type="hidden" name="items_json" id="itemsJson">
                        
                        <div id="selected_student_display" class="selected-student-box">
                            <div class="student-avatar" style="background: var(--green); color: white;"><i class="fas fa-user"></i></div>
                            <div>
                                <h4 id="display_name" style="margin: 0;"></h4>
                                <p id="display_id" style="margin: 0; font-size: 0.875rem; color: var(--gray);"></p>
                            </div>
                        </div>
                        <div id="no_student_msg" class="no-student-msg">
                            กรุณาเลือกนักเรียนจากรายการด้านซ้าย
                        </div>

                        <label class="form-label">ประเภทขยะ</label>
                        <div class="bottle-grid">
                            <label class="bottle-card">
                                <input type="radio" name="bottle_type" value="pet" required checked onchange="updateSizes('pet')">
                                <div class="bottle-content">
                                    <div class="bottle-icon"><i class="fas fa-recycle"></i></div>
                                    <h4>ขวดพลาสติกใส (PET)</h4>
                                </div>
                            </label>
                            <label class="bottle-card">
                                <input type="radio" name="bottle_type" value="can" onchange="updateSizes('can')">
                                <div class="bottle-content">
                                    <div class="bottle-icon"><i class="fas fa-box"></i></div>
                                    <h4>กระป๋องอลูมิเนียม</h4>
                                </div>
                            </label>
                            <label class="bottle-card">
                                <input type="radio" name="bottle_type" value="glass" onchange="updateSizes('glass')">
                                <div class="bottle-content">
                                    <div class="bottle-icon"><i class="fas fa-wine-bottle"></i></div>
                                    <h4>ขวดแก้ว</h4>
                                </div>
                            </label>
                            <label class="bottle-card">
                                <input type="radio" name="bottle_type" value="paper" onchange="updateSizes('paper')">
                                <div class="bottle-content">
                                    <div class="bottle-icon"><i class="fas fa-newspaper"></i></div>
                                    <h4>กระดาษ</h4>
                                </div>
                            </label>
                        </div>

                        <label class="form-label">ขนาด</label>
                        <div class="size-grid" id="sizeContainer"></div>

                        <label class="form-label">จำนวน (ชิ้น)</label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="number" id="inputQty" class="qty-input" min="1" value="1" oninput="calculatePrice()" onkeypress="handleEnter(event)">
                            <button type="button" class="btn-submit" style="width: auto; background: green; color: white; padding: 10px 20px;" onclick="addItemToList()">
                                <i class="fas fa-plus"></i> เพิ่มลงตะกร้า
                            </button>
                        </div>
                        
                        <div id="pricePreview" style="margin-top: 15px; padding: 15px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; color: #166534; font-weight: 600; display: none; text-align: center;"></div>

                        <div id="itemsListContainer" style="margin-top: 20px; display: none;">
                            <h4 style="margin-bottom: 10px; font-size: 1rem; font-weight: 600; color: var(--primary); text-align: center;">รายการที่กำลังฝาก</h4>
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>ประเภท</th>
                                        <th>ขนาด</th>
                                        <th>จำนวน</th>
                                        <th style="text-align: center;">ลบ</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTableBody"></tbody>
                            </table>
                        </div>

                        <button type="submit" class="btn-submit" style="margin-top: 20px;">ยืนยันการฝากทั้งหมด</button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal สแกน QR Code -->
    <div id="qrModal" class="modal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div class="modal-header">
                <h3 class="modal-title">สแกน QR Code</h3>
                <button class="btn-close" onclick="stopQRScanner()">&times;</button>
            </div>
            
            <div id="reader" style="width: 100%; margin-top: 10px;"></div>
            <p style="margin-top: 10px; color: #666; font-size: 0.9rem;" id="qrStatusText">กำลังเตรียมกล้อง...</p>
            
            <div class="qr-actions">
                <label for="qrImageInput" class="btn-scan-image">
                    <i class="fas fa-image"></i> อัปโหลดรูปภาพ
                </label>
                <input type="file" id="qrImageInput" accept="image/*" style="display: none;">
            </div>
        </div>
    </div>

    <script>
        function searchStudent() {
            const query = document.getElementById('searchInput').value;
            if (query.length < 2) return;

            fetch(`?search_student=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    const list = document.getElementById('studentList');
                    list.innerHTML = '';
                    if (data.length === 0) {
                        list.innerHTML = '<p style="color: #999; text-align: center;">ไม่พบข้อมูล</p>';
                        return;
                    }
                    data.forEach(student => {
                        let avatarHtml = '<div class="student-avatar" style="background: var(--green); color: white;"><i class="fas fa-user"></i></div>';
                        const item = document.createElement('div');
                        item.className = 'student-list-item';
                        item.innerHTML = `
                            ${avatarHtml}
                            <div>
                                <div style="font-weight: 600;">${student.first_name} ${student.last_name}</div>
                                <div style="font-size: 0.85rem; color: var(--gray);">ID: ${student.id_code}</div>
                            </div>
                        `;
                        item.onclick = () => selectStudent(student.id, `${student.first_name} ${student.last_name}`, student.id_code, student.class_group, student.balance, student.points);
                        list.appendChild(item);
                    });
                });
        }

        function selectStudent(id, name, code, group, balance, points) {
            document.getElementById('selected_student_id').value = id;
            let avatarHtml = '<div class="student-avatar" style="background: var(--green); color: white;"><i class="fas fa-user"></i></div>';
            const displayHtml = `
                ${avatarHtml}
                <div>
                    <h4 id="display_name" style="margin: 0;">${name}</h4>
                    <p id="display_id" style="margin: 0; font-size: 0.875rem; color: var(--gray);">
                        ID: ${code} | ชั้น: ${group || '-'}<br>
                        <span style="color:#e11d48; font-weight:600;">สะสม: ${points} แต้ม</span>
                    </p>
                </div>
            `;
            document.getElementById('selected_student_display').innerHTML = displayHtml;
            document.getElementById('selected_student_display').style.display = 'flex';
            document.getElementById('no_student_msg').style.display = 'none';
            fetchHistory(id);
        }

        let depositItems = [];
        const rates = <?php echo json_encode($rates_js); ?>;

        function calculatePrice() {
            const typeRadio = document.querySelector('input[name="bottle_type"]:checked');
            const qtyInput = document.getElementById('inputQty');
            const preview = document.getElementById('pricePreview');
            
            if (typeRadio && qtyInput && qtyInput.value > 0) {
                const type = typeRadio.value;
                const qty = parseFloat(qtyInput.value);
                const rate = rates[type] || {price: 0, points: 0};
                const totalMoney = (rate.price * qty).toFixed(2);
                const totalPoints = (rate.points * qty);
                
                preview.style.display = 'block';
                preview.innerHTML = `<i ></i> จะได้รับ: <span style="font-size: 1.2rem; color: #b45309;">${totalPoints}</span> แต้ม`;
            } else {
                preview.style.display = 'none';
            }
        }

        function addItemToList() {
            const typeRadio = document.querySelector('input[name="bottle_type"]:checked');
            const sizeRadio = document.querySelector('input[name="bottle_size"]:checked');
            const qtyInput = document.getElementById('inputQty');

            if (!typeRadio || !qtyInput.value || qtyInput.value <= 0) {
                Swal.fire('แจ้งเตือน', 'กรุณาเลือกประเภทและระบุจำนวนให้ถูกต้อง', 'warning');
                return;
            }

            const type = typeRadio.value;
            const size = sizeRadio ? sizeRadio.value : '-';
            const qty = parseInt(qtyInput.value);
            const typeLabel = typeRadio.nextElementSibling.querySelector('h4').innerText;
            let sizeLabel = sizeRadio ? sizeRadio.nextElementSibling.innerText : '-';

            depositItems.push({ type, size, qty, typeLabel, sizeLabel });
            renderItems();
            qtyInput.value = 1;
            calculatePrice();
        }

        function handleEnter(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addItemToList();
            }
        }

        function renderItems() {
            const tbody = document.getElementById('itemsTableBody');
            const container = document.getElementById('itemsListContainer');
            tbody.innerHTML = '';
            
            if (depositItems.length === 0) {
                container.style.display = 'none';
                return;
            }
            container.style.display = 'block';
            
            depositItems.forEach((item, index) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `<td>${item.typeLabel}</td><td>${item.sizeLabel}</td><td>${item.qty}</td><td style="text-align: center;"><button type="button" class="btn-icon danger" onclick="removeItem(${index})"><i class="fas fa-trash"></i></button></td>`;
                tbody.appendChild(tr);
            });
        }

        function removeItem(index) {
            depositItems.splice(index, 1);
            renderItems();
        }

        function prepareSubmission() {
            if (document.getElementById('selected_student_id').value === '') { Swal.fire('แจ้งเตือน', 'กรุณาเลือกนักเรียนก่อน', 'warning'); return false; }
            if (depositItems.length === 0) { Swal.fire('แจ้งเตือน', 'กรุณาเพิ่มรายการฝากอย่างน้อย 1 รายการ', 'warning'); return false; }
            document.getElementById('itemsJson').value = JSON.stringify(depositItems);
            return true;
        }

        const sizeData = {
            'pet': [{ val: '330ml', txt: '330-450 ml' }, { val: '600ml', txt: '600 ml' }, { val: '1500ml', txt: '1.5 ลิตร' }],
            'can': [{ val: '325ml', txt: '325 ml' }, { val: '500ml', txt: '500 ml' }],
            'glass': [{ val: 'small', txt: 'ขวดเล็ก' }, { val: 'large', txt: 'ขวดใหญ่' }],
            'paper': [{ val: 'kg', txt: 'กิโลกรัม' }]
        };

        function updateSizes(type) {
            const container = document.getElementById('sizeContainer');
            container.innerHTML = '';
            const sizes = sizeData[type] || [];
            sizes.forEach((size, index) => {
                const label = document.createElement('label');
                label.className = 'size-card';
                label.innerHTML = `
                    <input type="radio" name="bottle_size" value="${size.val}" ${index === 0 ? 'checked' : ''} onchange="calculatePrice()">
                    <div class="size-content">${size.txt}</div>
                `;
                container.appendChild(label);
            });
            calculatePrice();
        }

        function fetchHistory(studentId) {
            fetch(`?get_history=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    const list = document.getElementById('historyList');
                    const card = document.getElementById('historyCard');
                    list.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(item => {
                            let editBtn = item.is_editable ? `<button type="button" class="btn-icon" onclick='openEditModal(${JSON.stringify(item)})' title="แก้ไข"><i class="fas fa-edit"></i></button>` : '';
                            list.innerHTML += `
                                <div class="list-item" style="padding: 12px 20px;">
                                    <div>
                                        <p class="item-title" style="font-size: 0.95rem;">${item.type_th}</p>
                                        <p class="item-desc" style="font-size: 0.8rem;">${item.date_fmt}</p>
                                    </div>
                                    <div class="text-right">
                                        <span style="font-size: 0.9rem; font-weight: 600;">${item.quantity} ชิ้น</span><br>
                                        <span style="font-size: 0.9rem; color: #e11d48; font-weight: 600;">+${item.points_earned} แต้ม</span>
                                        ${editBtn}
                                    </div>
                                </div>
                            `;
                        });
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
        }

        document.addEventListener('DOMContentLoaded', () => { updateSizes('pet'); });

        <?php if ($message): ?>
            Swal.fire({
                title: '<?php echo ($msg_type == 'success') ? 'สำเร็จ!' : 'แจ้งเตือน'; ?>',
                text: '<?php echo $message; ?>',
                icon: '<?php echo $msg_type; ?>',
                confirmButtonColor: '#10b981',
                confirmButtonText: 'ปิด'
            });
        <?php endif; ?>

        // ---- ระบบสแกน QR Code แบบบังคับ Aspect Ratio -----
        let html5QrCode = null;

        function startQRScanner() {
            // 1. เปิด Modal ให้แสดงผลก่อน (เพื่อไม่ให้ไลบรารีคำนวณขนาดกรอบผิด)
            document.getElementById('qrModal').classList.add('active');
            document.getElementById('qrStatusText').innerText = "กำลังเตรียมกล้อง...";
            
            // 2. หน่วงเวลาเล็กน้อยให้ CSS Transition เปิดเสร็จสมบูรณ์
            setTimeout(() => {
                if (html5QrCode) {
                    html5QrCode.clear();
                }
                
                html5QrCode = new Html5Qrcode("reader");
                
                // ใช้การเปิดกล้องแบบ Promise พร้อมกำหนด Aspect Ratio
                html5QrCode.start(
                    { facingMode: "environment" }, 
                    {
                        fps: 10,
                        // คำนวณกรอบอัตโนมัติให้พอดีกับหน้าจอ
                        qrbox: function(qrframeWidth, qrframeHeight) {
                            let minEdge = Math.min(qrframeWidth, qrframeHeight);
                            let boxSize = Math.floor(minEdge * 0.7); 
                            return { width: boxSize, height: boxSize };
                        },
                        aspectRatio: 1.0 // บังคับสัดส่วน 1:1 ป้องกันภาพหน้าแบน/หน้าบาน
                    },
                    (decodedText, decodedResult) => {
                        // เมื่อสแกนติด
                        document.getElementById('searchInput').value = decodedText.trim();
                        stopQRScanner(); // ปิดกล้อง
                        searchStudent(); // ค้นหาเลย
                    },
                    (errorMessage) => {
                        // สแกนยังไม่เจอ ปล่อยผ่านไป
                    }
                ).then(() => {
                    document.getElementById('qrStatusText').innerText = "สแกน QR Code ให้อยู่ในกรอบ";
                }).catch((err) => {
                    console.error("Camera start error:", err);
                    document.getElementById('qrStatusText').innerHTML = "<span style='color:red;'>ไม่สามารถเปิดกล้องได้</span><br>กรุณาอัปโหลดรูปภาพแทน";
                });
            }, 300);
        }

        function stopQRScanner() {
            document.getElementById('qrModal').classList.remove('active');
            if (html5QrCode) {
                // ต้องตรวจสถานะก่อนหยุด ป้องกันบั๊กไลบรารี
                if (html5QrCode.getState() === 2) { // 2 = SCANNING
                    html5QrCode.stop().then(() => {
                        html5QrCode.clear();
                        html5QrCode = null;
                    }).catch(err => {
                        html5QrCode.clear();
                        html5QrCode = null;
                    });
                } else {
                    html5QrCode.clear();
                    html5QrCode = null;
                }
            }
        }

        // ระบบสแกนจากไฟล์รูปภาพ (แก้บั๊กประมวลผลชนกับกล้อง)
        document.getElementById('qrImageInput').addEventListener('change', function(e) {
            if (e.target.files.length == 0) return;
            const file = e.target.files[0];
            
            document.getElementById('qrStatusText').innerText = "กำลังประมวลผลรูปภาพ...";
            
            // สร้าง Promise เพื่อปิดกล้องก่อนอ่านไฟล์ (ถ้ากล้องเปิดอยู่)
            let prepareToScanFile = Promise.resolve();
            
            if (html5QrCode && html5QrCode.getState() === 2) { // 2 = SCANNING
                prepareToScanFile = html5QrCode.stop();
            } else if (!html5QrCode) {
                html5QrCode = new Html5Qrcode("reader");
            }
            
            prepareToScanFile.then(() => {
                return html5QrCode.scanFile(file, true);
            })
            .then(decodedText => {
                // อ่าน QR จากรูปสำเร็จ
                document.getElementById('searchInput').value = decodedText.trim();
                stopQRScanner();
                searchStudent();
            })
            .catch(err => {
                // ไม่เจอ QR ในรูปภาพ
                Swal.fire({
                    title: 'ไม่พบ QR Code',
                    text: 'รูปภาพนี้ไม่ชัดเจน หรือไม่มี QR Code อยู่ในภาพ',
                    icon: 'error',
                    confirmButtonColor: '#10b981'
                });
                document.getElementById('qrStatusText').innerText = "กรุณาสแกน หรืออัปโหลดรูปภาพใหม่";
                
                // รีเซ็ตเพื่อเปิดกล้องใหม่หากผู้ใช้อยากสแกนต่อ
                if(document.getElementById('qrModal').classList.contains('active')){
                    startQRScanner(); 
                }
            })
            .finally(() => {
                // เคลียร์ input ให้อัปโหลดไฟล์เดิมซ้ำได้
                e.target.value = '';
            });
        });
    </script>
</body>
</html>