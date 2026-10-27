<?php
session_start();
require_once '../db/db.php';

// 1. ตรวจสอบ Session: นักเรียนต้องล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../login.php");
    exit();
}


$student_id = $_SESSION['user_id'];
$pdo = Database::connect();

// ฟังก์ชันดึงเรทแลกเปลี่ยนจากตาราง system_settings
function getExchangeRates($pdo) {
    // ค่าเริ่มต้น (กรณีใน system_settings ยังไม่ได้ตั้งค่า)
    // points_per_money = ใช้กี่แต้ม ได้ 1 บาท (เช่น 10 แต้ม = 1 บาท)
    // points_per_score = ใช้กี่แต้ม ได้ 1 คะแนนพฤติกรรม (เช่น 50 แต้ม = 1 คะแนน)
    $rates = [
        'points_per_money' => 10,
        'points_per_score' => 50
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('points_per_money', 'points_per_score')");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $val = floatval($row['setting_value']);
                if ($val > 0) {
                    $rates[$row['setting_key']] = $val;
                }
            }
        }
    } catch (Exception $e) {
        // หากโครงสร้างคอลัมน์ต่างกัน จะใช้ค่าเริ่มต้นไปก่อนจนกว่าจะเชื่อมกับ settings.php
    }

    return $rates;
}

// API สำหรับดึงเรทแบบ Real-time (AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'get_rates_realtime') {
    $current_rates = getExchangeRates($pdo);
    
    $stmt_pts = $pdo->prepare("SELECT points FROM users WHERE id = :uid");
    $stmt_pts->execute([':uid' => $student_id]);
    $u_pts = $stmt_pts->fetchColumn();

    header('Content-Type: application/json');
    echo json_encode([
        'points_per_money' => $current_rates['points_per_money'],
        'points_per_score' => $current_rates['points_per_score'],
        'user_points' => intval($u_pts)
    ]);
    exit();
}

// 2. CSRF Protection: สร้าง Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$msg_type = '';
$rates = getExchangeRates($pdo);

// 3. Handle POST Request (ส่งคำร้อง)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security Error: Invalid CSRF Token";
        $msg_type = "error";
    } else {
        $type = $_POST['type'] ?? '';
        $points_to_use = filter_var($_POST['amount'], FILTER_VALIDATE_INT);

        if (!in_array($type, ['money', 'reward'])) {
            $message = "ประเภทรายการไม่ถูกต้อง";
            $msg_type = "error";
        } elseif (
            $points_to_use === false ||
            $points_to_use <= 0
        ) {
            $message = "กรุณาระบุจำนวนแต้มที่ถูกต้อง (มากกว่า 0)";
            $msg_type = "error";
        } else {
            try {
                // ดึงเรทล่าสุดอีกครั้งก่อนคำนวณจริง
                $latest_rates = getExchangeRates($pdo);
                $rate_used = ($type === 'money') ? $latest_rates['points_per_money'] : $latest_rates['points_per_score'];

                if ($points_to_use < $rate_used) {
                    throw new Exception("ต้องใช้ขั้นต่ำอย่างน้อย " . number_format($rate_used) . " แต้ม สำหรับการแลกรายการนี้");
                }

                // คำนวณจำนวนเงิน หรือ คะแนนพฤติกรรมที่จะได้รับ
                if ($type === 'money') {
                    $calculated_amount = round($points_to_use / $rate_used, 2);
                } else {
                    // คะแนนพฤติกรรมคิดเป็นจำนวนเต็ม และหักแต้มตามสัดส่วนจริง
                    $calculated_amount = floor($points_to_use / $rate_used);
                    if ($calculated_amount <= 0) {
                        throw new Exception("แต้มที่ระบุไม่เพียงพอที่จะแลกเป็นคะแนนพฤติกรรม");
                    }
                }

                $pdo->beginTransaction();

                // Lock row & Check Points
                $stmt = $pdo->prepare("SELECT points FROM users WHERE id = :uid FOR UPDATE");
                $stmt->execute([':uid' => $student_id]);
                $user = $stmt->fetch();

                if (!$user) {
                    throw new Exception("ไม่พบข้อมูลผู้ใช้งาน");
                }

                $current_points = $user['points'] ?? 0;

                if ($current_points < $points_to_use) {
                    throw new Exception("แต้มคงเหลือไม่เพียงพอ (คุณมี " . number_format($current_points) . " แต้ม)");
                }

                // Step 1: หักแต้มออกจากบัญชีนักเรียน
                $update_sql = "UPDATE users SET points = points - :pts WHERE id = :uid";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([':pts' => $points_to_use, ':uid' => $student_id]);

                // Step 2: บันทึกรายการคำร้องพร้อมยอดเงิน/คะแนนที่คำนวณได้ลงในคอลัมน์ amount
                $insert_sql = "INSERT INTO redemptions (student_id, type, amount, points, status) VALUES (:uid, :type, :amt, :pts, 'pending')";
                $stmt = $pdo->prepare($insert_sql);
                $stmt->execute([
                    ':uid' => $student_id,
                    ':type' => $type,
                    ':amt' => $calculated_amount,
                    ':pts' => $points_to_use
                ]);

                $pdo->commit();

                $unit_text = ($type === 'money') ? number_format($calculated_amount, 2) . " บาท" : number_format($calculated_amount) . " คะแนนพฤติกรรม";
                $message = "ส่งคำร้องสำเร็จ! ใช้ไป " . number_format($points_to_use) . " แต้ม (แลกเป็น " . $unit_text . ") กรุณารอเจ้าหน้าที่อนุมัติ";
                $msg_type = "success";

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = "ทำรายการไม่สำเร็จ: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
}

// 4. ดึงข้อมูลแสดงผล (GET)
$stmt = $pdo->prepare("SELECT points FROM users WHERE id = :uid");
$stmt->execute([':uid' => $student_id]);
$user_data = $stmt->fetch();
$points = $user_data['points'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM redemptions WHERE student_id = :uid ORDER BY created_at DESC LIMIT 20");
$stmt->execute([':uid' => $student_id]);
$history = $stmt->fetchAll();

// Helper Functions
function thai_date_short($strDate) {
    $strYear = date("y", strtotime($strDate)) + 43;
    $strMonth = date("n", strtotime($strDate));
    $strDay = date("j", strtotime($strDate));
    $strMonthCut = Array("", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค.");
    $strMonthThai = $strMonthCut[$strMonth];
    return "$strDay $strMonthThai $strYear";
}

function getStatusBadge($status) {
    $status = strtolower($status);
    if ($status == 'approved') return '<span style="font-size: 0.75rem; padding: 4px 10px; border-radius: 20px; background: #E8F5E9; color: #2E7D32; font-weight: 600;">อนุมัติแล้ว</span>';
    if ($status == 'rejected') return '<span style="font-size: 0.75rem; padding: 4px 10px; border-radius: 20px; background: #FFEBEE; color: #C62828; font-weight: 600;">ปฏิเสธ</span>';
    return '<span style="font-size: 0.75rem; padding: 4px 10px; border-radius: 20px; background: #FFF8E1; color: #FFA000; font-weight: 600;">รอตรวจสอบ</span>';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ส่งคำร้องขอแลก - EcoBank</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="studentcss/student.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .redeem-summary-grid {
            padding: 0 20px;
            margin-top: 15px;
            margin-bottom: 20px;
        }
        .redeem-summary-card {
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #fff5f7 0%, #ffe4e6 100%);
            border: 1px solid #fecdd3;
        }
        .redeem-summary-val {
            font-size: 2.5rem;
            font-weight: 700;
            color: #be123c;
            margin-bottom: 5px;
            z-index: 2;
        }
        .redeem-summary-lbl {
            font-size: 1rem;
            font-weight: 600;
            color: #9f1239;
            z-index: 2;
        }
        .bg-shape {
            position: absolute;
            right: -10px;
            bottom: -20px;
            font-size: 8rem;
            opacity: 0.1;
            z-index: 1;
            color: #e11d48;
            transform: rotate(-15deg);
        }
        .rate-badge {
            display: inline-block;
            margin-top: 4px;
            font-size: 0.8rem;
            padding: 2px 8px;
            border-radius: 10px;
            background: rgba(0,0,0,0.05);
        }
        .preview-box-calc {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
            transition: all 0.2s;
        }
    </style>
</head>
<body class="mb-body">

    <!-- Available Points -->
    <section class="redeem-summary-grid">
        <div class="redeem-summary-card">
            <i class="fas fa-star bg-shape"></i>
            <span class="redeem-summary-lbl">แต้มสะสมที่คุณแลกได้</span>
            <span class="redeem-summary-val"><span id="displayUserPoints"><?php echo number_format($points); ?></span> <span style="font-size: 1rem;">แต้ม</span></span>
        </div>
    </section>

    <!-- Redeem Form -->
    <section class="mb-list" style="margin: 0 20px;">
        <div class="mb-item" style="display: block; padding: 20px;">
            <form method="POST" id="redeemForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="type" id="inputType" value="money">

                <label class="form-label" style="margin-bottom: 10px; display: block; font-weight: 600; color: #333;">คุณต้องการนำแต้มไปแลกเป็นอะไร?</label>
                <div class="type-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px;">
                    <button type="button" class="type-btn type-btn-active" id="moneyBtn" onclick="selectType('money')" style="padding: 15px; border: 2px solid #10b981; border-radius: 12px; background: #f0fdf4; cursor: pointer; transition: all 0.2s;">
                        <i class="fas fa-money-bill-wave" style="font-size: 1.8rem; color: #10b981; margin-bottom: 8px; display: block;"></i>
                        <span style="font-weight: 600; color: #10b981; display: block;">แลกเงินสด</span>
                        <small class="rate-badge" id="rateMoneyText" style="color: #047857;"><?php echo number_format($rates['points_per_money']); ?> แต้ม = 1 บาท</small>
                    </button>
                    
                    <button type="button" class="type-btn" id="pointsBtn" onclick="selectType('reward')" style="padding: 15px; border: 2px solid #eee; border-radius: 12px; background: #fff; cursor: pointer; transition: all 0.2s;">
                        <i class="fas fa-award" style="font-size: 1.8rem; color: #ccc; margin-bottom: 8px; display: block;"></i>
                        <span style="font-weight: 600; color: #999; display: block;">แลกคะแนนพฤติกรรม</span>
                        <small class="rate-badge" id="rateScoreText" style="color: #6b7280;"><?php echo number_format($rates['points_per_score']); ?> แต้ม = 1 คะแนน</small>
                    </button>
                </div>

                <label class="form-label" style="margin-bottom: 10px; display: block; font-weight: 600; color: #333;">ระบุจำนวน <span style="color: #e11d48;">"แต้ม"</span> ที่ต้องการใช้</label>
                <input type="number" name="amount" id="amount" class="form-input" placeholder="ระบุจำนวนแต้ม..." oninput="calculateRealtime()" style="width: 100%; padding: 15px; border: 2px solid #eee; border-radius: 12px; font-size: 1.2rem; margin-bottom: 15px; outline: none; box-sizing: border-box;" step="1" min="1" max="<?php echo $points; ?>" required>

                <!-- กล่องแสดงผลการคำนวณแบบ Real-time -->
                <div id="calcPreview" class="preview-box-calc" style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534;">
                    คุณจะได้รับ: <span id="calcResult" style="font-size: 1.3rem;">0.00</span> <span id="calcUnit">บาท</span>
                </div>
                
                <button type="submit" style="width: 100%; padding: 15px; background: linear-gradient(135deg, #e11d48, #fb7185); color: white; border: none; border-radius: 12px; font-size: 1.1rem; font-weight: 600; cursor: pointer; box-shadow: 0 4px 15px rgba(225, 29, 72, 0.3);">
                    ยืนยันทำรายการ
                </button>
            </form>
        </div>
    </section>

    <!-- Status History Header -->
    <div style="padding: 25px 20px 10px; display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 1.1rem; font-weight: 600; color: #333; margin: 0;">ประวัติคำร้องล่าสุด</h2>
    </div>

    <!-- Status History List -->
    <section class="mb-list" style="padding: 0 20px;">
        <?php if (count($history) > 0): ?>
            <?php foreach ($history as $req): 
                $isMoney = ($req['type'] == 'money');
                $iconClass = $isMoney ? 'fa-money-bill-wave' : 'fa-award';
                $iconBg = $isMoney ? '#f0fdf4' : '#fffbeb';
                $iconColor = $isMoney ? '#10b981' : '#d97706';
                $title = $isMoney ? 'ขอแลกเงินสด' : 'ขอแลกคะแนนพฤติกรรม';
                $receiveText = $isMoney ? number_format($req['amount'], 2) . " บาท" : number_format($req['amount']) . " คะแนน";
                $amountText = "ใช้ไป " . number_format($req['points']) . " แต้ม (ได้ " . $receiveText . ")";
            ?>
            <div class="mb-item">
                <div class="mb-icon" style="background: <?php echo $iconBg; ?>; color: <?php echo $iconColor; ?>; border-radius: 10px;">
                    <i class="fas <?php echo $iconClass; ?>"></i>
                </div>
                <div class="mb-info">
                    <span class="mb-title"><?php echo $title; ?></span>
                    <span class="mb-date"><?php echo thai_date_short($req['created_at']); ?> • <?php echo $amountText; ?></span>
                </div>
                <div class="mb-amount" style="text-align: right;">
                    <?php echo getStatusBadge($req['status']); ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #999;">
                <i class="fas fa-history" style="font-size: 2.5rem; margin-bottom: 10px; opacity: 0.5;"></i>
                <p>ยังไม่มีประวัติคำร้อง</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Bottom Nav -->
    <?php include 'includes/bottom_nav.php'; ?>

    <script>
        let currentRates = {
            points_per_money: <?php echo floatval($rates['points_per_money']); ?>,
            points_per_score: <?php echo floatval($rates['points_per_score']); ?>
        };

        function selectType(type) {
            const moneyBtn = document.getElementById('moneyBtn');
            const pointsBtn = document.getElementById('pointsBtn');
            const inputType = document.getElementById('inputType');
            const calcPreview = document.getElementById('calcPreview');
            
            inputType.value = type;

            if (type === 'money') {
                moneyBtn.style.borderColor = '#10b981';
                moneyBtn.style.background = '#f0fdf4';
                moneyBtn.querySelector('i').style.color = '#10b981';
                moneyBtn.querySelector('span').style.color = '#10b981';
                
                pointsBtn.style.borderColor = '#eee';
                pointsBtn.style.background = '#fff';
                pointsBtn.querySelector('i').style.color = '#ccc';
                pointsBtn.querySelector('span').style.color = '#999';

                calcPreview.style.background = '#f0fdf4';
                calcPreview.style.borderColor = '#bbf7d0';
                calcPreview.style.color = '#166534';
            } else {
                pointsBtn.style.borderColor = '#d97706';
                pointsBtn.style.background = '#fffbeb';
                pointsBtn.querySelector('i').style.color = '#d97706';
                pointsBtn.querySelector('span').style.color = '#d97706';
                
                moneyBtn.style.borderColor = '#eee';
                moneyBtn.style.background = '#fff';
                moneyBtn.querySelector('i').style.color = '#ccc';
                moneyBtn.querySelector('span').style.color = '#999';

                calcPreview.style.background = '#fffbeb';
                calcPreview.style.borderColor = '#fde68a';
                calcPreview.style.color = '#b45309';
            }
            calculateRealtime();
        }

        function calculateRealtime() {
            const type = document.getElementById('inputType').value;
            const pts = parseFloat(document.getElementById('amount').value) || 0;
            const resultEl = document.getElementById('calcResult');
            const unitEl = document.getElementById('calcUnit');

            if (type === 'money') {
                const money = pts / currentRates.points_per_money;
                resultEl.textContent = money.toFixed(2);
                unitEl.textContent = 'บาท';
            } else {
                const score = Math.floor(pts / currentRates.points_per_score);
                resultEl.textContent = score;
                unitEl.textContent = 'คะแนนพฤติกรรม';
            }
        }

        // ดึงเรทล่าสุดจากฐานข้อมูลแบบ Real-time ทุกๆ 3 วินาที
        function fetchRatesRealtime() {
            fetch('?action=get_rates_realtime')
                .then(res => res.json())
                .then(data => {
                    if (data.points_per_money && data.points_per_score) {
                        currentRates.points_per_money = parseFloat(data.points_per_money);
                        currentRates.points_per_score = parseFloat(data.points_per_score);

                        document.getElementById('rateMoneyText').textContent = `${currentRates.points_per_money} แต้ม = 1 บาท`;
                        document.getElementById('rateScoreText').textContent = `${currentRates.points_per_score} แต้ม = 1 คะแนน`;
                        
                        if (typeof data.user_points !== 'undefined') {
                            document.getElementById('displayUserPoints').textContent = Number(data.user_points).toLocaleString();
                            document.getElementById('amount').max = data.user_points;
                        }
                        calculateRealtime();
                    }
                })
                .catch(err => console.error('Realtime sync error:', err));
        }

        // Initialize
        selectType('money');
        setInterval(fetchRatesRealtime, 3000);

        <?php if ($message): ?>
            Swal.fire({
                title: '<?php echo ($msg_type == 'success') ? 'สำเร็จ!' : 'แจ้งเตือน'; ?>',
                text: '<?php echo $message; ?>',
                icon: '<?php echo $msg_type; ?>',
                confirmButtonColor: '<?php echo ($msg_type == 'success') ? '#e11d48' : '#374151'; ?>',
                confirmButtonText: 'ตกลง'
            });
        <?php endif; ?>
    </script>
</body>
</html>